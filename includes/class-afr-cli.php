<?php
/**
 * WP-CLI: wp assemble-content <command> (alias: wp field-report, until launch)
 */

defined( 'ABSPATH' ) || exit;

class AFR_CLI {

	/**
	 * Sync content from Contentful.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Sync every published entry of every registered type, plus Site Features. Default when --entry is omitted.
	 *
	 * [--type=<type>]
	 * : Sync only one registered type, by Contentful ID (fieldReport) or post type (field_report). Never touches other types.
	 *
	 * [--entry=<id>]
	 * : Sync one Contentful entry by ID.
	 *
	 * [--force]
	 * : Re-render even when the stored revision already matches.
	 *
	 * [--dry-run]
	 * : Report what would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content sync --all
	 *     wp assemble-content sync --type=fieldReport
	 *     wp assemble-content sync --entry=fieldreport-aeo-benchmarking-20260708 --force
	 *     wp assemble-content sync --all --dry-run
	 */
	public function sync( array $args, array $assoc ): void {
		$entry_id = $assoc['entry'] ?? '';
		$force    = isset( $assoc['force'] );
		$dry      = isset( $assoc['dry-run'] );
		$type     = '';

		if ( ! AFR_Settings::is_configured() ) {
			WP_CLI::error( 'Contentful is not configured. Set the space ID and delivery token first.' );
		}

		if ( isset( $assoc['type'] ) ) {
			$type = AFR_Types::resolve( (string) $assoc['type'] );
			if ( $type === '' ) {
				WP_CLI::error( sprintf( 'Unknown type "%s". Run `wp assemble-content types` to list them.', $assoc['type'] ) );
			}
		}

		if ( $dry ) {
			$this->dry_run( $entry_id ? (string) $entry_id : '', $type );

			return;
		}

		$result = $entry_id
			? AFR_Sync::sync_one( (string) $entry_id, $force, 'cli' )
			: AFR_Sync::sync_all( $force, 'cli', $type );

		foreach ( $result['messages'] as $message ) {
			WP_CLI::log( '  ' . $message );
		}

		$summary = sprintf(
			'created %d, updated %d, unchanged %d, drafted %d, errors %d',
			$result['created'],
			$result['updated'],
			$result['unchanged'],
			$result['drafted'],
			$result['errors']
		);

		if ( $result['errors'] > 0 ) {
			WP_CLI::error( $summary );
		}

		WP_CLI::success( $summary );
	}

	/** Fetch and report without writing, including what a full sync would draft. */
	private function dry_run( string $entry_id, string $only_type ): void {
		$rows     = [];
		$problems = [];

		if ( $entry_id !== '' ) {
			$fetches = [ '' => [ 'items' => array_filter( [ AFR_Contentful::get_entry( $entry_id ) ] ), 'error' => '', 'complete' => true ] ];
		} else {
			$fetches = [];
			foreach ( AFR_Types::all() as $content_type => $type ) {
				if ( $only_type === '' || $only_type === $content_type ) {
					$fetches[ $content_type ] = AFR_Contentful::get_type( (string) $content_type );
				}
			}
		}

		foreach ( $fetches as $content_type => $fetch ) {
			if ( ! $fetch['complete'] ) {
				$problems[] = sprintf( '%s: %s A real sync would skip orphan drafting for this type.', $content_type, $fetch['error'] ?: 'incomplete fetch.' );
			}

			$seen = [];
			foreach ( $fetch['items'] as $entry ) {
				$id      = (string) ( $entry['sys']['id'] ?? '?' );
				$ctype   = (string) ( $entry['sys']['contentType']['sys']['id'] ?? '' );
				$post_id = AFR_Sync::find_post_by_entry_id( $id );
				$rev     = (string) ( $entry['sys']['revision'] ?? '' );
				$known   = $post_id ? (string) get_post_meta( $post_id, AFR_CPT::META_REVISION, true ) : '';
				$seen[]  = $id;

				$rows[] = [
					'type'      => $ctype,
					'entry'     => $id,
					'headline'  => wp_trim_words( (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? AFR_Contentful::field( $entry, 'title' ) ?? '' ), 9 ),
					'post'      => $post_id ?: '—',
					'rev'       => $rev,
					'action'    => ! $post_id ? 'create' : ( $known === $rev && get_post_status( $post_id ) === 'publish' ? 'unchanged' : 'update' ),
					'audiences' => implode( '/', (array) AFR_Contentful::field( $entry, 'availableTo', [] ) ),
				];
			}

			// What a full sync would draft. Only meaningful for a complete type fetch.
			if ( $content_type === '' || ! $fetch['complete'] ) {
				continue;
			}

			$type  = AFR_Types::get( (string) $content_type );
			$posts = get_posts(
				[
					'post_type'        => $type['post_type'],
					'post_status'      => 'publish',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				]
			);
			foreach ( $posts as $post_id ) {
				$known_id = (string) get_post_meta( (int) $post_id, AFR_CPT::META_ENTRY_ID, true );
				if ( $known_id !== '' && ! in_array( $known_id, $seen, true ) ) {
					$rows[] = [
						'type'      => $content_type,
						'entry'     => $known_id,
						'headline'  => wp_trim_words( get_the_title( (int) $post_id ), 9 ),
						'post'      => $post_id,
						'rev'       => '',
						'action'    => 'draft',
						'audiences' => '',
					];
				}
			}
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'type', 'entry', 'headline', 'post', 'rev', 'action', 'audiences' ] );

