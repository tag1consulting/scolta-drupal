<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\scolta_ui\AiProvider\Amazee\DrupalConfigStorage;
use Drupal\scolta_ui\Cache\DrupalCacheDriver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tag1\Scolta\AiProvider\Amazee\AmazeeAccountUpgrader;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\AiProvider\Amazee\AmazeeModelResolver;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;

/**
 * Multi-step form for connecting Scolta to the Amazee.ai AI provider.
 *
 * Two connection paths, both explicit, neither reached on its own. Selecting
 * Amazee.ai as the provider connects nothing; an operator has to come here and
 * choose one:
 *  1. Try the demo — one click: no email, no account, no other input. The demo
 *     is one-time per site and runs until its included credit is used up.
 *  2. Enter your Amazee credentials — three steps: email → OTP → region. This
 *     signs in to (or creates) a real amazee.ai account and stores the
 *     credentials it returns. Email-only, mirroring amazee.ai's own
 *     ai_provider_amazeeio module; there is no paste-your-API-key path. It is
 *     also the recovery path once a demo's credit runs out, which
 *     KeyExpiryRecovery flags with its upgrade-needed marker.
 *
 * Form state machine values for 'amazee_step':
 *  - 'start'          The initial view (disconnected or already connected).
 *  - 'verification'   OTP sent; waiting for the code.
 *  - 'region'         Signed in; waiting for region selection.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
class AmazeeSettingsForm extends FormBase {

  /**
   * Neither private nor readonly, both deliberately.
   *
   * FormBase brings in DependencySerializationTrait, and a cached form is
   * unserialized by reassigning its service properties. That reassignment
   * cannot reach a private property and cannot write a readonly one at all —
   * a readonly property would raise "Cannot modify readonly property" on the
   * first rebuild of a cached form. See https://www.drupal.org/node/3110266.
   */
  public function __construct(
    protected DrupalConfigStorage $storage,
    protected AmazeeTrialProvisioner $trialProvisioner,
    protected AmazeeAccountUpgrader $upgrader,
    protected KeyExpiryRecovery $keyRecovery,
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
      new AmazeeTrialProvisioner($amazeeClient, $storage, NULL, new AmazeeModelResolver($amazeeClient)),
      new AmazeeAccountUpgrader($amazeeClient, $storage),
      // Reads/clears the same re-authentication marker the admin notice and
      // /health observe, over the default cache bin ScoltaAiService records it
      // in. A successful reconnect clears it so the prompt goes away.
      new KeyExpiryRecovery($storage, new DrupalCacheDriver($container->get('cache.default'))),
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
        '#markup' => '<p>' . $this->connectedMessage($creds['region']) . '</p>',
      ];

      // An expired or revoked connection is the one moment an operator needs
      // the account path, so say so here instead of leaving them to work out
      // that "Disconnect" is a prerequisite.
      if ($this->keyRecovery->isUpgradeNeeded()) {
        $form['upgrade_needed'] = [
          '#markup' => '<p>' . $this->t(
            'Your Amazee.ai connection is no longer accepted, so AI features are off. Enter your Amazee credentials below to keep AI running: sign in with your email address and we will set up your account.',
          ) . '</p>',
        ];
        $form += $this->buildAccountSignIn();
      }

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
        'Connect Scolta to <a href=":url" target="_blank">Amazee.ai</a> for privacy-respecting, budget-aware AI search. Nothing is connected until you choose one of the two actions below.',
        [':url' => 'https://amazee.ai'],
      ) . '</p>',
    ];

    // ACTION ONE: the demo. No email, no account, no other input — one click.
    // The email field below belongs to the account path only, so this button
    // limits validation to nothing and never sees it.
    $form['demo'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Try the demo'),
    ];
    $form['demo']['description'] = [
      '#markup' => '<p>' . $this->t(
        'Turn on AI search right now with a free demo. No email address, no account, no card. The demo runs until its included credit is used up; after that you continue by signing in with your email below.',
      ) . '</p>',
    ];
    $form['demo']['actions']['trial'] = [
      '#type' => 'submit',
      '#value' => $this->t('Try the demo'),
      '#submit' => [[$this, 'submitStartTrial']],
      '#limit_validation_errors' => [],
    ];

    // ACTION TWO: the operator's own amazee.ai account, by email. This is the
    // only credential path there is: amazee.ai issues and manages the keys, so
    // there is no form here to paste one into.
    $form += $this->buildAccountSignIn();

    return $form;
  }

  /**
   * Builds the "enter your Amazee credentials" (email sign-in) section.
   *
   * Email-only by design, mirroring amazee.ai's own ai_provider_amazeeio
   * module: signing in returns the account's credentials and Scolta stores
   * them. An operator who already holds an amazee.ai account attaches it here
   * with that account's email; the same flow creates the account when it does
   * not exist yet. There is deliberately no paste-your-API-key field.
   */
  private function buildAccountSignIn(): array {
    $form = [];

    $form['account'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Enter your Amazee credentials'),
    ];
    $form['account']['description'] = [
      '#markup' => '<p>' . $this->t(
        'Sign in with the email address on your amazee.ai account. We will email you a verification code, you pick a region, and your account credentials are stored here. If you do not have an account yet, this creates one. You never generate or paste an API key.',
      ) . '</p>',
    ];
    $form['account']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#description' => $this->t('Where the verification code is sent.'),
    ];
    $form['account']['actions']['signin'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send verification code'),
      '#submit' => [[$this, 'submitRequestCode']],
      '#validate' => [[$this, 'validateAccountEmail']],
    ];

    return $form;
  }

  /**
   * The connected status line, stating only what the store recorded.
   *
   * Provenance is written when a connection is established, so this names the
   * demo or the operator's account from a fact rather than a guess. A
   * connection made before provenance was recorded names neither.
   */
  private function connectedMessage(string $region): string {
    // No instanceof guard: $storage is typed to DrupalConfigStorage, which
    // implements ProvenanceAwareConfigStorageInterface, so a check here is
    // dead code (PHPStan: instanceof.alwaysTrue). Adapters whose store is
    // typed to the base interface do need one; this one cannot.
    $source = $this->storage->loadConnectionSource();

    return match ($source) {
      AmazeeConnectionSource::Demo => (string) $this->t(
        'Connected to Amazee.ai using the free demo (region: <strong>@region</strong>).',
        ['@region' => $region],
      ),
      AmazeeConnectionSource::Account => (string) $this->t(
        'Connected to Amazee.ai with your account (region: <strong>@region</strong>).',
        ['@region' => $region],
      ),
      default => (string) $this->t(
        'Connected to Amazee.ai (region: <strong>@region</strong>).',
        ['@region' => $region],
      ),
    };
  }

  /**
   * Requires an email address for the account path, and only for that path.
   *
   * The demo button carries #limit_validation_errors => [] and never reaches
   * this, which is what lets trying the demo cost no input at all.
   */
  public function validateAccountEmail(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('email')) === '') {
      $form_state->setErrorByName('email', $this->t('Enter the email address on your amazee.ai account.'));
    }
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

  /**
   * Establish the free demo connection, on one click and no other input.
   *
   * Deliberately passes no email. The demo asks for nothing, which is the
   * whole point of it: an operator evaluating Scolta's AI should not have to
   * hand over an address first. The account path collects an email because
   * amazee.ai needs one to issue a real account; the demo does not.
   *
   * Reachable at any time — on a fresh install, or after running on another
   * provider — but the demo itself is one-time. When its credit is gone the
   * control plane refuses, and the error handler below points at the account
   * path instead of failing silently or quietly minting something else.
   */
  public function submitStartTrial(array &$form, FormStateInterface $form_state): void {
    try {
      $result = $this->trialProvisioner->provision();
      // Fresh credentials are stored — clear any pending re-authentication
      // prompt so the admin notice and /health recover.
      $this->keyRecovery->clearUpgradeNeeded();
      $this->selectAmazeeProvider();

      if ($result->aiModel !== NULL || $result->aiExpansionModel !== NULL) {
        // The provisioner resolves Amazee LiteLLM gateway aliases, which only
        // the gateway accepts. They go to the gateway-scoped keys, never to
        // the operator-facing ai_model / ai_expansion_model — those hold
        // provider-native IDs and stay valid for a site that later switches to
        // a direct provider key (scolta-php#251). No "still at the default"
        // guard is needed any more: nothing an operator chose lives in these
        // keys, so there is nothing here to protect.
        $config = $this->configFactory()->getEditable('scolta_ui.settings');

        if ($result->aiModel !== NULL) {
          $config->set('amazee_model', $result->aiModel);
        }
        if ($result->aiExpansionModel !== NULL) {
          $config->set('amazee_expansion_model', $result->aiExpansionModel);
        }
        $config->save();

        $modelInfo = $result->aiModel ?? $this->t('(default)');
        $this->messenger()->addStatus($this->t(
          'Connected to Amazee.ai. The demo is active. AI model set to @model.',
          ['@model' => $modelInfo],
        ));
      }
      else {
        $this->messenger()->addStatus($this->t('Connected to Amazee.ai. The demo is active.'));
      }

      $form_state->setRebuild(TRUE);
    }
    catch (AmazeeApiException $e) {
      // The demo is one-time. A refusal here is most often "already used", and
      // the useful next step is always the same, so name it rather than leaving
      // an operator with an API error and no route forward.
      $this->messenger()->addError($this->t(
        'Could not start the demo: @error',
        ['@error' => $e->getMessage()],
      ));
      $this->messenger()->addWarning($this->t(
        'The free demo can only be used once per site. If this site has already used it, continue under "Enter your Amazee credentials" below: sign in with your email address and we will set up your account.',
      ));
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
      // Fresh credentials are stored — clear any pending re-authentication
      // prompt so the admin notice and /health recover.
      $this->keyRecovery->clearUpgradeNeeded();
      $this->selectAmazeeProvider();
      $form_state->set('amazee_step', 'start');
      $form_state->setRebuild(TRUE);
      $this->messenger()->addStatus($this->t('Successfully connected to Amazee.ai.'));
    }
    catch (AmazeeApiException $e) {
      $this->messenger()->addError($this->t('Connection failed: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Select Amazee.ai as the AI provider on a completed connection.
   *
   * The stored connection is used only while 'amazee' is the selected
   * provider, so finishing this flow has to set it — otherwise the operator
   * completes a connection, is told they are connected, and AI keeps running
   * on whatever provider was selected before, with the connection inert.
   *
   * This is not automatic enablement: it is the last step of an action the
   * operator started by entering their email and pressing a button here.
   */
  private function selectAmazeeProvider(): void {
    $config = $this->configFactory()->getEditable('scolta_ui.settings');
    if ($config->get('ai_provider') !== 'amazee') {
      $config->set('ai_provider', 'amazee')->save();
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
