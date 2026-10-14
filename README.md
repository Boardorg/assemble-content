# Assemble Content

The Assemble Content feed: a WordPress plugin that copies published Contentful entries into hidden, read-only post types. Contentful is the only source of truth; the WordPress copy is a cache that `wp assemble-content sync --all --force` can rebuild at any time.

Field Reports and playbooks (1.8.0) are the content types today. Others are added as entries in the content-type registry, `AFR_Types` (`includes/class-afr-types.php`), or through the `afr_content_types` filter: post type, URL slug, field mapping, link depth, dependencies and whether it is gated. Fetching, syncing, webhook routing, orphan drafting and the CLI all read that one list.

Synced post types are hidden from wp-admin (`show_ui => false`) and never exposed over REST. The only admin screen is **Settings → Assemble Content**. The plugin is site-agnostic: the public site (theassemble.com) uses it now, and the Member Center installs a tagged release.

## Rendering for a theme

Each request is rendered for one audience view (standard, council, delegate, public or denied). A theme that wants its own markup calls `AFR_Renderer::view_model( $post_id )`, which returns the resolved view and **only the sections that view may show**, as data or plugin-rendered Rich Text HTML (the shapes are documented on the method). The theme renders what it gets and never re-derives audiences. It returns false from `afr_filter_the_content` for the single pages it renders itself; any stray `the_content` call there then gets the teaser, and the plugin's document styles aren't enqueued. Archives, feeds and the stored `post_content` are unaffected.

Without a theme that does this, the plugin renders its own `.afr-doc` document through `the_content`, formatted from the same sections.

**`afr_view` (1.9.0).** `( string $view, int $post_id )` lets a site settle the final view when its own rules decide access. The Member Center uses it: a member who may read a piece gets `standard`, even when only Public is ticked, and nobody else gets a teaser.

**Full text for Public reports (1.7.0, opt-in).** A site that runs a free-read meter can return true from `afr_public_full_text` (`( bool $full, int $post_id )`, default false). The public view of a report ticked Public then also carries its body sections (never engagement), and the view model's `full_text` is true; the site decides what to show. It has no effect on any other view, so members-only reports are never affected, and the plugin's own HTML render is unchanged. The public site turns it on (`assemble-core`); the Member Center needn't.

## Playbooks (1.8.0)

Contentful's `playbook` type becomes the `playbook` post type at `/playbooks/<slug>/`, gated and placed like Field Reports (`availableTo`, `publishTo`). The map is `AFR_Playbook_Type`; the gate is `AFR_Playbook::view_model( $post_id )`, which reuses `AFR_Audience::view_for()` and its own sections table: identity (title, community, topics, dek, edition, image, people) for every view; `toc`, `chapters`, `community` and `author` for members; the first chapter (`intro`) and `join` for the public view; `gate` for denied. Council and delegate audiences get the standard view. `afr_public_full_text` covers playbooks the same way.

The body is one Rich Text field. Each Heading 2 starts a chapter with a stable anchor (`id`, de-duplicated); a Heading 2 with a colon ("Days 0–30: Diagnose and Orient") is a numbered phase (`kind`, `kicker`, `rest`, `number`). A chapter's blocks are its lead paragraph, Rich Text HTML, subsections (an all-italic paragraph after a Heading 3 is its `subtitle`), and `callout` blocks from embedded `playbookCallout` entries (kinds `stat`, `checklist`, `checkpoint`, `note`, `table`, `layers`), which the site maps onto its own markup. `the_content` on a playbook is always the teaser, so the stored body is never served; the site renders the single page from the view model.

## Where a piece appears: Publish to (1.9.0)

Field Reports and playbooks have three editor-facing checkbox fields:

- **Publish to:** where the piece appears. `Assemble Site`, then each Network, Board and Council by name. The list lives in `contentful/places.mjs`.
- **Functional areas:** the Boards the piece is about, by Community entry name. The Community entry still supplies the name, logo, boilerplate and practice area.
- **Who can read it** (`availableTo`): unchanged, plus `Council Member`.

Each site declares a channel, and only pieces placed on it sync there (`AFR_Placement::on_site()`):

| Channel | Syncs |
|---|---|
| `Public website` or `Assemble Site` | pieces with Assemble Site ticked |
| `Member Center` | pieces with any other place ticked |
| anything else | pieces with that exact place ticked |

- **Setting the channel:** the `AFR_SITE_CHANNEL` constant (use this on VIP) or `afr_settings[site_channel]`. The `afr_site_channel` filter can override either, and `afr_on_site` can override the result per entry.
- **Unticking:** a piece no longer placed on a site is drafted there, with the reason "not published to this site". That happens on the next full sync, or straight away through the webhook.
- **No channel set:** the site syncs nothing and drafts nothing.

**Places are stored** as terms in a hidden `afr_place` taxonomy on every placed type. `AFR_Query::for_places( [ 'HR Network' ] )` lists pieces for a place, and `AFR_Query::places( $post_id )` reads them back. Placement only lists. Who may read a body is still `availableTo` and `afr_current_audiences`.

**Functional areas are resolved by name** from the cached community directory. `AFR_Placement::normalize()` copies them into the Community link fields the renderers read: the first Board fills the kicker slot. The view model also carries `functional_areas`, with every Board in order, and `places`. A site that knows where the reader is chooses the colour and kicker from that list; with no context, it uses the first. The plugin's own accent colour follows the first Board's practice area.

**Before Contentful is migrated,** entries without Publish to or Functional areas are read the old way, and sync exactly as before:
- "Public website" in Show on becomes Assemble Site.
- "Member Center" in Show on becomes each linked community's name.
- The Community links are the functional areas.

`contentful/migrate-places.mjs` fills the new fields on existing entries. It runs as a dry run unless passed `--apply`, and never fills a Network.

