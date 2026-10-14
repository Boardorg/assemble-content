/**
 * One-time: fill Functional areas and Publish to on existing Field Reports and
 * Playbooks from their old Community links and Show on.
 *
 * - Functional areas <- the names of the linked Community entries (primary first).
 * - Publish to <- "Public website" becomes Assemble Site; "Member Center" becomes
 *   each linked Board, and each linked Council. Networks are never filled:
 *   editors tick those.
 * - Only empty fields are filled, so re-running never overwrites an editor's choice.
 * - An entry that was published with no pending changes is republished, so the
 *   sites see the new fields. An entry with unpublished edits is updated but left
 *   for its editor to publish, and is listed.
 *
 * Dry run by default. Pass --apply to write.
 *
 * Usage: node --env-file=../../.env.contentful migrate-places.mjs [--apply]
 */
import 'dotenv/config'
import { createClient } from 'contentful-management'
import { ASSEMBLE_SITE, BOARDS, COUNCILS } from './places.mjs'

const apply = process.argv.includes('--apply')
const spaceId = process.env.CONTENTFUL_SPACE_ID
const accessToken = process.env.CONTENTFUL_MANAGEMENT_TOKEN
const environmentId = process.env.CONTENTFUL_ENVIRONMENT

if (!spaceId || !accessToken || !environmentId) {
  console.error('Missing CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN, or CONTENTFUL_ENVIRONMENT.')
  process.exit(1)
}
if (environmentId === 'master') {
  console.error('Refusing to run against the master environment.')
  process.exit(1)
}

const client = createClient({ accessToken }, { type: 'plain', defaults: { spaceId, environmentId } })

const locales = await client.locale.getMany({})
const locale = (locales.items.find((l) => l.default) ?? { code: 'en-US' }).code

// Community entry ID -> name.
const communityNames = new Map()
for (let skip = 0; ; skip += 100) {
  const page = await client.entry.getMany({ query: { content_type: 'community', limit: 100, skip } })
  for (const c of page.items) communityNames.set(c.sys.id, c.fields.name?.[locale] ?? '')
  if (skip + page.items.length >= page.total) break
}

const LINK_FIELDS = {
  fieldReport: ['primaryCommunity', 'additionalCommunities'],
  playbook: ['community'],
}

const value = (entry, field) => entry.fields[field]?.[locale]
const isPublishedClean = (e) => !!e.sys.publishedVersion && e.sys.version === e.sys.publishedVersion + 1

const rows = []
const problems = []

for (const [type, linkFields] of Object.entries(LINK_FIELDS)) {
  for (let skip = 0; ; skip += 100) {
    const page = await client.entry.getMany({ query: { content_type: type, limit: 100, skip } })

    for (const entry of page.items) {
      const id = entry.sys.id
      const title = value(entry, 'headline') ?? value(entry, 'title') ?? value(entry, 'internalTitle') ?? id
      const changes = {}

      // Functional areas from the Community links. A linked Council is a place, not a functional area.
      const linkedNames = linkFields
        .flatMap((f) => {
          const v = value(entry, f)
          return Array.isArray(v) ? v : v ? [v] : []
        })
        .map((link) => communityNames.get(link.sys.id) || link.sys.id)
      const councils = linkedNames.filter((name) => COUNCILS.includes(name))

      let areas = value(entry, 'functionalAreas') ?? []
      if (!areas.length) {
        for (const name of linkedNames) {
          if (BOARDS.includes(name)) {
            areas.push(name)
          } else if (!COUNCILS.includes(name)) {
            problems.push(`${type} ${id}: community "${name}" is not a Board in places.mjs (unpublished or renamed?); left out`)
          }
        }
        areas = [...new Set(areas)]
        if (areas.length) changes.functionalAreas = areas
      }

      // Publish to from Show on. Never a Network.
      const publishTo = value(entry, 'publishTo') ?? []
      if (!publishTo.length) {
        const places = []
        const showOn = value(entry, 'showOn') ?? []
        if (showOn.includes('Public website')) places.push(ASSEMBLE_SITE)
        if (showOn.includes('Member Center')) places.push(...areas, ...councils)
        if (places.length) changes.publishTo = [...new Set(places)]
      }

      if (!Object.keys(changes).length) continue

      const clean = isPublishedClean(entry)
      rows.push({
        type,
        id,
        title: String(title).slice(0, 60),
        functionalAreas: (changes.functionalAreas ?? []).join(' / '),
        publishTo: (changes.publishTo ?? []).join(' / '),
        after: clean ? 'republish' : entry.sys.publishedVersion ? 'update, editor publishes (has pending edits)' : 'update (draft)',
      })

      if (!apply) continue

      for (const [field, v] of Object.entries(changes)) {
        entry.fields[field] = { ...(entry.fields[field] ?? {}), [locale]: v }
      }
      const updated = await client.entry.update({ entryId: id }, entry)
      if (clean) await client.entry.publish({ entryId: id }, updated)
    }

    if (skip + page.items.length >= page.total) break
  }
}

console.table(rows)
for (const p of problems) console.warn('!', p)
console.log(`\n${rows.length} entries ${apply ? 'updated' : 'would be updated'}; ${problems.length} problems.`)
if (!apply) console.log('Dry run. Re-run with --apply to write.')
console.log('Networks are not filled. Editors tick each Network in Publish to.')
