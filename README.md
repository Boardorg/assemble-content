# Assemble Content

The Assemble Content feed: a WordPress plugin that copies published content from its sources into hidden, read-only post types and cached lists. The sources are the only source of truth; the WordPress copy is a cache that `wp assemble-content sync --all --force` (and `wp assemble-content summits refresh`) can rebuild at any time.

| Content type | Registry key | Source | Stored as |
|---|---|---|---|
| Field Reports | `fieldReport` | Contentful | `field_report` posts |
| Playbooks (1.8.0) | `playbook` | Contentful | `playbook` posts |
| Community directory | `community` | Contentful | the `ac_communities` option |
| Site Features | `siteFeature` | Contentful | the `ac_site_features` option |
| Summits (1.10.0) | `summits` | Executive Platforms' summit API (WP-JSON) | the `ac_summits` option |

Synced post types are hidden from wp-admin (`show_ui => false`) and never exposed over REST. The admin screens are **Settings → Assemble Content** (credentials and status) and **Settings → Synced Content** (the sync report). The plugin is site-agnostic: the public site (theassemble.com) and the Member Center both run it.

## Structure (2.0.0)

Every kind of synced content is a **content type** read from a **source**:

```
includes/
  abstracts/       AC_Source, AC_Content_Type, AC_Post_Type_Content, AC_Option_Content
  core/            AC_Types (the registry), AC_Sync, AC_Settings, AC_Query, AC_Migration, AC_Compat
  sources/
    contentful/    AC_Contentful (Delivery API), its webhook, draft preview and Rich Text
    wp-json/       AC_WP_JSON (another site's REST API, with ETags)
  shared/          AC_Audience, AC_Bypass, AC_Placement, AC_Post_Date: gating and placement
  content-types/
    field-reports/ type, renderer, engagement footer, listing helpers
    playbooks/     type, renderer
    communities/  site-features/  summits/
  admin/  cli/
```

- **`AC_Source`** fetches and identifies items: `fetch_all()`, `fetch_one()`, `describe()`. `AC_Contentful` and `AC_WP_JSON` are the two today; add one through the `ac_sources` filter.
- **`AC_Content_Type`** is one kind of content: its source, its config, `sync()` and `summary()`. It has two storage kinds:
  - **`AC_Post_Type_Content`**: one post per item, in a hidden post type. It holds the whole sync engine (matching by `_ac_entry_id`, revisions, bookkeeping meta, placement, orphan drafting, dry runs) and the post type registration. A subclass implements `map()` and, if it needs them, `after_save()`, `register_taxonomies()` and `content_for()` (what `the_content` serves; a gated type serves its teaser by default).
  - **`AC_Option_Content`**: the whole list cached in one option. A sync fetches, maps (`map_item()`), checks (`validate()`) and replaces the option; a failed or suspect fetch keeps the last good copy. Each records its last outcome in `<option>_status`.
- **`AC_Types`** is the registry. Each entry is a config array naming its `class`; the `ac_content_types` filter can change, remove or add entries. A config with a `map` callable and no `class` becomes an `AC_Generic_Post_Type`, so a site can add a simple post type without a class. `AC_Types::all()` and `AC_Types::get()` still return the post-type configs in the shape they had before 2.0.0.

**To add a type:** subclass the storage kind, give it a `defaults()` config (label, source, post type or option, dependencies), put it in its own folder under `content-types/`, and register it in `AC_Types::definitions()` and the classmap in `assemble-content.php`. Syncing, the webhook (for Contentful types), the CLI and the admin screens pick it up from the registry.

`sync --all`, "Sync now" and a webhook dependency change sync every type with `full_sync` on, in registry order (the community directory first, since functional areas are resolved from it). Summits have `full_sync` off: they refresh on their own hourly schedule.

## Upgrading to 2.0.0

2.0.0 renames everything from `afr` ("Assemble Field Reports") to `ac` ("Assemble Content"): classes (`AFR_Renderer` is now `AC_Field_Report_Renderer`, `AFR_CPT` is split between `AC_Post_Type_Content` and `AC_Field_Report_Type`, `AFR_Features` is `AC_Site_Features`, `AFR_REST` is `AC_Contentful_Webhook`, `AFR_Playbook` is `AC_Playbook_Renderer`, the rest are `AFR_X` → `AC_X`), hooks (`afr_*` → `ac_*`), constants (`AFR_SPACE_ID` → `AC_SPACE_ID` and so on), options, post meta, the `afr_place` taxonomy, query args (`?ac_preview=`, `?ac_as=`, `?ac_bypass=`), the webhook header (`X-AC-Secret`), CSS classes (`.ac-doc`, `--ac-accent`) and style handles.

