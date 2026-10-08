# Assemble Content

The Assemble Content feed: a WordPress plugin that copies published Contentful entries into hidden, read-only post types. Contentful is the only source of truth; the WordPress copy is a cache that `wp assemble-content sync --all --force` can rebuild at any time.

Field Reports are the first content type. Others (playbooks next) are added as entries in the content-type registry, `AFR_Types` (`includes/class-afr-types.php`), or through the `afr_content_types` filter: post type, URL slug, field mapping, link depth, dependencies and whether it is gated. Fetching, syncing, webhook routing, orphan drafting and the CLI all read that one list.

Synced post types are hidden from wp-admin (`show_ui => false`) and never exposed over REST. The only admin screen is **Settings → Assemble Content**. The plugin is site-agnostic: the public site (theassemble.com) uses it now, and the Member Center will install a tagged release later.

## Rendering for a theme

Each request is rendered for one audience view (standard, council, delegate, public or denied). A theme that wants its own markup calls `AFR_Renderer::view_model( $post_id )`, which returns the resolved view and **only the sections that view may show**, as data or plugin-rendered Rich Text HTML (the shapes are documented on the method). The theme renders what it gets and never re-derives audiences. It returns false from `afr_filter_the_content` for the single pages it renders itself; any stray `the_content` call there then gets the teaser, and the plugin's document styles aren't enqueued. Archives, feeds and the stored `post_content` are unaffected.

Without a theme that does this, the plugin renders its own `.afr-doc` document through `the_content`, formatted from the same sections.

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
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/view-model-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/render-snapshot.php
```

`sync-test.php` covers paging (150 entries), failed, short and shifted pages (nothing drafted), real unpublishing (drafted), and type isolation. `fingerprint.php` hashes every synced post so you can prove a refactor changed nothing: run it, `sync --all --force`, run it again, diff. `view-model-test.php` checks, for every synced report and view, that only the allowed sections are present and that no members-only sentence reaches the public or denied view (plus a positive control, anonymous and admin `?afr_as=` resolution, the `afr_filter_the_content` opt-out and the bypass). `render-snapshot.php` hashes every view's HTML (pass `full` for the HTML), to diff before and after a renderer change.

## Deploys

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`) via GitHub Actions. Production deploys are manual and need a reviewer's approval.
