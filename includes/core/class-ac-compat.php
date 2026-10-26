<?php
/**
 * Keeps code written against the plugin before 2.0.0 (`AFR_*` classes, `afr_*`
 * hooks and constants) working while sites move to the `ac` names.
 *
 *   - Classes: an `AFR_*` name autoloads as an alias of its `AC_*` class.
 *   - Hooks: a callback on an `afr_*` hook still runs, through the deprecated-hook
 *     API, so WP_DEBUG reports each one still in use.
 *   - Constants: AC_Settings reads each `AC_*` constant, else its `AFR_*` name.
 *   - Query args: `?afr_preview=`, `?afr_as=` and the rest are read as their
 *     `ac_*` names, so Contentful's stored preview URL and shared links keep
 *     working.
 *   - Global functions: afr_community_accent() and afr_community_label().
 *
 * Stored data is renamed once, by AC_Migration; nothing here reads old keys.
 * The webhook's `X-AFR-Secret` header and pre-2.0.0 preview signatures are
 * accepted where they are checked (AC_Contentful_Webhook, AC_Preview).
 */

defined( 'ABSPATH' ) || exit;

class AC_Compat {

	public const VERSION = '2.0.0';

	/** Old class => new class. */
	public const CLASSES = [
		'AFR_Audience'          => 'AC_Audience',
		'AFR_Bypass'            => 'AC_Bypass',
		'AFR_CLI'               => 'AC_CLI',
		'AFR_Communities'       => 'AC_Communities',
		'AFR_Contentful'        => 'AC_Contentful',
		'AFR_CPT'               => 'AC_Field_Report_Type',
		'AFR_Engagement'        => 'AC_Engagement',
		'AFR_Features'          => 'AC_Site_Features',
		'AFR_Field_Report_Type' => 'AC_Field_Report_Type',
		'AFR_Listing'           => 'AC_Listing',
		'AFR_Placement'         => 'AC_Placement',
		'AFR_Playbook'          => 'AC_Playbook_Renderer',
		'AFR_Playbook_Type'     => 'AC_Playbook_Type',
		'AFR_Post_Date'         => 'AC_Post_Date',
		'AFR_Preview'           => 'AC_Preview',
		'AFR_Query'             => 'AC_Query',
		'AFR_Renderer'          => 'AC_Field_Report_Renderer',
		'AFR_REST'              => 'AC_Contentful_Webhook',
		'AFR_RichText'          => 'AC_RichText',
		'AFR_Settings'          => 'AC_Settings',
		'AFR_Summit_Editions'   => 'AC_Summit_Editions',
		'AFR_Summit_Extras'     => 'AC_Summit_Extras',
		'AFR_Summits'           => 'AC_Summits',
		'AFR_Summits_CLI'       => 'AC_Summits_CLI',
		'AFR_Sync'              => 'AC_Sync',
		'AFR_Types'             => 'AC_Types',
	];

	/** Filters, old => new. */
	public const FILTERS = [
		'afr_accent_color'            => 'ac_accent_color',
		'afr_audience_roles'          => 'ac_audience_roles',
		'afr_bypass_mode'             => 'ac_bypass_mode',
		'afr_community_label_limit'   => 'ac_community_label_limit',
		'afr_content_types'           => 'ac_content_types',
		'afr_current_audiences'       => 'ac_current_audiences',
		'afr_engagement_html'         => 'ac_engagement_html',
		'afr_engagement_links'        => 'ac_engagement_links',
		'afr_filter_the_content'      => 'ac_filter_the_content',
		'afr_on_site'                 => 'ac_on_site',
		'afr_placeholder_image_id'    => 'ac_placeholder_image_id',
		'afr_placement_warnings'      => 'ac_placement_warnings',
		'afr_preview_capability'      => 'ac_preview_capability',
		'afr_public_full_text'        => 'ac_public_full_text',
		'afr_public_setup_paragraphs' => 'ac_public_setup_paragraphs',
		'afr_render_own_headline'     => 'ac_render_own_headline',
		'afr_site_channel'            => 'ac_site_channel',
		'afr_summit_asset_url'        => 'ac_summit_asset_url',
		'afr_summit_edition_codes'    => 'ac_summit_edition_codes',
		'afr_summit_extras'           => 'ac_summit_extras',
		'afr_summit_url'              => 'ac_summit_url',
		'afr_summits'                 => 'ac_summits',
		'afr_topic_taxonomy'          => 'ac_topic_taxonomy',
		'afr_view'                    => 'ac_view',
	];

	/**
	 * Actions, old => new. `afr_render_preview` is bridged only on a preview
	 * request that has a callback for it, because AC_Preview prints its own page
	 * when `ac_render_preview` has none.
	 */
	public const ACTIONS = [
		'afr_synced' => 'ac_synced',
	];

	/** Query args, old => new. */
	public const QUERY_ARGS = [
		'afr_preview' => 'ac_preview',
		'afr_view'    => 'ac_view',
		'afr_exp'     => 'ac_exp',
		'afr_sig'     => 'ac_sig',
		'afr_as'      => 'ac_as',
		'afr_bypass'  => 'ac_bypass',
	];

	public static function init(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );

		foreach ( self::FILTERS as $old => $new ) {
			add_filter(
				$new,
				static fn( ...$args ) => apply_filters_deprecated( $old, $args, self::VERSION, $new ),
				0,
				10
			);
		}

		foreach ( self::ACTIONS as $old => $new ) {
			add_action(
				$new,
				static function ( ...$args ) use ( $old, $new ): void {
					do_action_deprecated( $old, $args, self::VERSION, $new );
				},
				0,
				10
			);
		}

		// Before AC_Preview (0) answers the request.
		add_action( 'template_redirect', [ self::class, 'bridge_render_preview' ], -1 );

		self::map_query_args();
	}

	/** An `AFR_*` class becomes an alias of its `AC_*` class the first time it is used. */
	public static function autoload( string $class ): void {
		if ( isset( self::CLASSES[ $class ] ) && class_exists( self::CLASSES[ $class ] ) ) {
			class_alias( self::CLASSES[ $class ], $class );
		}
	}

	/**
	 * A configuration constant's value: the `AC_*` constant, else its `AFR_*` name.
	 *
	 * @param string $name An `AC_*` constant name.
	 */
	public static function constant( string $name ): string {
		foreach ( [ $name, 'AFR_' . substr( $name, 3 ) ] as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return '';
	}

	/** Read an old query arg as its new name, unless the new one is also present. */
	private static function map_query_args(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- renames read-only args; each is authorised where it is read.
		foreach ( self::QUERY_ARGS as $old => $new ) {
			if ( isset( $_GET[ $old ] ) && ! isset( $_GET[ $new ] ) ) {
				$_GET[ $new ] = $_GET[ $old ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- copied as-is; sanitised where it is read.
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function bridge_render_preview(): void {
		if ( ! has_action( 'afr_render_preview' ) ) {
			return;
		}

		add_action(
			'ac_render_preview',
			static function ( ...$args ): void {
				do_action_deprecated( 'afr_render_preview', $args, self::VERSION, 'ac_render_preview' );
			},
			0,
			10
		);
	}
}

if ( ! function_exists( 'afr_community_accent' ) ) {
	/** @deprecated 2.0.0 Use ac_community_accent(). */
	function afr_community_accent( ?int $post_id = null ): string {
		return ac_community_accent( $post_id );
	}
}

if ( ! function_exists( 'afr_community_label' ) ) {
	/** @deprecated 2.0.0 Use ac_community_label(). */
	function afr_community_label( ?int $post_id = null ): string {
		return ac_community_label( $post_id );
	}
}
