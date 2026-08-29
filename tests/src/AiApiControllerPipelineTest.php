<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\scolta\Controller\AiApiControllerBase;
use Drupal\scolta\Service\ScoltaAiService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * A concrete AiApiControllerBase whose invokeHandler() is controllable.
 *
 * Records every invocation so tests can assert whether (and with what) the
 * endpoint handler was reached, and returns a canned result so tests can
 * drive the shared response mapping.
 */
final class RecordingPipelineController extends AiApiControllerBase {

  /**
   * Every invokeHandler() call: ['handler' => ..., 'body' => ...].
   *
   * @var array<int, array{handler: \Tag1\Scolta\Http\AiEndpointHandler, body: array}>
   */
  public array $invocations = [];

  /**
   * The result invokeHandler() returns.
   *
   * @var array<string, mixed>
   */
  public array $result = ['ok' => TRUE, 'data' => ['ok' => TRUE]];

  /**
   * {@inheritdoc}
   */
  protected function invokeHandler(AiEndpointHandler $handler, array $body): array {
    $this->invocations[] = ['handler' => $handler, 'body' => $body];
    return $this->result;
  }

}

/**
 * A PSR-3 logger that records every log record for assertions.
 */
final class PipelineRecordingLogger extends AbstractLogger {

  /**
   * Recorded records: ['level' => ..., 'message' => ..., 'context' => ...].
   *
   * @var array<int, array{level: mixed, message: string, context: array}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

}

/**
 * A flood backend that records calls and answers from a scripted map.
 */
final class RecordingFlood implements FloodInterface {

  /**
   * Every isAllowed() call: [event, threshold, window, identifier].
   *
   * @var array<int, array>
   */
  public array $isAllowedCalls = [];

  /**
   * Every register() call: [event, window, identifier].
   *
   * @var array<int, array>
   */
  public array $registerCalls = [];

  /**
   * Per-event isAllowed() answers; events not listed answer TRUE.
   *
   * @var array<string, bool>
   */
  public array $answers = [];

  /**
   * When set, isAllowed() throws it (simulates a broken flood backend).
   */
  public ?\Throwable $throws = NULL;

