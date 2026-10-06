<?php
/**
 * The `field_report` post type and its community taxonomy.
 *
 * `show_in_rest` is deliberately false: reports carry member-gated content, and a
 * REST-exposed post type would serve `content.rendered` to anonymous requests,
 * bypassing the audience gate entirely.
 */

defined( 'ABSPATH' ) || exit;

class AFR_CPT {

	public const POST_TYPE = 'field_report';
	public const TAXONOMY  = 'field_report_community';

	/** Meta keys. Underscore-prefixed keys stay hidden from the meta box UI. */
	public const META_ENTRY_ID   = '_afr_entry_id';
	public const META_REVISION   = '_afr_revision';
	public const META_UPDATED_AT = '_afr_updated_at';
	public const META_DATA       = '_afr_data';
	public const META_AUDIENCES  = '_afr_available_to';
	public const META_SOURCE     = '_afr_source_line';
	public const META_PULLQUOTE  = '_afr_pullquote';
	public const META_SHORT      = '_afr_takeaways_short';
	public const META_SYNCED_AT  = '_afr_synced_at';

	/**
	 * Promotion, mirrored from Contentful's `featured` / `featuredRank`.
	 *
	 * META_FEATURED is stored as '1' or '0' rather than being deleted when false,
	 * so a meta_query can sort on it without a NOT EXISTS branch.
	 */
	public const META_FEATURED      = '_afr_featured';
	public const META_FEATURED_RANK = '_afr_featured_rank';

	public static function init(): void {
		// Taxonomy first, deliberately. Rewrite rules are matched in registration
		// order, and the post type's `field-reports/[^/]+/([^/]+)` attachment rule
		// would otherwise swallow `field-reports/community/<slug>` and 404 it.
		add_action( 'init', [ self::class, 'register_taxonomy' ], 9 );
		add_action( 'init', [ self::class, 'register_post_type' ], 10 );
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'editor_notice' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ self::class, 'column_content' ], 10, 2 );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			[
				'labels'          => [
					'name'          => 'Field Reports',
					'singular_name' => 'Field Report',
					'menu_name'     => 'Field Reports',
					'all_items'     => 'All Field Reports',
					'search_items'  => 'Search Field Reports',
					'not_found'     => 'No Field Reports synced yet.',
				],
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => 'dashicons-analytics',
				'menu_position'   => 21,
				'has_archive'     => 'field-reports',
				'rewrite'         => [ 'slug' => 'field-reports', 'with_front' => false ],
				'supports'        => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				// Contentful owns the content; nobody should be authoring these in WP.
				'capabilities'    => [ 'create_posts' => 'do_not_allow' ],
			]
		);
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			[ self::POST_TYPE ],
			[
				'labels'            => [
					'name'          => 'Communities',
					'singular_name' => 'Community',
				],
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => false,
				'rewrite'           => [ 'slug' => 'field-reports/community', 'with_front' => false ],
			]
		);
	}

	// ------------------------------------------------------------- admin extras

	public static function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( $key === 'title' ) {
				$out['afr_audiences'] = 'Available to';
			}
		}
		$out['afr_synced'] = 'Last synced';

		return $out;
	}

	public static function column_content( string $column, int $post_id ): void {
		if ( $column === 'afr_audiences' ) {
			$aud = get_post_meta( $post_id, self::META_AUDIENCES, true );
			echo $aud ? esc_html( implode( ', ', (array) $aud ) ) : '<em>none</em>';
		}

		if ( $column === 'afr_synced' ) {
			$at = get_post_meta( $post_id, self::META_SYNCED_AT, true );
			echo $at ? esc_html( (string) $at ) : '&mdash;';
		}
	}

	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( $post->post_type !== self::POST_TYPE ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'] );

		$entry_id = get_post_meta( $post->ID, self::META_ENTRY_ID, true );
		if ( $entry_id ) {
			$settings = AFR_Settings::all();
			$url      = sprintf(
				'https://app.contentful.com/spaces/%s/environments/%s/entries/%s',
				rawurlencode( $settings['space_id'] ),
				rawurlencode( $settings['environment'] ),
				rawurlencode( (string) $entry_id )
			);
			$actions['afr_contentful'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">Edit in Contentful</a>',
				esc_url( $url )
			);
		}

		return $actions;
	}

	public static function editor_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== self::POST_TYPE || $screen->base !== 'post' ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Contentful is the source of truth for Field Reports.</strong> '
			. 'Edits made here are overwritten on the next sync — edit the entry in Contentful instead.</p></div>';
	}
}
