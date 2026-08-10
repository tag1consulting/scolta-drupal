<?php

declare(strict_types=1);

namespace Drupal\scolta\Form;

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

    // Built here rather than concatenated inside the array: coder 8 and coder 9
    // disagree on how a continuation line inside an array is indented, and this
    // repo is linted by both (CI pins coder 9; `ddev phpcs` gets coder 8, which
    // is what drupal/core-dev allows).
    $profileDescription = t('Memory Scolta budgets for its own work during a build. Total process memory is this plus the Drupal baseline (~130 MB) and I/O overhead, so allow noticeably more than the figure shown. A profile larger than the process @limit_flag is reduced to fit rather than allowed to fail mid-build.', ['@limit_flag' => 'memory_limit']);
    $profileDescription = $profileDescription . ' ' . $limitDescription;

    $fieldset['memory_budget_profile'] = [
      '#type'          => 'select',
      '#title'         => t('Memory budget profile'),
      // These numbers are Scolta's own allocation during a build, not the
      // peak RSS of the process. Drupal's baseline (~130 MB) and I/O overhead
      // sit on top, so "≤ 96 MB peak" was a guarantee the code never made and
      // measured builds never met — real peaks were 137 MB on a 1.4k-page
      // corpus. Naming what the number actually budgets costs nothing and
      // stops the form promising a ceiling.
      '#options'       => [
        'conservative' => t('Conservative — ~96 MB for indexing (default)'),
        'balanced'     => t('Balanced — ~384 MB for indexing'),
        'aggressive'   => t('Aggressive — ~1 GB for indexing'),
      ],
      '#default_value' => $config->profile(),
      '#description'   => $profileDescription,
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

}
