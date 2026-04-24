<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Tag1\Scolta\Index\MemoryBudgetSuggestion;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Index\MemoryBudgetSuggestion;

/**
 * Builds and extracts the Memory Budget fieldset for ScoltaSettingsForm.
 */
final class MemoryBudgetSettingsFieldSet {

  /**
   * Build the #type => 'fieldset' render array for the settings form.
   *
   * @param \Tag1\Scolta\Config\MemoryBudgetConfig $config
   *   The currently persisted config.
   *
   * @return array
   *   A Drupal Form API render array.
   */
  public static function build(MemoryBudgetConfig $config): array {
    $suggestion = $config->suggest();
    $limitText  = MemoryBudgetSuggestion::getMemoryLimitText();
    $fit        = MemoryBudgetSuggestion::checkProfileFit($config->profile());

    $fieldset = [
      '#type'        => 'details',
      '#title'       => t('Memory Budget'),
      '#open'        => FALSE,
      '#description' => t(
        "Scolta's memory budget tells Scolta how much RAM to use while building the search index. It never exceeds the PHP memory limit your host already allows. You do not need to edit php.ini unless you want to use a profile that requires more memory than your host provides."
      ),
    ];

    $limitDescription = t(
      'Your current PHP memory limit is @limit. The conservative profile fits within 128 MB and is safe for most shared hosts. Detected: @reason Can be overridden per-run with <code>--memory-budget</code> on drush scolta:build.',
      [
        '@limit'  => $limitText,
        '@reason' => $suggestion['reason'],
      ]
    );

    if ($fit['status'] === 'warn') {
      $limitDescription = $limitDescription . ' ' . t(
        '<strong style="color:red">@warning</strong>',
        ['@warning' => $fit['warning']]
      );
    }

    $fieldset['memory_budget_profile'] = [
      '#type'          => 'select',
      '#title'         => t('Memory budget profile'),
      '#options'       => [
        'conservative' => t('Conservative — ≤ 96 MB peak (default)'),
        'balanced'     => t('Balanced — ~384 MB'),
        'aggressive'   => t('Aggressive — ~1 GB'),
      ],
      '#default_value' => $config->profile(),
      '#description'   => $limitDescription,
    ];

    $fieldset['chunk_size'] = [
      '#type'          => 'number',
      '#title'         => t('Chunk size'),
      '#default_value' => $config->chunkSize(),
      '#min'           => 1,
      '#step'          => 1,
      '#description'   => t('Pages per chunk during a PHP build. Leave blank to use the profile default (50 / 200 / 500 for conservative / balanced / aggressive). Lower values reduce peak RSS; higher values reduce merge overhead on large corpora. Can be overridden per-run with @flag on drush scolta:build.', ['@flag' => '--chunk-size']),
    ];

    return $fieldset;
  }

  /**
   * Extract a MemoryBudgetConfig from submitted form values.
   *
   * @param array $values
   *   The $form_state->getValues() array (or a sub-array).
   *
   * @return \Tag1\Scolta\Config\MemoryBudgetConfig
   *   The loaded memory budget configuration.
   */
  public static function extract(array $values): MemoryBudgetConfig {
    $chunkRaw = $values['chunk_size'] ?? '';
    return MemoryBudgetConfig::load([
      'profile'      => $values['memory_budget_profile'] ?? 'conservative',
      'custom_bytes' => NULL,
      'chunk_size'   => ($chunkRaw !== '' && $chunkRaw !== NULL) ? (int) $chunkRaw : NULL,
    ]);
  }

  /**
   * Format a byte value as a human-readable string (MB or GB).
   */
  public static function formatBytes(int $bytes): string {
    if ($bytes >= 1024 * 1024 * 1024) {
      return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
    }

    return round($bytes / (1024 * 1024)) . ' MB';
  }

}