  /**
   * {@inheritdoc}
   */
  public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL) {
    if ($this->throws !== NULL) {
      throw $this->throws;
    }
    $this->isAllowedCalls[] = [$name, $threshold, $window, $identifier];
    return $this->answers[$name] ?? TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function register($name, $window = 3600, $identifier = NULL) {
    $this->registerCalls[] = [$name, $window, $identifier];
  }

  /**
   * {@inheritdoc}
   */
  public function clear($name, $identifier = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {}

}

/**
 * Behavioral tests for the shared AI API request pipeline.
 *
 * Constructs a real (test-local) subclass of AiApiControllerBase and drives
 * handle() end to end with stubbed services: flood denial and fail-closed
 * behavior, both flood thresholds, JSON-body validation, success mapping,
 * and the error path's logging and exception-message hygiene. Replaces the
 * source-grep coverage that used to live in ControllerJsonSafetyTest and
 * ControllerHandlerTest.
 *
 * ControllerBase helpers (config(), getLogger()) fall back to the \Drupal
 * container, so each test installs a minimal container and tearDown()
 * removes it.
 */
class AiApiControllerPipelineTest extends TestCase {

  /**
   * The logger every controller under test logs to.
   */
  private PipelineRecordingLogger $logger;

  /**
   * Flood settings served by the config stub, keyed by full config key.
   *
   * @var array<string, int>
   */
  private array $floodConfig = [];

  protected function setUp(): void {
    $this->logger = new PipelineRecordingLogger();

    $settings = $this->createStub(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      fn (string $key) => $this->floodConfig[$key] ?? NULL
    );
    $configFactory = $this->createStub(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($settings);

    $loggerFactory = $this->createStub(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);

    $container = new ContainerBuilder();
    $container->set('config.factory', $configFactory);
    $container->set('logger.factory', $loggerFactory);
    \Drupal::setContainer($container);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
  }

  /**
   * Build the controller under test.
   */
  private function createController(FloodInterface $flood, ?ScoltaAiService $aiService = NULL): RecordingPipelineController {
    if ($aiService === NULL) {
      $aiService = $this->createStub(ScoltaAiService::class);
      $aiService->method('getConfig')->willReturn(new ScoltaConfig());
    }
    return new RecordingPipelineController(
      $aiService,
      $this->createStub(EventDispatcherInterface::class),
      $flood,
    );
  }

  /**
   * A POST request with the given raw body.
   */
  private function request(string $body): Request {
    return Request::create('/api/scolta/v1/test', 'POST', [], [], [], [], $body);
  }

  // -------------------------------------------------------------------
  // Flood control.
  // -------------------------------------------------------------------

  public function testFloodDenialReturns429AndNeverInvokesTheHandler(): void {
    $flood = new RecordingFlood();
    $flood->answers['scolta.ai_api_ip'] = FALSE;

    // A mocked AI service proves the pipeline stops before any AI work:
    // getConfig() is the first AI-service touch in handle().
    $aiService = $this->createMock(ScoltaAiService::class);
    $aiService->expects($this->never())->method('getConfig');

    $controller = $this->createController($flood, $aiService);
    $response = $controller->handle($this->request('{"query":"x"}'));

    $this->assertSame(429, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame(['error'], array_keys($body));
    $this->assertNotSame('', $body['error']);
    $this->assertSame([], $controller->invocations, 'A throttled request must never reach the handler');
  }

  public function testFloodBackendFailureFailsClosedAndLogsTheError(): void {
    $flood = new RecordingFlood();
    $flood->throws = new \RuntimeException('flood storage is down');

    $controller = $this->createController($flood);
    $response = $controller->handle($this->request('{"query":"x"}'));

    $this->assertSame(429, $response->getStatusCode(), 'A broken flood backend must deny the request, not bypass rate limiting');
    $this->assertSame([], $controller->invocations);
    $this->assertNotEmpty($this->logger->records, 'The flood failure must be logged');
    $this->assertSame('error', $this->logger->records[0]['level']);
    $this->assertSame('flood storage is down', $this->logger->records[0]['context']['@msg'] ?? NULL);
  }

  public function testBothFloodThresholdsAreConsulted(): void {
    $flood = new RecordingFlood();

    $controller = $this->createController($flood);
    $response = $controller->handle($this->request('{"query":"x"}'));

    $this->assertSame(200, $response->getStatusCode());
    $events = array_column($flood->isAllowedCalls, 0);
    $this->assertSame(['scolta.ai_api_ip', 'scolta.ai_api_global'], $events, 'Both the per-IP and the global threshold must be checked');
    // The per-IP event is keyed by the client IP, the global one by a shared
    // identifier — otherwise the global threshold degrades to per-IP.
    $this->assertSame('127.0.0.1', $flood->isAllowedCalls[0][3]);
    $this->assertSame('global', $flood->isAllowedCalls[1][3]);
    // An allowed request is registered against both windows.
    $this->assertSame(['scolta.ai_api_ip', 'scolta.ai_api_global'], array_column($flood->registerCalls, 0));
  }

  public function testGlobalThresholdDenialReturns429(): void {
    $flood = new RecordingFlood();
    $flood->answers['scolta.ai_api_global'] = FALSE;

    $controller = $this->createController($flood);
    $response = $controller->handle($this->request('{"query":"x"}'));

    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame([], $controller->invocations);
  }

  public function testConfiguredFloodLimitsReachTheBackend(): void {
    $this->floodConfig = [
      'flood.ai_ip_limit' => 5,
      'flood.ai_ip_window' => 30,
      'flood.ai_global_limit' => 100,
      'flood.ai_global_window' => 120,
    ];
    $flood = new RecordingFlood();

    $this->createController($flood)->handle($this->request('{}'));

    $this->assertSame(['scolta.ai_api_ip', 5, 30, '127.0.0.1'], $flood->isAllowedCalls[0]);
    $this->assertSame(['scolta.ai_api_global', 100, 120, 'global'], $flood->isAllowedCalls[1]);
  }

  public function testZeroThresholdDisablesThatFloodLayer(): void {
    $this->floodConfig = ['flood.ai_ip_limit' => 0];
    $flood = new RecordingFlood();

    $response = $this->createController($flood)->handle($this->request('{}'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['scolta.ai_api_global'], array_column($flood->isAllowedCalls, 0), 'A 0 limit must skip the per-IP layer but keep the global one');
  }

  // -------------------------------------------------------------------
  // Request body validation.
  // -------------------------------------------------------------------

  public function testMalformedJsonBodyReturns400AndNeverInvokesTheHandler(): void {
    $controller = $this->createController(new RecordingFlood());

    $response = $controller->handle($this->request('{"query": broken'));

    $this->assertSame(400, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayHasKey('error', $body);
    $this->assertSame([], $controller->invocations, 'A malformed body must be rejected before the handler runs');
  }

  public function testNonArrayJsonBodyReturns400(): void {
    $controller = $this->createController(new RecordingFlood());

    $response = $controller->handle($this->request('"just a string"'));

    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame([], $controller->invocations);
  }

  // -------------------------------------------------------------------
  // Response mapping.
  // -------------------------------------------------------------------

  public function testHandlerSuccessReturnsTheDataAs200Json(): void {
    $controller = $this->createController(new RecordingFlood());
    $controller->result = ['ok' => TRUE, 'data' => ['terms' => ['a', 'b']]];

    $response = $controller->handle($this->request('{"query":"pricing"}'));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['terms' => ['a', 'b']], json_decode((string) $response->getContent(), TRUE));
    // The decoded body reached the handler intact.
    $this->assertCount(1, $controller->invocations);
    $this->assertSame(['query' => 'pricing'], $controller->invocations[0]['body']);
    $this->assertInstanceOf(AiEndpointHandler::class, $controller->invocations[0]['handler']);
  }

  public function testHandlerFailureMapsStatusHidesExceptionAndLogsIt(): void {
    $exception = new \RuntimeException('anthropic key sk-secret rejected');
    $controller = $this->createController(new RecordingFlood());
    $controller->result = [
      'ok' => FALSE,
      'status' => 502,
      'error' => 'AI service unavailable',
      'limit' => 2,
      'exception' => $exception,
    ];

    $response = $controller->handle($this->request('{"query":"x"}'));

    $this->assertSame(502, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('AI service unavailable', $body['error']);
    $this->assertSame(2, $body['limit'], 'The remaining-limit value must be forwarded when present');
    $this->assertStringNotContainsString('sk-secret', (string) $response->getContent(), 'The exception message must never leak into the response body');

    // The Throwable itself reaches the log context for dblog/backtraces.
    $this->assertNotEmpty($this->logger->records);
    $record = $this->logger->records[0];
    $this->assertSame('error', $record['level']);
    $this->assertSame($exception, $record['context']['exception'] ?? NULL);
    $this->assertSame('anthropic key sk-secret rejected', $record['context']['@msg'] ?? NULL);
  }

  public function testHandlerFailureWithoutLimitOmitsTheLimitKey(): void {
    $controller = $this->createController(new RecordingFlood());
    $controller->result = ['ok' => FALSE, 'status' => 404, 'error' => 'Feature disabled'];

    $response = $controller->handle($this->request('{}'));

    $this->assertSame(404, $response->getStatusCode());
    $this->assertSame(['error' => 'Feature disabled'], json_decode((string) $response->getContent(), TRUE));
    $this->assertSame([], $this->logger->records, 'A failure result without an exception must not log');
  }

}
