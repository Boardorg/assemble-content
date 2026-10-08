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
 * behind AFR_Audience. Never use one of these to decide whether to render
 * report *content*.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Query {

	/**
	 * Featured reports, by rank then newest.
	 *
	 * @return WP_Post[]
	 */
	public static function featured( int $limit = 4 ): array {
		return get_posts(
			[
				'post_type'      => AFR_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'numberposts'    => $limit,
				'meta_key'       => AFR_CPT::META_FEATURED_RANK,
				'orderby'        => [ 'meta_value_num' => 'ASC', 'date' => 'DESC' ],
				'meta_query'     => [
					[
						'key'   => AFR_CPT::META_FEATURED,
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
				'post_type'      => AFR_CPT::POST_TYPE,
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
				'post_type'    => AFR_CPT::POST_TYPE,
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
				'post_type'   => AFR_CPT::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'tax_query'   => [ // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.tax_query_tax_query -- one indexed term lookup.
					[
						'taxonomy' => AFR_CPT::TAXONOMY,
						'field'    => 'slug',
						'terms'    => $community_slug,
					],
				],
			]
		);
	}

	/** Is this report flagged in Contentful? */
	public static function is_featured( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, AFR_CPT::META_FEATURED, true );
	}

	/**
	 * A report's topics, in the order the writer chose them.
	 *
	 * @return array<int,array{name:string,slug:string}>
	 */
	public static function topics( int $post_id ): array {
		return self::entry_topics( AFR_Renderer::data( $post_id ) );
	}

	/**
	 * Topics from a decoded entry, in the writer's order.
	 *
	 * @return array<int,array{name:string,slug:string}>
	 */
	public static function entry_topics( ?array $entry ): array {
		$topics = [];

		foreach ( (array) AFR_Contentful::field( $entry, 'topics', [] ) as $topic ) {
			$name = trim( (string) ( AFR_Contentful::field( is_array( $topic ) ? $topic : null, 'name' ) ?? '' ) );
			if ( $name !== '' ) {
				$topics[] = [
					'name' => $name,
					'slug' => sanitize_title( (string) ( AFR_Contentful::field( $topic, 'slug' ) ?? $name ) ),
				];
			}
		}

		return $topics;
	}
}
