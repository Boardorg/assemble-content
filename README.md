# Assemble Content

The Assemble Content feed: a WordPress plugin that copies published Contentful entries into hidden, read-only post types. Contentful is the only source of truth; the WordPress copy is a cache that `wp assemble-content sync --all --force` can rebuild at any time.

Field Reports are the first content type. Playbooks and others follow through a content-type registry. The plugin is site-agnostic: the public site (theassemble.com) uses it now, and the Member Center will install a tagged release later.

Formerly `assemble-field-reports`. Internal names (`afr_*` options, filters and post meta, `AFR_*` classes) are unchanged, so existing sites switch over without a data migration. Never activate both plugins at once.

## WP-CLI

```
wp assemble-content status
wp assemble-content sync --all [--force] [--dry-run]
wp assemble-content sync --entry=<id> [--force]
wp assemble-content matrix
wp assemble-content audiences
```

`wp field-report …` still works as an alias until launch.

## Configuration

Settings live in the `afr_settings` option (space ID, delivery token, environment, webhook secret). **Always set `environment` explicitly**; if it is empty the plugin reads `master`. No credentials are stored in this repo.

## Contentful scripts

`contentful/` holds the content-model and setup scripts. They read credentials from environment variables; see `contentful/.env.example` for the names. Every script refuses the `master` environment.

## Deploys

Pushes to `main` deploy to the WP Engine beta (`assemblebeta`) via GitHub Actions. Production deploys are manual and need a reviewer's approval.