- **Stored data is migrated once, automatically** (`AC_Migration`), on the first request after the upgrade (`init` priority 0, so it also runs where activation hooks don't, as on VIP). Options are copied to their new names, `_afr_*` post meta and the `afr_place` taxonomy are renamed in place, and the old summits cron event is unscheduled. `ac_db_version` records that it ran; `wp assemble-content migrate` runs it by hand.
- **Old names keep working, with deprecation notices** (`AC_Compat`): `AFR_*` classes are aliases, callbacks on `afr_*` hooks still run (WP_DEBUG reports each), `AFR_*` constants are read when the `AC_*` ones aren't set, `?afr_*` query args are read as their `ac_*` names, and the webhook still accepts `X-AFR-Secret`. Preview links signed before the upgrade work until they expire. Move to the new names when convenient; the shims will go in a later major release.
- **What doesn't carry over:** CSS that targets `.afr-*` classes (use `.ac-*`), the bypass cookie (visitors who opted in must click again), and `AC_Contentful::get_type()`, `get_field_reports()`, `get_site_features()` and `AC_Summits::request()`, `refresh_and_record()` (use the type's `sync()`, `AC_Summits::refresh()` or `AC_WP_JSON::get()`).
- **Styles are registered, never enqueued.** The plugin no longer puts any style or script on the page. A site that prints the plugin's own `.ac-doc` document enqueues `AC_Field_Report_Renderer::STYLE_HANDLE` (`ac-field-report`, which depends on the `ac-fonts` Google Fonts stylesheet) where it needs it.
- **Contentful setup**, when convenient: change the webhook header to `X-AC-Secret` (`contentful/setup-wp-webhook.mjs`) and the preview URL to `?ac_preview={entry.sys.id}` (`contentful/setup-preview-url.mjs`). Both old forms keep working until then.

## Rendering for a theme

Each request is rendered for one audience view (standard, council, delegate, public or denied). A theme that wants its own markup calls `AC_Field_Report_Renderer::view_model( $post_id )`, which returns the resolved view and **only the sections that view may show**, as data or plugin-rendered Rich Text HTML (the shapes are documented on the method). The theme renders what it gets and never re-derives audiences. It returns false from `ac_filter_the_content` for the single pages it renders itself; any stray `the_content` call there then gets the teaser. Archives, feeds and the stored `post_content` are unaffected.

Without a theme that does this, the plugin renders its own `.ac-doc` document through `the_content`, formatted from the same sections. The site enqueues its styles: `wp_enqueue_style( AC_Field_Report_Renderer::STYLE_HANDLE )`.

**`ac_view` (1.9.0).** `( string $view, int $post_id )` lets a site settle the final view when its own rules decide access. The Member Center uses it: a member who may read a piece gets `standard`, even when only Public is ticked, and nobody else gets a teaser.

**Full text for Public reports (1.7.0, opt-in).** A site that runs a free-read meter can return true from `ac_public_full_text` (`( bool $full, int $post_id )`, default false). The public view of a report ticked Public then also carries its body sections (never engagement), and the view model's `full_text` is true; the site decides what to show. It has no effect on any other view, so members-only reports are never affected, and the plugin's own HTML render is unchanged. The public site turns it on (`assemble-core`); the Member Center needn't.

## Playbooks (1.8.0)

Contentful's `playbook` type becomes the `playbook` post type at `/playbooks/<slug>/`, gated and placed like Field Reports (`availableTo`, `publishTo`). The map is `AC_Playbook_Type`; the gate is `AC_Playbook_Renderer::view_model( $post_id )`, which reuses `AC_Audience::view_for()` and its own sections table: identity (title, community, topics, dek, edition, image, people) for every view; `toc`, `chapters`, `community` and `author` for members; the first chapter (`intro`) and `join` for the public view; `gate` for denied. Council and delegate audiences get the standard view. `ac_public_full_text` covers playbooks the same way.

The body is one Rich Text field. Each Heading 2 starts a chapter with a stable anchor (`id`, de-duplicated); a Heading 2 with a colon ("Days 0–30: Diagnose and Orient") is a numbered phase (`kind`, `kicker`, `rest`, `number`). A chapter's blocks are its lead paragraph, Rich Text HTML, subsections (an all-italic paragraph after a Heading 3 is its `subtitle`), and `callout` blocks from embedded `playbookCallout` entries (kinds `stat`, `checklist`, `checkpoint`, `note`, `table`, `layers`), which the site maps onto its own markup. `the_content` on a playbook is always the teaser, so the stored body is never served; the site renders the single page from the view model.

