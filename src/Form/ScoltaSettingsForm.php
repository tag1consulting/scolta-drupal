<?php

namespace Drupal\scolta\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class ScoltaSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['scolta.settings'];
  }

  public function getFormId() {
    return 'scolta_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('scolta.settings');

    $form['ai_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI Provider'),
      '#options' => [
        'anthropic' => 'Anthropic (Claude)',
        'openai' => 'OpenAI',
      ],
      '#default_value' => $config->get('ai_provider') ?? 'anthropic',
    ];

    $form['scoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Scoring'),
      '#open' => TRUE,
    ];

    $form['scoring']['title_boost'] = [
      '#type' => 'number',
      '#title' => $this->t('Title boost factor'),
      '#default_value' => $config->get('scoring.title_boost') ?? 2.0,
      '#step' => 0.1,
    ];

    $form['scoring']['recency_decay'] = [
      '#type' => 'number',
      '#title' => $this->t('Recency decay factor'),
      '#default_value' => $config->get('scoring.recency_decay') ?? 0.1,
      '#step' => 0.01,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('scolta.settings')
      ->set('ai_provider', $form_state->getValue('ai_provider'))
      ->set('scoring.title_boost', $form_state->getValue('title_boost'))
      ->set('scoring.recency_decay', $form_state->getValue('recency_decay'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
