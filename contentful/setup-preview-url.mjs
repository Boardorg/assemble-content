// Registers (or updates) a Contentful "Content preview" platform that opens
// Field Reports in a WordPress site's draft preview (assemble-content 1.4.0+):
//   <site>/?afr_preview={entry.sys.id}
//
// Space-level setting; it changes no entries. Idempotent: matched by name.
//
//   node --env-file=../../.env.contentful setup-preview-url.mjs            # the beta
//   node --env-file=../../.env.contentful setup-preview-url.mjs --dry-run
//   node --env-file=../../.env.contentful setup-preview-url.mjs --production   # at launch only
//
// Env: CONTENTFUL_SPACE_ID, CONTENTFUL_MANAGEMENT_TOKEN.

const BETA = { name: 'WordPress Beta', site: 'https://assemblebeta.wpenginepowered.com' };
const PRODUCTION = { name: 'WordPress Production', site: 'https://theassemble.com' };
const CONTENT_TYPE = 'fieldReport';

const args = process.argv.slice(2);
const dryRun = args.includes('--dry-run');
const target = args.includes('--production') ? PRODUCTION : BETA;

const space = process.env.CONTENTFUL_SPACE_ID;
const token = process.env.CONTENTFUL_MANAGEMENT_TOKEN;
if (!space || !token) {
	console.error('CONTENTFUL_SPACE_ID and CONTENTFUL_MANAGEMENT_TOKEN are required.');
	process.exit(1);
}

const base = `https://api.contentful.com/spaces/${encodeURIComponent(space)}/preview_environments`;
const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/vnd.contentful.management.v1+json' };

async function call(url, options = {}) {
	const res = await fetch(url, { ...options, headers: { ...headers, ...(options.headers || {}) } });
	const body = await res.json().catch(() => ({}));
	if (!res.ok) {
		throw new Error(`HTTP ${res.status}: ${body.message || JSON.stringify(body)}`);
	}
	return body;
}

const url = `${target.site}/?afr_preview={entry.sys.id}`;
const wanted = {
	name: target.name,
	description: 'Opens the latest draft on the WordPress site (admins, or a share link from the preview page).',
	configurations: [{ contentType: CONTENT_TYPE, url, enabled: true, example: false }],
};

const list = await call(`${base}?limit=100`);
const existing = (list.items || []).find((p) => p.name === target.name);

console.log(`Space has ${list.items?.length ?? 0} preview platform(s): ${(list.items || []).map((p) => p.name).join(', ') || 'none'}`);
console.log(`${existing ? 'Update' : 'Create'} "${target.name}": ${CONTENT_TYPE} -> ${url}`);

if (dryRun) {
	console.log('Dry run: nothing written.');
	process.exit(0);
}

const saved = existing
	? await call(`${base}/${existing.sys.id}`, {
			method: 'PUT',
			headers: { 'X-Contentful-Version': String(existing.sys.version) },
			body: JSON.stringify({ ...existing, ...wanted, configurations: [...(existing.configurations || []).filter((c) => c.contentType !== CONTENT_TYPE), ...wanted.configurations] }),
		})
	: await call(base, { method: 'POST', body: JSON.stringify(wanted) });

console.log(`Saved "${saved.name}" (version ${saved.sys.version}).`);
