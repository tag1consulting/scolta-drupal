# Claude Rules for scolta-drupal

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Major versions are synchronized across all Scolta packages. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

### Rules

- **NEVER** reimplement scoring, HTML cleaning, or prompt logic. These belong in scolta-core via scolta-php.
- **NEVER** change `composer.json` to depend on `tag1/scolta-core`. Depend on `tag1/scolta-php`.
- Dependency constraint MUST be a caret constraint: `"tag1/scolta-php": "^X.Y"` (or `@dev` for development).
- If scolta-php adds a new method you need, bump the minimum constraint (e.g., `^1.5`).
- All public methods SHOULD have `@since` and `@stability` annotations.

### Drupal conventions

- Use Drupal coding standards (no `declare(strict_types=1)` in .module files, but use it in classes).
- Services are defined in `scolta.services.yml` — service argument count MUST match constructor parameter count.
- Config schema (`config/schema/`) MUST match install defaults (`config/install/`).
- Route controllers MUST exist and have the referenced methods.

## Testing

- Run: `./vendor/bin/phpunit`
- Tests run without a Drupal bootstrap — they use YAML parsing and reflection.
- WASM-dependent tests are covered by scolta-php, not this package.
