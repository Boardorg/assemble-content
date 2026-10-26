<?php
/**
 * WP-CLI: wp assemble-content <command> (alias: wp field-report, until launch)
 */

defined( 'ABSPATH' ) || exit;

class AC_CLI {

	/**
	 * Sync content from its sources.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Sync every type in the full sync: the community directory, every post type and Site Features. Default when --entry is omitted. Summits refresh on their own schedule; see `wp assemble-content summits refresh`.
	 *
	 * [--type=<type>]
	 * : Sync only one registered type, by registry key (fieldReport, community, summits) or post type (field_report). Never touches other types.
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
		$entry_id = (string) ( $assoc['entry'] ?? '' );
		$force    = isset( $assoc['force'] );
		$dry      = isset( $assoc['dry-run'] );
		$type     = '';

		if ( isset( $assoc['type'] ) ) {
			$type = AC_Types::resolve( (string) $assoc['type'] );
			if ( $type === '' ) {
				WP_CLI::error( sprintf( 'Unknown type "%s". Run `wp assemble-content types` to list them.', $assoc['type'] ) );
			}
		}

		$needs_contentful = $entry_id !== '' || $type === '' || AC_Types::type( $type )->config( 'source' ) === 'contentful';
		if ( $needs_contentful && ! AC_Settings::is_configured() ) {
			WP_CLI::error( 'Contentful is not configured. Set the space ID and delivery token first.' );
		}

		$result = $entry_id !== ''
			? AC_Sync::sync_one( $entry_id, $force, 'cli', $dry )
			: AC_Sync::sync_all( $force, 'cli', $type, $dry );

		if ( $dry ) {
			$this->report_dry_run( $result );

			return;
		}

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

	/** Print a dry run: one row per post-type item, then warnings and what a real sync would do. */
	private function report_dry_run( array $result ): void {
		if ( $result['rows'] ) {
			WP_CLI\Utils\format_items( 'table', $result['rows'], [ 'type', 'entry', 'headline', 'post', 'rev', 'action', 'audiences', 'places' ] );
		}

		// Option types and errors have no rows; their lines say what happened.
		foreach ( $result['messages'] as $message ) {
			if ( ! preg_match( '/^(would |unchanged #)/', $message ) ) {
				WP_CLI::log( '  ' . $message );
			}
		}

		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		$counts = array_count_values( array_column( $result['rows'], 'action' ) );
		ksort( $counts );
		WP_CLI::success(
			sprintf(
				'dry run, nothing written. %s',
				$counts ? implode( ', ', array_map( static fn( $k, $v ) => "$v $k", array_keys( $counts ), $counts ) ) : 'no entries'
			)
		);
	}

	/**
	 * List the content types this site syncs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content types
	 */
	public function types(): void {
		$rows = [];
		foreach ( AC_Types::types() as $key => $type ) {
			$is_post = $type instanceof AC_Post_Type_Content;
			$rows[]  = [
				'key'          => $key,
				'source'       => $type->source() ? $type->source()->id() : '?',
				'stored as'    => $is_post ? 'post type ' . $type->post_type() : 'option ' . $type::OPTION,
				'url'          => $is_post ? '/' . trim( (string) $type->config( 'slug' ), '/' ) . '/' : '—',
				'gated'        => $is_post && $type->is_gated() ? 'yes' : 'no',
				'full sync'    => $type->in_full_sync() ? 'yes' : 'no',
				'dependencies' => implode( ', ', $type->dependencies() ) ?: '—',
				'state'        => $type->summary(),
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'source', 'stored as', 'url', 'gated', 'full sync', 'dependencies', 'state' ] );
	}

