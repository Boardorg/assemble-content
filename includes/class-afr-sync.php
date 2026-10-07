<?php
/**
 * Contentful -> WordPress sync.
 *
 * Which Contentful types are synced, and how each maps onto a post, comes from
 * the AFR_Types registry; this class is the same for every type.
 *
 * Posts are matched to entries by the `_afr_entry_id` meta key, never by slug, so
 * renaming a headline in Contentful updates the existing post instead of orphaning
 * it. Nothing is ever deleted: an unpublished or removed entry moves its post to
 * draft and says so in the sync log.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Sync {

	/**
	 * Sync every registered content type, or just one.
	 *
	 * Each type is fetched in full and its orphans drafted only when that fetch
	 * was complete. A failed or partial fetch of one type never drafts anything,
	 * and a sync of one type never touches another type's posts.
	 *
	 * @param string $only_type Contentful content type ID; '' syncs every type plus Site Features.
	 * @return array{created:int,updated:int,unchanged:int,drafted:int,errors:int,messages:string[]}
	 */
	public static function sync_all( bool $force = false, string $trigger = 'manual', string $only_type = '' ): array {
		$result = self::blank_result();
		$types  = AFR_Types::all();

		if ( $only_type !== '' ) {
			if ( ! isset( $types[ $only_type ] ) ) {
				$result['errors']     = 1;
				$result['messages'][] = sprintf( 'content type %s is not registered', $only_type );
				self::record( $result, $trigger );

				return $result;
			}

			$types = [ $only_type => $types[ $only_type ] ];
		}

		foreach ( $types as $content_type => $type ) {
			self::sync_type( (string) $content_type, $type, $force, $result );
		}

		// The community directory and Site Features ride along with a full sync so `wp assemble-content sync --all`
		// rebuilds the entire website surface, not just the post types.
		if ( $only_type === '' ) {
			$communities = AFR_Communities::sync();
			if ( $communities['error'] !== '' ) {
				$result['errors']++;
				$result['messages'][] = 'communities: ' . $communities['error'];
			} else {
				$result['messages'][] = sprintf( 'communities: %d cached', $communities['count'] );
			}

			$features = AFR_Features::sync();
			if ( $features['error'] !== '' ) {
				$result['errors']++;
				$result['messages'][] = 'site features: ' . $features['error'];
			} else {
				$result['messages'][] = sprintf( 'site features: %d cached', $features['count'] );
			}
		}

		self::record( $result, $trigger );

		return $result;
	}

	/** Fetch, upsert and orphan-draft one registered type. Adds to $result. */
	private static function sync_type( string $content_type, array $type, bool $force, array &$result ): void {
		$fetch = AFR_Contentful::get_type( $content_type );

		$seen = [];
		foreach ( $fetch['items'] as $entry ) {
			$outcome = self::sync_entry( $entry, $force );
			$result[ $outcome['status'] ] = ( $result[ $outcome['status'] ] ?? 0 ) + 1;
			$result['messages'][]         = $outcome['message'];

			// Entries that fail to save still count as seen, so they aren't drafted.
			if ( ! empty( $outcome['entry_id'] ) ) {
				$seen[] = $outcome['entry_id'];
			}
		}

		if ( ! $fetch['complete'] ) {
			$result['errors']++;
			$result['messages'][] = sprintf(
				'%s: %s Orphan drafting skipped, so nothing was drafted.',
				$content_type,
				$fetch['error'] !== '' ? $fetch['error'] : 'incomplete fetch.'
			);

			return;
		}

		// Anything in WP that Contentful no longer publishes gets drafted, not deleted.
		foreach ( self::orphans( (string) $type['post_type'], $seen ) as $post_id ) {
			self::draft_post( (int) $post_id, 'no longer published in Contentful' );
			$result['drafted']++;
			$result['messages'][] = sprintf( 'drafted #%d (no longer published in Contentful)', $post_id );
		}
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
	 * Map one resolved entry onto a post, using its type's registry entry.
	 *
	 * @return array{status:string,message:string,post_id:int,entry_id:string}
	 */
	private static function sync_entry( array $entry, bool $force ): array {
		$entry_id     = (string) ( $entry['sys']['id'] ?? '' );
		$content_type = (string) ( $entry['sys']['contentType']['sys']['id'] ?? '' );
		$type         = AFR_Types::get( $content_type );

		if ( ! $type ) {
			return [
				'status'   => 'errors',
				'message'  => sprintf( 'skipped %s: content type %s is not synced on this site', $entry_id ?: '(unknown)', $content_type ?: 'unknown' ),
				'post_id'  => 0,
				'entry_id' => $entry_id,
			];
		}

		$mapped = (array) call_user_func( $type['map'], $entry );
		$fields = (array) ( $mapped['post'] ?? [] );
		$meta   = (array) ( $mapped['meta'] ?? [] );

		if ( $entry_id === '' || (string) ( $fields['post_title'] ?? '' ) === '' ) {
			return [
				'status'   => 'errors',
				'message'  => sprintf( 'skipped %s: missing sys.id or title', $entry_id ?: '(unknown)' ),
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

		$postarr = array_merge(
			$fields,
			[
				'post_type'   => $type['post_type'],
				'post_status' => 'publish',
			]
		);

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

		// Bookkeeping every synced type shares.
		update_post_meta( $post_id, AFR_CPT::META_ENTRY_ID, $entry_id );
		update_post_meta( $post_id, AFR_CPT::META_REVISION, $revision );
		update_post_meta( $post_id, AFR_CPT::META_UPDATED_AT, $updated );
		update_post_meta( $post_id, AFR_CPT::META_DATA, wp_slash( wp_json_encode( $entry ) ) );
		update_post_meta( $post_id, AFR_CPT::META_SYNCED_AT, current_time( 'mysql' ) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}

		if ( is_callable( $type['after_save'] ) ) {
			call_user_func( $type['after_save'], $post_id, $entry );
		}

		return [
			'status'   => $status,
			'message'  => sprintf( '%s #%d %s (rev %s)', $status, $post_id, $entry_id, $revision ),
			'post_id'  => $post_id,
			'entry_id' => $entry_id,
		];
	}

	// ----------------------------------------------------------------- lookups

	public static function find_post_by_entry_id( string $entry_id ): int {
		$post_types = AFR_Types::post_types();
		if ( ! $post_types ) {
			return 0;
		}

		$posts = get_posts(
			[
				'post_type'        => $post_types,
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

	/** Published posts of one type whose entry IDs were not in that type's last full fetch. */
	private static function orphans( string $post_type, array $seen_entry_ids ): array {
		$posts = get_posts(
			[
				'post_type'        => $post_type,
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
