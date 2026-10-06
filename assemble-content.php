<?php
/**
 * Plugin Name:       Assemble Content
 * Description:       The Assemble Content feed. Syncs published Contentful entries (Field Reports today) into hidden, gated post types. Webhook-driven, with WP-CLI commands and per-audience rendering.
 * Version:           1.0.0
 * Requires PHP:      8.0
 * Author:            Assemble
 * Text Domain:       assemble-content
 *
 * Reads from the Contentful Delivery API only — the token stored here cannot write
 * to Contentful. Contentful is the source of truth; anything this plugin writes into
 * WordPress is disposable and can be rebuilt with `wp assemble-content sync --all`.
 *
 * Formerly `assemble-field-reports`. Internal names (afr_* options, filters and meta,
 * AFR_* classes) are unchanged on purpose, so the switch-over needs no data migration.
 * Never activate this alongside assemble-field-reports: the class names collide.
 */

defined( 'ABSPATH' ) || exit;

define( 'AFR_VERSION', '1.0.0' );
define( 'AFR_FILE', __FILE__ );
define( 'AFR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFR_URL', plugin_dir_url( __FILE__ ) );

require_once AFR_DIR . 'includes/class-afr-settings.php';
require_once AFR_DIR . 'includes/class-afr-cpt.php';
require_once AFR_DIR . 'includes/class-afr-contentful.php';
require_once AFR_DIR . 'includes/class-afr-richtext.php';
require_once AFR_DIR . 'includes/class-afr-bypass.php';
require_once AFR_DIR . 'includes/class-afr-audience.php';
require_once AFR_DIR . 'includes/class-afr-engagement.php';
require_once AFR_DIR . 'includes/class-afr-renderer.php';
require_once AFR_DIR . 'includes/class-afr-listing.php';
require_once AFR_DIR . 'includes/class-afr-features.php';
require_once AFR_DIR . 'includes/class-afr-query.php';
require_once AFR_DIR . 'includes/class-afr-sync.php';
require_once AFR_DIR . 'includes/class-afr-rest.php';

AFR_CPT::init();
AFR_Bypass::init();
AFR_Renderer::init();
AFR_Listing::init();
AFR_REST::init();
AFR_Settings::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AFR_DIR . 'includes/class-afr-cli.php';
	WP_CLI::add_command( 'assemble-content', 'AFR_CLI' );
	// Old name, kept as an alias until launch.
	WP_CLI::add_command( 'field-report', 'AFR_CLI' );
}

register_activation_hook( __FILE__, static function (): void {
	// Same order as the init hooks — see the note in AFR_CPT::init().
	AFR_CPT::register_taxonomy();
	AFR_CPT::register_post_type();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, static function (): void {
	flush_rewrite_rules();
} );
