<?php

declare(strict_types=1);

namespace Drupal\scolta\Config;

use Drupal\Core\TypedData\Plugin\DataType\BooleanData;

/**
 * The typed-data class behind the `scolta.boolean` config schema type.
 *
 * Config::save() casts every value to its schema type, and core's
 * BooleanData casts with (bool), so the string "false" that a plain
 * `drush config:set scolta.settings ai_expand_query false` passes was stored
 * as TRUE before anything in this module could read it. This casts the way
 * the operator meant it; see BooleanSetting::coerce().
 *
 * Deliberately outside the Plugin\DataType namespace: it is referenced by
 * class from config/schema/scolta.schema.yml, not discovered as a plugin.
 *
 * @since 2.0.0
 * @stability experimental
 */
class LenientBooleanData extends BooleanData {

  /**
   * {@inheritdoc}
   */
  public function getCastedValue() {
    return BooleanSetting::coerce($this->value);
  }

}
