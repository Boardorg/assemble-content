<?php
/**
 * Runs syncs across content types and keeps the sync log.
 *
 * Each type syncs itself (AC_Post_Type_Content::sync(), AC_Option_Content::sync());
 * this class runs them in registry order, routes a single Contentful entry to its
 * type, and records the outcome in the `ac_last_sync` option that the admin
 * screens and `wp assemble-content status` read.
 */

defined( 'ABSPATH' ) || exit;

class AC_Sync {

	public const LOG_OPTION = 'ac_last_sync';

	/**
	 * Sync every registered type in the full sync, or just one type.
	 *
	 * Types run in registry order, so the community directory is fresh before the
	 * post types resolve functional areas from it. A type that fails never stops
	 * the next, and a sync of one type never touches another type's posts.
	 *
	 * @param string $only_type Registry key; '' syncs every type with `full_sync` on.
	 * @return array{created:int,updated:int,unchanged:int,drafted:int,errors:int,messages:string[],warnings:string[],rows:array}
	 */
	public static function sync_all( bool $force = false, string $trigger = 'manual', string $only_type = '', bool $dry_run = false ): array {
		$result = self::blank_result();
		$types  = AC_Types::types();

		if ( $only_type !== '' ) {
			if ( ! isset( $types[ $only_type ] ) ) {
				$result['errors']     = 1;
				$result['messages'][] = sprintf( 'content type %s is not registered', $only_type );
				self::record( $result, $trigger, $dry_run );

				return $result;
			}

			$types = [ $only_type => $types[ $only_type ] ];
		} else {
			$types = array_filter( $types, static fn( $type ) => $type->in_full_sync() );
		}

		foreach ( $types as $type ) {
			$outcome = $type->sync( $force, $dry_run );

			if ( $type instanceof AC_Post_Type_Content ) {
				foreach ( [ 'created', 'updated', 'unchanged', 'drafted', 'errors' ] as $count ) {
					$result[ $count ] += (int) $outcome[ $count ];
				}
				foreach ( [ 'messages', 'warnings', 'rows' ] as $list ) {
					$result[ $list ] = array_merge( $result[ $list ], (array) ( $outcome[ $list ] ?? [] ) );
				}
				continue;
			}

			// Option types replace a whole list: one line each.
			$name = strtolower( $type->label() );
			if ( $outcome['error'] !== '' ) {
				$result['errors']++;
				$result['messages'][] = $name . ': ' . $outcome['error'];
			} else {
				$result['messages'][] = sprintf( '%s: %d %s', $name, $outcome['count'], $dry_run ? 'fetched' : ( $outcome['unchanged'] ? 'unchanged' : 'cached' ) );
			}
			foreach ( $outcome['warnings'] as $warning ) {
				$result['warnings'][] = $name . ': ' . $warning;
			}
		}

		self::record( $result, $trigger, $dry_run );

		return $result;
	}

