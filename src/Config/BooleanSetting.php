<?php

declare(strict_types=1);

namespace Drupal\scolta\Config;

/**
 * Reads a boolean setting the way the operator meant it.
 *
 * `drush config:set scolta.settings ai_expand_query false` stores the string
 * "false" unless `--input-format=yaml` is passed, and PHP casts every
 * non-empty string but "0" to TRUE, so a plain `(bool)` read turned the
 * setting on. Every boolean read of scolta.settings goes through here
 * instead: "false", "0", "off", "no" and "" read as FALSE, "true", "1", "on"
 * and "yes" as TRUE, and anything else falls back to PHP truthiness.
 *
 * @since 2.0.0
 * @stability experimental
 */
final class BooleanSetting {

  /**
   * Coerce a stored setting value to a boolean.
   *
   * @param mixed $value
   *   The value as read from config.
   * @param bool $default
   *   Returned when the value is NULL (the key is not set).
   *
   * @return bool
   *   The value as a boolean.
   *
   * @since 2.0.0
   * @stability experimental
   */
  public static function coerce(mixed $value, bool $default = FALSE): bool {
    if ($value === NULL) {
      return $default;
    }
    if (is_bool($value)) {
      return $value;
    }
    if (is_string($value) || is_int($value) || is_float($value)) {
      return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
    return (bool) $value;
  }

}
