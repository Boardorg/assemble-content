/**
 * Lists Contentful Delivery API keys for the space and ensures one exists that
 * can read the `field-report` environment. Prints the CDA + CPA tokens.
 *
 * The WordPress plugin needs a *Delivery* (read-only) token — never the CMA
 * token, which can write. Run: node scripts/setup-delivery-token.mjs
 */
import 'dotenv/config'
import { createClient } from 'contentful-management'

const spaceId = process.env.CONTENTFUL_SPACE_ID
const environmentId = process.env.CONTENTFUL_ENVIRONMENT
const accessToken = process.env.CONTENTFUL_MANAGEMENT_TOKEN
const KEY_NAME = 'WordPress Beta (assemblebeta)'

if (!spaceId || !accessToken || !environmentId) {
  throw new Error('Missing CONTENTFUL_* vars in .env')
}
if (environmentId === 'master') {
  throw new Error('CONTENTFUL_ENVIRONMENT is master — refusing to run.')
}

const client = createClient({ accessToken }, { type: 'plain', defaults: { spaceId, environmentId } })

const envLink = { sys: { type: 'Link', linkType: 'Environment', id: environmentId } }

const keys = await client.apiKey.getMany({ spaceId, query: { limit: 100 } })
console.log(`Existing delivery API keys (${keys.items.length}):`)
for (const k of keys.items) {
  const envs = (k.environments ?? []).map((e) => e.sys.id).join(', ') || '(none)'
  console.log(`  - ${k.name}  [envs: ${envs}]`)
}

let key = keys.items.find((k) => k.name === KEY_NAME)

if (!key) {
  console.log(`\nCreating key "${KEY_NAME}" scoped to ${environmentId}...`)
  key = await client.apiKey.create(
    { spaceId },
    {
      name: KEY_NAME,
      description: 'Read-only Delivery access for the Assemble beta WordPress plugin.',
      environments: [envLink],
    }
  )
  console.log('Created.')
} else if (!(key.environments ?? []).some((e) => e.sys.id === environmentId)) {
  console.log(`\nAdding ${environmentId} to existing key "${KEY_NAME}"...`)
  key = await client.apiKey.update(
    { spaceId, apiKeyId: key.sys.id },
    { ...key, environments: [...(key.environments ?? []), envLink] }
  )
  console.log('Updated.')
} else {
  console.log(`\nKey "${KEY_NAME}" already scoped to ${environmentId}.`)
}

console.log('\n--- Values for the WordPress plugin ---')
console.log(`space:        ${spaceId}`)
console.log(`environment:  ${environmentId}`)
console.log(`CDA token:    ${key.accessToken}`)

if (key.preview_api_key?.sys?.id) {
  const previewKey = await client.previewApiKey.get({
    spaceId,
    previewApiKeyId: key.preview_api_key.sys.id,
  })
  console.log(`CPA token:    ${previewKey.accessToken}`)
}
