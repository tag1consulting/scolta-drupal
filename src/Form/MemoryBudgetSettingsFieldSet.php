<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

use Tag1\Scolta\Config\MemoryBudgetConfig;

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

    $descriptions = [
      'conservative' => t('Peak RAM ≤ 96 MB — safe for shared hosting (memory_limit ≤ 128 MB).'),
      'balanced'     => t('~256–512 MB available. Larger chunks, fewer round-trips.'),
      'aggressive'   => t('≥ 1 GB available. Maximises throughput on high-memory servers.'),
    ];

    $fieldset = [
      '#type'        => 'details',
      '#title'       => t('Memory Budget'),
      '#open'        => FALSE,
      '#description' => t(
        'Controls how aggressively the PHP indexer uses memory. The streaming pipeline keeps peak RAM bounded regardless of corpus size; this setting trades RAM for fewer round-trips and larger buffers.'
      ),
    ];

    $fieldset['memory_budget_profile'] = [
      '#type'          => 'select',
      '#title'         => t('Memory budget profile'),
      '#options'       => [
        'conservative' => t('Conservative — ≤ 96 MB peak (default)'),
        'balanced'     => t('Balanced — ~384 MB'),
        'aggressive'   => t('Aggressive — ~1 GB'),
      ],
      '#default_value' => $config->profile(),
      '#description'   => t(
        '<strong>Detected:</strong> @reason Can be overridden per-run with <code>--memory-budget</code> on drush scolta:build.',
        ['@reason' => $suggestion['reason']]
      ),
    ];

    $fieldset['memory_budget_profile_descriptions'] = [
      '#type'   => 'item',
      '#markup' => '<ul>' . implode('', array_map(
        static fn(string $p, $d): string => "<li><strong>$p</strong>: $d</li>",
        array_keys($descriptions),
        $descriptions,
      )) . '</ul>',
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
   */
  public static function extract(array $values): MemoryBudgetConfig {
    return MemoryBudgetConfig::load([
      'profile'      => $values['memory_budget_profile'] ?? 'conservative',
      'custom_bytes' => NULL,
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
