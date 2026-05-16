# Scolta AI Search for Drupal

AI-powered search for Drupal — semantic relevance scoring, AI summaries, and natural language query expansion on top of Drupal's Search API.

Built and maintained by [Tag1 Consulting](https://tag1.com/) — technology leadership since 2007. [Tag1 offers AI strategy, architecture, and implementation consulting](https://tag1.com/services/ai/) for organizations evaluating or deploying AI-powered products.

## Requirements

- Drupal 10.3+ or Drupal 11
- PHP 8.1+
- `drupal/search_api` ^1.0

## Installation

```bash
composer require tag1/scolta-drupal
drush en scolta
drush scolta:build
```

## Drush Commands

| Command | Description |
|---|---|
| `drush scolta:build` | Build the search index (export + pagefind) |
| `drush scolta:build --force` | Force rebuild even if content has not changed |
| `drush scolta:build --resume` | Resume a previously interrupted build |
| `drush scolta:build --restart` | Discard interrupted state and start fresh |
| `drush scolta:build --chunk-size=N` | Process N pages per chunk (overrides config) |
| `drush scolta:finalize` | Merge chunks into the final search index |
| `drush scolta:status` | Show current index status |
| `drush scolta:cleanup` | Remove stale temporary index files |
| `drush scolta:discover` | Discover indexable content types |

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

Configure the provider at *Administration → Configuration → AI → AI Providers*, using a Key entity for secure API key storage.

Finally, select **Drupal AI module** in Scolta settings at *Administration → Configuration → Search and Metadata → Scolta AI Search → AI Configuration → AI Provider*.

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

## Troubleshooting

### "No search results"

If searches return no results, the search index may not exist yet. Build it with:

```bash
drush scolta:build
```

If you have previously run `drush search-api:index`, that is not sufficient — Scolta requires its own build step to generate the pagefind index.

### Permissions

Scolta defines a **Use Scolta AI features** permission (`use scolta ai`) that gates the AI API endpoints. This permission is granted to the **anonymous** and **authenticated** roles automatically at module install, so search visitors receive AI overviews out of the box with no admin action required.

To restrict AI features to specific roles (e.g. authenticated users only), revoke the permission from the anonymous role at *Administration → People → Permissions*.

### Configuration

Visit *Administration → Configuration → Search and Metadata → Scolta AI Search* to configure the AI provider, API key, model, and indexing options.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

## About Tag1 Consulting

Scolta is designed, built, and maintained by [Tag1 Consulting](https://tag1.com/). Tag1 has been delivering technology leadership since 2007 and is one of the leading open-source consulting firms in the world.

Tag1 offers [AI strategy, architecture, and implementation consulting](https://tag1.com/services/ai/) — from evaluating whether AI search is right for your organization, to production deployment and ongoing tuning. If you need help integrating Scolta, customizing scoring for your content model, or connecting it to your AI provider of choice, [get in touch](https://tag1.com/contact/).

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