**Sync warnings:** an entry with no place, or a functional area with no Community entry, still syncs, but the sync log carries a warning. Sites add their own checks through `afr_placement_warnings`.

## Dates (1.9.0)

A piece is filed under:
1. its **Publication date** (`publishDate`), if set
2. otherwise, the discussion date in its source line
3. otherwise, its first publish time

A date with no time means local midnight. Timestamps are converted properly, so `post_date_gmt` is always right. A future date is clamped to now: Contentful's scheduling decides when a piece goes live, never WordPress.

## Fetch failures and the webhook (1.9.0)

- **A failed fetch leaves the post alone.** A single-entry fetch that fails (timeout, 401, 429, 5xx, a malformed response) no longer drafts the post. The sync reports an error and the webhook answers 500, so Contentful retries. Only a successful response with no entry means unpublished. `AFR_Contentful::get_entry_result()` tells the two apart.
- **De-duplication is per delivery.** The webhook skips a delivery only when it has already handled that exact topic, entry and revision successfully, so a publish quickly followed by an unpublish is never dropped.
- **One sync per entry at a time.** Concurrent deliveries for the same entry are serialized with an object-cache lock. A busy entry answers 503, which Contentful retries.

## Topics, practice areas and Member Center groups

Each synced report carries its classification in queryable form, not only inside `_afr_data`:

- **Topics:** `_afr_topic` meta, one row per topic slug. The slugs are the Member Center `content-topic` vocabulary, mirrored in Contentful's `topic` type. A site with its own topic taxonomy returns its name from the `afr_topic_taxonomy` filter (the Member Center returns `'content-topic'`). Each sync then sets that taxonomy's **existing** terms on the report and never creates a term. The site registers the taxonomy for `field_report` itself.
- **Practice areas:** `_afr_practice_area` meta, one row per area of the report's communities (`human-resources`, `marketing`, `manufacturing`, `technology`, `finance`).
- **Member Center groups:** a hidden `field_report_mc_group` taxonomy whose terms are the functional areas' `memberCenterGroups` slugs. `AFR_Query::for_groups()` lists reports about those Boards. To list what a Network or Board should *show*, use places (`for_places()`): publishing to a Network is explicit, never inherited from its Boards.
- **Other hooks:** `AFR_Query::topic_slugs()` and `AFR_Query::groups()` read them back. `AFR_Communities::all()` includes each community's `groups`. The `afr_synced( $post_id, $entry, $content_type )` action fires after every written entry, for site-side mappings such as search indexing.

Run `wp assemble-content sync --all --force` once after upgrading so existing posts get the new terms and meta.

## Draft preview

`/?afr_preview=<entry id>` renders an entry's latest draft from Contentful's Preview API through the same view model (and the theme's template, via the `afr_render_preview` action). Nothing is stored or synced; the page is sent noindex and uncacheable. Open to users with `manage_options` (filter `afr_preview_capability`), who can switch views with `&afr_view=` and copy a share link, and to anyone with a signed link (HMAC of the entry ID and expiry with the site's salts, 7 days by default): `wp assemble-content preview-link <entry-id> [--days=7]`. Needs a Content Preview API token in `afr_settings[preview_token]` (or `AFR_PREVIEW_TOKEN`). Register `<site>/?afr_preview={entry.sys.id}` in Contentful → Settings → Content preview.

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
wp assemble-content preview-link <entry-id> [--days=7] [--view=standard]
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
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/preview-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/groups-topics-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/channel-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/placement-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/public-full-text-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/playbook-test.php
npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/type-isolation-test.php
```

`playbook-test.php` uses a synthetic entry: the registry entry and post type (no REST, no admin UI), the map, chapters, anchors, subtitles and callouts, every view's exact sections with members-only text absent from public and denied (and present in standard), real visitors (anonymous, anonymous `?afr_as=`, a stubbed Board Member, a Public playbook with and without full text), and `the_content` serving only the teaser. `type-isolation-test.php` serves the site's own synced entries back through a fake Delivery API and shows that a playbook sync never drafts a Field Report and a Field Report sync never drafts a playbook: per-type full syncs, a full sync with the other type offline, and the webhook path. Note that `channel-test.php` runs real syncs, so a local playbook that isn't published in Contentful gets drafted by it.

`channel-test.php` runs real syncs with the channel forced, covering: set, unset (nothing drafted), an unticked entry through the webhook path (drafted), and a channel with no reports (everything drafted). It restores the reports afterwards. `groups-topics-test.php` checks the group terms, topic and practice-area meta against each entry, `for_groups()`, `afr_topic_taxonomy` (existing terms only) and `afr_synced`.

`sync-test.php` covers paging (150 entries), failed, short and shifted pages (nothing drafted), real unpublishing (drafted), and type isolation. `fingerprint.php` hashes every synced post so you can prove a refactor changed nothing: run it, `sync --all --force`, run it again, diff. `view-model-test.php` checks, for every synced report and view, that only the allowed sections are present and that no members-only sentence reaches the public or denied view (plus a positive control, anonymous and admin `?afr_as=` resolution, the `afr_filter_the_content` opt-out and the bypass). `public-full-text-test.php` checks `afr_public_full_text`: off changes nothing, on adds the body (no engagement) to a Public report's public view only, a members-only report stays denied, and the plugin render is untouched. `render-snapshot.php` hashes every view's HTML (pass `full` for the HTML), to diff before and after a renderer change. `preview-test.php` runs draft preview against a fake Preview API: no token (503), anonymous (403, no request made), staff, each view's allowed sections, signed links (valid, tampered, extended, reused, expired), non-report entries (404), and that nothing is written.

## Deploys

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`) via GitHub Actions. Production deploys are manual and need a reviewer's approval.
