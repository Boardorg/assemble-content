/**
 * Builds the Field Report v1 content model from field-report-contentful-plan.md.
 *
 * Placement (1.9.0): Functional areas, Publish to and Publication date are added
 * beside the old Community links and Show on, which stay until both sites read the
 * new fields. migrate-places.mjs fills the new fields on existing entries.
 *
 * - Creates the target environment (from master) if it doesn't exist. Never runs against master.
 * - Idempotent: safe to re-run; existing content types are updated in place.
 * - Creates the content types below: person, community, topicCategory, topic, mdTake,
 *   fieldReport, playbookCallout, playbook, siteFeature.
 * - Configures the Field Report editor with 4 tabs: Basics, Standard Report,
 *   Newsletter Version, Variations.
 *
 * Usage: node scripts/setup-content-model.mjs
 * Reads CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN, CONTENTFUL_ENVIRONMENT from .env
 */
import 'dotenv/config'
import { createClient } from 'contentful-management'
import { AUDIENCES, BOARDS, PLACES } from './places.mjs'

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
const date = (id, name, extra = {}) => ({ ...base, id, name, type: 'Date', ...extra })
const checkboxes = (id, name, values, extra = {}) => ({
  ...base, id, name, type: 'Array',
  items: { type: 'Symbol', validations: [{ in: values }] },
  ...extra,
})
const url = (id, name, extra = {}) => ({
  ...base, id, name, type: 'Symbol',
  validations: [{ regexp: { pattern: '^(https?://|/).+', flags: 'i' } }],
  ...extra,
})

// The old "Show on" values. Superseded by Publish to; kept until both sites read it.
const CHANNELS = ['Member Center', 'Public website']

// Practice-area keys shared with the Member Center (Network practice_area) and the website (data-area).
const PRACTICE_AREAS = ['human-resources', 'marketing', 'manufacturing', 'technology', 'finance']

const TAKEAWAY_RT_VALIDATIONS = [
  { enabledMarks: ['bold', 'italic'], message: 'Only bold and italic are allowed in takeaways.' },
  { enabledNodeTypes: ['ordered-list', 'list-item'], message: 'Takeaways are a numbered list.' },
]

// A playbook body: chapters (H2), subsections (H3), lists, quotes, links, and
// Playbook Callout embeds. No H1 (the title is the H1), no tables or images inline.
// Kinds of box a writer can embed in a playbook body.
const CALLOUT_KINDS = ['Stat', 'Checklist', 'Checkpoint', 'Note', 'Table', 'Layers']

