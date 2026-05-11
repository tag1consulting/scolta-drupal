# Scolta AI Search for Drupal

AI-powered search for Drupal — semantic relevance scoring, AI summaries, and natural language query expansion on top of Drupal's Search API.

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

## Troubleshooting

### "No search results"

If searches return no results, the search index may not exist yet. Build it with:

```bash
drush scolta:build
```

If you have previously run `drush search-api:index`, that is not sufficient — Scolta requires its own build step to generate the pagefind index.

### Permissions

Users must have the **Use Scolta search** permission. Grant it at *Administration → People → Permissions*.

### Configuration

Visit *Administration → Configuration → Search and Metadata → Scolta AI Search* to configure the AI provider, API key, model, and indexing options.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full list of changes.

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
