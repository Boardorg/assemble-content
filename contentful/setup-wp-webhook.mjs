/**
 * Creates (or updates) the Contentful webhook that notifies the WordPress beta
 * when content changes.
 *
 * Webhooks are space-level in Contentful, so this one is filtered to fire only
 * for the `field-report` environment — publishing in `master` will not reach the
 * beta. Idempotent: re-running updates the existing webhook in place.
 *
 * Run: node scripts/setup-wp-webhook.mjs
 */
import 'dotenv/config'
import { createClient } from 'contentful-management'

const spaceId = process.env.CONTENTFUL_SPACE_ID
const environmentId = process.env.CONTENTFUL_ENVIRONMENT
const accessToken = process.env.CONTENTFUL_MANAGEMENT_TOKEN
const secret = process.env.AFR_WEBHOOK_SECRET
const url = process.env.WP_BETA_WEBHOOK_URL

const NAME = 'WordPress Beta (assemblebeta)'

if (!spaceId || !accessToken || !environmentId) throw new Error('Missing CONTENTFUL_* vars in .env')
if (!secret) throw new Error('Missing AFR_WEBHOOK_SECRET in .env')
if (!url) throw new Error('Missing WP_BETA_WEBHOOK_URL in .env')
if (environmentId === 'master') throw new Error('CONTENTFUL_ENVIRONMENT is master — refusing to run.')

// Guard against ever pointing this at production by accident.
if (!url.includes('assemblebeta')) {
  throw new Error(`WP_BETA_WEBHOOK_URL does not look like the beta host: ${url}`)
}

const client = createClient({ accessToken }, { type: 'plain', defaults: { spaceId, environmentId } })

const definition = {
  name: NAME,
  url,
  topics: [
    'Entry.publish',
    'Entry.unpublish',
    'Entry.delete',
    'Entry.archive',
    'Asset.publish',
    'Asset.unpublish',
    'Asset.delete',
  ],
  // Only this environment; master must never reach the beta.
  filters: [{ equals: [{ doc: 'sys.environment.sys.id' }, environmentId] }],
  headers: [{ key: 'X-AFR-Secret', value: secret, secret: true }],
  active: true,
}

const existing = await client.webhook.getMany({ spaceId, query: { limit: 100 } })
console.log(`Existing webhooks (${existing.items.length}): ${existing.items.map((w) => w.name).join(', ') || '(none)'}`)

const current = existing.items.find((w) => w.name === NAME)

let hook
if (current) {
  console.log(`\nUpdating "${NAME}" (${current.sys.id})...`)
  hook = await client.webhook.update({ spaceId, webhookDefinitionId: current.sys.id }, { ...current, ...definition })
} else {
  console.log(`\nCreating "${NAME}"...`)
  hook = await client.webhook.create({ spaceId }, definition)
}

console.log('\n--- Webhook ---')
console.log(`id:          ${hook.sys.id}`)
console.log(`url:         ${hook.url}`)
console.log(`active:      ${hook.active}`)
console.log(`topics:      ${hook.topics.join(', ')}`)
console.log(`environment: filtered to ${environmentId}`)
console.log(`secret hdr:  X-AFR-Secret (stored secret, not readable back)`)
