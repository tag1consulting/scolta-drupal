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

The browser assets (search JS/CSS and the WASM scorer) are not part of the
module's codebase: at install time — and again on every cache rebuild — they
are copied from the installed `tag1/scolta-php` package into
`public://scolta-assets`. Updating scolta-php therefore needs nothing beyond
the usual `composer update` followed by `drush cr`.

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
| `drush scolta:build --entity-ids=12,34` | Build an index of only these entities; IDs that cannot be loaded are logged and skipped. `--bundle` is ignored (PHP indexer only) |
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

### Selecting an AI provider is always manual

Scolta ships with **no AI provider selected**. The **AI Provider** field opens on *- Select a provider -*, and while nothing is selected AI features are off: search works exactly as it does now, no provider is assumed, and Anthropic in particular is not silently assumed. There is no default anywhere.

This is going-forward only. A site that already saved a provider keeps it and keeps working; nothing rewrites, clears or re-defaults an existing value, and there is no update hook that turns AI off on a working install. Only new installs start with nothing selected.

### Amazee.ai (managed gateway, opt-in)

Amazee.ai is a managed AI gateway you can enable without holding an API key of your own, and it comes with a no-cost evaluation. Enabling it takes two deliberate steps and never happens on its own:

1. Select **Amazee.ai (managed gateway)** as the **AI Provider** at *Administration > Configuration > Search and Metadata > Scolta AI Search > AI Configuration*, and save.
2. Follow the **Set up Amazee.ai** link from that screen and choose one of two actions. Nothing is connected until you do:
   - **Try the demo** — one click. No email, no account, no card. AI is on immediately and runs until the demo's included credit is used up. The demo is one-time per site; it stays available whether you are on a fresh install or coming from another provider, and once it has been used the page points you at the account path instead.
   - **Enter your Amazee credentials** — sign in with the email address on your amazee.ai account. Amazee emails a verification code, you pick a region, and your account's credentials are stored for you. If you do not have an account yet, this creates one. You never generate or paste an API key: this mirrors amazee.ai's own `ai_provider_amazeeio` module, which manages the keys for you, so there is deliberately no bring-your-own-key form.

When a connection stops being accepted — a demo whose credit ran out, or revoked credentials — AI degrades cleanly, `/health` reports it, and the Amazee.ai settings page shows a prompt pointing straight at **Enter your Amazee credentials**. Completing that flow restores AI without disconnecting first. Nothing is provisioned automatically at any point in that recovery.

The settings page states which of the two actions established the current connection, because that is recorded when it happens rather than inferred afterwards. A connection made before Scolta recorded it says only "Connected to Amazee.ai".

Installing the module configures no AI provider and stores no credentials, and no page request, cron run or activation will establish a connection for you. Selecting any other provider afterwards removes the stored connection, so the gateway can never serve traffic for a site that has moved to its own key. Selecting Amazee.ai again and repeating the connect flow re-establishes it.

**Model configuration on this path is separate.** Amazee.ai serves models through a LiteLLM gateway under its own names (`claude-4-5-sonnet`), which no provider's own API accepts. Scolta therefore keeps them apart: the gateway's names are resolved automatically into `amazee_model` and `amazee_expansion_model` and are read only while Amazee.ai credentials are in use, while the **AI Model** and **Expansion Model** fields on the settings form hold provider-native IDs (`claude-sonnet-4-5-20250929`, `gpt-4o`) and are what a direct provider key uses. The two gateway settings have no form field, because there is nothing to choose — they are whatever the gateway offers. Switching away from Amazee.ai therefore leaves your own model choice intact.

Sites installed before this separation existed may have an Amazee.ai gateway name sitting in **AI Model**. `drush updatedb` moves it across and restores the shipped default, reporting what it moved; if you use a direct Anthropic or OpenAI key, check the **AI Model** field afterwards.

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

