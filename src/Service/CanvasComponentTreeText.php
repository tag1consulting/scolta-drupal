<?php

declare(strict_types=1);

namespace Drupal\scolta\Service;

use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * The text a visitor reads on a Canvas component tree, in page order.
 *
 * A Canvas page (and any entity with a `component_tree` field) keeps its
 * content in component inputs rather than in a text field, so the body field
 * lookup in ScoltaContentGatherer found nothing and every such page was
 * skipped as empty. This walks the tree the way it renders: root components in
 * order, each component's own text before the components placed in its slots,
 * slots in the order the component declares them.
 *
 * A prop counts as text when it is free prose: a string with no `enum` (a pick
 * list such as a CSS class or a heading level) and no `format` (a URL, an
 * email address, a date). Where Canvas records its own judgement of the prop's
 * shape (`derived_schema_metadata.string_shape`, which its translation UI
 * keys on), that is used instead, so props Canvas does not treat as prose,
 * such as a component's internal ID, stay out. Images, videos and other object
 * props are never strings and never included. Rich text is reduced to plain
 * text.
 *
 * Only this class names Canvas classes, and ScoltaContentGatherer reaches it
 * only when the canvas module is enabled, so a site without Canvas never loads
 * it.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class CanvasComponentTreeText {

  /**
   * Matches a UUID, which no visitor reads as text.
   */
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

  /**
   * The reader-visible text of a component tree, one paragraph per input.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $tree
   *   A `component_tree` field item list.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $host
   *   The entity (translation) the tree belongs to, for inputs that read from
   *   it.
   *
   * @return string
   *   The text, paragraphs separated by a blank line; empty when the tree has
   *   no text inputs.
   *
   * @since 2.0.0
   * @stability experimental
   */
  public static function extract(FieldItemListInterface $tree, FieldableEntityInterface $host): string {
    // Children by parent UUID and slot, in field order, which is the order a
    // slot renders its components in. Root components have neither.
    $children = [];
    foreach ($tree as $item) {
      if ($item instanceof ComponentTreeItem) {
        $children[$item->getParentUuid() ?? ''][$item->getSlot() ?? ''][] = $item;
      }
    }

    $paragraphs = [];
    foreach ($children[''][''] ?? [] as $item) {
      self::walk($item, $children, $host, $paragraphs);
    }

    return implode("\n\n", $paragraphs);
  }

  /**
   * Collect one component's text, then its slots' components, depth first.
   *
   * @param \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The component instance.
   * @param array<string, array<string, \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem[]>> $children
   *   Child instances by parent UUID and slot.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $host
   *   The host entity.
   * @param string[] $paragraphs
   *   The text collected so far.
   */
  private static function walk(ComponentTreeItem $item, array $children, FieldableEntityInterface $host, array &$paragraphs): void {
    $component = $item->getComponent();
    if ($component === NULL) {
      return;
    }

    foreach (self::componentText($item, $host) as $text) {
      $paragraphs[] = $text;
    }

    $slots = $children[$item->getUuid()] ?? [];
    // Declared slot order first; anything the definition no longer names
    // (a slot removed in a newer component version) after it.
    $order = array_unique([...array_keys($component->getSlotDefinitions()), ...array_keys($slots)]);
    foreach ($order as $slot) {
      foreach ($slots[$slot] ?? [] as $child) {
        self::walk($child, $children, $host, $paragraphs);
      }
    }
  }

  /**
   * The text inputs of one component instance, in the component's prop order.
   *
   * @return string[]
   *   Plain text values, empty ones dropped.
   */
  private static function componentText(ComponentTreeItem $item, FieldableEntityInterface $host): array {
    $component = $item->getComponent();
    $source = $component?->getComponentSource();
    // Block components and other sources without a props schema carry
    // configuration, not text.
    if (!$source instanceof JsonSchemaPropsComponentSourceBase) {
      return [];
    }

    $properties = $source->getMetadata()->schema['properties'] ?? [];
    $fieldDefinitions = $component->getSettings()['prop_field_definitions'] ?? [];

    try {
      $values = $source->getResolvedExplicitInput($item->getUuid(), $item, $host);
    }
    catch (\Throwable $e) {
      // An input that cannot be evaluated renders as an error for editors and
      // as nothing for visitors, so it contributes nothing here either. It is
      // logged, so a page indexed without some of its text can be traced.
      \Drupal::logger('scolta')->warning('Could not read the inputs of component @uuid (@component) on @type @id for the search index: @message', [
        '@uuid' => $item->getUuid(),
        '@component' => $item->getComponentId(),
        '@type' => $host->getEntityTypeId(),
        '@id' => $host->id(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }

    $texts = [];
    foreach ($properties as $name => $schema) {
      if (!is_array($schema) || !self::isText($schema, $fieldDefinitions[$name] ?? NULL)) {
        continue;
      }
      foreach (self::strings($values[$name] ?? NULL) as $value) {
        $text = trim(PlainTextOutput::renderFromHtml($value));
        if ($text !== '' && !preg_match(self::UUID_PATTERN, $text)) {
          $texts[] = $text;
        }
      }
    }

    return $texts;
  }

  /**
   * Whether a prop holds prose a visitor reads.
   *
   * @param array $schema
   *   The prop's JSON schema.
   * @param array|null $fieldDefinition
   *   The prop's entry in the Component's prop_field_definitions setting.
   */
  private static function isText(array $schema, ?array $fieldDefinition): bool {
    // A list of strings is text when its items are.
    if (in_array('array', (array) ($schema['type'] ?? []), TRUE) && is_array($schema['items'] ?? NULL)) {
      $schema = $schema['items'];
    }
    if (!in_array('string', (array) ($schema['type'] ?? []), TRUE) || isset($schema['enum']) || isset($schema['format'])) {
      return FALSE;
    }
    // Canvas versions that record the shape say which strings are prose; the
    // absence of a string_shape there is a deliberate "not prose".
    if (is_array($fieldDefinition) && array_key_exists('derived_schema_metadata', $fieldDefinition)) {
      $shape = $fieldDefinition['derived_schema_metadata']['string_shape'] ?? NULL;
      return is_array($shape) && !isset($shape['format']);
    }
    return TRUE;
  }

  /**
   * The string values in a resolved input: a string, or a list of them.
   *
   * @return string[]
   *   The strings found.
   */
  private static function strings(mixed $value): array {
    // Canvas resolves an input to an evaluation result: the value plus the
    // cacheability and assets it carries.
    if (is_object($value) && property_exists($value, 'value')) {
      $value = $value->value;
    }
    // Rich text resolves to processed markup.
    if ($value instanceof \Stringable) {
      $value = (string) $value;
    }
    if (is_string($value)) {
      return [$value];
    }
    if (is_array($value) && array_is_list($value)) {
      return array_merge(...array_map(self::strings(...), $value));
    }
    return [];
  }

}
