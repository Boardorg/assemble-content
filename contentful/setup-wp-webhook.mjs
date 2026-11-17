/**
 * Creates (or updates) the Contentful webhook that tells one WordPress site
 * when content changes. One webhook per site, matched by name.
 *
 * Webhooks are space-level in Contentful, so each one is filtered to fire only
 * for CONTENTFUL_ENVIRONMENT; publishing in `master` never reaches a site.
 * Idempotent: re-running updates the existing webhook in place.
 *
 * The secret must equal the site's AC_WEBHOOK_SECRET (or AFR_WEBHOOK_SECRET)
 * constant, or the site rejects every delivery with a 401.
 *
 * Run from this folder:
 *   node --env-file=../../.env.contentful setup-wp-webhook.mjs [--target=<key>] [--activate|--deactivate] [--dry-run]
 *
 * Targets: beta (default), assemble-production, mc-migration, mc-production.
 * A new production webhook is created inactive; pass --activate once the site
 * is ready. An existing webhook keeps its active state unless a flag is given.
 */
import 'dotenv/config'
import { createClient } from 'contentful-management'

const ENDPOINT = '/wp-json/assemble/v1/contentful'

// Secret env vars: the beta keeps the original AC_WEBHOOK_SECRET name.
const TARGETS = {
  beta: {
    name: 'WordPress Beta (assemblebeta)',
    host: 'assemblebeta.wpenginepowered.com',
    secretVar: 'AC_WEBHOOK_SECRET',
    production: false,
  },
  'assemble-production': {
    name: 'WordPress Production (assemble1)',
    host: 'theassemble.com',
    secretVar: 'AC_WEBHOOK_SECRET_ASSEMBLE_PRODUCTION',
    production: true,
  },
  'mc-migration': {
    name: 'Member Center sandbox (migration)',
    host: 'members-board-org-migration.go-vip.net',
    secretVar: 'AC_WEBHOOK_SECRET_MC_MIGRATION',
    production: false,
  },
  'mc-production': {
    name: 'Member Center production (members.board.org)',
    host: 'members.board.org',
    secretVar: 'AC_WEBHOOK_SECRET_MC_PRODUCTION',
    production: true,
  },
}

const args = process.argv.slice(2)
const flag = (f) => args.includes(f)
const targetKey = (args.find((a) => a.startsWith('--target=')) || '--target=beta').split('=')[1]
const target = TARGETS[targetKey]
if (!target) throw new Error(`Unknown --target=${targetKey}. Use one of: ${Object.keys(TARGETS).join(', ')}`)
if (flag('--activate') && flag('--deactivate')) throw new Error('Pass --activate or --deactivate, not both.')
const dryRun = flag('--dry-run')

const spaceId = process.env.CONTENTFUL_SPACE_ID
const environmentId = process.env.CONTENTFUL_ENVIRONMENT
const accessToken = process.env.CONTENTFUL_MANAGEMENT_TOKEN
// AFR_WEBHOOK_SECRET is the beta's name before 2.0.0.
const secret = process.env[target.secretVar] || (targetKey === 'beta' ? process.env.AFR_WEBHOOK_SECRET : '')
// The beta's URL could always be overridden; it must still be on the beta host.
const url = (targetKey === 'beta' && process.env.WP_BETA_WEBHOOK_URL) || `https://${target.host}${ENDPOINT}`

if (!spaceId || !accessToken || !environmentId) throw new Error('Missing CONTENTFUL_* vars in .env')
if (!secret && !dryRun) throw new Error(`Missing ${target.secretVar} in .env`)
if (environmentId === 'master') throw new Error('CONTENTFUL_ENVIRONMENT is master — refusing to run.')
if (new URL(url).host !== target.host) throw new Error(`Webhook URL ${url} is not on ${target.host}`)

const client = createClient({ accessToken }, { type: 'plain', defaults: { spaceId, environmentId } })

const existing = await client.webhook.getMany({ spaceId, query: { limit: 100 } })
console.log(`Existing webhooks (${existing.items.length}): ${existing.items.map((w) => w.name).join(', ') || '(none)'}`)

const current = existing.items.find((w) => w.name === target.name)
// Another webhook already pointing at this site would double every sync.
const clash = existing.items.find((w) => w !== current && new URL(w.url).host === target.host)
if (clash) throw new Error(`"${clash.name}" already points at ${target.host}. Rename or remove it first.`)

let active
if (flag('--activate')) active = true
else if (flag('--deactivate')) active = false
else active = current ? current.active : !target.production

const definition = {
  name: target.name,
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
  // Only this environment; master must never reach a site.
  filters: [{ equals: [{ doc: 'sys.environment.sys.id' }, environmentId] }],
  headers: [{ key: 'X-AC-Secret', value: secret, secret: true }],
  active,
}

if (dryRun) {
  console.log(`\nDry run: would ${current ? `update "${target.name}" (${current.sys.id})` : `create "${target.name}"`}`)
  console.log(`url:         ${url}`)
  console.log(`active:      ${active}`)
  console.log(`environment: filtered to ${environmentId}`)
  console.log(`secret:      ${secret ? `from ${target.secretVar}` : `MISSING (${target.secretVar})`}`)
  process.exit(0)
}

let hook
if (current) {
  console.log(`\nUpdating "${target.name}" (${current.sys.id})...`)
  hook = await client.webhook.update({ spaceId, webhookDefinitionId: current.sys.id }, { ...current, ...definition })
} else {
  console.log(`\nCreating "${target.name}"...`)
  hook = await client.webhook.create({ spaceId }, definition)
}

console.log('\n--- Webhook ---')
console.log(`id:          ${hook.sys.id}`)
console.log(`url:         ${hook.url}`)
console.log(`active:      ${hook.active}`)
console.log(`topics:      ${hook.topics.join(', ')}`)
console.log(`environment: filtered to ${environmentId}`)
console.log(`secret hdr:  X-AC-Secret (stored secret, not readable back)`)