const PLAYBOOK_RT_VALIDATIONS = [
  {
    enabledNodeTypes: ['heading-2', 'heading-3', 'ordered-list', 'unordered-list', 'blockquote', 'hr', 'hyperlink', 'embedded-entry-block'],
    message: 'Use Heading 2 for chapters and Heading 3 for subsections; embed only Playbook Callouts.',
  },
  { nodes: { 'embedded-entry-block': [{ linkContentType: ['playbookCallout'], message: 'Only Playbook Callouts can be embedded.' }] } },
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
        symbol('practiceArea', 'Practice area', { validations: [{ in: PRACTICE_AREAS }] }),
        {
          ...base, id: 'memberCenterGroups', name: 'Member Center groups', type: 'Array',
          items: { type: 'Symbol', validations: [{ regexp: { pattern: '^[a-z0-9-]+$' } }] },
        },
        assetLink('logo', 'Logo'),
        longText('boilerplate', 'Boilerplate'),
      ],
    },
    controls: [
      { fieldId: 'practiceArea', widgetId: 'dropdown', settings: { helpText: 'The Assemble practice this community belongs to. Same keys as the Member Center Network practice_area and the website’s data-area: human-resources (Human Resources), manufacturing (Supply Chain & Manufacturing), marketing (Marketing and Communications), technology (Data and Technology), finance (Finance and Legal).' } },
      { fieldId: 'memberCenterGroups', widgetId: 'tagEditor', settings: { helpText: 'The Member Center BuddyPress group slug(s) for this community, e.g. learning-development. ESG uses two (esg-csr, esg-csr-sustainability). Leave blank for councils that have no Member Center group.' } },
    ],
  },
  {
    id: 'topicCategory',
    data: {
      name: 'Topic Category',
      description: 'A row of a Board’s Terrain Map (the Assemble Content Tag Taxonomy workbook). Each belongs to one community and groups that community’s topics.',
      displayField: 'name',
      fields: [
        symbol('name', 'Name', { required: true }),
        symbol('slug', 'Slug', { required: true, validations: [{ unique: true }, { regexp: { pattern: '^[a-z0-9-]+$' } }] }),
        entryLink('community', 'Community', ['community'], { required: true }),
        integer('position', 'Position', { validations: [{ range: { min: 1, max: 99 } }] }),
      ],
    },
    controls: [
      { fieldId: 'slug', widgetId: 'singleLine', settings: { helpText: 'Community short name plus the category, e.g. ldb-l-and-d-strategy. Permanent once used.' } },
      { fieldId: 'position', widgetId: 'numberEditor', settings: { helpText: 'Order of this row in the Board’s Terrain Map, 1 first.' } },
    ],
  },
  {
    id: 'topic',
    data: {
      name: 'Topic',
      description: 'Closed topic vocabulary from the Assemble Content Tag Taxonomy workbook, the same list the Member Center content-topic taxonomy uses. Topics answer “what is this about”; communities answer “who is it for”.',
      displayField: 'name',
      fields: [
        symbol('name', 'Name', { required: true }),
        symbol('slug', 'Slug', { required: true, validations: [{ unique: true }, { regexp: { pattern: '^[a-z0-9-]+$' } }] }),
        longText('description', 'What it covers'),
        entryLinks('categories', 'Categories', ['topicCategory'], { validations: [{ size: { min: 1 } }] }),
      ],
    },
    controls: [
      { fieldId: 'slug', widgetId: 'singleLine', settings: { helpText: 'Must match the Member Center content-topic slug exactly. Slugs are permanent: never rename one that content uses. Retire it and add a new topic instead.' } },
      { fieldId: 'categories', widgetId: 'entryLinksEditor', settings: { helpText: 'The Terrain Map row(s) this topic sits in. A topic shared by several Boards has one category per Board; it is never duplicated.' } },
    ],
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
        checkboxes('functionalAreas', 'Functional areas', BOARDS),
        entryLinks('topics', 'Topics', ['topic']),
        entryLink('author', 'Author', ['person']),
        assetLink('featuredImage', 'Featured image'),
        checkboxes('publishTo', 'Publish to', PLACES),
        checkboxes('availableTo', 'Who can read it', AUDIENCES),
        date('publishDate', 'Publication date'),
        // Superseded by Functional areas and Publish to; removed once both sites read those.
        entryLink('primaryCommunity', 'Community (old: use Functional areas)', ['community']),
        entryLinks('additionalCommunities', 'Additional communities (old: use Functional areas)', ['community']),
        checkboxes('showOn', 'Show on (old: use Publish to)', CHANNELS),
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
      { fieldId: 'functionalAreas', widgetId: 'checkbox', settings: { helpText: 'The Boards this report is about. Practice area, and so colour, comes from these. Who sees the report is set by Publish to, not here.' } },
      { fieldId: 'publishTo', widgetId: 'checkbox', settings: { helpText: 'Where this report appears: the Assemble Site and each Network, Board or Council. Untick a place to take the report off it. A Network only gets what is ticked for it.' } },
      { fieldId: 'availableTo', widgetId: 'checkbox', settings: { helpText: 'Who can read the full report in the places above. Board Chairs count as Board Members; Council Members count as Board Members but never see Board-Chair-only reports. On the Assemble Site, Public readers get the free-read version.' } },
      { fieldId: 'publishDate', widgetId: 'datePicker', settings: { format: 'dateonly', helpText: 'The date the report is filed under. Leave blank to use the discussion date in the source line, else the first publish date. Use Contentful scheduling to publish later.' } },
      { fieldId: 'primaryCommunity', widgetId: 'entryLinkEditor', settings: { helpText: 'Old field. Use Functional areas instead.' } },
      { fieldId: 'additionalCommunities', widgetId: 'entryLinksEditor', settings: { helpText: 'Old field. Use Functional areas instead.' } },
      { fieldId: 'showOn', widgetId: 'checkbox', settings: { helpText: 'Old field. Use Publish to instead; it wins when both are set.' } },
      { fieldId: 'topics', widgetId: 'entryLinksEditor', settings: { helpText: 'One to four topics, chosen from the topics offered in this report’s functional areas.' } },
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
      { groupId: 'basics', name: 'Basics', fields: ['internalTitle', 'headline', 'slug', 'sourceLine', 'functionalAreas', 'topics', 'author', 'featuredImage', 'publishTo', 'availableTo', 'publishDate', 'featured', 'featuredRank', 'primaryCommunity', 'additionalCommunities', 'showOn'] },
      { groupId: 'standardReport', name: 'Standard Report', fields: ['mdTakes', 'pickingUpSubhead', 'pickingUpBody', 'charts', 'pullquote', 'focusSubhead', 'focusBody', 'takeawaysStandard'] },
      { groupId: 'newsletterVersion', name: 'Newsletter Version', fields: ['newsletterSetup', 'newsletterFocus', 'newsletterImage', 'takeawaysNewsletter', 'recordingUrl'] },
      { groupId: 'variations', name: 'Variations', fields: ['takeawaysShort', 'socialCopyOverride'] },
    ],
  },
  {
    id: 'playbookCallout',
    data: {
      name: 'Playbook Callout',
      description: 'A box placed inside a playbook body (Insert → Embed entry): a pull-stat, a checklist, a “good looks like” checkpoint, a note to managers, a two-column table, or the “How the guidance layers” list. The site decides the look; the writer decides the position.',
      displayField: 'internalTitle',
      fields: [
        symbol('internalTitle', 'Internal title', { required: true }),
        symbol('kind', 'Kind', { required: true, validations: [{ in: CALLOUT_KINDS }] }),
        symbol('label', 'Label'),
        symbol('tag', 'Tag'),
        symbol('stat', 'Stat'),
        longText('text', 'Text'),
        { ...base, id: 'items', name: 'Items', type: 'Array', items: { type: 'Symbol', validations: [] } },
      ],
    },
    controls: [
      { fieldId: 'kind', widgetId: 'dropdown', settings: { helpText: 'Stat: a large figure and one line. Checklist: tick-box items. Checkpoint: “By day 30, good looks like” and a paragraph. Note: a bold lead and a sentence or two. Table: two columns. Layers: a short labelled list, e.g. Phase / Play / Principle.' } },
      { fieldId: 'label', widgetId: 'singleLine', settings: { helpText: 'The small heading. Checklist: e.g. Checklist. Checkpoint: e.g. By day 30, good looks like. Note: the bold lead, e.g. If the AEO lead reports to you: Table: the two column headings as First: Second. Layers: blank for “How the guidance layers”.' } },
      { fieldId: 'tag', widgetId: 'singleLine', settings: { helpText: 'Checklist only. A short tag beside the heading, e.g. Days 0–30.' } },
      { fieldId: 'stat', widgetId: 'singleLine', settings: { helpText: 'Stat only. The figure, e.g. 70%.' } },
      { fieldId: 'text', widgetId: 'multipleLine', settings: { helpText: 'Stat: the line under the figure. Checkpoint and Note: the paragraph.' } },
      { fieldId: 'items', widgetId: 'tagEditor', settings: { helpText: 'Checklist: one item each. Table and Layers: one row each, as Label: Value (split at the first colon).' } },
    ],
  },
  {
    id: 'playbook',
    data: {
      name: 'Playbook',
      description: 'A long-form guide built from peer intelligence. One body: each Heading 2 is a chapter (and a table of contents entry), each Heading 3 a subsection. A Heading 2 with a colon, e.g. “Days 0–30: Diagnose and Orient”, is a phase.',
      displayField: 'title',
      fields: [
        symbol('title', 'Title', { required: true }),
        symbol('slug', 'Slug', { required: true, validations: [{ unique: true }, { regexp: { pattern: '^[a-z0-9-]+$' } }] }),
        longText('dek', 'Dek'),
        symbol('edition', 'Edition'),
        checkboxes('functionalAreas', 'Functional areas', BOARDS),
        entryLinks('topics', 'Topics', ['topic']),
        entryLink('author', 'Author', ['person']),
        entryLink('editor', 'Editor', ['person']),
        assetLink('featuredImage', 'Featured image'),
        checkboxes('publishTo', 'Publish to', PLACES),
        checkboxes('availableTo', 'Who can read it', AUDIENCES),
        date('publishDate', 'Publication date'),
        // Superseded by Functional areas and Publish to; removed once both sites read those.
        entryLink('community', 'Community (old: use Functional areas)', ['community']),
        checkboxes('showOn', 'Show on (old: use Publish to)', CHANNELS),
        richText('body', 'Body', { validations: PLAYBOOK_RT_VALIDATIONS }),
      ],
    },
    controls: [
      { fieldId: 'slug', widgetId: 'slugEditor', settings: { trackingFieldId: 'title', helpText: 'The page address: /playbooks/<slug>/.' } },
      { fieldId: 'dek', widgetId: 'multipleLine', settings: { helpText: 'One or two sentences under the title. Also the description in search results and on cards.' } },
      { fieldId: 'edition', widgetId: 'singleLine', settings: { helpText: 'Shown under the dek, e.g. 2026 Edition.' } },
      { fieldId: 'functionalAreas', widgetId: 'checkbox', settings: { helpText: 'The Boards this playbook is about. They supply the breadcrumbs, the colour (by practice area), the About card and the featured Summit.' } },
      { fieldId: 'publishTo', widgetId: 'checkbox', settings: { helpText: 'Where this playbook appears: the Assemble Site and each Network, Board or Council.' } },
      { fieldId: 'publishDate', widgetId: 'datePicker', settings: { format: 'dateonly', helpText: 'The date the playbook is filed under. Leave blank to use the first publish date.' } },
      { fieldId: 'community', widgetId: 'entryLinkEditor', settings: { helpText: 'Old field. Use Functional areas instead.' } },
      { fieldId: 'topics', widgetId: 'entryLinksEditor', settings: { helpText: 'One to four topics from the functional areas’ categories.' } },
      { fieldId: 'author', widgetId: 'entryLinkEditor', settings: { helpText: 'Shown as “Written by”, with the bio at the foot of the playbook.' } },
      { fieldId: 'editor', widgetId: 'entryLinkEditor', settings: { helpText: 'Shown as “Edited by”. Optional.' } },
      { fieldId: 'showOn', widgetId: 'checkbox', settings: { helpText: 'Old field. Use Publish to instead; it wins when both are set.' } },
      { fieldId: 'availableTo', widgetId: 'checkbox', settings: { helpText: 'Who can read the complete playbook in the places above. On the Assemble Site, Public readers get the first chapter, then a prompt to join.' } },
      { fieldId: 'body', widgetId: 'richTextEditor', settings: { helpText: 'Heading 2 starts a chapter; Heading 3 a subsection. A line in italics straight after a Heading 3 is its subtitle. Pull-stats and the guidance list are Playbook Callout entries (Embed entry).' } },
    ],
    tabs: [
      { groupId: 'basics', name: 'Basics', fields: ['title', 'slug', 'dek', 'edition', 'functionalAreas', 'topics', 'author', 'editor', 'featuredImage', 'publishTo', 'availableTo', 'publishDate', 'community', 'showOn'] },
      { groupId: 'bodyTab', name: 'Body', fields: ['body'] },
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
