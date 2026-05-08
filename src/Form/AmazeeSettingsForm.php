<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\scolta\AiProvider\Amazee\DrupalConfigStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\AiProvider\Amazee\AmazeeAccountUpgrader;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;

/**
 * Multi-step form for connecting Scolta to the Amazee.ai AI provider.
 *
 * Two connection paths:
 *  1. Trial — one step: email → provision trial → connected.
 *  2. Upgrade — three steps: email → OTP → region selection → connected.
 *
 * Form state machine values for 'amazee_step':
 *  - 'start'          The initial view (disconnected or already connected).
 *  - 'verification'   OTP sent; waiting for the code.
 *  - 'region'         Signed in; waiting for region selection.
 *
 * @since 0.4.0
 * @stability experimental
 */
class AmazeeSettingsForm extends FormBase {

  public function __construct(
    private readonly DrupalConfigStorage $storage,
    private readonly AmazeeClient $amazeeClient,
    private readonly AmazeeTrialProvisioner $trialProvisioner,
    private readonly AmazeeAccountUpgrader $upgrader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $httpClient = $container->get('http_client');
    $storage = $container->get('scolta.amazee_config_storage');
    $amazeeClient = new AmazeeClient(httpClient: $httpClient);
    return new static(
      $storage,
      $amazeeClient,
      new AmazeeTrialProvisioner($amazeeClient, $storage),
      new AmazeeAccountUpgrader($amazeeClient, $storage),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'scolta_amazee_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $form_state->get('amazee_step') ?? 'start';

    $form['#title'] = $this->t('Amazee.ai Configuration');

    switch ($step) {
      case 'verification':
        return $this->buildVerificationStep($form, $form_state);

      case 'region':
        return $this->buildRegionStep($form, $form_state);

      default:
        return $this->buildStartStep($form, $form_state);
    }
  }

  /**
   * Builds the initial step: show connected status or start/sign-in options.
   */
  private function buildStartStep(array $form, FormStateInterface $form_state): array {
    $creds = $this->storage->load();

    if ($creds !== NULL) {
      $form['status'] = [
        '#markup' => '<p>' . $this->t(
          'Connected to Amazee.ai (region: <strong>@region</strong>).',
          ['@region' => $creds['region']],
        ) . '</p>',
      ];

      $form['actions']['disconnect'] = [
        '#type' => 'submit',
        '#value' => $this->t('Disconnect'),
        '#submit' => [[$this, 'submitDisconnect']],
        '#limit_validation_errors' => [],
      ];

      return $form;
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t(
        'Connect Scolta to <a href=":url" target="_blank">Amazee.ai</a> for privacy-respecting, budget-aware AI search. Start a free trial or sign in to an existing account.',
        [':url' => 'https://amazee.ai'],
      ) . '</p>',
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#description' => $this->t('Used to provision or sign in to your Amazee.ai account.'),
    ];

    $form['actions']['trial'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start free trial'),
      '#submit' => [[$this, 'submitStartTrial']],
    ];

    $form['actions']['signin'] = [
      '#type' => 'submit',
      '#value' => $this->t('Sign in to existing account'),
      '#submit' => [[$this, 'submitRequestCode']],
    ];

    return $form;
  }

  /**
   * Builds the verification code entry step.
   */
  private function buildVerificationStep(array $form, FormStateInterface $form_state): array {
    $email = $form_state->get('amazee_email');

    $form['info'] = [
      '#markup' => '<p>' . $this->t(
        'A verification code has been sent to <strong>@email</strong>. Enter it below.',
        ['@email' => $email],
      ) . '</p>',
    ];

    $form['code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Verification code'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'one-time-code'],
    ];

    $form['actions']['verify'] = [
      '#type' => 'submit',
      '#value' => $this->t('Verify code'),
      '#submit' => [[$this, 'submitVerifyCode']],
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => [[$this, 'submitBack']],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Builds the region selection step.
   */
  private function buildRegionStep(array $form, FormStateInterface $form_state): array {
    $form['info'] = [
      '#markup' => '<p>' . $this->t('Select the region where your AI requests will be processed.') . '</p>',
    ];

    try {
      $sessionToken = $form_state->get('amazee_session_token');
      $regions = $this->upgrader->listRegions($sessionToken);

      $options = [];
      foreach ($regions as $region) {
        $id = $region['id'] ?? '';
        $name = $region['name'] ?? $id;
        if ($id !== '') {
          $options[$id] = $name;
        }
      }

      if (empty($options)) {
        $form['error'] = ['#markup' => '<p>' . $this->t('No regions available. Please try again.') . '</p>'];
        $form['actions']['back'] = [
          '#type' => 'submit',
          '#value' => $this->t('Back'),
          '#submit' => [[$this, 'submitBack']],
          '#limit_validation_errors' => [],
        ];
        return $form;
      }

      $form['region'] = [
        '#type' => 'radios',
        '#title' => $this->t('Region'),
        '#options' => $options,
        '#required' => TRUE,
      ];
    }
    catch (AmazeeApiException $e) {
      $form['error'] = ['#markup' => '<p>' . $this->t('Error fetching regions: @error', ['@error' => $e->getMessage()]) . '</p>'];
      $form['actions']['back'] = [
        '#type' => 'submit',
        '#value' => $this->t('Back'),
        '#submit' => [[$this, 'submitBack']],
        '#limit_validation_errors' => [],
      ];
      return $form;
    }

    $form['actions']['connect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect'),
      '#submit' => [[$this, 'submitConnect']],
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#submit' => [[$this, 'submitBack']],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Submit handlers.
  // ---------------------------------------------------------------------------

  /**
   * Provision a free trial and immediately connect.
   */
  public function submitStartTrial(array &$form, FormStateInterface $form_state): void {
    $email = $form_state->getValue('email');

    try {
      $this->trialProvisioner->provision($email);
      $this->messenger()->addStatus($this->t('Connected to Amazee.ai. Your free trial is active.'));
      $form_state->setRebuild(TRUE);
    }
    catch (AmazeeApiException $e) {
      $this->messenger()->addError($this->t('Trial provisioning failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Send a verification code to the given email address.
   */
  public function submitRequestCode(array &$form, FormStateInterface $form_state): void {
    $email = $form_state->getValue('email');

    try {
      $this->upgrader->requestVerificationCode($email);
      $form_state->set('amazee_step', 'verification');
      $form_state->set('amazee_email', $email);
      $form_state->setRebuild(TRUE);
      $this->messenger()->addStatus($this->t('Verification code sent to @email.', ['@email' => $email]));
    }
    catch (AmazeeApiException $e) {
      $this->messenger()->addError($this->t('Could not send verification code: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Verify the OTP code and advance to region selection.
   */
  public function submitVerifyCode(array &$form, FormStateInterface $form_state): void {
    $email = $form_state->get('amazee_email');
    $code = $form_state->getValue('code');

    try {
      $sessionToken = $this->upgrader->signIn($email, $code);
      $form_state->set('amazee_step', 'region');
      $form_state->set('amazee_session_token', $sessionToken);
      $form_state->setRebuild(TRUE);
    }
    catch (AmazeeApiException $e) {
      $this->messenger()->addError($this->t('Verification failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Provision a private key in the selected region and store credentials.
   */
  public function submitConnect(array &$form, FormStateInterface $form_state): void {
    $sessionToken = $form_state->get('amazee_session_token');
    $regionId = $form_state->getValue('region');

    try {
      $this->upgrader->upgrade($sessionToken, $regionId);
      $form_state->set('amazee_step', 'start');
      $form_state->setRebuild(TRUE);
      $this->messenger()->addStatus($this->t('Successfully connected to Amazee.ai.'));
    }
    catch (AmazeeApiException $e) {
      $this->messenger()->addError($this->t('Connection failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Remove stored credentials.
   */
  public function submitDisconnect(array &$form, FormStateInterface $form_state): void {
    $this->storage->clear();
    $form_state->set('amazee_step', 'start');
    $form_state->setRebuild(TRUE);
    $this->messenger()->addStatus($this->t('Disconnected from Amazee.ai.'));
  }

  /**
   * Return to the start step.
   */
  public function submitBack(array &$form, FormStateInterface $form_state): void {
    $form_state->set('amazee_step', 'start');
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // All transitions use custom submit handlers; this is intentionally empty.
  }

}
