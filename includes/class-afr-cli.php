<?php
/**
 * WP-CLI: wp field-report <command>
 */

defined( 'ABSPATH' ) || exit;

class AFR_CLI {

	/**
	 * Sync Field Reports from Contentful.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Sync every published Field Report. Default when --entry is omitted.
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
	 *     wp field-report sync --all
	 *     wp field-report sync --entry=fieldreport-aeo-benchmarking-20260708 --force
	 *     wp field-report sync --all --dry-run
	 */
	public function sync( array $args, array $assoc ): void {
		$entry_id = $assoc['entry'] ?? '';
		$force    = isset( $assoc['force'] );
		$dry      = isset( $assoc['dry-run'] );

		if ( ! AFR_Settings::is_configured() ) {
			WP_CLI::error( 'Contentful is not configured. Set the space ID and delivery token first.' );
		}

		if ( $dry ) {
			$this->dry_run( $entry_id ? (string) $entry_id : '' );

			return;
		}

		$result = $entry_id
			? AFR_Sync::sync_one( (string) $entry_id, $force, 'cli' )
			: AFR_Sync::sync_all( $force, 'cli' );

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

	/** Fetch and report without writing. */
	private function dry_run( string $entry_id ): void {
		$fetch = $entry_id
			? [ 'items' => array_filter( [ AFR_Contentful::get_entry( $entry_id ) ] ), 'error' => '' ]
			: AFR_Contentful::get_field_reports();

		if ( ! empty( $fetch['error'] ) ) {
			WP_CLI::error( $fetch['error'] );
		}

		$rows = [];
		foreach ( $fetch['items'] as $entry ) {
			$id      = (string) ( $entry['sys']['id'] ?? '?' );
			$post_id = AFR_Sync::find_post_by_entry_id( $id );
			$rev     = (string) ( $entry['sys']['revision'] ?? '' );
			$known   = $post_id ? (string) get_post_meta( $post_id, AFR_CPT::META_REVISION, true ) : '';

			$rows[] = [
				'entry'     => $id,
				'headline'  => wp_trim_words( (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? '' ), 9 ),
				'post'      => $post_id ?: '—',
				'rev'       => $rev,
				'action'    => ! $post_id ? 'create' : ( $known === $rev ? 'unchanged' : 'update' ),
				'audiences' => implode( '/', (array) AFR_Contentful::field( $entry, 'availableTo', [] ) ),
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'entry', 'headline', 'post', 'rev', 'action', 'audiences' ] );
		WP_CLI::success( sprintf( 'dry run — %d entr%s inspected, nothing written', count( $rows ), count( $rows ) === 1 ? 'y' : 'ies' ) );
	}

	/**
	 * Show connection and sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp field-report status
	 */
	public function status(): void {
		$settings = AFR_Settings::all();
		$counts   = wp_count_posts( AFR_CPT::POST_TYPE );
		$last     = get_option( 'afr_last_sync', [] );

		WP_CLI::log( 'Contentful' );
		WP_CLI::log( '  space:        ' . ( $settings['space_id'] ?: '(not set)' ) );
		WP_CLI::log( '  environment:  ' . $settings['environment'] );
		WP_CLI::log( '  CDA token:    ' . ( $settings['delivery_token'] ? 'set' : '(not set)' ) );
		WP_CLI::log( '  webhook:      ' . ( $settings['webhook_secret'] ? 'secret set' : 'DISABLED (no secret)' ) );
		WP_CLI::log( '  webhook URL:  ' . AFR_REST::webhook_url() );

		WP_CLI::log( '' );
		WP_CLI::log( 'Reachability' );

		$probe = AFR_Contentful::get_entries( [ 'content_type' => 'fieldReport', 'limit' => 1 ] );
		if ( $probe['error'] !== '' ) {
			WP_CLI::log( '  ✗ ' . $probe['error'] );
		} else {
			WP_CLI::log( sprintf( '  ✓ delivery API reachable — %d published fieldReport entries', $probe['total'] ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Gating' );
		$mode = AFR_Bypass::mode();
		WP_CLI::log( '  bypass mode:  ' . $mode . ( $mode === AFR_Bypass::MODE_OFF ? '' : '   <-- the audience gate is OPEN' ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'WordPress' );
		WP_CLI::log( sprintf( '  posts:        %d published, %d draft', (int) ( $counts->publish ?? 0 ), (int) ( $counts->draft ?? 0 ) ) );
		WP_CLI::log( '  archive:      ' . get_post_type_archive_link( AFR_CPT::POST_TYPE ) );

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
	 *     wp field-report audiences
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
	 *     wp field-report matrix
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
