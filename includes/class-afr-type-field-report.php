<?php
/**
 * How a Contentful `fieldReport` entry maps onto a `field_report` post.
 *
 * Registered in AFR_Types. The sync engine handles matching, revisions, the
 * shared `_afr_*` bookkeeping meta and orphan drafting; this class only says
 * which post fields, meta and terms a Field Report carries.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Field_Report_Type {

	/**
	 * @return array{post: array, meta: array<string,mixed>}
	 */
	public static function map( array $entry ): array {
		$headline = (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? '' );

		// Promotion. Always written, never deleted — see the note on META_FEATURED.
		$featured = (bool) AFR_Contentful::field( $entry, 'featured', false );
		$rank     = AFR_Contentful::field( $entry, 'featuredRank' );

		return [
			'post' => [
				'post_title'   => $headline,
				'post_name'    => sanitize_title( (string) ( AFR_Contentful::field( $entry, 'slug' ) ?? $headline ) ),
				// A rendered copy so search, Yoast and excerpts have something to read.
				// Display always re-renders per audience, so this is never served raw.
				'post_content' => AFR_Renderer::render_report( $entry ),
				'post_excerpt' => self::excerpt( $entry ),
				'post_date'    => self::post_date( $entry ),
			],
			'meta' => [
				AFR_CPT::META_AUDIENCES     => (array) ( AFR_Contentful::field( $entry, 'availableTo', [] ) ),
				AFR_CPT::META_SOURCE        => (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' ),
				AFR_CPT::META_PULLQUOTE     => (string) ( AFR_Contentful::field( $entry, 'pullquote' ) ?? '' ),
				AFR_CPT::META_SHORT         => (string) ( AFR_Contentful::field( $entry, 'takeawaysShort' ) ?? '' ),
				AFR_CPT::META_FEATURED      => $featured ? '1' : '0',
				// Unranked featured reports sort behind every ranked one, so an editor who
				// ticks the box without picking a number still gets sensible order.
				AFR_CPT::META_FEATURED_RANK => null === $rank ? 99 : (int) $rank,
			],
		];
	}

	public static function after_save( int $post_id, array $entry ): void {
		$communities = self::communities( $entry );

		self::apply_communities( $post_id, $communities );
		self::apply_groups( $post_id, $communities );
		self::apply_topics( $post_id, $entry );

		$areas = [];
		foreach ( $communities as $community ) {
			$areas[] = sanitize_key( (string) ( AFR_Contentful::field( $community, 'practiceArea' ) ?? '' ) );
		}
		self::set_multi_meta( $post_id, AFR_CPT::META_PRACTICE_AREAS, $areas );
	}

	/**
	 * Primary community first, then the additional ones, as resolved entries.
	 *
	 * @return array<int,array>
	 */
	private static function communities( array $entry ): array {
		$communities = [];

		$primary = AFR_Contentful::field( $entry, 'primaryCommunity' );
		if ( is_array( $primary ) ) {
			$communities[] = $primary;
		}

		foreach ( (array) AFR_Contentful::field( $entry, 'additionalCommunities', [] ) as $extra ) {
			if ( is_array( $extra ) ) {
				$communities[] = $extra;
			}
		}

		return $communities;
	}

	/** Communities become terms in the community taxonomy. */
	private static function apply_communities( int $post_id, array $communities ): void {
		$term_ids = [];
		foreach ( $communities as $community ) {
			$name = (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' );
			if ( $name === '' ) {
				continue;
			}

			$slug = sanitize_title( (string) ( AFR_Contentful::field( $community, 'slug' ) ?? $name ) );
			$term = get_term_by( 'slug', $slug, AFR_CPT::TAXONOMY );

			if ( ! $term ) {
				$created = wp_insert_term( $name, AFR_CPT::TAXONOMY, [ 'slug' => $slug ] );
				if ( is_wp_error( $created ) ) {
					continue;
				}
				$term_ids[] = (int) $created['term_id'];
				continue;
			}

			$term_ids[] = (int) $term->term_id;
		}

		wp_set_object_terms( $post_id, $term_ids, AFR_CPT::TAXONOMY, false );
	}

	/** Each community's Member Center group slugs become hidden group terms. */
	private static function apply_groups( int $post_id, array $communities ): void {
		$groups = [];
		foreach ( $communities as $community ) {
			foreach ( (array) AFR_Contentful::field( $community, 'memberCenterGroups', [] ) as $group ) {
				$group = sanitize_title( (string) $group );
				if ( $group !== '' ) {
					$groups[] = $group;
				}
			}
		}

		// Passing slugs creates missing terms; these are the site's own hidden list.
		wp_set_object_terms( $post_id, array_values( array_unique( $groups ) ), AFR_CPT::GROUP_TAXONOMY, false );
	}

	/**
	 * Topic slugs go to meta, and, when the site names a topic taxonomy through
	 * `afr_topic_taxonomy` (the Member Center: 'content-topic'), onto that
	 * taxonomy's existing terms. Never creates a term: the site's vocabulary is
	 * closed, and a slug it lacks is skipped.
	 */
	private static function apply_topics( int $post_id, array $entry ): void {
		$slugs = wp_list_pluck( AFR_Query::entry_topics( $entry ), 'slug' );
		self::set_multi_meta( $post_id, AFR_CPT::META_TOPICS, $slugs );

		$taxonomy = (string) apply_filters( 'afr_topic_taxonomy', '', $post_id, $entry );
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

	/** Replace a multi-row meta key with one row per unique, non-empty value. */
	private static function set_multi_meta( int $post_id, string $key, array $values ): void {
		delete_post_meta( $post_id, $key );
		foreach ( array_unique( array_filter( array_map( 'strval', $values ) ) ) as $value ) {
			add_post_meta( $post_id, $key, $value );
		}
	}

	private static function excerpt( array $entry ): string {
		$setup = trim( (string) ( AFR_Contentful::field( $entry, 'newsletterSetup' ) ?? '' ) );

		if ( $setup !== '' ) {
			return $setup;
		}

		return wp_trim_words( AFR_RichText::to_text( AFR_Contentful::field( $entry, 'pickingUpBody' ) ), 40 );
	}

	/**
	 * Date the report from its source line where possible — a report about a
	 * 2026-06-16 discussion should not be dated by when it was synced.
	 */
	private static function post_date( array $entry ): string {
		$source = (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' );

		if ( preg_match( '/\b([A-Z][a-z]+ \d{1,2},? \d{4})\b/', $source, $m ) ) {
			$ts = strtotime( $m[1] );
			if ( $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$created = (string) ( $entry['sys']['createdAt'] ?? '' );
		$ts      = $created ? strtotime( $created ) : false;

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );
	}
}
