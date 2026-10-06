# Assemble Content

The Assemble Content feed: a WordPress plugin that copies published Contentful entries into hidden, read-only post types. Contentful is the only source of truth; the WordPress copy is a cache that `wp assemble-content sync --all --force` can rebuild at any time.

Field Reports are the first content type. Others (playbooks next) are added as entries in the content-type registry, `AFR_Types` (`includes/class-afr-types.php`), or through the `afr_content_types` filter: post type, URL slug, field mapping, link depth, dependencies and whether it is gated. Fetching, syncing, webhook routing, orphan drafting and the CLI all read that one list.

Synced post types are hidden from wp-admin (`show_ui => false`) and never exposed over REST. The only admin screen is **Settings → Assemble Content**. The plugin is site-agnostic: the public site (theassemble.com) uses it now, and the Member Center will install a tagged release later.

Formerly `assemble-field-reports`. Internal names (`afr_*` options, filters and post meta, `AFR_*` classes) are unchanged, so existing sites switch over without a data migration. Never activate both plugins at once.

## WP-CLI

```
wp assemble-content status
wp assemble-content types
wp assemble-content sync --all [--force] [--dry-run]
wp assemble-content sync --type=<fieldReport|field_report> [--force] [--dry-run]
wp assemble-content sync --entry=<id> [--force]
wp assemble-content matrix
wp assemble-content audiences
```

Full fetches page through the Delivery API (ordered by `sys.id` so pages are stable). A sync moves a post to draft only when its type's fetch was complete; a failed or partial fetch drafts nothing, and syncing one type never touches another.

`wp field-report …` still works as an alias until launch.

## Configuration

Settings live in the `afr_settings` option (space ID, delivery token, environment, webhook secret). **Always set `environment` explicitly**; if it is empty the plugin reads `master`. No credentials are stored in this repo.

## Contentful scripts

`contentful/` holds the content-model and setup scripts. They read credentials from environment variables; see `contentful/.env.example` for the names. Every script refuses the `master` environment.

## Tests

Local only (wp-env), against a fake Delivery API; nothing real is fetched or changed:

```
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/sync-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/fingerprint.php
```

`sync-test.php` covers paging (150 entries), failed, short and shifted pages (nothing drafted), real unpublishing (drafted), and type isolation. `fingerprint.php` hashes every synced post so you can prove a refactor changed nothing: run it, `sync --all --force`, run it again, diff.

## Deploys

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`) via GitHub Actions. Production deploys are manual and need a reviewer's approval.
