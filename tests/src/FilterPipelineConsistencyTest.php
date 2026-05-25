<?php

declare(strict_types=1);

namespace Drupal\scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Validates that filter and sort config sections are internally consistent.
 *
 * Catches config drift where, e.g., a field is added to filter_fields but
 * not to filter_field_descriptions, or a sortable field has no field_mapping.
 */
class FilterPipelineConsistencyTest extends TestCase {

  /**
   * Build a config array with all filter/sort sections populated.
   *
   * Tests override individual keys to inject inconsistencies.
   */
  private function baseConfig(): array {
    return [
      'filter_fields' => ['topics', 'era', 'region'],
      'filter_field_descriptions' => [
        'topics' => 'Subject area',
        'era' => 'Time period',
        'region' => 'Geographic region',
      ],
      'sortable_fields' => ['word_count', 'reference_count'],
      'sortable_field_descriptions' => [
        'word_count' => 'Word count',
        'reference_count' => 'Reference count',
      ],
      'field_mappings' => [
        'sortable' => [
          'field_word_count' => 'word_count',
          'field_reference_count' => 'reference_count',
        ],
        'filters' => [
          'field_era' => 'era',
          'field_region' => 'region',
        ],
      ],
    ];
  }

  private function assertFilterPipelineConsistent(array $config): array {
    $errors = [];

    $filterFields = $config['filter_fields'] ?? [];
    $filterDescs = $config['filter_field_descriptions'] ?? [];
    $sortableFields = $config['sortable_fields'] ?? [];
    $sortableDescs = $config['sortable_field_descriptions'] ?? [];
    $mappingSortable = $config['field_mappings']['sortable'] ?? [];
    $mappingFilters = $config['field_mappings']['filters'] ?? [];

    // Rule 1: Every filter_field_descriptions key must be in filter_fields.
    foreach (array_keys($filterDescs) as $field) {
      if (!in_array($field, $filterFields, true)) {
        $errors[] = "filter_field_descriptions['{$field}'] has no entry in filter_fields";
      }
    }

    // Rule 2: Every sortable_field_descriptions key must be in sortable_fields.
    foreach (array_keys($sortableDescs) as $field) {
      if (!in_array($field, $sortableFields, true)) {
        $errors[] = "sortable_field_descriptions['{$field}'] has no entry in sortable_fields";
      }
    }

    // Rule 3: Every field_mappings.filters value must be in filter_fields.
    foreach ($mappingFilters as $drupalField => $scoltaField) {
      if (!in_array($scoltaField, $filterFields, true)) {
        $errors[] = "field_mappings.filters['{$drupalField}'] maps to '{$scoltaField}' which is not in filter_fields";
      }
    }

    // Rule 4: Every field_mappings.sortable value must be in sortable_fields.
    foreach ($mappingSortable as $drupalField => $scoltaField) {
      if (!in_array($scoltaField, $sortableFields, true)) {
        $errors[] = "field_mappings.sortable['{$drupalField}'] maps to '{$scoltaField}' which is not in sortable_fields";
      }
    }

    // Rule 5: Every sortable_field must have a field_mapping.
    $mappedSortable = array_values($mappingSortable);
    foreach ($sortableFields as $field) {
      if (!in_array($field, $mappedSortable, true)) {
        $errors[] = "sortable_fields['{$field}'] has no field_mappings.sortable entry";
      }
    }

    return $errors;
  }

  // -------------------------------------------------------------------
  // Passing cases
  // -------------------------------------------------------------------

  public function testConsistentConfigProducesNoErrors(): void {
    $errors = $this->assertFilterPipelineConsistent($this->baseConfig());
    $this->assertEmpty($errors, implode("\n", $errors));
  }

  public function testEmptyConfigProducesNoErrors(): void {
    $errors = $this->assertFilterPipelineConsistent([]);
    $this->assertEmpty($errors);
  }

  // -------------------------------------------------------------------
  // Drift detection
  // -------------------------------------------------------------------

  public function testDetectsOrphanedFilterDescription(): void {
    $config = $this->baseConfig();
    $config['filter_field_descriptions']['nonexistent'] = 'A field that is not in filter_fields';
    $errors = $this->assertFilterPipelineConsistent($config);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('nonexistent', $errors[0]);
  }

  public function testDetectsOrphanedSortableDescription(): void {
    $config = $this->baseConfig();
    $config['sortable_field_descriptions']['ghost_field'] = 'Not in sortable_fields';
    $errors = $this->assertFilterPipelineConsistent($config);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('ghost_field', $errors[0]);
  }

  public function testDetectsFilterMappingToUnknownField(): void {
    $config = $this->baseConfig();
    $config['field_mappings']['filters']['field_foo'] = 'nonexistent_filter';
    $errors = $this->assertFilterPipelineConsistent($config);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('nonexistent_filter', $errors[0]);
  }

  public function testDetectsSortableMappingToUnknownField(): void {
    $config = $this->baseConfig();
    $config['field_mappings']['sortable']['field_foo'] = 'nonexistent_sort';
    $errors = $this->assertFilterPipelineConsistent($config);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('nonexistent_sort', $errors[0]);
  }

  public function testDetectsSortableFieldWithoutMapping(): void {
    $config = $this->baseConfig();
    $config['sortable_fields'][] = 'unmapped_field';
    $errors = $this->assertFilterPipelineConsistent($config);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('unmapped_field', $errors[0]);
  }

  // -------------------------------------------------------------------
  // Wikipedia demo config (regression anchor)
  // -------------------------------------------------------------------

  public function testWikipediaConfigIsConsistent(): void {
    $config = [
      'filter_fields' => ['topics', 'era', 'region'],
      'filter_field_descriptions' => [
        'topics' => 'Subject area or domain. Valid values: Arts, Biography, Engineering, Geography, History, Mathematics, Medicine, Military, Nature, Philosophy, Religion, Science, Society, Sports, Technology',
        'era' => 'Historical period. Values: "Ancient (before 500 CE)", "Medieval (500-1500)", "Early Modern (1500-1800)", "Modern (1800-1945)", "Contemporary (1945-present)", "Timeless"',
        'region' => 'Geographic region. Values: Africa, Americas, Antarctica, Asia, Europe, "Global / Multiple Regions", "Not Geographic", Oceania, Space',
      ],
      'sortable_fields' => ['word_count', 'reference_count'],
      'sortable_field_descriptions' => [
        'word_count' => 'Total number of words in the Wikipedia article (typically 2,000–15,000)',
        'reference_count' => 'Number of citations and references in the Wikipedia article',
      ],
      'field_mappings' => [
        'sortable' => [
          'field_word_count' => 'word_count',
          'field_reference_count' => 'reference_count',
        ],
        'filters' => [
          'field_era' => 'era',
          'field_region' => 'region',
        ],
      ],
    ];

    $errors = $this->assertFilterPipelineConsistent($config);
    $this->assertEmpty($errors, "Wikipedia config is inconsistent:\n" . implode("\n", $errors));
  }

}
