<?php
/**
 * The `field_report` post type's constants and its community taxonomy.
 *
 * The post type itself is registered from the AFR_Types registry, like every
 * other synced type: hidden from wp-admin, and `show_in_rest` deliberately false,
 * because reports carry member-gated content and a REST-exposed post type would
 * serve `content.rendered` to anonymous requests, bypassing the audience gate.
 */

defined( 'ABSPATH' ) || exit;

class AFR_CPT {

	public const POST_TYPE = 'field_report';
	public const TAXONOMY  = 'field_report_community';

	/**
	 * Member Center group slugs (a community's `memberCenterGroups`), as hidden
	 * terms. A site that runs on BuddyPress groups (the Member Center) selects
	 * reports for a group, or a Network's Boards, with one indexed tax_query.
	 */
	public const GROUP_TAXONOMY = 'field_report_mc_group';

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

	/**
	 * One row per value, so `meta_query` can match a single slug or key:
	 * topic slugs (the Member Center content-topic vocabulary) and the practice
	 * areas of the report's communities.
	 */
	public const META_TOPICS         = '_afr_topic';
	public const META_PRACTICE_AREAS = '_afr_practice_area';

	public static function init(): void {
		// Taxonomy first, deliberately. Rewrite rules are matched in registration
		// order, and the post type's `field-reports/[^/]+/([^/]+)` attachment rule
		// would otherwise swallow `field-reports/community/<slug>` and 404 it.
		// The post type registers at 10 from AFR_Types::init().
		add_action( 'init', [ self::class, 'register_taxonomy' ], 9 );
	}

	/** Kept for the activation hook, which must register in the same order as init. */
	public static function register_post_type(): void {
		AFR_Types::register_post_types();
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
				'show_ui'           => false,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'rewrite'           => [ 'slug' => 'field-reports/community', 'with_front' => false ],
			]
		);

		register_taxonomy(
			self::GROUP_TAXONOMY,
			[ self::POST_TYPE ],
			[
				'labels'            => [
					'name'          => 'Member Center groups',
					'singular_name' => 'Member Center group',
				],
				'public'            => false,
				'hierarchical'      => false,
				'show_ui'           => false,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'query_var'         => false,
				'rewrite'           => false,
			]
		);
	}
}
