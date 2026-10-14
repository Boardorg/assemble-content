// The values editors tick in "Functional areas" and "Publish to".
//
// Each Board name must match its Community entry's `name` exactly: the plugin
// finds the Board's logo, boilerplate and practice area by that name, and the
// Member Center matches each group's "Contentful place" setting against it.
// Names are permanent once content uses them, like topic slugs.

export const ASSEMBLE_SITE = 'Assemble Site'

export const NETWORKS = [
  'HR Network',
  'Supply Chain & Manufacturing Network',
  'Marketing & Communications Network',
  'Data & Technology Network',
  'Finance & Legal Network',
]

export const BOARDS = [
  'AEO Board',
  'Community Board',
  'Culture & Experience Board',
  'Data & AI Strategy Board',
  'Data Privacy Board',
  'DEI Board',
  'Ecommerce Board',
  'ESG & CSR Board',
  'Learning & Development Board',
  'Manufacturing Board',
  'SocialMedia.org',
  'SocialMedia.org Health',
  'Supply Chain Board',
  'Talent Marketing Board',
]

export const COUNCILS = [
  'Digital Marketing & Communications Council',
  'Workforce Experience Council',
]

export const PLACES = [ASSEMBLE_SITE, ...NETWORKS, ...BOARDS, ...COUNCILS]

// "Who can read it". Existing values keep their meaning; Council Member is new.
export const AUDIENCES = ['Public', 'Network Member', 'Board Member', 'Board Chair', 'Council Member', 'Council Chair']