## Where a piece appears: Publish to (1.9.0)

Field Reports and playbooks have three editor-facing checkbox fields:

- **Publish to:** where the piece appears. `Assemble Site`, then each Network, Board and Council by name. The list lives in `contentful/places.mjs`.
- **Functional areas:** the Boards the piece is about, by Community entry name. The Community entry still supplies the name, logo, boilerplate and practice area.
- **Who can read it** (`availableTo`): unchanged, plus `Council Member`.

Each site declares a channel, and only pieces placed on it sync there (`AC_Placement::on_site()`):

| Channel | Syncs |
|---|---|
| `Public website` or `Assemble Site` | pieces with Assemble Site ticked |
| `Member Center` | pieces with any other place ticked |
| anything else | pieces with that exact place ticked |

- **Setting the channel:** the `AC_SITE_CHANNEL` constant (use this on VIP) or `ac_settings[site_channel]`. The `ac_site_channel` filter can override either, and `ac_on_site` can override the result per entry.
- **Unticking:** a piece no longer placed on a site is drafted there, with the reason "not published to this site". That happens on the next full sync, or straight away through the webhook.
- **No channel set:** the site syncs nothing and drafts nothing.

**Places are stored** as terms in a hidden `ac_place` taxonomy on every placed type. `AC_Query::for_places( [ 'HR Network' ] )` lists pieces for a place, and `AC_Query::places( $post_id )` reads them back. Placement only lists. Who may read a body is still `availableTo` and `ac_current_audiences`.

**Functional areas are resolved by name** from the cached community directory. `AC_Placement::normalize()` copies them into the Community link fields the renderers read: the first Board fills the kicker slot. The view model also carries `functional_areas`, with every Board in order, and `places`. A site that knows where the reader is chooses the colour and kicker from that list; with no context, it uses the first. The plugin's own accent colour follows the first Board's practice area.

**Before Contentful is migrated,** entries without Publish to or Functional areas are read the old way, and sync exactly as before:
- "Public website" in Show on becomes Assemble Site.
- "Member Center" in Show on becomes each linked community's name.
- The Community links are the functional areas.

`contentful/migrate-places.mjs` fills the new fields on existing entries. It runs as a dry run unless passed `--apply`, and never fills a Network.

**Sync warnings:** an entry with no place, or a functional area with no Community entry, still syncs, but the sync log carries a warning. Sites add their own checks through `ac_placement_warnings`.

## Dates (1.9.0)

A piece is filed under:
1. its **Publication date** (`publishDate`), if set
2. otherwise, the discussion date in its source line
3. otherwise, its first publish time

A date with no time means local midnight. Timestamps are converted properly, so `post_date_gmt` is always right. A future date is clamped to now: Contentful's scheduling decides when a piece goes live, never WordPress.

## Fetch failures and the webhook (1.9.0)

- **A failed fetch leaves the post alone.** A single-entry fetch that fails (timeout, 401, 429, 5xx, a malformed response) no longer drafts the post. The sync reports an error and the webhook answers 500, so Contentful retries. Only a successful response with no entry means unpublished. `AC_Contentful::get_entry_result()` tells the two apart.
- **De-duplication is per delivery.** The webhook skips a delivery only when it has already handled that exact topic, entry and revision successfully, so a publish quickly followed by an unpublish is never dropped.
- **One sync per entry at a time.** Concurrent deliveries for the same entry are serialized with an object-cache lock. A busy entry answers 503, which Contentful retries.

## Topics, practice areas and Member Center groups

Each synced report carries its classification in queryable form, not only inside `_ac_data`:

- **Topics:** `_ac_topic` meta, one row per topic slug. The slugs are the Member Center `content-topic` vocabulary, mirrored in Contentful's `topic` type. A site with its own topic taxonomy returns its name from the `ac_topic_taxonomy` filter (the Member Center returns `'content-topic'`). Each sync then sets that taxonomy's **existing** terms on the report and never creates a term. The site registers the taxonomy for `field_report` itself.
- **Practice areas:** `_ac_practice_area` meta, one row per area of the report's communities (`human-resources`, `marketing`, `manufacturing`, `technology`, `finance`).
- **Member Center groups:** a hidden `field_report_mc_group` taxonomy whose terms are the functional areas' `memberCenterGroups` slugs. `AC_Query::for_groups()` lists reports about those Boards. To list what a Network or Board should *show*, use places (`for_places()`): publishing to a Network is explicit, never inherited from its Boards.
- **Other hooks:** `AC_Query::topic_slugs()` and `AC_Query::groups()` read them back. `AC_Communities::all()` includes each community's `groups`. The `ac_synced( $post_id, $entry, $content_type )` action fires after every written entry, for site-side mappings such as search indexing.

