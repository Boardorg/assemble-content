/**
 * Seeds the promotional content the 2026 homepage expects.
 *
 * - One Site Feature: the Decision Intelligence Benchmark card, transcribed from
 *   assemble-site-mockup.html so the homepage hero has real content to render.
 * - Marks two Field Reports as featured, so the stream demonstrably orders by
 *   featuredRank rather than date.
 *
 * Idempotent: re-running updates the same entries rather than creating more.
 * Everything it writes is editorial content Marketing can change or unpublish in
 * Contentful — nothing here is structural.
 *
 * Usage: node scripts/seed-site-features.mjs
 */
import 'dotenv/config'
import { createClient } from 'contentful-management'

const spaceId = process.env.CONTENTFUL_SPACE_ID
const accessToken = process.env.CONTENTFUL_MANAGEMENT_TOKEN
const environmentId = process.env.CONTENTFUL_ENVIRONMENT

if (!spaceId || !accessToken || !environmentId) {
  console.error('Missing CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN, or CONTENTFUL_ENVIRONMENT in .env')
  process.exit(1)
}
if (environmentId === 'master') {
  console.error('Refusing to run against the master environment.')
  process.exit(1)
}

const client = createClient({ accessToken }, { type: 'plain', defaults: { spaceId, environmentId } })
const en = (v) => ({ 'en-US': v })

// How many reports to promote, and in what order. Matched on the slug field so
// the script does not depend on entry IDs that differ per environment.
const FEATURE_SLUGS = [
  'aeo-metrics-multiply-which-signals-inform-decisions',
  'better-aeo-starts-with-clear-ownership',
]

const BENCHMARK = {
  internalTitle: 'Homepage hero — Decision Intelligence Benchmark',
  placement: 'Homepage hero',
  active: true,
  rank: 1,
  kicker: 'Featured · The Benchmark',
  badge: '2026 edition',
  headline: 'The Decision Intelligence Benchmark: how leaders actually make their hardest calls.',
  blurb:
    'Our flagship study of how senior leaders decide — where they get stuck, what breaks the tie, and how a peer who’s been there changes the outcome. Take part and see how you compare.',
  ctaLabel: 'Explore the benchmark',
  ctaUrl: '/benchmark/',
  asideLabel: 'Why it exists',
  asideHeadline: 'Leaders have never had more data — or found it harder to decide with confidence.',
  asideBody: 'The benchmark maps how peers actually get to an answer.',
  asideCtaLabel: 'Take part',
  asideCtaUrl: '/benchmark/',
}

async function upsertSiteFeature() {
  const existing = await client.entry.getMany({
    query: { content_type: 'siteFeature', 'fields.internalTitle': BENCHMARK.internalTitle, limit: 1 },
  })

  const fields = Object.fromEntries(Object.entries(BENCHMARK).map(([k, v]) => [k, en(v)]))

  let entry
  if (existing.items.length) {
    entry = await client.entry.update({ entryId: existing.items[0].sys.id }, { ...existing.items[0], fields })
    console.log(`Updated Site Feature ${entry.sys.id}`)
  } else {
    entry = await client.entry.create({ contentTypeId: 'siteFeature' }, { fields })
    console.log(`Created Site Feature ${entry.sys.id}`)
  }

  await client.entry.publish({ entryId: entry.sys.id }, entry)
  console.log(`Published Site Feature ${entry.sys.id}`)
}

async function markFeatured() {
  for (const [i, slug] of FEATURE_SLUGS.entries()) {
    const found = await client.entry.getMany({
      query: { content_type: 'fieldReport', 'fields.slug': slug, limit: 1 },
    })

    if (!found.items.length) {
      console.warn(`  ! no fieldReport with slug "${slug}" — skipped`)
      continue
    }

    const current = await client.entry.get({ entryId: found.items[0].sys.id })
    const updated = await client.entry.update(
      { entryId: current.sys.id },
      { ...current, fields: { ...current.fields, featured: en(true), featuredRank: en(i + 1) } }
    )
    await client.entry.publish({ entryId: updated.sys.id }, updated)
    console.log(`Featured (rank ${i + 1}): ${slug}`)
  }
}

console.log(`Space: ${spaceId}  Environment: ${environmentId}\n`)
await upsertSiteFeature()
await markFeatured()
console.log('\nDone. Run `./scripts/wpbeta.sh field-report sync --all --force` to pull it into WordPress.')
