<?php
/**
 * Plugin Name:       Assemble Content
 * Description:       The Assemble Content feed. Syncs published content into hidden, gated post types and cached lists: Field Reports, playbooks, communities and Site Features from Contentful, and summits from Executive Platforms' summit API. Webhook-driven, with WP-CLI commands and per-audience rendering.
 * Version:           2.0.1
 * Requires PHP:      8.0
 * Author:            Assemble
 * Text Domain:       assemble-content
 *
 * Reads from its sources only — the Contentful token stored here cannot write to
 * Contentful, and the summit API is public. The sources are the truth; anything
 * this plugin writes into WordPress is disposable and can be rebuilt with
 * `wp assemble-content sync --all` and `wp assemble-content summits refresh`.
 *
 * Formerly `assemble-field-reports`. 2.0.0 renamed every `afr` name to `ac`:
 * AC_Migration renames stored data once, and AC_Compat keeps `AFR_*` classes and
 * `afr_*` hooks and constants working. Never activate this alongside
 * assemble-field-reports: the old class names collide.
 */

defined( 'ABSPATH' ) || exit;

define( 'AC_VERSION', '2.0.1' );
define( 'AC_FILE', __FILE__ );
define( 'AC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AC_URL', plugin_dir_url( __FILE__ ) );

/**
 * Where each class lives, under includes/:
 *
 *   abstracts/      what every source and content type is built on
 *   core/           the registry, sync runner, settings, queries, migration, compat
 *   sources/        one folder per source: Contentful (with its webhook, draft
 *                   preview and Rich Text) and WP-JSON
 *   shared/         audience gating and placement, shared by the gated types
 *   content-types/  one folder per content type
 *   admin/, cli/    screens and commands
 */
const AC_CLASSES = [
	'AC_Source'                => 'abstracts/abstract-class-ac-source.php',
	'AC_Content_Type'          => 'abstracts/abstract-class-ac-content-type.php',
	'AC_Post_Type_Content'     => 'abstracts/abstract-class-ac-post-type-content.php',
	'AC_Option_Content'        => 'abstracts/abstract-class-ac-option-content.php',
	'AC_Types'                 => 'core/class-ac-types.php',
	'AC_Sync'                  => 'core/class-ac-sync.php',
	'AC_Settings'              => 'core/class-ac-settings.php',
	'AC_Query'                 => 'core/class-ac-query.php',
	'AC_Migration'             => 'core/class-ac-migration.php',
	'AC_Compat'                => 'core/class-ac-compat.php',
	'AC_Contentful'            => 'sources/contentful/class-ac-contentful.php',
	'AC_Contentful_Webhook'    => 'sources/contentful/class-ac-contentful-webhook.php',
	'AC_Preview'               => 'sources/contentful/class-ac-preview.php',
	'AC_RichText'              => 'sources/contentful/class-ac-richtext.php',
	'AC_WP_JSON'               => 'sources/wp-json/class-ac-wp-json.php',
	'AC_Audience'              => 'shared/class-ac-audience.php',
	'AC_Bypass'                => 'shared/class-ac-bypass.php',
	'AC_Placement'             => 'shared/class-ac-placement.php',
	'AC_Post_Date'             => 'shared/class-ac-post-date.php',
	'AC_Generic_Post_Type'     => 'content-types/class-ac-generic-post-type.php',
	'AC_Field_Report_Type'     => 'content-types/field-reports/class-ac-field-report-type.php',
	'AC_Field_Report_Renderer' => 'content-types/field-reports/class-ac-field-report-renderer.php',
	'AC_Engagement'            => 'content-types/field-reports/class-ac-engagement.php',
	'AC_Listing'               => 'content-types/field-reports/class-ac-listing.php',
	'AC_Playbook_Type'         => 'content-types/playbooks/class-ac-playbook-type.php',
	'AC_Playbook_Renderer'     => 'content-types/playbooks/class-ac-playbook-renderer.php',
	'AC_Communities'           => 'content-types/communities/class-ac-communities.php',
	'AC_Site_Features'         => 'content-types/site-features/class-ac-site-features.php',
	'AC_Summits'               => 'content-types/summits/class-ac-summits.php',
	'AC_Summit_Extras'         => 'content-types/summits/class-ac-summit-extras.php',
	'AC_Summit_Editions'       => 'content-types/summits/class-ac-summit-editions.php',
	'AC_Settings_Page'         => 'admin/class-ac-settings-page.php',
	'AC_Sync_Report'           => 'admin/class-ac-sync-report.php',
	'AC_CLI'                   => 'cli/class-ac-cli.php',
	'AC_Summits_CLI'           => 'cli/class-ac-summits-cli.php',
];

spl_autoload_register(
	static function ( string $class ): void {
		if ( isset( AC_CLASSES[ $class ] ) ) {
			require_once AC_DIR . 'includes/' . AC_CLASSES[ $class ];
		}
	}
);

// The global wrappers for listing cards, and the old names' shims.
require_once AC_DIR . 'includes/content-types/field-reports/functions.php';
require_once AC_DIR . 'includes/core/class-ac-compat.php';

AC_Compat::init();
AC_Migration::init();

// Each registered type hooks itself up at `init` 1: post types, taxonomies,
// the_content, and type-specific hooks such as the summits cron.
AC_Types::init();
AC_Placement::init();
AC_Bypass::init();
AC_Contentful_Webhook::init();
AC_Preview::init();
AC_Settings_Page::init();
AC_Sync_Report::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'assemble-content', 'AC_CLI' );
	WP_CLI::add_command( 'assemble-content summits', 'AC_Summits_CLI' );
	// Old name, kept as an alias until launch.
	WP_CLI::add_command( 'field-report', 'AC_CLI' );
}

// Never fires where a site loads the plugin from code (VIP's plugin loader); those
// sites flush rewrite rules themselves.
register_activation_hook(
	__FILE__,
	static function (): void {
		AC_Types::register_post_types();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( AC_Summits::CRON );
		flush_rewrite_rules();
	}
);