Run `wp assemble-content sync --all --force` once after upgrading so existing posts get the new terms and meta.

## Draft preview

`/?ac_preview=<entry id>` renders an entry's latest draft from Contentful's Preview API through the same view model (and the theme's template, via the `ac_render_preview` action). Nothing is stored or synced; the page is sent noindex and uncacheable. Open to users with `manage_options` (filter `ac_preview_capability`), who can switch views with `&ac_view=` and copy a share link, and to anyone with a signed link (HMAC of the entry ID and expiry with the site's salts, 7 days by default): `wp assemble-content preview-link <entry-id> [--days=7]`. Needs a Content Preview API token in `ac_settings[preview_token]` (or `AC_PREVIEW_TOKEN`). Register `<site>/?ac_preview={entry.sys.id}` in Contentful → Settings → Content preview.

Formerly `assemble-field-reports`. Never activate both plugins at once: the old plugin's `AFR_*` classes collide with this one's compatibility aliases.

## Summits (1.10.0)

Summit facts come from **Executive Platforms' summit API** (public, read-only; reference: the hub's `EP-SUMMITS-API-SPEC.md`), not from Contentful. EP stays the source of truth, and this plugin never writes to it. The reader lives here so both sites use one copy: the public site through `assemble-core`'s wrappers, the Member Center through its adapter.

