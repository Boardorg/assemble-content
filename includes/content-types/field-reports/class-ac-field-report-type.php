<?php
/**
 * Field Reports: Contentful `fieldReport` entries as `field_report` posts.
 *
 * The sync engine (AC_Post_Type_Content) handles matching, revisions, the shared
 * `_ac_*` bookkeeping meta and orphan drafting; this class says which post fields,
 * meta and terms a Field Report carries, registers its taxonomies, and hands
 * `the_content` to AC_Field_Report_Renderer.
 *
 * `show_in_rest` stays false, because reports carry member-gated content and a
 * REST-exposed post type would serve `content.rendered` to anonymous requests,
 * bypassing the audience gate.
 */

defined( 'ABSPATH' ) || exit;

class AC_Field_Report_Type extends AC_Post_Type_Content {

	public const POST_TYPE = 'field_report';
	public const TAXONOMY  = 'field_report_community';

	/**
	 * Member Center group slugs (a community's `memberCenterGroups`), as hidden
	 * terms. A site that runs on BuddyPress groups (the Member Center) selects
	 * reports for a group, or a Network's Boards, with one indexed tax_query.
	 */
	public const GROUP_TAXONOMY = 'field_report_mc_group';

	/** Field Report meta. The bookkeeping keys are on AC_Post_Type_Content. */
	public const META_SOURCE    = '_ac_source_line';
	public const META_PULLQUOTE = '_ac_pullquote';
	public const META_SHORT     = '_ac_takeaways_short';

	/**
	 * Promotion, mirrored from Contentful's `featured` / `featuredRank`.
	 *
	 * META_FEATURED is stored as '1' or '0' rather than being deleted when false,
	 * so a meta_query can sort on it without a NOT EXISTS branch.
	 */
	public const META_FEATURED      = '_ac_featured';
	public const META_FEATURED_RANK = '_ac_featured_rank';

	public static function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'post_type'      => self::POST_TYPE,
				'label'          => 'Field Reports',
				'singular'       => 'Field Report',
				'slug'           => 'field-reports',
				'include'        => 3,
				'page_size'      => 100,
				'gated'          => true,
				'dependencies'   => [ 'mdTake', 'person', 'community', 'topic', 'topicCategory' ],
				// Only entries placed on this site sync (AC_Placement).
				'placed'         => true,
				// The July theme reads has_post_thumbnail() for its placeholder image.
				'post_type_args' => [
					'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				],
			]
		);
	}

	public function init(): void {
		parent::init();
		AC_Field_Report_Renderer::init();
		AC_Listing::init();
	}

	public function register_taxonomies(): void {
		register_taxonomy(
			self::TAXONOMY,
			[ $this->post_type() ],
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
			[ $this->post_type() ],
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

	/** The plugin's own document, or the teaser where the theme renders the page. */
	protected function content_for( WP_Post $post, string $content ): string {
		return AC_Field_Report_Renderer::filter_post_content( $post, $content );
	}

	/**
	 * @return array{post: array, meta: array}
	 */
	public function map( array $item ): array {
		$headline = (string) ( AC_Contentful::field( $item, 'headline' ) ?? '' );

		// Promotion. Always written, never deleted — see the note on META_FEATURED.
		$featured = (bool) AC_Contentful::field( $item, 'featured', false );
		$rank     = AC_Contentful::field( $item, 'featuredRank' );

		return [
			'post' => [
				'post_title'   => $headline,
				'post_name'    => sanitize_title( (string) ( AC_Contentful::field( $item, 'slug' ) ?? $headline ) ),
				// A rendered copy so search, Yoast and excerpts have something to read.
				// Display always re-renders per audience, so this is never served raw.
				'post_content' => AC_Field_Report_Renderer::render_report( $item ),
				'post_excerpt' => self::excerpt( $item ),
			],
			'meta' => [
				self::META_AUDIENCES     => (array) ( AC_Contentful::field( $item, 'availableTo', [] ) ),
				self::META_SOURCE        => (string) ( AC_Contentful::field( $item, 'sourceLine' ) ?? '' ),
				self::META_PULLQUOTE     => (string) ( AC_Contentful::field( $item, 'pullquote' ) ?? '' ),
				self::META_SHORT         => (string) ( AC_Contentful::field( $item, 'takeawaysShort' ) ?? '' ),
				self::META_FEATURED      => $featured ? '1' : '0',
				// Unranked featured reports sort behind every ranked one, so an editor who
				// ticks the box without picking a number still gets sensible order.
				self::META_FEATURED_RANK => null === $rank ? 99 : (int) $rank,
			],
		];
	}

	public function after_save( int $post_id, array $item ): void {
		$communities = AC_Placement::functional_areas( $item );

		self::apply_communities( $post_id, $communities );
		self::apply_groups( $post_id, $communities );
		self::apply_topics( $post_id, $item );

		$areas = [];
		foreach ( $communities as $community ) {
			$areas[] = sanitize_key( (string) ( AC_Contentful::field( $community, 'practiceArea' ) ?? '' ) );
		}
		self::set_multi_meta( $post_id, self::META_PRACTICE_AREAS, $areas );
	}

	/** Communities become terms in the community taxonomy. */
	private static function apply_communities( int $post_id, array $communities ): void {
		$term_ids = [];
		foreach ( $communities as $community ) {
			$name = (string) ( AC_Contentful::field( $community, 'name' ) ?? '' );
			if ( $name === '' ) {
				continue;
			}

			$slug = sanitize_title( (string) ( AC_Contentful::field( $community, 'slug' ) ?? $name ) );
			$term = get_term_by( 'slug', $slug, self::TAXONOMY );

			if ( ! $term ) {
				$created = wp_insert_term( $name, self::TAXONOMY, [ 'slug' => $slug ] );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term_ids[] = (int) $created['term_id'];
				continue;
			}

			$term_ids[] = (int) $term->term_id;
		}

		wp_set_object_terms( $post_id, $term_ids, self::TAXONOMY, false );
	}

	/** Each community's Member Center group slugs become hidden group terms. */
	private static function apply_groups( int $post_id, array $communities ): void {
		$groups = [];
		foreach ( $communities as $community ) {
			foreach ( (array) AC_Contentful::field( $community, 'memberCenterGroups', [] ) as $group ) {
				$group = sanitize_title( (string) $group );
				if ( $group !== '' ) {
					$groups[] = $group;
				}
			}
		}

		// Passing slugs creates missing terms; these are the site's own hidden list.
		wp_set_object_terms( $post_id, array_values( array_unique( $groups ) ), self::GROUP_TAXONOMY, false );
	}

	/**
	 * Topic slugs go to meta, and, when the site names a topic taxonomy through
	 * `ac_topic_taxonomy` (the Member Center: 'content-topic'), onto that
	 * taxonomy's existing terms. Never creates a term: the site's vocabulary is
	 * closed, and a slug it lacks is skipped.
	 */
	private static function apply_topics( int $post_id, array $item ): void {
		$slugs = wp_list_pluck( AC_Query::entry_topics( $item ), 'slug' );
		self::set_multi_meta( $post_id, self::META_TOPICS, $slugs );

		$taxonomy = (string) apply_filters( 'ac_topic_taxonomy', '', $post_id, $item );
		if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$term_ids = [];
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
	}

	private static function excerpt( array $item ): string {
		$setup = trim( (string) ( AC_Contentful::field( $item, 'newsletterSetup' ) ?? '' ) );

		if ( $setup !== '' ) {
			return $setup;
		}

		return wp_trim_words( AC_RichText::to_text( AC_Contentful::field( $item, 'pickingUpBody' ) ), 40 );
	}
}