	/**
	 * Sync a single Contentful entry by ID: the webhook's path, and `--entry`.
	 */
	public static function sync_one( string $entry_id, bool $force = false, string $trigger = 'manual', bool $dry_run = false ): array {
		$result = self::blank_result();
		$source = AC_Types::source( 'contentful' );
		$fetch  = $source ? $source->fetch_one( $entry_id ) : [ 'item' => null, 'error' => 'the Contentful source is not registered' ];
		$entry  = $fetch['item'];

		// A failed request says nothing about the entry: keep the post as it is.
		if ( $fetch['error'] !== '' ) {
			$result['errors']     = 1;
			$result['messages'][] = sprintf( 'could not fetch %s, post left unchanged: %s', $entry_id, $fetch['error'] );
			self::record( $result, $trigger, $dry_run );

			return $result;
		}

		if ( ! $entry ) {
			// Absent from the CDA means unpublished or deleted.
			$post_id = self::find_post_by_entry_id( $entry_id );
			$type    = $post_id ? AC_Types::for_post_type( (string) get_post_type( $post_id ) ) : null;

			if ( $post_id && $type ) {
				if ( ! $dry_run ) {
					$type->remove( $post_id, 'entry not published in Contentful' );
				}
				$result['drafted']    = 1;
				$result['messages'][] = sprintf( '%s #%d (%s not published)', $dry_run ? 'would draft' : 'drafted', $post_id, $entry_id );
			} else {
				$result['errors']     = 1;
				$result['messages'][] = sprintf( 'entry %s not found in the delivery API', $entry_id );
			}

			self::record( $result, $trigger, $dry_run );

			return $result;
		}

		$content_type = $source->describe( $entry )['type'];
		$type         = AC_Types::type( $content_type );

		if ( ! $type instanceof AC_Post_Type_Content || $type->config( 'source' ) !== $source->id() ) {
			$result['errors']     = 1;
			$result['messages'][] = sprintf( 'skipped %s: content type %s is not synced on this site', $entry_id, $content_type ?: 'unknown' );
			self::record( $result, $trigger, $dry_run );

			return $result;
		}

		$entry = $type->prepare( $entry );

		if ( ! $type->on_site( $entry ) ) {
			$channel = AC_Settings::site_channel();
			$post_id = self::find_post_by_entry_id( $entry_id );

			if ( $channel === '' ) {
				$result['errors']     = 1;
				$result['messages'][] = sprintf( 'skipped %s: site channel not set', $entry_id );
			} elseif ( $post_id && get_post_status( $post_id ) === 'publish' ) {
				if ( $dry_run ) {
					$result['rows'][] = $type->dry_run_row( $entry, $post_id, 'draft (not on this site)' );
				} else {
					$type->remove( $post_id, 'not published to this site' );
				}
				$result['drafted']    = 1;
				$result['messages'][] = sprintf( '%s #%d (%s not published to %s)', $dry_run ? 'would draft' : 'drafted', $post_id, $entry_id, $channel );
			} else {
				$result['unchanged']  = 1;
				$result['messages'][] = sprintf( 'skipped %s: not published to %s', $entry_id, $channel );
			}

			self::record( $result, $trigger, $dry_run );

			return $result;
		}

		$outcome                      = $type->sync_item( $entry, $force, $dry_run );
		$result[ $outcome['status'] ] = 1;
		$result['messages'][]         = $outcome['message'];

		if ( $dry_run ) {
			$result['rows'][] = $type->dry_run_row( $entry, $outcome['post_id'], $outcome['action'] );
			foreach ( $type->warnings( $entry ) as $warning ) {
				$result['warnings'][] = sprintf( '%s: %s', $entry_id, $warning );
			}
		}

		self::record( $result, $trigger, $dry_run );

		return $result;
	}

	/** @deprecated 1.9.0 Use AC_Placement::on_site(). */
	public static function on_channel( array $entry, string $field = '' ): bool {
		return AC_Placement::on_site( AC_Placement::normalize( $entry ) );
	}

	// ----------------------------------------------------------------- lookups

	/** The synced post for a source item ID, in any synced post type, or 0. */
	public static function find_post_by_entry_id( string $entry_id ): int {
		$post_types = AC_Types::post_types();
		if ( ! $post_types ) {
			return 0;
		}

		$posts = get_posts(
			[
				'post_type'        => $post_types,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => AC_Post_Type_Content::META_ENTRY_ID,
				'meta_value'       => $entry_id,
				'suppress_filters' => true,
			]
		);

		return $posts ? (int) $posts[0] : 0;
	}

	/** A Field Report by slug, for Rich Text entry links. */
	public static function find_post_by_slug( string $slug ): int {
		$post = get_page_by_path( $slug, OBJECT, AC_Field_Report_Type::POST_TYPE );

		return $post ? (int) $post->ID : 0;
	}

	// ------------------------------------------------------------------ logging

	/** An empty result, the shape every post type sync and sync_one() return. */
	public static function blank_result(): array {
		return [
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'drafted'   => 0,
			'errors'    => 0,
			'messages'  => [],
			'warnings'  => [],
			'rows'      => [],
		];
	}

	/**
	 * The last sync's outcome.
	 *
	 * @return array{time?:string,trigger?:string,created?:int,updated?:int,unchanged?:int,drafted?:int,errors?:int,messages?:string[]}
	 */
	public static function last(): array {
		return (array) get_option( self::LOG_OPTION, [] );
	}

	private static function record( array $result, string $trigger, bool $dry_run = false ): void {
		if ( $dry_run ) {
			return;
		}

		update_option(
			self::LOG_OPTION,
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
