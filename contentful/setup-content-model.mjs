/**
 * Builds the Field Report v1 content model from field-report-contentful-plan.md.
 *
 * - Creates the target environment (from master) if it doesn't exist. Never runs against master.
 * - Idempotent: safe to re-run; existing content types are updated in place.
 * - Creates 5 content types: person, community, mdTake, takeaway, fieldReport.
 * - Configures the Field Report editor with 4 tabs: Basics, Standard Report,
 *   Newsletter Version, Variations.
 *
 * Usage: node scripts/setup-content-model.mjs
 * Reads CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN, CONTENTFUL_ENVIRONMENT from .env
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
  console.error('Refusing to run against the master environment. Set CONTENTFUL_ENVIRONMENT to a dev environment (e.g. field-report).')
  process.exit(1)
}

const client = createClient(
  { accessToken },
  { type: 'plain', defaults: { spaceId, environmentId } }
)

// ---------- field helpers ----------
const base = { localized: false, required: false, disabled: false, omitted: false, validations: [] }
const symbol = (id, name, extra = {}) => ({ ...base, id, name, type: 'Symbol', ...extra })
const longText = (id, name, extra = {}) => ({ ...base, id, name, type: 'Text', ...extra })
const richText = (id, name, extra = {}) => ({ ...base, id, name, type: 'RichText', ...extra })
const assetLink = (id, name, extra = {}) => ({ ...base, id, name, type: 'Link', linkType: 'Asset', ...extra })
const entryLink = (id, name, types, extra = {}) => ({
  ...base, id, name, type: 'Link', linkType: 'Entry',
  validations: [{ linkContentType: types }], ...extra,
})
const entryLinks = (id, name, types, extra = {}) => ({
  ...base, id, name, type: 'Array',
  items: { type: 'Link', linkType: 'Entry', validations: [{ linkContentType: types }] },
  ...extra,
})
const assetLinks = (id, name, extra = {}) => ({
  ...base, id, name, type: 'Array',
  items: { type: 'Link', linkType: 'Asset', validations: [] },
  ...extra,
})
const boolean = (id, name, extra = {}) => ({ ...base, id, name, type: 'Boolean', ...extra })
const integer = (id, name, extra = {}) => ({ ...base, id, name, type: 'Integer', ...extra })
const url = (id, name, extra = {}) => ({
  ...base, id, name, type: 'Symbol',
  validations: [{ regexp: { pattern: '^(https?://|/).+', flags: 'i' } }],
  ...extra,
})

const AUDIENCES = ['Public', 'Board Member', 'Board Chair', 'Delegate', 'Network Member', 'Council Chair']

const TAKEAWAY_RT_VALIDATIONS = [
  { enabledMarks: ['bold', 'italic'], message: 'Only bold and italic are allowed in takeaways.' },
  { enabledNodeTypes: ['ordered-list', 'list-item'], message: 'Takeaways are a numbered list.' },
]

// ---------- content type definitions ----------
const contentTypes = [
  {
    id: 'person',
    data: {
      name: 'Person',
      description: 'Authors, Membership Directors, Council Directors, and other attributed contributors.',
      displayField: 'name',
      fields: [
        symbol('name', 'Name', { required: true }),
        symbol('role', 'Role'),
        symbol('organization', 'Organization'),
        longText('biography', 'Biography'),
        assetLink('headshot', 'Headshot'),
        assetLink('hedcut', 'Hedcut'),
      ],
    },
  },
  {
    id: 'community',
    data: {
      name: 'Community',
      description: 'Board branding and boilerplate. Supplies the About the Board and Assemble section.',
      displayField: 'name',
      fields: [
        symbol('name', 'Name', { required: true }),
        symbol('shortName', 'Short name'),
        symbol('slug', 'Slug', { validations: [{ unique: true }] }),
        assetLink('logo', 'Logo'),
        longText('boilerplate', 'Boilerplate'),
      ],
    },
  },
  {
    id: 'mdTake',
    data: {
      name: 'MD Take',
      description: 'One attributed Story in Brief block. A Field Report can have more than one.',
      displayField: 'internalTitle',
      fields: [
        symbol('internalTitle', 'Internal title', { required: true }),
        entryLink('speaker', 'Speaker', ['person'], { required: true }),
        longText('fullTake', 'Full take', { required: true }),
        longText('newsletterTake', 'Newsletter take'),
      ],
    },
    controls: [
      { fieldId: 'fullTake', widgetId: 'multipleLine', settings: { helpText: 'Standard 2–4 sentence Story in Brief.' } },
      { fieldId: 'newsletterTake', widgetId: 'multipleLine', settings: { helpText: 'Optional compressed 1–3 sentence version, rendered at the end of the newsletter as “[First name]’s Notes.”' } },
    ],
  },
  {
    id: 'fieldReport',
    data: {
      name: 'Field Report',
      description: 'The canonical Field Report. Audience views (Public, Council glance, etc.) and social are derived — only the newsletter has its own compression fields.',
      displayField: 'internalTitle',
      fields: [
        // Basics
        symbol('internalTitle', 'Internal title', { required: true }),
        symbol('headline', 'Headline', { required: true }),
        symbol('slug', 'Slug', { validations: [{ unique: true }] }),
        symbol('sourceLine', 'Source line'),
        entryLink('primaryCommunity', 'Community', ['community'], { required: true }),
        entryLinks('additionalCommunities', 'Additional communities', ['community']),
        entryLink('author', 'Author', ['person']),
        assetLink('featuredImage', 'Featured image'),
        {
          ...base, id: 'availableTo', name: 'Available to', type: 'Array',
          items: { type: 'Symbol', validations: [{ in: AUDIENCES }] },
        },
        // Promotion. Drives the homepage stream and the Peer Intelligence
        // carousel on the website; nothing else reads these.
        boolean('featured', 'Featured'),
        integer('featuredRank', 'Featured rank', { validations: [{ range: { min: 1, max: 99 } }] }),
        // Standard report
        entryLinks('mdTakes', 'MD Takes', ['mdTake'], { validations: [{ size: { min: 1 } }] }),
        symbol('pickingUpSubhead', 'Picking Up the Story subhead'),
        richText('pickingUpBody', 'Picking Up the Story body'),
        assetLinks('charts', 'Charts'),
        longText('pullquote', 'Pullquote'),
        symbol('focusSubhead', 'Focus of the Discussion subhead'),
        richText('focusBody', 'Focus of the Discussion body'),
        richText('takeawaysStandard', 'Key Takeaways (Standard)', { validations: TAKEAWAY_RT_VALIDATIONS }),
        // Newsletter version
        longText('newsletterSetup', 'Newsletter setup'),
        richText('newsletterFocus', 'Newsletter focus'),
        assetLink('newsletterImage', 'Newsletter image'),
        richText('takeawaysNewsletter', 'Key Takeaways (Newsletter)', { validations: TAKEAWAY_RT_VALIDATIONS }),
        symbol('recordingUrl', 'Recording URL', {
          validations: [{ regexp: { pattern: '^https?://.+', flags: 'i' } }],
        }),
        // Variations
        longText('takeawaysShort', 'Key Takeaways (Short)'),
        longText('socialCopyOverride', 'Social copy override'),
      ],
    },
    controls: [
      { fieldId: 'slug', widgetId: 'slugEditor', settings: { trackingFieldId: 'headline' } },
      { fieldId: 'sourceLine', widgetId: 'singleLine', settings: { helpText: 'Final formatted source line, e.g. “From the March 2026 AEO Board discussion.”' } },
      { fieldId: 'availableTo', widgetId: 'checkbox', settings: { helpText: 'Public: headline + Picking Up the Story only. Other audiences: complete report. Council Chair: complete report plus the derived Key Takeaways at a Glance view.' } },
      { fieldId: 'featured', widgetId: 'boolean', settings: { helpText: 'Promote this report to the front of the homepage stream and the Peer Intelligence carousel. Gating is unaffected — a featured report that a visitor cannot open still shows only its card.' } },
      { fieldId: 'featuredRank', widgetId: 'numberEditor', settings: { helpText: 'Order among featured reports, 1 first. Leave blank and it falls in by date behind the ranked ones.' } },
      { fieldId: 'mdTakes', widgetId: 'entryLinksEditor', settings: { helpText: 'One or more Story in Brief blocks, in display order.' } },
      { fieldId: 'charts', widgetId: 'assetLinksEditor', settings: { helpText: 'Static chart images in narrative order. Use the asset title for the chart name and the asset description for alt text or a source note.' } },
      { fieldId: 'pullquote', widgetId: 'multipleLine', settings: { helpText: 'Anonymous peer quote. Pullquotes from confidential member discussions must be anonymized — no attribution field.' } },
      { fieldId: 'takeawaysStandard', widgetId: 'richTextEditor', settings: { helpText: 'Numbered list of three to six takeaways. Bold each action-oriented lead, then the 3–5 sentence explanation.' } },
      { fieldId: 'newsletterSetup', widgetId: 'multipleLine', settings: { helpText: 'Compressed Picking Up the Story for the newsletter.' } },
      { fieldId: 'newsletterImage', widgetId: 'assetLinkEditor', settings: { helpText: 'Single image for the newsletter — typically one of the charts already included in the standard Field Report.' } },
      { fieldId: 'takeawaysNewsletter', widgetId: 'richTextEditor', settings: { helpText: 'Numbered list of four compressed takeaways — bold lead, then no more than two sentences.' } },
      { fieldId: 'takeawaysShort', widgetId: 'multipleLine', settings: { helpText: 'Headline-only takeaways (one per line), used for the Council Chair at-a-glance view.' } },
      { fieldId: 'recordingUrl', widgetId: 'urlEditor', settings: { helpText: 'Newsletter-only recording link.' } },
      { fieldId: 'socialCopyOverride', widgetId: 'multipleLine', settings: { helpText: 'Leave blank to use Picking Up the Story as the source for social copy. Enter custom copy only when the report needs a more tailored social treatment.' } },
    ],
    tabs: [
      { groupId: 'basics', name: 'Basics', fields: ['internalTitle', 'headline', 'slug', 'sourceLine', 'primaryCommunity', 'additionalCommunities', 'author', 'featuredImage', 'availableTo', 'featured', 'featuredRank'] },
      { groupId: 'standardReport', name: 'Standard Report', fields: ['mdTakes', 'pickingUpSubhead', 'pickingUpBody', 'charts', 'pullquote', 'focusSubhead', 'focusBody', 'takeawaysStandard'] },
      { groupId: 'newsletterVersion', name: 'Newsletter Version', fields: ['newsletterSetup', 'newsletterFocus', 'newsletterImage', 'takeawaysNewsletter', 'recordingUrl'] },
      { groupId: 'variations', name: 'Variations', fields: ['takeawaysShort', 'socialCopyOverride'] },
    ],
  },
  {
    id: 'siteFeature',
    data: {
      name: 'Site Feature',
      description:
        'A promotional slot on the Assemble website that is not a Field Report — the Decision Intelligence Benchmark card on the homepage is the first one. Marketing owns these here rather than in wp-admin so the whole homepage is edited in one place.',
      displayField: 'internalTitle',
      fields: [
        symbol('internalTitle', 'Internal title', { required: true }),
        symbol('placement', 'Placement', {
          required: true,
          validations: [{ in: ['Homepage hero'] }],
        }),
        boolean('active', 'Active'),
        integer('rank', 'Rank', { validations: [{ range: { min: 1, max: 99 } }] }),
        // The lead card.
        symbol('kicker', 'Kicker'),
        symbol('badge', 'Badge'),
        symbol('headline', 'Headline', { required: true }),
        longText('blurb', 'Blurb'),
        symbol('ctaLabel', 'CTA label'),
        url('ctaUrl', 'CTA URL'),
        // The black tile beside it. Omit these and the card renders full width.
        symbol('asideLabel', 'Aside label'),
        longText('asideHeadline', 'Aside headline'),
        longText('asideBody', 'Aside body'),
        symbol('asideCtaLabel', 'Aside CTA label'),
        url('asideCtaUrl', 'Aside CTA URL'),
      ],
    },
    controls: [
      { fieldId: 'placement', widgetId: 'dropdown', settings: { helpText: 'Where on the site this feature appears. Only the homepage hero exists today.' } },
      { fieldId: 'active', widgetId: 'boolean', settings: { helpText: 'Unchecked hides the feature without unpublishing it.' } },
      { fieldId: 'rank', widgetId: 'numberEditor', settings: { helpText: 'Order when more than one feature is active in the same placement, 1 first.' } },
      { fieldId: 'kicker', widgetId: 'singleLine', settings: { helpText: 'Small label above the headline, e.g. “Featured · The Benchmark”.' } },
      { fieldId: 'badge', widgetId: 'singleLine', settings: { helpText: 'Second label on the opposite side of the card, e.g. “2026 edition”.' } },
      { fieldId: 'blurb', widgetId: 'multipleLine', settings: { helpText: 'Two or three sentences. This is the promise, not the summary.' } },
      { fieldId: 'ctaUrl', widgetId: 'singleLine', settings: { helpText: 'Absolute URL, or a site-relative path beginning with a slash.' } },
      { fieldId: 'asideLabel', widgetId: 'singleLine', settings: { helpText: 'Label on the black tile, e.g. “Why it exists”. Leave the whole aside blank to render the card full width.' } },
      { fieldId: 'asideHeadline', widgetId: 'multipleLine', settings: { helpText: 'The large line on the black tile.' } },
      { fieldId: 'asideCtaUrl', widgetId: 'singleLine', settings: { helpText: 'Absolute URL, or a site-relative path beginning with a slash.' } },
    ],
    tabs: [
      { groupId: 'placementTab', name: 'Placement', fields: ['internalTitle', 'placement', 'active', 'rank'] },
      { groupId: 'card', name: 'Card', fields: ['kicker', 'badge', 'headline', 'blurb', 'ctaLabel', 'ctaUrl'] },
      { groupId: 'aside', name: 'Aside', fields: ['asideLabel', 'asideHeadline', 'asideBody', 'asideCtaLabel', 'asideCtaUrl'] },
    ],
  },
]

// ---------- helpers ----------
const isNotFound = (e) => e?.name === 'NotFound' || /404|NotFound/.test(e?.message ?? '')
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function ensureEnvironment() {
  try {
    const env = await client.environment.get({ environmentId })
    console.log(`Environment "${environmentId}" already exists (status: ${env.sys.status.sys.id}).`)
    return
  } catch (e) {
    if (!isNotFound(e)) throw e
  }
  console.log(`Creating environment "${environmentId}" from master...`)
  await client.environment.createWithId(
    { environmentId, sourceEnvironmentId: 'master' },
    { name: environmentId }
  )
  // wait until ready
  for (let i = 0; i < 30; i++) {
    const env = await client.environment.get({ environmentId })
    if (env.sys.status.sys.id === 'ready') {
      console.log(`Environment "${environmentId}" is ready.`)
      return
    }
    await sleep(2000)
  }
  throw new Error(`Environment "${environmentId}" did not become ready in time.`)
}

async function upsertContentType({ id, data }) {
  let ct
  try {
    const existing = await client.contentType.get({ contentTypeId: id })
    ct = await client.contentType.update({ contentTypeId: id }, { ...existing, ...data })
    console.log(`Updated content type ${id}`)
  } catch (e) {
    if (!isNotFound(e)) throw e
    ct = await client.contentType.createWithId({ contentTypeId: id }, data)
    console.log(`Created content type ${id}`)
  }
  await client.contentType.publish({ contentTypeId: id }, ct)
  console.log(`Published content type ${id}`)
}

async function configureEditorInterface({ id, controls, tabs }) {
  if (!controls && !tabs) return
  const ei = await client.editorInterface.get({ contentTypeId: id })

  if (controls) {
    const byField = new Map((ei.controls ?? []).map((c) => [c.fieldId, c]))
    for (const c of controls) {
      byField.set(c.fieldId, { fieldId: c.fieldId, widgetNamespace: 'builtin', widgetId: c.widgetId, settings: c.settings })
    }
    ei.controls = [...byField.values()]
  }

  if (tabs) {
    ei.editorLayout = tabs.map((t) => ({
      groupId: t.groupId,
      name: t.name,
      items: t.fields.map((fieldId) => ({ fieldId })),
    }))
    ei.groupControls = tabs.map((t) => ({
      groupId: t.groupId,
      widgetNamespace: 'builtin',
      widgetId: 'topLevelTab',
    }))
  }

  await client.editorInterface.update({ contentTypeId: id }, ei)
  console.log(`Configured editor interface for ${id}`)
}

// ---------- run ----------
console.log(`Space: ${spaceId}  Environment: ${environmentId}\n`)
await ensureEnvironment()

for (const ct of contentTypes) {
  await upsertContentType(ct)
}
for (const ct of contentTypes) {
  await configureEditorInterface(ct)
}

console.log('\nDone. Content model:')
const list = await client.contentType.getMany({})
for (const ct of list.items) {
  console.log(`  - ${ct.name} (${ct.sys.id}): ${ct.fields.length} fields`)
}
