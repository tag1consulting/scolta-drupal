# Scolta AI Search for Drupal

[![CI](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-drupal/actions/workflows/ci.yml)

AI-powered search for Drupal — semantic relevance scoring, AI summaries, and natural language query expansion on top of Drupal's Search API.

Built and maintained by [Tag1 Consulting](https://tag1.com/) — technology leadership since 2007. [Tag1 offers AI strategy, architecture, and implementation consulting](https://tag1.com/services/) for organizations evaluating or deploying AI-powered products.

## Status

Scolta 1.0 — the API documented here is stable. Breaking changes follow semantic versioning: no removal or signature change without a major version bump and a deprecation cycle. File bugs at the [issue tracker](https://github.com/tag1consulting/scolta-drupal/issues).

## What Is Scolta?

Scolta is a scoring, ranking, and AI layer built on [Pagefind](https://pagefind.app/). Pagefind is the search engine: it builds a static inverted index at publish time, runs a browser-side WASM search engine, produces word-position data, and generates highlighted excerpts. Scolta takes Pagefind's result set and re-ranks it with configurable boosts — title match weight, content match weight, recency decay curves, and phrase-proximity multipliers. No search server required. Queries resolve in the visitor's browser against the pre-built static index.

This Drupal module is one of three CMS adapters (alongside [scolta-wp](https://github.com/tag1consulting/scolta-wp) and [scolta-laravel](https://github.com/tag1consulting/scolta-laravel)). It integrates with Drupal's Search API, provides Drush commands, an admin settings form, a search block, and API endpoints for AI query expansion and summarization.

The LLM tier — query expansion, result summarization, follow-up questions — is optional. When enabled, it sends the query text and selected result excerpts to a configured LLM provider. The base search tier shares nothing with any third party; it runs entirely in the visitor's browser.

## Requirements

- Drupal 10.3+ or Drupal 11
- PHP 8.1+
- `drupal/search_api` ^1.0

## Installation

```bash
composer require tag1/scolta-drupal
drush en scolta
```

### Search API setup

Scolta uses Drupal's Search API as its indexing framework. After enabling the module:

1. Go to *Administration > Configuration > Search and Metadata > Search API* (`/admin/config/search/search-api`)
2. Add a new **Server** and select **Scolta Pagefind** as the backend
3. Add a new **Index**, select the content types you want to search, and assign it to the Scolta server
4. Build the search index:

```bash
drush scolta:build
```

5. Place the **Scolta Search** block on your site via *Structure > Block Layout*

## Drush Commands

| Command | Description |
|---|---|
| `drush scolta:export` (`se`) | Export content as HTML files for Pagefind indexing |
| `drush scolta:build` (`sb`) | Build the search index (export + index + deploy) |
| `drush scolta:build --force` | Force rebuild even if content has not changed |
| `drush scolta:build --resume` | Resume a previously interrupted build |
| `drush scolta:build --restart` | Discard interrupted state and start fresh |
| `drush scolta:build --indexer=php` | Use a specific indexer mode (`php`, `binary`, or `auto`) |
| `drush scolta:build --memory-budget=256M` | Set memory budget (profile name or byte value) |
| `drush scolta:build --chunk-size=N` | Process N pages per chunk (overrides config) |
| `drush scolta:finalize` (`sf`) | Merge chunks into the final search index |
| `drush scolta:rebuild-index` (`sri`) | Rebuild index from existing exported HTML files |
| `drush scolta:clear-cache` (`scc`) | Clear expansion and summary caches |
| `drush scolta:check-setup` (`scs`) | Verify dependencies and configuration |
| `drush scolta:status` (`sst`) | Show current index, indexer, and AI provider status |
| `drush scolta:download-pagefind` (`sdp`) | Download the Pagefind binary for the current platform |

## Large Corpora and Shared Hosting

On sites with thousands of pages or on shared-hosting environments, builds can be interrupted by PHP timeouts, SSH disconnects, or memory limits.

**Use `drush scolta:build` for initial and full index builds.** Do not use `drush search-api:index` — Search API's batch pipeline can exhaust shared-host resource limits on large corpora.

### Surviving SSH disconnects

Run the build inside a persistent terminal session so it survives disconnects:

```bash
# nohup — simplest, output goes to nohup.out
nohup drush scolta:build --indexer=php &

# screen
screen -S scolta
drush scolta:build --indexer=php
# Detach: Ctrl+A, D  — reconnect: screen -r scolta

# tmux
tmux new-session -s scolta
drush scolta:build --indexer=php
# Detach: Ctrl+B, D  — reconnect: tmux attach -t scolta
```

### Resuming an interrupted build

If the build is interrupted (timeout, disconnect, memory limit), resume from where it stopped:

```bash
drush scolta:build --resume
```

Use `--restart` to discard the interrupted state and start the build fresh:

```bash
drush scolta:build --restart
```

### Deferred finalization on very large corpora

On very large sites, `drush scolta:build` may defer the final merge step to stay within memory limits. Run finalization separately:

```bash
drush scolta:finalize
```

## AI Provider Configuration

Scolta supports three AI provider paths. The right path depends on where you are in your deployment:

### Amazee.ai (zero-config default)

On [Amazee.io](https://www.amazee.io/) hosting, Scolta auto-provisions a free Amazee.ai trial at install time — no API key needed, and search works immediately out of the box. This is the fastest path to a working AI-powered search, ideal for getting started or evaluating Scolta.

If you later want more control over your AI provider, you can switch to one of the options below at any time. Amazee.ai is the default, not a lock.

### Drupal AI module (recommended for production)

For sites that want full control over their AI provider, Scolta integrates with the [Drupal AI module](https://www.drupal.org/project/ai) — the same provider abstraction used by CKEditor AI, AI Automators, and other AI Initiative modules.

When "Drupal AI module" is selected in Scolta's settings, Scolta routes all AI requests through the Drupal AI module's configured default provider. This gives you:

- **48+ supported providers** — Anthropic, OpenAI, Google Gemini, AWS Bedrock, Mistral, Ollama, Groq, and more
- **Key module integration** — API keys stored securely using Drupal's Key module, out of code and config
- **Rate limiting and token tracking** — managed by the Drupal AI module site-wide
- **Hooks** — `hook_alter_ai_message`, `hook_alter_ai_response`, and others fire for Scolta requests
- **Centralized provider management** — change your AI provider site-wide without touching Scolta config

**Setup:**

```bash
composer require drupal/ai
drush en ai
```

Then install a provider module for your preferred AI service, for example:

```bash
composer require drupal/ai_provider_anthropic
drush en ai_provider_anthropic
```

Configure the provider at *Administration > Configuration > AI > AI Providers*, using a Key entity for secure API key storage.

Finally, select **Drupal AI module** in Scolta settings at *Administration > Configuration > Search and Metadata > Scolta AI Search > AI Configuration > AI Provider*.

Scolta will use the Drupal AI module's configured default provider and model. The model, API key, expansion model, and base URL fields in Scolta's settings are hidden when this provider is selected — the Drupal AI module manages all of these.

**Upgrading from Amazee.ai:** If your site auto-provisioned with Amazee.ai and you want to switch to the Drupal AI module, install `drupal/ai`, configure a provider there, then change the dropdown in Scolta settings. Amazee.ai credentials remain stored (so you can switch back), but Scolta will route through the Drupal AI module once you select it.

### Built-in providers (standalone)

For simple setups or sites without the Drupal AI module, Scolta can make direct HTTP calls to Anthropic or OpenAI with an API key configured via environment variable or `settings.php`:

```bash
# Environment variable (preferred)
export SCOLTA_API_KEY="sk-ant-..."

# Or in settings.php
$settings['scolta.api_key'] = 'sk-ant-...';
```

Select **Anthropic (Claude)** or **OpenAI** in Scolta's AI provider settings to use this path.

## Tuning search breadth

**Getting fewer results than you expect on a recipe, product, or catalog site?** Go to *Administration > Configuration > Search and Metadata > Scolta AI Search*, open the **Site Type** section, choose the **Recipe & Content Catalog** preset, save, and rebuild the index (`drush scolta:build`).

Scolta defaults to a conservative search breadth so generic words ("easy", "quick", "best") don't flood your results. On a recipe or catalog site, the useful domain words you actually want to match — ingredients, techniques, product attributes — are common enough that the default can hide them. The **Recipe & Content Catalog** preset widens the breadth (and tunes a handful of other ranking settings) so those searches return the fuller set of matches you'd expect.

Pick the **Site Type** that matches your site and Scolta sets sensible defaults for you:

| Your site | Preset |
| --------- | ------ |
| Recipes, product or content catalogs | Recipe & Content Catalog |
| Docs, knowledge bases, encyclopedias, references | Documentation & Reference |
| Online stores | E-commerce & Product Store |
| Blogs and editorial sites | Blog & Editorial |
| News sites | Start from Scratch, then tune recency |

You rarely need to touch individual numbers — the preset is the recommended path, and any value you change by hand in the **Scoring** section still overrides the preset. The one advanced knob worth knowing is **Search breadth** (`expand_subword_max_frequency`): higher returns more results but can pull in loosely-related matches; lower keeps results tight. The Recipe & Content Catalog preset already raises it from `0.05` to `0.10`.

One further advanced knob controls how a multi-term query expansion feeds the AI summary. **Expansion combine mode** (`expansion_combine_mode`) is either `relevance_union` (historical behavior) or `round_robin`, which deals the top candidates from each expansion sub-query so the summarizer sees breadth across distinct sub-topics. It is preset-defaulted — the Recipe & Content Catalog, Blog & Editorial, and E-commerce presets default it to `round_robin`; the others use `relevance_union` — and any value you set by hand overrides the preset. The visible results list stays relevance-sorted in both modes.

For the evidence behind each preset — the scoring sweeps and the per-parameter data — see [scolta-php's `docs/TUNING.md`](https://github.com/tag1consulting/scolta-php/blob/main/docs/TUNING.md).

## Troubleshooting

### "No search results"

If searches return no results, the search index may not exist yet. Build it with:

```bash
drush scolta:build
```

If you have previously run `drush search-api:index`, that is not sufficient — Scolta requires its own build step to generate the pagefind index.

### Permissions

Scolta defines a **Use Scolta AI features** permission (`use scolta ai`) that gates the AI API endpoints. This permission is granted to the **anonymous** and **authenticated** roles automatically at module install, so search visitors receive AI overviews out of the box with no admin action required.

On a site installed before that grant existed, the permission is backfilled by a database update: run `drush updatedb` (or visit `/update.php`) after updating the module. Until that update runs, anonymous requests to the AI endpoints return 403. The update grants the permission only where it is missing, and does not run again afterwards, so a later decision to revoke it stands.

To restrict AI features to specific roles (e.g. authenticated users only), revoke the permission from the anonymous role at *Administration > People > Permissions*.

The health endpoint (`GET /api/scolta/v1/health`) is reachable without any permission so uptime monitors always work, but callers without **Administer Scolta** (`administer scolta`) receive only `{"status": "ok"|"degraded"}`. The full diagnostic payload (AI provider, index integrity, fragment counts) requires `administer scolta`.

### Configuration

Visit *Administration > Configuration > Search and Metadata > Scolta AI Search* to configure the AI provider, API key, model, and indexing options.

#### AI endpoint rate limiting

The AI API endpoints (`/api/scolta/v1/expand-query`, `/api/scolta/v1/summarize`, `/api/scolta/v1/followup`) make cost-bearing LLM calls and are reachable by anonymous visitors by default. The **Rate Limiting** section of the settings form configures per-IP and site-wide flood thresholds (defaults: 60 requests/minute per IP, 1000 requests/minute site-wide); requests beyond a threshold are rejected with HTTP 429 before any AI work happens. Set a limit to 0 to disable that layer.

#### Auto-rebuild debounce

When auto-rebuild is enabled, content saves enqueue an index rebuild that cron processes. The rebuild is debounced by the backend's **Rebuild delay** setting (Search API server > backend configuration, default 300 seconds): the queue waits until that many seconds have passed since the *last* content change, so a burst of edits produces one build instead of many.

#### Drush config:set and config path precedence

Scolta's config stores scoring and display values in nested namespaces (`scoring.*`, `display.*`). When using `drush config:set`, use the full nested path:

```bash
# Correct — nested path used by the admin UI
drush config:set scolta.settings display.max_pagefind_results 10
drush config:set scolta.settings scoring.title_match_boost 2.0

# Also accepted — top-level keys take precedence over nested values
drush config:set scolta.settings max_pagefind_results 10
```

Top-level keys (without a namespace prefix) override nested values of the same name, so both forms work. The nested path is canonical and matches the admin UI; the top-level form is convenient for one-off overrides.

## External Services

Scolta connects to external services under specific conditions. No data is sent automatically — all connections are triggered by admin/developer action or explicit configuration.

### GitHub API (api.github.com)

**When:** An administrator runs `drush scolta:download-pagefind` to download the Pagefind binary.
**What is sent:** A standard HTTPS GET request to `https://api.github.com/repos/CloudCannon/pagefind/releases/latest`. No personally identifiable information is transmitted beyond standard HTTP request headers (IP address, user agent).
**Service:** GitHub, operated by GitHub, Inc. (a subsidiary of Microsoft Corporation).
**Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
**Privacy Statement:** https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

### Pagefind Binary (GitHub Releases / Pagefind)

**When:** `drush scolta:download-pagefind` downloads the Pagefind binary from GitHub Releases after querying the GitHub API above.
**What is sent:** A standard HTTPS GET request to download the release archive. No personally identifiable information is transmitted beyond standard HTTP request headers.
**Service:** Pagefind is an open-source project (MIT license) maintained by the Pagefind project.
**Pagefind:** https://pagefind.app/
**CloudCannon:** https://cloudcannon.com/
**Pagefind License:** https://github.com/Pagefind/pagefind/blob/main/LICENSE

### Amazee.ai

**When:** On [Amazee.io](https://www.amazee.io/) hosting, Scolta automatically provisions a free Amazee.ai trial on first activation. Once provisioned, every search query made by site visitors is sent to the Amazee.ai API endpoint while AI features are active.
**What is sent:** The user's search query text, and selected page content excerpts (for result summarization).
**Service:** Amazee.ai, operated by Amazee Group AG.
**Amazee.ai:** https://amazee.ai/
**Terms of Service:** https://amazee.ai/terms/
**Privacy Policy:** https://amazee.ai/privacy/

### AI Provider APIs (Drupal AI module or built-in)

**When:** A visitor performs a search and AI features are enabled. Which provider receives the data depends on the Scolta AI provider setting.
**What is sent:** The user's search query text and selected page content excerpts (for result summarization) are sent to the configured provider's API endpoint.
**Providers:**

- **Drupal AI module** — Scolta routes requests through the [Drupal AI module](https://www.drupal.org/project/ai), which supports 48+ providers. Review the terms and privacy policy of the provider configured in the Drupal AI module.
- **Anthropic (Claude)** — processes search queries and page excerpts directly.
  Terms of Service: https://www.anthropic.com/legal/consumer-terms
  Privacy Policy: https://www.anthropic.com/legal/privacy
- **OpenAI** — processes search queries and page excerpts directly.
  Terms of Use: https://openai.com/policies/terms-of-use
  Privacy Policy: https://openai.com/policies/privacy-policy
- **OpenAI-compatible endpoints** (including self-hosted Ollama and other providers) — any endpoint configured by the site administrator that speaks the OpenAI API protocol. Review the terms and privacy policy of your chosen provider.

No AI API calls are made unless a provider is configured and AI features are enabled in Scolta settings.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

## About Tag1 Consulting

Scolta is designed, built, and maintained by [Tag1 Consulting](https://tag1.com/). Tag1 has been delivering technology leadership since 2007 and is one of the leading open-source consulting firms in the world.

Tag1 offers [AI strategy, architecture, and implementation consulting](https://tag1.com/services/) — from evaluating whether AI search is right for your organization, to production deployment and ongoing tuning. If you need help integrating Scolta, customizing scoring for your content model, or connecting it to your AI provider of choice, [get in touch](https://tag1.com/contact/).

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