**Moving from Amazee.ai:** If your site is on the Amazee.ai gateway and you want to switch to the Drupal AI module, install `drupal/ai`, configure a provider there, then change the dropdown in Scolta settings. Saving the change removes the stored Amazee.ai connection, so nothing of it is left to shadow the provider you selected; to go back, select Amazee.ai again and complete the connect flow.

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

## Search as you type

**On by default.** Typing in the search box opens a suggestions dropdown underneath it. Typing alone never runs a search: the full pipeline (AI query expansion, the merge, facet counts, the AI overview, follow-ups) still waits for Enter, the search button, or a selected suggestion. **No index rebuild is needed** — suggestions read the same fragments the result list does, so the feature works on an index built by any earlier release.

Everything is configurable at *Administration > Configuration > Search and Metadata > Scolta AI Search*, in the **Search as you type** section:

| Setting | Config key | Default | What it does |
| ------- | ---------- | ------- | ------------ |
| Enable search as you type | `sayt_enabled` | `true` | The off switch (see below) |
| Minimum characters | `sayt_min_chars` | `2` | How much must be typed before suggestions are fetched |
| Debounce | `sayt_debounce_ms` | `150` | Idle milliseconds after the last keystroke before a suggestion pass runs |
| Maximum suggestions | `sayt_max_suggestions` | `6` | Rows shown, and the cap on fragment loads per pass |
| Offer recent searches | `sayt_recent_searches` | `true` | Suggest the visitor their own recent searches |
| Maximum recent searches | `sayt_max_recent` | `3` | How many recent searches are shown |
| Enrich with AI query expansion | `sayt_expand` | `true` | Merge AI expansion matches into the dropdown |
| AI enrichment calls per minute | `sayt_expand_per_minute` | `6` | Per-visitor cap on those AI calls |
| AI enrichment delay | `sayt_expansion_delay_ms` | `500` | Idle milliseconds before the AI call fires |
| Selecting a suggestion | `sayt_suggestion_action` | `navigate` | `navigate` opens the result, `search` runs the full search for it |

**The off switch.** Set **Enable search as you type** to off (`drush config:set scolta.settings sayt_enabled 0`) and the search widget behaves exactly as it did before this feature existed: no dropdown, no combobox roles on the input, no browser storage touched on any path, and no suggestion searches. The results, ranking and AI overview are unaffected either way.

**Sites in Chinese, Japanese or Korean generally want `sayt_min_chars: 1`.** The count is in characters as a person sees them, and a single han character is already a meaningful query; a floor of two means a CJK visitor gets no suggestions until their query is twice as specific as an English speaker's needs to be.

