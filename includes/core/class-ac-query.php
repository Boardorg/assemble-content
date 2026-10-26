<?php
/**
 * Report queries for the website.
 *
 * One place that knows what "featured first, then newest" means, so the
 * homepage stream, the Peer Intelligence carousel and any Elementor loop that
 * wants the same order all agree.
 *
 * ⚠️ These queries do NOT filter by audience. Listing a gated report is
 * deliberate — the card is the lead-generation surface and the body stays
 * behind AC_Audience. Never use one of these to decide whether to render
 * report *content*.
 */

defined( 'ABSPATH' ) || exit;

class AC_Query {

	/**
	 * Featured reports, by rank then newest.
	 *
	 * @return WP_Post[]
	 */
	public static function featured( int $limit = 4 ): array {
		return get_posts(
			[
				'post_type'      => AC_Field_Report_Type::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => $limit,
				'meta_key'       => AC_Field_Report_Type::META_FEATURED_RANK,
				'orderby'        => [ 'meta_value_num' => 'ASC', 'date' => 'DESC' ],
				'meta_query'     => [
					[
						'key'   => AC_Field_Report_Type::META_FEATURED,
						'value' => '1',
					],
				],
			]
		);
	}

	/**
	 * The stream: featured reports first in their chosen order, then everything
	 * else newest-first, with no report appearing twice.
	 *
	 * Two queries rather than one clever ordered query — WP_Query cannot express
	 * "this meta ascending, then fall back to date" across posts that lack the
	 * meta without an ambiguous join, and the dataset is a few dozen rows.
	 *
	 * @return WP_Post[]
	 */
	public static function stream( int $limit = 8 ): array {
		$featured = self::featured( $limit );
		$ids      = wp_list_pluck( $featured, 'ID' );

		$remaining = $limit - count( $featured );
		if ( $remaining < 1 ) {
			return $featured;
		}

		$rest = get_posts(
			[
				'post_type'      => AC_Field_Report_Type::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => $remaining,
				'post__not_in'   => $ids ?: [ 0 ],
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		return array_merge( $featured, $rest );
	}

	/**
	 * Everything except what the carousel already showed, newest first.
	 *
	 * This is what fixes the /blog/ overlap: the grid can be told which reports
	 * the carousel above it has already used.
	 *
	 * @param int[] $exclude
	 * @return WP_Post[]
	 */
	public static function rest_of_stream( array $exclude = [], int $limit = -1 ): array {
		return get_posts(
			[
				'post_type'    => AC_Field_Report_Type::POST_TYPE,
				'post_status'  => 'publish',
				'numberposts'  => $limit,
				'post__not_in' => $exclude ?: [ 0 ],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);
	}

	/**
	 * Newest reports tagged with one community (primary or additional).
	 *
	 * @return WP_Post[]
	 */
	public static function in_community( string $community_slug, int $limit = 3 ): array {
		return get_posts(
			[
				'post_type'   => AC_Field_Report_Type::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'tax_query'   => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term lookup.
					[
						'taxonomy' => AC_Field_Report_Type::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $community_slug,
					],
				],
			]
		);
	}

	/**
	 * Newest reports for any of these Member Center groups. A Network passes its
	 * Practice Boards (its `practice_areas` group meta), so every report from
	 * those Boards rolls up to it. Like the rest of this class, this lists; it
	 * never decides who may read a body.
	 *
	 * @param string[] $groups Member Center group slugs, e.g. [ 'learning-development', 'talent' ].
	 * @param array    $args   Extra get_posts() args (paged, offset, date_query…).
	 * @return WP_Post[]
	 */
	public static function for_groups( array $groups, int $limit = 10, array $args = [] ): array {
		$groups = array_values( array_filter( array_map( 'sanitize_title', $groups ) ) );
		if ( ! $groups ) {
			return [];
		}

		return get_posts(
			array_merge(
				[
					'post_type'   => AC_Field_Report_Type::POST_TYPE,
					'post_status' => 'publish',
					'numberposts' => $limit,
					'orderby'     => 'date',
					'order'       => 'DESC',
				],
				$args,
				[
					'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term lookup.
						[
							'taxonomy' => AC_Field_Report_Type::GROUP_TAXONOMY,
							'field'    => 'slug',
							'terms'    => $groups,
						],
					],
				]
			)
		);
	}

	/**
	 * Newest pieces published to any of these places (`publishTo` names, e.g.
	 * 'HR Network'). Lists only; never decides who may read a body.
	 *
	 * @param string[] $places    Place names.
	 * @param string[] $post_type Synced post types; defaults to Field Reports.
	 * @param array    $args      Extra get_posts() args.
	 * @return WP_Post[]
	 */
	public static function for_places( array $places, int $limit = 10, array $args = [], array $post_type = [ AC_Field_Report_Type::POST_TYPE ] ): array {
		$slugs = array_values( array_filter( array_map( [ AC_Placement::class, 'slug' ], $places ) ) );
		if ( ! $slugs ) {
			return [];
		}

		return get_posts(
			array_merge(
				[
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'numberposts' => $limit,
					'orderby'     => 'date',
					'order'       => 'DESC',
				],
				$args,
				[
					'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one indexed term lookup.
						[
							'taxonomy' => AC_Placement::TAXONOMY,
							'field'    => 'slug',
							'terms'    => $slugs,
						],
					],
				]
			)
		);
	}

	/**
	 * A piece's place names.
	 *
	 * @return string[]
	 */
	public static function places( int $post_id ): array {
		return AC_Placement::post_places( $post_id );
	}

	/**
	 * A report's Member Center group slugs.
	 *
	 * @return string[]
	 */
	public static function groups( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, AC_Field_Report_Type::GROUP_TAXONOMY, [ 'fields' => 'slugs' ] );
		return is_wp_error( $terms ) ? [] : $terms;
	}

	/**
	 * A report's topic slugs, read from meta (no JSON decode).
	 *
	 * @return string[]
	 */
	public static function topic_slugs( int $post_id ): array {
		return array_values( (array) get_post_meta( $post_id, AC_Post_Type_Content::META_TOPICS, false ) );
	}

	/** Is this report flagged in Contentful? */
	public static function is_featured( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, AC_Field_Report_Type::META_FEATURED, true );
	}

	/**
	 * A report's topics, in the order the writer chose them.
	 *
	 * @return array<int,array{name:string,slug:string}>
	 */
	public static function topics( int $post_id ): array {
		return self::entry_topics( AC_Post_Type_Content::data( $post_id ) );
	}

	/**
	 * Topics from a decoded entry, in the writer's order.
	 *
	 * @return array<int,array{name:string,slug:string}>
	 */
	public static function entry_topics( ?array $entry ): array {
		$topics = [];

		foreach ( (array) AC_Contentful::field( $entry, 'topics', [] ) as $topic ) {
			$name = trim( (string) ( AC_Contentful::field( is_array( $topic ) ? $topic : null, 'name' ) ?? '' ) );
			if ( $name !== '' ) {
				$topics[] = [
					'name' => $name,
					'slug' => sanitize_title( (string) ( AC_Contentful::field( $topic, 'slug' ) ?? $name ) ),
				];
			}
		}

		return $topics;
	}
}
