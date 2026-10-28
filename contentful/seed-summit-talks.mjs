/**
 * Seeds Summit libraries and their talks from a JSON file.
 *
 * - Each library, talk, speaker and author (a Person) is matched on slug or name,
 *   so re-running updates the same entries rather than creating more.
 * - Topics are looked up by their slug and must already exist; a missing one is
 *   reported and skipped.
 * - Library images are fetched from their URLs; transcript PDFs are uploaded from
 *   the folder given as the second argument.
 * - Entries are left as drafts unless --publish is passed, so an editor can check
 *   them in Contentful first.
 *
 * Usage: node contentful/seed-summit-talks.mjs <seed.json> <pdf-folder> [--publish]
 * The pdf folder is only read for talks that name a transcriptPdf.
 * Reads CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN, CONTENTFUL_ENVIRONMENT from .env
 */
import 'dotenv/config'
import { readFileSync } from 'node:fs'
import { basename, join } from 'node:path'
import { createClient } from 'contentful-management'

const spaceId = process.env.CONTENTFUL_SPACE_ID
const accessToken = process.env.CONTENTFUL_MANAGEMENT_TOKEN
const environmentId = process.env.CONTENTFUL_ENVIRONMENT
const [seedPath, pdfDir] = process.argv.slice(2).filter((a) => !a.startsWith('--'))
const publish = process.argv.includes('--publish')

if (!spaceId || !accessToken || !environmentId) {
  console.error('Missing CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN, or CONTENTFUL_ENVIRONMENT in .env')
  process.exit(1)
}
if (environmentId === 'master') {
  console.error('Refusing to run against the master environment.')
  process.exit(1)
}
if (!seedPath || !pdfDir) {
  console.error('Usage: node contentful/seed-summit-talks.mjs <seed.json> <pdf-folder> [--publish]')
  process.exit(1)
}

const client = createClient({ accessToken }, { type: 'plain', defaults: { spaceId, environmentId } })
const seed = JSON.parse(readFileSync(seedPath, 'utf8'))
const en = (v) => ({ 'en-US': v })
const link = (id, linkType = 'Entry') => ({ sys: { type: 'Link', linkType, id } })
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// Drop empty values, so a blank in the seed never clears a field an editor filled.
function localize(fields) {
  const out = {}
  for (const [key, value] of Object.entries(fields)) {
    if (value === '' || value === null || value === undefined || (Array.isArray(value) && !value.length)) continue
    out[key] = en(value)
  }
  return out
}

async function findEntry(contentType, field, value) {
  const res = await client.entry.getMany({ query: { content_type: contentType, [`fields.${field}`]: value, limit: 1 } })
  return res.items[0] ?? null
}

async function upsertEntry(contentType, field, value, fields) {
  const existing = await findEntry(contentType, field, value)
  let entry
  if (existing) {
    entry = await client.entry.update({ entryId: existing.sys.id }, { ...existing, fields: { ...existing.fields, ...localize(fields) } })
    console.log(`Updated ${contentType} ${value}`)
  } else {
    entry = await client.entry.create({ contentTypeId: contentType }, { fields: localize(fields) })
    console.log(`Created ${contentType} ${value}`)
  }
  if (publish) entry = await client.entry.publish({ entryId: entry.sys.id }, entry)
  return entry
}

async function processedAsset(asset) {
  for (let i = 0; i < 30; i++) {
    const current = await client.asset.get({ assetId: asset.sys.id })
    if (current.fields.file?.['en-US']?.url) return current
    await sleep(1000)
  }
  throw new Error(`Asset ${asset.sys.id} did not finish processing.`)
}

// Matched on title, since assets have no slug.
async function upsertAsset(title, description, file) {
  const found = await client.asset.getMany({ query: { 'fields.title': title, limit: 1 } })
  if (found.items[0]) {
    console.log(`Found asset ${title}`)
    return found.items[0]
  }
  let asset = await client.asset.create({}, { fields: { title: en(title), description: en(description), file: en(file) } })
  await client.asset.processForAllLocales({}, asset)
  asset = await processedAsset(asset)
  if (publish) asset = await client.asset.publish({ assetId: asset.sys.id }, asset)
  console.log(`Created asset ${title}`)
  return asset
}

async function uploadPdf(fileName) {
  const upload = await client.upload.create({}, { file: readFileSync(join(pdfDir, fileName)) })
  return upsertAsset(fileName, '', { contentType: 'application/pdf', fileName: basename(fileName), uploadFrom: link(upload.sys.id, 'Upload') })
}

const topicIds = new Map()
async function topicLinks(slugs) {
  const links = []
  for (const slug of slugs) {
    if (!topicIds.has(slug)) topicIds.set(slug, (await findEntry('topic', 'slug', slug))?.sys.id ?? null)
    const id = topicIds.get(slug)
    if (id) links.push(link(id))
    else console.warn(`  topic "${slug}" not found; skipped`)
  }
  return links
}

// ---------- run ----------
console.log(`Space: ${spaceId}  Environment: ${environmentId}  ${publish ? 'publishing' : 'drafts only'}\n`)

// Older seed files carry one `library`; newer ones a `libraries` list and a `library` slug per talk.
const libraries = seed.libraries ?? [seed.library]
const libraryIds = new Map()

for (const lib of libraries) {
  const image = lib.image?.url
    ? await upsertAsset(lib.image.title, lib.image.description, { contentType: 'image/jpeg', fileName: basename(new URL(lib.image.url).pathname), upload: lib.image.url })
    : null

  const library = await upsertEntry('summitLibrary', 'slug', lib.slug, {
    name: lib.name,
    slug: lib.slug,
    edition: lib.edition,
    city: lib.city,
    startDate: lib.startDate,
    endDate: lib.endDate,
    description: lib.description,
    image: image ? link(image.sys.id, 'Asset') : null,
  })
  libraryIds.set(lib.slug, library.sys.id)
}

for (const talk of seed.talks) {
  const libraryId = libraryIds.get(talk.library ?? libraries[0].slug)
  if (!libraryId) {
    console.warn(`  ${talk.slug}: library "${talk.library}" is not in this file; skipped`)
    continue
  }

  const speakers = []
  for (const person of talk.speakers) {
    speakers.push(link((await upsertEntry('person', 'name', person.name, person)).sys.id))
  }

  const author = talk.author ? await upsertEntry('person', 'name', talk.author.name, talk.author) : null
  const pdf = talk.transcriptPdf ? await uploadPdf(talk.transcriptPdf) : null

  await upsertEntry('summitTalk', 'slug', talk.slug, {
    title: talk.title,
    originalTitle: talk.originalTitle,
    slug: talk.slug,
    summit: link(libraryId),
    organization: talk.organization,
    dek: talk.dek,
    sessionStart: talk.sessionStart,
    topics: await topicLinks(talk.topics),
    speakers,
    speakerNote: talk.speakerNote,
    author: author ? link(author.sys.id) : null,
    publishTo: talk.publishTo ?? seed.publishTo,
    availableTo: talk.availableTo ?? seed.availableTo,
    videoUrl: talk.videoUrl,
    brief: talk.brief,
    keyTakeaways: talk.keyTakeaways,
    fullStory: talk.fullStory,
    transcript: talk.transcript,
    transcriptPdf: pdf ? link(pdf.sys.id, 'Asset') : null,
  })
}

console.log(`\nDone: ${libraries.length} libraries, ${seed.talks.length} talks${publish ? ', published' : ' as drafts'}.`)
