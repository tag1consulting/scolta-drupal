# Scolta for Drupal

Drupal module providing AI-powered search with Pagefind. Integrates with Search API as a backend and delivers client-side search with optional AI query expansion, summarization, and follow-up conversations.

## How It Works

1. **Indexing** -- Search API indexes content through the Scolta backend, which exports each item as an HTML file with Pagefind data attributes, then runs the Pagefind CLI to build a static search index.
2. **Search** -- Entirely client-side. The browser loads `pagefind.js`, searches the static index, and scolta.js handles scoring, filtering, and result rendering.
3. **AI features** -- Optional. When an API key is configured, the module provides server-side endpoints for query expansion, result summarization, and follow-up conversations powered by Anthropic or OpenAI.

## Requirements

- Drupal 10.3+ or 11
- Search API module (`drupal/search_api`)
- PHP 8.1+
- [Extism](https://extism.org) shared library (for WASM scoring)
- PHP FFI enabled (`ffi.enable=true`)
- Pagefind CLI (`npm install -g pagefind`)

## Installation

```bash
composer require tag1/scolta-drupal tag1/scolta-php
drush en scolta
```

### Install Extism (if not already present)

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
```

## Setup

### 1. Create a Search API Server

Go to **Admin > Configuration > Search > Search API** (`/admin/config/search/search-api`) and add a new server:

- **Backend**: Scolta (Pagefind)
- **Build directory**: `private://scolta-build`
- **Output directory**: `public://scolta-pagefind`

### 2. Create a Search API Index

Create an index on the Scolta server. Add the content types and fields you want indexed.

### 3. Index Content

```bash
drush search-api:index
```

Or let Search API cron handle it.

### 4. Place the Search Block

Go to **Admin > Structure > Block Layout** and place the **Scolta Search** block in your desired region.

### 5. Configure AI (Optional)

Set the API key via environment variable (recommended):

```bash
export SCOLTA_API_KEY=sk-ant-...
```

Or in `settings.php`:

```php
$settings['scolta.api_key'] = 'sk-ant-...';
```

Then configure AI settings at **Admin > Configuration > Search > Scolta** (`/admin/config/search/scolta`).

## Verify Your Setup

After installation, run the setup check to verify all prerequisites:

```bash
drush scolta:check-setup
```

This verifies PHP version, FFI extension, Extism library, WASM binary, Pagefind binary, AI provider configuration, and cache backend. Fix any items marked as failed before proceeding.

## Configuration

The settings form at `/admin/config/search/scolta` provides:

- **AI Configuration** -- Provider, model, feature toggles, follow-up limits
- **Content** -- Site name and description for AI prompt context
- **Scoring** -- Title/content match boosts, recency decay, expanded term weights
- **Display** -- Excerpt length, results per page, AI summary parameters
- **Cache** -- TTL for AI response caching
- **Custom Prompts** -- Override the built-in expand, summarize, and follow-up prompts
- **Status** -- AI provider, Pagefind binary, index status, Search API index

## API Endpoints

| Method | Path | Permission | Description |
|--------|------|------------|-------------|
| POST | `/api/scolta/v1/expand-query` | `use scolta ai` | Expand a search query into related terms |
| POST | `/api/scolta/v1/summarize` | `use scolta ai` | Summarize search results |
| POST | `/api/scolta/v1/followup` | `use scolta ai` | Continue a search conversation |

## Drush Commands

```bash
drush scolta:build                    # Export content and build Pagefind index
drush scolta:build --skip-pagefind    # Export HTML without rebuilding index
drush scolta:export                   # Export content to HTML only
drush scolta:rebuild-index            # Rebuild Pagefind index from existing HTML
drush scolta:status                   # Show tracker, content, index, and AI status
drush scolta:clear-cache              # Clear Scolta AI response caches
drush scolta:download-pagefind        # Download the Pagefind binary
drush scolta:check-setup              # Verify PHP, Extism, Pagefind, and configuration
```

## Permissions

- **Administer Scolta** -- Access the settings form
- **Use Scolta AI features** -- Access the AI endpoints (grant to anonymous for public search)

## Module Structure

```
src/
  Commands/ScoltaCommands.php         # Drush commands
  Controller/ExpandQueryController.php    # AI query expansion endpoint
  Controller/FollowUpController.php       # AI follow-up endpoint
  Controller/SummarizeController.php      # AI summarization endpoint
  Form/ScoltaSettingsForm.php             # Admin settings form
  Plugin/Block/ScoltaSearchBlock.php      # Search UI block
  Plugin/search_api/backend/ScoltaBackend.php  # Search API backend
  Service/PagefindBuilder.php             # Pagefind CLI orchestration
  Service/PagefindExporter.php            # Content-to-HTML export
  Service/ScoltaAiService.php             # AI service wrapper
js/
  scolta.js -> ../../scolta-php/assets/js/scolta.js     # Shared search UI
  scolta-drupal-bridge.js                                # Drupal behavior bridge
css/
  scolta.css -> ../../scolta-php/assets/css/scolta.css  # Shared search styles
config/
  install/scolta.settings.yml       # Default configuration
  schema/scolta.schema.yml          # Config schema
```

## Testing

```bash
# Unit/structural tests (no Drupal bootstrap required)
cd packages/scolta-drupal
./vendor/bin/phpunit

# Functional tests (requires DDEV with Drupal installed)
cd test-drupal-11
ddev exec php vendor/bin/phpunit --testsuite=scolta-functional
```

## Troubleshooting

### "FFI not enabled" or WASM load failure

Scolta requires PHP FFI and the Extism shared library:

```bash
# Check FFI
php -r "echo extension_loaded('ffi') ? 'OK' : 'MISSING';"

# Check Extism
php -r "echo class_exists('\Extism\Plugin') ? 'OK' : 'MISSING';"

# Linux: check library path
ldconfig -p | grep extism

# macOS: check library
ls /usr/local/lib/libextism.dylib
```

Install Extism if missing:

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
```

### "Pagefind binary not found"

```bash
drush scolta:download-pagefind
# or
npm install -g pagefind
```

### "AI features not working"

1. Verify API key: `drush scolta:check-setup`
2. Clear stale cache: `drush scolta:clear-cache`
3. Also clear Drupal cache: `drush cr`

### "No search results"

1. Check index status: `drush scolta:status`
2. Run a full build: `drush search-api:index && drush scolta:build`
3. Verify the Pagefind output directory is web-accessible

## License

GPL-2.0-or-later