**Why the enrichment cap exists.** Suggestion-driven AI expansions share the AI flood budget with committed searches — expansion, summarize and follow-up all count against the same per-IP limit described under [AI endpoint rate limiting](#ai-endpoint-rate-limiting) (60 requests/minute by default). Without a cap, a visitor's whole allowance could be spent on prefixes they never submitted, and the search they actually ran would come back with no expansion and no AI overview. Over the cap, suggestions silently fall back to keyword matches until the window rolls.

**On an existing site**, `drush updatedb` (or `/update.php`) adds these defaults to `scolta.settings`. Any of the ten you had already set by hand is left alone, including `sayt_enabled: false`. Sites that manage permissions and settings through exported configuration should `drush cex` afterwards and commit the changed `scolta.settings.yml`.

## Troubleshooting

### "No search results"

If searches return no results, the search index may not exist yet. Build it with:

```bash
drush scolta:build
```

If you have previously run `drush search-api:index`, that is not sufficient — Scolta requires its own build step to generate the pagefind index.

### Permissions

Scolta defines a **Use Scolta AI features** permission (`use scolta ai`) that gates the AI API endpoints. This permission is granted to the **authenticated** role automatically at module install, so logged-in visitors receive AI overviews with no admin action required. The **anonymous** role is deliberately not granted it: the AI endpoints make cost-bearing LLM calls, and opening them to unauthenticated traffic is a decision for the site rather than a default.

To serve AI overviews to anonymous visitors, grant **Use Scolta AI features** to the anonymous role at *Administration > People > Permissions*. Until you do, anonymous requests to the AI endpoints return 403.

A site installed before this change may already hold the anonymous grant, which earlier releases applied automatically. A database update revokes it: run `drush updatedb` (or visit `/update.php`) after updating the module. It runs once, so a later decision to grant it again stands.

**If the site manages permissions through exported configuration, the update alone is not enough.** Run the update in the environment you export from, then `drush cex`, and commit the changed `user.role.anonymous.yml` and `user.role.authenticated.yml`. `drush deploy` runs `config:import` after `updatedb`, so on a site whose exported config predates the update, the import restores the old grant seconds after the hook removed it — the update having run and the permission still being present at the same time is exactly what this looks like. Re-exporting also makes the change survive every later `config:import`, which running the update on each environment separately does not.

The health endpoint (`GET /api/scolta/v1/health`) is reachable without any permission so uptime monitors always work, but callers without **Administer Scolta** (`administer scolta`) receive only `{"status": "ok"|"degraded"}`. The full diagnostic payload (AI provider, index integrity, fragment counts) requires `administer scolta`.

#### Narrowing AI access beyond the permission

A role-level permission cannot express every rule a site needs — a per-user preference, a quota, an entitlement that arrives with a subscription. The `scolta.ai_access` service is the one decision point for all three AI features, and both gates ask it: `ScoltaSearchBlock` before it tells the browser a feature exists, and the endpoint routes before they serve the request the browser then makes. Decorate it to narrow the rule, and the search UI stops offering what the endpoint would refuse.

The service answers **who may use a feature**, not **what the site offers**. The `ai_expand_query` / `ai_summarize` switches and the `max_follow_ups` quota stay in configuration and stay enforced where they are, so a switched-off feature keeps answering `404` and an exhausted follow-up quota keeps answering `429` with the remaining `limit`. Don't restate them in a decorator: that turns those documented responses into a `403` from routing, and the quota can't be expressed as access at all — the real rule counts the messages in the request body, which no access check is given.

```yaml
# mymodule.services.yml
services:
  mymodule.ai_access:
    class: Drupal\mymodule\Access\MyAiAccess
    decorates: scolta.ai_access
    arguments: ['@mymodule.ai_access.inner']

  cache_context.user.my_ai_optout:
    class: Drupal\mymodule\Cache\AiOptOutCacheContext
    arguments: ['@current_user']
    tags:
      - { name: cache.context }
```

```php
final class MyAiAccess implements AiAccessInterface {

  public function __construct(private readonly AiAccessInterface $inner) {}

  public function access(AccountInterface $account, string $feature): AccessResultInterface {
    $result = $this->inner->access($account, $feature);
    // Only ever narrow: hand back a refusal as it arrived.
    if (!$result->isAllowed()) {
      return $result;
    }

    $preference = $this->userWantsAi($account)
      ? AccessResult::allowed()
      : AccessResult::forbidden('The user has opted out of AI search.');
    $preference->addCacheContexts(['user.my_ai_optout']);

    return $result->andIf($preference);
  }

}
```

The cacheability on the returned result matters as much as the answer. The block's render array takes it on, so an implementation that varies by account must say so, or the first visitor's answer is served to the next. Declare that variation with a cache context keyed on the **value** your rule reads, not with `cachePerUser()`: a boolean preference gives everything downstream two shared cache variants, where per-user gives one per account — on a large membership that is millions of copies of the search page's cacheable pieces, each hit only when the same person returns. This is the same distinction core draws between `user.roles` and `user`.

```php
final class AiOptOutCacheContext implements CacheContextInterface {

  public function __construct(private readonly AccountInterface $currentUser) {}

  public static function getLabel(): string {
    return (string) t('AI search opt-out');
  }

  public function getContext(): string {
    return $this->userWantsAi($this->currentUser) ? '1' : '0';
  }

  public function getCacheableMetadata(): CacheableMetadata {
    // Merged only where this context is optimized away under a coarser one
    // such as `user`. That entry is keyed per account, so a preference
    // change no longer moves its owner to a different variant — the entry
    // itself must be invalidated when the account is saved. The shared
    // two-variant entries never get this tag, which is the point: an entry
    // serving millions must not be purged because one of them saved.
    return (new CacheableMetadata())->addCacheTags(['user:' . $this->currentUser->id()]);
  }

}
```

A flip then takes effect on the member's next request with no invalidation at all — the context value is recomputed per request, so they simply land in the other variant.

The three features are `AiAccessInterface::FEATURE_EXPAND`, `FEATURE_SUMMARIZE` and `FEATURE_FOLLOW_UP`. Follow-ups are only offered inside an overview, so refusing `FEATURE_SUMMARIZE` withdraws both.

### Configuration

Visit *Administration > Configuration > Search and Metadata > Scolta AI Search* to configure the AI provider, API key, model, and indexing options.

#### AI endpoint rate limiting

The AI API endpoints (`/api/scolta/v1/expand-query`, `/api/scolta/v1/summarize`, `/api/scolta/v1/followup`) make cost-bearing LLM calls. They require the **Use Scolta AI features** permission, which is granted to authenticated users at install; flood limits apply to every caller regardless. The **Rate Limiting** section of the settings form configures per-IP and site-wide flood thresholds (defaults: 60 requests/minute per IP, 1000 requests/minute site-wide); requests beyond a threshold are rejected with HTTP 429 before any AI work happens. Set a limit to 0 to disable that layer.

#### Auto-rebuild debounce

When auto-rebuild is enabled, content saves enqueue an index rebuild that cron processes. The rebuild is debounced by the backend's **Rebuild delay** setting (Search API server > backend configuration, default 300 seconds): the queue waits until that many seconds have passed since the *last* content change, so a burst of edits produces one build instead of many.

Inserts, updates and deletes all enqueue a request, and each one names the node and the content item IDs it touched.

#### Incremental index updates

A queued request that names what changed can be applied to the existing index instead of rebuilding it: the worker gathers only the changed nodes and updates, adds or tombstones just their pages. On a large site this is the difference between an edit costing seconds and an edit costing a full rebuild, because the content gather — not the merge — is where a build spends its time.

Two `scolta.settings` keys control it. They have no form field yet, so set them with Drush:

```bash
# Turn the incremental path off entirely (every request becomes a full rebuild).
drush config:set scolta.settings incremental.enabled false

# Largest change set applied incrementally before falling back to a full
# rebuild. Default 100. Set to 0 to remove the ceiling.
drush config:set scolta.settings incremental.max_changed_items 250
```

The worker falls back to a full rebuild, and logs why at `warning`, whenever it cannot update exactly: a queued request that does not name what changed (the install hook and the Search API backend enqueue plain full-rebuild markers), a change set over the threshold, or an index with no page-table ledger yet. Incremental updates apply to an index; they do not create one, so the first build after installing is always a full build.

**This path is dormant until a `tag1/scolta-php` release carrying `IncrementalIndexUpdater` is installed.** Until then the class is absent, the worker takes the full build path, and nothing changes.

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

**When:** Only after an administrator selects Amazee.ai as the AI provider and completes the connect flow in Scolta's settings. Once connected, every search query made by site visitors is sent to the Amazee.ai API endpoint while AI features are active.
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

## Code Repository Mirroring

This project is maintained on Github. The code is git.drupalcode.org/prject/scolta is configured with a pull mirror of the Github repo so that Drupal sites may get the package via the usual drupal.org Composer facade.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

## About Tag1 Consulting

Scolta is designed, built, and maintained by [Tag1 Consulting](https://tag1.com/). Tag1 has been delivering technology leadership since 2007 and is one of the leading open-source consulting firms in the world.

Tag1 offers [AI strategy, architecture, and implementation consulting](https://tag1.com/services/) — from evaluating whether AI search is right for your organization, to production deployment and ongoing tuning. If you need help integrating Scolta, customizing scoring for your content model, or connecting it to your AI provider of choice, [get in touch](https://tag1.com/contact/).

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