	/**
	 * Show connection and sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content status
	 */
	public function status(): void {
		$settings = AC_Settings::all();
		$last     = AC_Sync::last();

		WP_CLI::log( 'Contentful' );
		WP_CLI::log( '  space:        ' . ( $settings['space_id'] ?: '(not set)' ) );
		WP_CLI::log( '  environment:  ' . $settings['environment'] );
		WP_CLI::log( '  site channel: ' . ( $settings['site_channel'] !== '' ? $settings['site_channel'] : '(not set — nothing syncs)' ) );
		WP_CLI::log( '  CDA token:    ' . ( $settings['delivery_token'] ? 'set' : '(not set)' ) );
		WP_CLI::log( '  webhook:      ' . ( $settings['webhook_secret'] ? 'secret set' : 'DISABLED (no secret)' ) );
		WP_CLI::log( '  webhook URL:  ' . AC_Contentful_Webhook::webhook_url() );
		WP_CLI::log( '  data:         ' . ( AC_Migration::is_done() ? 'migrated to 2.0.0 names' : 'NOT MIGRATED — run `wp assemble-content migrate`' ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'Reachability' );

		foreach ( AC_Types::types() as $key => $type ) {
			if ( $type->config( 'source' ) !== 'contentful' ) {
				continue;
			}

			$probe = AC_Contentful::get_entries( [ 'content_type' => $key, 'limit' => 1 ] );
			if ( $probe['error'] !== '' ) {
				WP_CLI::log( '  ✗ ' . $key . ': ' . $probe['error'] );
			} else {
				WP_CLI::log( sprintf( '  ✓ delivery API reachable — %d published %s entries', $probe['total'], $key ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Gating' );
		$mode = AC_Bypass::mode();
		WP_CLI::log( '  bypass mode:  ' . $mode . ( $mode === AC_Bypass::MODE_OFF ? '' : '   <-- the audience gate is OPEN' ) );

		WP_CLI::log( '' );
		WP_CLI::log( 'WordPress' );
		foreach ( AC_Types::types() as $key => $type ) {
			WP_CLI::log( sprintf( '  %-13s %s', $key . ':', $type->summary() ) );
			if ( $type instanceof AC_Post_Type_Content && get_post_type_archive_link( $type->post_type() ) ) {
				WP_CLI::log( '  archive:      ' . get_post_type_archive_link( $type->post_type() ) );
			}
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
	 * Rename data stored before 2.0.0 (afr_* options, _afr_* post meta, the
	 * afr_place taxonomy) to the ac names. Runs on its own on the first request
	 * after an upgrade; this runs it now, or again with --force.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Run even if this site is already marked as migrated.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content migrate
	 */
	public function migrate( array $args, array $assoc ): void {
		if ( AC_Migration::is_done() && ! isset( $assoc['force'] ) ) {
			WP_CLI::success( 'Already migrated. Pass --force to run again.' );

			return;
		}

		$result = AC_Migration::run();

		if ( ! $result['ran'] ) {
			WP_CLI::error( 'Not run: ' . implode( '; ', $result['messages'] ) );
		}

		foreach ( $result['messages'] as $message ) {
			WP_CLI::log( '  ' . $message );
		}

		WP_CLI::success( $result['messages'] ? 'Migrated.' : 'Nothing to migrate.' );
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

		foreach ( AC_Settings::audience_roles() as $audience => $roles ) {
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
	 * Print the audience matrix for every gated type — which posts are visible to whom.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content matrix
	 */
	public function matrix(): void {
		$gated = array_values( array_map( static fn( $type ) => $type->post_type(), array_filter( AC_Types::post_type_objects(), static fn( $type ) => $type->is_gated() ) ) );
		$posts = $gated ? get_posts(
			[
				'post_type'   => $gated,
				'post_status' => 'any',
				'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- WP-CLI only; the matrix lists every gated post.
			]
		) : [];

		if ( ! $posts ) {
			WP_CLI::warning( 'Nothing gated synced yet.' );

			return;
		}

		$audiences = AC_Settings::audiences();
		$rows      = [];

		foreach ( $posts as $post ) {
			$allowed = AC_Audience::report_audiences( $post->ID );
			$row     = [
				'post'   => $post->ID,
				'type'   => $post->post_type,
				'slug'   => $post->post_name,
				'status' => $post->post_status,
			];

			foreach ( $audiences as $audience ) {
				$row[ $audience ] = in_array( $audience, $allowed, true ) ? '✓' : '·';
			}

			$rows[] = $row;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array_merge( [ 'post', 'type', 'slug', 'status' ], $audiences ) );
	}

	/**
	 * Print a signed draft-preview link for one entry: anyone with it can read
	 * the latest draft until it expires.
	 *
	 * ## OPTIONS
	 *
	 * <entry-id>
	 * : Contentful entry ID.
	 *
	 * [--days=<days>]
	 * : How long the link works. Default 7, at most 30.
	 *
	 * [--view=<view>]
	 * : standard, council, delegate, public or denied. Default standard.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content preview-link fieldreport-aeo-benchmarking-20260708
	 *
	 * @subcommand preview-link
	 */
	public function preview_link( array $args, array $assoc ): void {
		if ( ! AC_Preview::is_configured() ) {
			WP_CLI::warning( 'No preview token is stored, so the link will show "not set up" until one is.' );
		}

		$days = max( 1, min( 30, (int) ( $assoc['days'] ?? 7 ) ) );
		WP_CLI::line( AC_Preview::signed_url( (string) $args[0], $days * DAY_IN_SECONDS, sanitize_key( (string) ( $assoc['view'] ?? '' ) ) ) );
	}
}