- **`AC_Summits`: the index**, an option-stored content type on the WP-JSON source. `GET /wp-json/ep/v1/summits?v=1` into the `ac_summits` option, hourly (WP-Cron `ac_summits_refresh`) and on demand (`AC_Summits::refresh()`), sent with the stored `ETag`, so an unchanged list is a 304 with no body. A refresh that looks wrong keeps the last good copy: unreachable, not the expected JSON, or a list that's empty or under half as long as before. A summit with no current edition or start date is left out with a warning. Read with `AC_Summits::all( $upcoming_only )` (filter `ac_summits`), `with_extras()`, `get( $code )`, and build links with `AC_Summits::url( $summit, 'details'|'register'|'agenda' )` (filter `ac_summit_url`), the one place that changes when a summit's pages move onto Assemble.
- **`AC_Summit_Extras`: Assemble's own fields per summit code**, which EP doesn't keep: `area`, `area_label`, `communities` (community directory slugs; `['*']` for every Community in its area), `blurb`, `logo`, plus the ad fields `format` (`in-person` or `virtual`), `cta_label`, `ad_headline`, `ad_pitch`, `image` and `video_url`/`video_caption`. An extra never overrides an EP fact; an unmapped summit gets the defaults. `logo` and `image` are paths in the shared image library under `wp-content/assets/`; a site that serves the library elsewhere uses `ac_summit_asset_url`. Change values without a commit through `ac_summit_extras`.
- **`AC_Summit_Editions`: full editions** (days, members' forum, sessions with stream parts, chairs, speakers), only for the codes a site lists in the `ac_summit_edition_codes` filter (none by default). Each is stored in `ac_summit_edition_{code}` (not autoloaded), with its own `ETag`, and refreshed with the index. Mapping rules, shared with the Delegate Sales Tool: logistics (`hospitality` slots, `announcement` sessions) are dropped; parts with no title yet are skipped; one-to-one meeting slots keep their description and lose their parts; stream labels come from the edition's streams by number; the members' forum is the day with `members_only`; "to be announced" people keep `tba` and their company, with no name. An edition that comes back with no days keeps the stored days. Part IDs are positional (EP renumbers lunch tables), so anything stored against a session should match by title first.

Each run's outcome is stored in `ac_summits_status`. When the last run failed, or nothing has refreshed for three days, administrators see a notice in wp-admin. The HTTP calls run only from cron and WP-CLI, never while a page renders.

```
wp assemble-content summits refresh [--dry-run]
wp assemble-content summits list [--all]
wp assemble-content summits edition <code> [--year=<year>] [--dry-run] [--delete] [--format=summary|sessions|json]
```

## WP-CLI

```
wp assemble-content status
wp assemble-content types
wp assemble-content sync --all [--force] [--dry-run]
wp assemble-content sync --type=<fieldReport|field_report|community|...> [--force] [--dry-run]
wp assemble-content sync --entry=<id> [--force] [--dry-run]
wp assemble-content matrix
wp assemble-content audiences
wp assemble-content preview-link <entry-id> [--days=7] [--view=standard]
wp assemble-content migrate [--force]
```

`types` lists every registered type with its source, storage and state. `--type` takes any registry key or post type; `--type=summits` is the same as `summits refresh`.

Full fetches page through the Delivery API (ordered by `sys.id` so pages are stable). A sync moves a post to draft only when its type's fetch was complete; a failed or partial fetch drafts nothing, and syncing one type never touches another.

`wp field-report …` still works as an alias until launch.

## Configuration

Settings live in the `AC_*` constants (`AC_SPACE_ID`, `AC_ENVIRONMENT`, `AC_DELIVERY_TOKEN`, `AC_PREVIEW_TOKEN`, `AC_WEBHOOK_SECRET`, `AC_SITE_CHANNEL`; the `AFR_*` names still work) or the `ac_settings` option (space ID, delivery token, environment, webhook secret). **Always set `environment` explicitly**; if it is empty the plugin reads `master`. No credentials are stored in this repo.

## Sync report (2.0.0)

**Settings → Synced Content** (`AC_Sync_Report`) shows the last sync and its log, a **Sync now** button (the same full sync as `sync --all`), each content type's state, and a paged, read-only table of every synced post. It moved here from the Member Center's own plugin. A site adds its own context through filters instead of a page of its own:

| Filter | Value |
|---|---|
| `ac_sync_report_intro` | The paragraph under the title (plain text). |
| `ac_sync_report_notices` | `[ [ 'type' => error\|warning\|success\|info, 'message' => … ] ]`, with `$synced` (after "Sync now"). |
| `ac_sync_report_summary_rows` | Extra "Last sync" rows: `label => value`. |
| `ac_sync_report_columns` | The posts table: `key => [ 'label' => …, 'render' => fn( WP_Post ): string ]`; `render` returns escaped HTML. Defaults: piece, status, Publish to, Who can read it, topics, date, synced. |
| `ac_sync_report_query_args` | The posts table's `WP_Query` args. |
| `ac_sync_report_after` (action) | After the posts table. |

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

`playbook-test.php` uses a synthetic entry: the registry entry and post type (no REST, no admin UI), the map, chapters, anchors, subtitles and callouts, every view's exact sections with members-only text absent from public and denied (and present in standard), real visitors (anonymous, anonymous `?ac_as=`, a stubbed Board Member, a Public playbook with and without full text), and `the_content` serving only the teaser. `type-isolation-test.php` serves the site's own synced entries back through a fake Delivery API and shows that a playbook sync never drafts a Field Report and a Field Report sync never drafts a playbook: per-type full syncs, a full sync with the other type offline, and the webhook path. Note that `channel-test.php` runs real syncs, so a local playbook that isn't published in Contentful gets drafted by it.

`channel-test.php` runs real syncs with the channel forced, covering: set, unset (nothing drafted), an unticked entry through the webhook path (drafted), and a channel with no reports (everything drafted). It restores the reports afterwards. `groups-topics-test.php` checks the group terms, topic and practice-area meta against each entry, `for_groups()`, `ac_topic_taxonomy` (existing terms only) and `ac_synced`.

`sync-test.php` covers paging (150 entries), failed, short and shifted pages (nothing drafted), real unpublishing (drafted), and type isolation. `fingerprint.php` hashes every synced post so you can prove a refactor changed nothing: run it, `sync --all --force`, run it again, diff. `view-model-test.php` checks, for every synced report and view, that only the allowed sections are present and that no members-only sentence reaches the public or denied view (plus a positive control, anonymous and admin `?ac_as=` resolution, the `ac_filter_the_content` opt-out and the bypass). `public-full-text-test.php` checks `ac_public_full_text`: off changes nothing, on adds the body (no engagement) to a Public report's public view only, a members-only report stays denied, and the plugin render is untouched. `render-snapshot.php` hashes every view's HTML (pass `full` for the HTML), to diff before and after a renderer change. `preview-test.php` runs draft preview against a fake Preview API: no token (503), anonymous (403, no request made), staff, each view's allowed sections, signed links (valid, tampered, extended, reused, expired), non-report entries (404), and that nothing is written.

## Deploys

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`) via GitHub Actions. Production deploys are manual and need a reviewer's approval.