		foreach ( $problems as $problem ) {
			WP_CLI::warning( $problem );
		}

		$counts = array_count_values( array_column( $rows, 'action' ) );
		ksort( $counts );
		WP_CLI::success(
			sprintf(
				'dry run, nothing written. %s',
				$counts ? implode( ', ', array_map( static fn( $k, $v ) => "$v $k", array_keys( $counts ), $counts ) ) : 'no entries'
			)
		);
	}

	/**
	 * List the content types this site syncs from Contentful.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content types
	 */
	public function types(): void {
		$rows = [];
		foreach ( AFR_Types::all() as $content_type => $type ) {
			$counts = wp_count_posts( $type['post_type'] );
			$rows[] = [
				'contentful'   => $content_type,
				'post_type'    => $type['post_type'],
				'url'          => '/' . trim( (string) $type['slug'], '/' ) . '/',
				'gated'        => $type['gated'] ? 'yes' : 'no',
				'dependencies' => implode( ', ', (array) $type['dependencies'] ) ?: '—',
				'published'    => (int) ( $counts->publish ?? 0 ),
				'draft'        => (int) ( $counts->draft ?? 0 ),
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'contentful', 'post_type', 'url', 'gated', 'dependencies', 'published', 'draft' ] );
	}

	/**
	 * Show connection and sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content status
	 */
	public function status(): void {
		$settings = AFR_Settings::all();
		$last     = get_option( 'afr_last_sync', [] );

		WP_CLI::log( 'Contentful' );
		WP_CLI::log( '  space:        ' . ( $settings['space_id'] ?: '(not set)' ) );
		WP_CLI::log( '  environment:  ' . $settings['environment'] );
		WP_CLI::log( '  CDA token:    ' . ( $settings['delivery_token'] ? 'set' : '(not set)' ) );
		WP_CLI::log( '  webhook:      ' . ( $settings['webhook_secret'] ? 'secret set' : 'DISABLED (no secret)' ) );
		WP_CLI::log( '  webhook URL:  ' . AFR_REST::webhook_url() );

		WP_CLI::log( '' );
		WP_CLI::log( 'Reachability' );

		foreach ( array_keys( AFR_Types::all() ) as $content_type ) {
			$probe = AFR_Contentful::get_entries( [ 'content_type' => $content_type, 'limit' => 1 ] );
			if ( $probe['error'] !== '' ) {
				WP_CLI::log( '  ✗ ' . $content_type . ': ' . $probe['error'] );
			} else {
				WP_CLI::log( sprintf( '  ✓ delivery API reachable — %d published %s entries', $probe['total'], $content_type ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Gating' );
		$mode = AFR_Bypass::mode();
		WP_CLI::log( '  bypass mode:  ' . $mode . ( $mode === AFR_Bypass::MODE_OFF ? '' : '   <-- the audience gate is OPEN' ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'WordPress' );
		foreach ( AFR_Types::all() as $type ) {
			$counts = wp_count_posts( $type['post_type'] );
			WP_CLI::log( sprintf( '  %-13s %d published, %d draft', $type['post_type'] . ':', (int) ( $counts->publish ?? 0 ), (int) ( $counts->draft ?? 0 ) ) );
			WP_CLI::log( '  archive:      ' . get_post_type_archive_link( $type['post_type'] ) );
		}

		if ( $last ) {
			WP_CLI::log( sprintf(
				'  last sync:    %s (%s) — created %d, updated %d, unchanged %d, drafted %d, errors %d',
				$last['time'] ?? '?',
				$last['trigger'] ?? '?',
				(int) ( $last['created'] ?? 0 ),
				(int) ( $last['updated'] ?? 0 ),
				(int) ( $last['unchanged'] ?? 0 ),
				(int) ( $last['drafted'] ?? 0 ),
				(int) ( $last['errors'] ?? 0 )
			) );
		} else {
			WP_CLI::log( '  last sync:    never' );
		}
	}

	/**
	 * Show the audience -> role mapping and which roles actually exist.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content audiences
	 */
	public function audiences(): void {
		$existing = array_keys( wp_roles()->roles );
		$rows     = [];

		foreach ( AFR_Settings::audience_roles() as $audience => $roles ) {
			$roles   = (array) $roles;
			$missing = array_diff( $roles, $existing );

			$rows[] = [
				'audience' => $audience,
				'roles'    => $roles ? implode( ', ', $roles ) : '(anyone)',
				'status'   => ! $roles ? 'n/a' : ( $missing ? 'MISSING: ' . implode( ', ', $missing ) : 'ok' ),
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'audience', 'roles', 'status' ] );
	}

	/**
	 * Print the per-report audience matrix — which posts are visible to whom.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content matrix
	 */
	public function matrix(): void {
		$posts = get_posts(
			[
				'post_type'   => AFR_CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
			]
		);

		if ( ! $posts ) {
			WP_CLI::warning( 'No Field Reports synced yet.' );

			return;
		}

		$audiences = AFR_Settings::audiences();
		$rows      = [];

		foreach ( $posts as $post ) {
			$allowed = AFR_Audience::report_audiences( $post->ID );
			$row     = [
				'post'   => $post->ID,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
			];

			foreach ( $audiences as $audience ) {
				$row[ $audience ] = in_array( $audience, $allowed, true ) ? '✓' : '·';
			}

			$rows[] = $row;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array_merge( [ 'post', 'slug', 'status' ], $audiences ) );
	}
}
