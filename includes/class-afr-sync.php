<?php
/**
 * Contentful -> WordPress sync.
 *
 * Posts are matched to entries by the `_afr_entry_id` meta key, never by slug, so
 * renaming a headline in Contentful updates the existing post instead of orphaning
 * it. Nothing is ever deleted: an unpublished or removed entry moves its post to
 * draft and says so in the sync log.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Sync {

	/**
	 * Sync every published Field Report.
	 *
	 * @return array{created:int,updated:int,unchanged:int,drafted:int,errors:int,messages:string[]}
	 */
	public static function sync_all( bool $force = false, string $trigger = 'manual' ): array {
		$result = self::blank_result();
		$fetch  = AFR_Contentful::get_field_reports();

		if ( $fetch['error'] !== '' ) {
			$result['errors']     = 1;
			$result['messages'][] = $fetch['error'];
			self::record( $result, $trigger );

			return $result;
		}

		$seen = [];
		foreach ( $fetch['items'] as $entry ) {
			$outcome = self::sync_entry( $entry, $force );
			$result[ $outcome['status'] ] = ( $result[ $outcome['status'] ] ?? 0 ) + 1;
			$result['messages'][]         = $outcome['message'];

			if ( ! empty( $outcome['entry_id'] ) ) {
				$seen[] = $outcome['entry_id'];
			}
		}

		// Site Features ride along with a full sync so `wp field-report sync --all`
		// rebuilds the entire website surface, not just the reports.
		$features = AFR_Features::sync();
		if ( $features['error'] !== '' ) {
			$result['errors']++;
			$result['messages'][] = 'site features: ' . $features['error'];
		} else {
			$result['messages'][] = sprintf( 'site features: %d cached', $features['count'] );
		}

		// Anything in WP that Contentful no longer publishes gets drafted, not deleted.
		foreach ( self::orphans( $seen ) as $post_id ) {
			self::draft_post( (int) $post_id, 'no longer published in Contentful' );
			$result['drafted']++;
			$result['messages'][] = sprintf( 'drafted #%d (no longer published in Contentful)', $post_id );
		}

		self::record( $result, $trigger );

		return $result;
	}

	/** Sync a single entry by Contentful ID. */
	public static function sync_one( string $entry_id, bool $force = false, string $trigger = 'manual' ): array {
		$result = self::blank_result();
		$entry  = AFR_Contentful::get_entry( $entry_id );

		if ( ! $entry ) {
			// Absent from the CDA means unpublished or deleted.
			$post_id = self::find_post_by_entry_id( $entry_id );

			if ( $post_id ) {
				self::draft_post( $post_id, 'entry not published in Contentful' );
				$result['drafted']    = 1;
				$result['messages'][] = sprintf( 'drafted #%d (%s not published)', $post_id, $entry_id );
			} else {
				$result['errors']     = 1;
				$result['messages'][] = sprintf( 'entry %s not found in the delivery API', $entry_id );
			}

			self::record( $result, $trigger );

			return $result;
		}

		$outcome                      = self::sync_entry( $entry, $force );
		$result[ $outcome['status'] ] = 1;
		$result['messages'][]         = $outcome['message'];

		self::record( $result, $trigger );

		return $result;
	}

	/**
	 * Map one resolved entry onto a post.
	 *
	 * @return array{status:string,message:string,post_id:int,entry_id:string}
	 */
	private static function sync_entry( array $entry, bool $force ): array {
		$entry_id = (string) ( $entry['sys']['id'] ?? '' );
		$headline = (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? '' );

		if ( $entry_id === '' || $headline === '' ) {
			return [
				'status'   => 'errors',
				'message'  => sprintf( 'skipped %s: missing sys.id or headline', $entry_id ?: '(unknown)' ),
				'post_id'  => 0,
				'entry_id' => $entry_id,
			];
		}

		$content_type = (string) ( $entry['sys']['contentType']['sys']['id'] ?? '' );
		if ( $content_type !== 'fieldReport' ) {
			return [
				'status'   => 'errors',
				'message'  => sprintf( 'skipped %s: content type is %s, not fieldReport', $entry_id, $content_type ?: 'unknown' ),
				'post_id'  => 0,
				'entry_id' => $entry_id,
			];
		}

		$post_id  = self::find_post_by_entry_id( $entry_id );
		$revision = (string) ( $entry['sys']['revision'] ?? '' );
		$updated  = (string) ( $entry['sys']['updatedAt'] ?? '' );

		if ( $post_id && ! $force ) {
			$known_rev = (string) get_post_meta( $post_id, AFR_CPT::META_REVISION, true );
			$known_at  = (string) get_post_meta( $post_id, AFR_CPT::META_UPDATED_AT, true );

			if ( $known_rev === $revision && $known_at === $updated && get_post_status( $post_id ) === 'publish' ) {
				return [
					'status'   => 'unchanged',
					'message'  => sprintf( 'unchanged #%d %s (rev %s)', $post_id, $entry_id, $revision ),
					'post_id'  => $post_id,
					'entry_id' => $entry_id,
				];
			}
		}

		$postarr = [
			'post_type'    => AFR_CPT::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $headline,
			'post_name'    => sanitize_title( (string) ( AFR_Contentful::field( $entry, 'slug' ) ?? $headline ) ),
			// A rendered copy so search, Yoast and excerpts have something to read.
			// Display always re-renders per audience, so this is never served raw.
			'post_content' => AFR_Renderer::render_report( $entry ),
			'post_excerpt' => self::excerpt( $entry ),
			'post_date'    => self::post_date( $entry ),
		];

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$saved         = wp_update_post( $postarr, true );
			$status        = 'updated';
		} else {
			$saved  = wp_insert_post( $postarr, true );
			$status = 'created';
		}

		if ( is_wp_error( $saved ) ) {
			return [
				'status'   => 'errors',
				'message'  => sprintf( 'failed %s: %s', $entry_id, $saved->get_error_message() ),
				'post_id'  => 0,
				'entry_id' => $entry_id,
			];
		}

		$post_id = (int) $saved;

		update_post_meta( $post_id, AFR_CPT::META_ENTRY_ID, $entry_id );
		update_post_meta( $post_id, AFR_CPT::META_REVISION, $revision );
		update_post_meta( $post_id, AFR_CPT::META_UPDATED_AT, $updated );
		update_post_meta( $post_id, AFR_CPT::META_DATA, wp_slash( wp_json_encode( $entry ) ) );
		update_post_meta( $post_id, AFR_CPT::META_AUDIENCES, (array) ( AFR_Contentful::field( $entry, 'availableTo', [] ) ) );
		update_post_meta( $post_id, AFR_CPT::META_SOURCE, (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' ) );
		update_post_meta( $post_id, AFR_CPT::META_PULLQUOTE, (string) ( AFR_Contentful::field( $entry, 'pullquote' ) ?? '' ) );
		update_post_meta( $post_id, AFR_CPT::META_SHORT, (string) ( AFR_Contentful::field( $entry, 'takeawaysShort' ) ?? '' ) );
		update_post_meta( $post_id, AFR_CPT::META_SYNCED_AT, current_time( 'mysql' ) );

		// Promotion. Always written, never deleted — see the note on META_FEATURED.
		$featured = (bool) AFR_Contentful::field( $entry, 'featured', false );
		$rank     = AFR_Contentful::field( $entry, 'featuredRank' );
		update_post_meta( $post_id, AFR_CPT::META_FEATURED, $featured ? '1' : '0' );
		// Unranked featured reports sort behind every ranked one, so an editor who
		// ticks the box without picking a number still gets sensible order.
		update_post_meta( $post_id, AFR_CPT::META_FEATURED_RANK, null === $rank ? 99 : (int) $rank );

		self::apply_communities( $post_id, $entry );

		return [
			'status'   => $status,
			'message'  => sprintf( '%s #%d %s (rev %s)', $status, $post_id, $entry_id, $revision ),
			'post_id'  => $post_id,
			'entry_id' => $entry_id,
		];
	}

	/** Primary + additional communities become terms in the community taxonomy. */
	private static function apply_communities( int $post_id, array $entry ): void {
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

	// ----------------------------------------------------------------- lookups

	public static function find_post_by_entry_id( string $entry_id ): int {
		$posts = get_posts(
			[
				'post_type'        => AFR_CPT::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => AFR_CPT::META_ENTRY_ID,
				'meta_value'       => $entry_id,
				'suppress_filters' => true,
			]
		);

		return $posts ? (int) $posts[0] : 0;
	}

	public static function find_post_by_slug( string $slug ): int {
		$post = get_page_by_path( $slug, OBJECT, AFR_CPT::POST_TYPE );

		return $post ? (int) $post->ID : 0;
	}

	/** Published posts whose entry IDs were not in the last full fetch. */
	private static function orphans( array $seen_entry_ids ): array {
		$posts = get_posts(
			[
				'post_type'        => AFR_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);

		$orphans = [];
		foreach ( $posts as $post_id ) {
			$entry_id = (string) get_post_meta( (int) $post_id, AFR_CPT::META_ENTRY_ID, true );

			// Posts with no entry ID were not created by this plugin — leave them be.
			if ( $entry_id !== '' && ! in_array( $entry_id, $seen_entry_ids, true ) ) {
				$orphans[] = (int) $post_id;
			}
		}

		return $orphans;
	}

	private static function draft_post( int $post_id, string $reason ): void {
		wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
		update_post_meta( $post_id, '_afr_drafted_reason', $reason );
		update_post_meta( $post_id, AFR_CPT::META_SYNCED_AT, current_time( 'mysql' ) );
	}

	// ------------------------------------------------------------------ logging

	private static function blank_result(): array {
		return [
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'drafted'   => 0,
			'errors'    => 0,
			'messages'  => [],
		];
	}

	private static function record( array $result, string $trigger ): void {
		update_option(
			'afr_last_sync',
			[
				'time'      => current_time( 'mysql' ),
				'trigger'   => $trigger,
				'created'   => $result['created'],
				'updated'   => $result['updated'],
				'unchanged' => $result['unchanged'],
				'drafted'   => $result['drafted'],
				'errors'    => $result['errors'],
				'messages'  => array_slice( $result['messages'], -25 ),
			],
			false
		);
	}
}
