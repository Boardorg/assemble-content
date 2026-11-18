<?php
/**
 * Background change sync tests: the Sync API queue, linked re-syncs, the webhook.
 *
 * Runs against a fake Contentful Delivery API (pre_http_request), with only two
 * throwaway types registered: a synced post type and a dependency it links to.
 * Everything the test creates is deleted at the end, and settings are restored.
 *
 * Local only:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/changes-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

const AC_CT_TALK   = 'acTestTalk';
const AC_CT_PERSON = 'acTestPerson';

$GLOBALS['ac_ct'] = [
	'entries'  => [],   // id => entry served by the CDA.
	'initial'  => [],   // pages of the first full list.
	'changes'  => [],   // items the next delta serves.
	'links'    => [],   // Keyed entry:ID or asset:ID, each a list of linking [ ID, type ] pairs.
	'fail'     => [],   // Keyed like links: that lookup answers HTTP 500.
	'requests' => [],
	'delta'    => 0,
	'down'     => false,
];

function ac_ct_entry( string $id, string $type, string $title, int $rev = 1 ): array {
	return [
		'sys'    => [
			'id'          => $id,
			'type'        => 'Entry',
			'revision'    => $rev,
			'updatedAt'   => sprintf( '2026-01-01T00:00:%02dZ', $rev ),
			'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => $type ] ],
		],
		'fields' => [ 'title' => $title ],
	];
}

function ac_ct_change( string $kind, string $id, string $type = '' ): array {
	$sys = [ 'id' => $id, 'type' => $kind ];
	if ( $type !== '' ) {
		$sys['contentType'] = [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => $type ] ];
	}

	return [ 'sys' => $sys ];
}

function ac_ct_response( int $code, array $body ): array {
	return [
		'headers'  => [],
		'body'     => wp_json_encode( $body ),
		'response' => [ 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Error' ],
		'cookies'  => [],
		'filename' => null,
	];
}

function ac_ct_sync_url( string $key, string $token ): string {
	return 'https://cdn.contentful.com/spaces/test/environments/test/sync?sync_token=' . $token;
}

// The fake Delivery API.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( (string) $url, 'cdn.contentful.com' ) ) {
			return $pre;
		}

		$t    = &$GLOBALS['ac_ct'];
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );

		if ( str_ends_with( $path, '/sync' ) ) {
			$token           = (string) ( $q['sync_token'] ?? '' );
			$t['requests'][] = 'sync:' . ( $token ?: 'initial' );

			if ( 'expired' === $token ) {
				return ac_ct_response( 400, [ 'message' => 'test: bad sync token' ] );
			}

			if ( ! empty( $t['down'] ) ) {
				return ac_ct_response( 503, [ 'message' => 'test: Sync API down' ] );
			}

			// The first full list, page by page.
			if ( ! empty( $q['initial'] ) || str_starts_with( $token, 'init-' ) ) {
				$page = $token === '' ? 0 : (int) substr( $token, 5 );
				$body = [ 'items' => $t['initial'][ $page ] ?? [] ];
				if ( isset( $t['initial'][ $page + 1 ] ) ) {
					$body['nextPageUrl'] = ac_ct_sync_url( 'page', 'init-' . ( $page + 1 ) );
				} else {
					$body['nextSyncUrl'] = ac_ct_sync_url( 'sync', 'delta-' . ( ++$t['delta'] ) );
				}

				return ac_ct_response( 200, $body );
			}

			$items        = $t['changes'];
			$t['changes'] = [];

			return ac_ct_response( 200, [ 'items' => $items, 'nextSyncUrl' => ac_ct_sync_url( 'sync', 'delta-' . ( ++$t['delta'] ) ) ] );
		}

		// Link lookups.
		foreach ( [ 'links_to_entry' => 'entry', 'links_to_asset' => 'asset' ] as $arg => $kind ) {
			if ( isset( $q[ $arg ] ) ) {
				$key             = $kind . ':' . $q[ $arg ];
				$t['requests'][] = 'links:' . $key;

				if ( ! empty( $t['fail'][ $key ] ) ) {
					return ac_ct_response( 500, [ 'message' => 'test: lookup failure' ] );
				}

				$items = array_map(
					static fn( $l ) => [ 'sys' => [ 'id' => $l[0], 'type' => 'Entry', 'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => $l[1] ] ] ] ],
					$t['links'][ $key ] ?? []
				);

				return ac_ct_response( 200, [ 'total' => count( $items ), 'items' => $items ] );
			}
		}

		// One entry by ID.
		if ( isset( $q['sys_id'] ) || isset( $q['sys.id'] ) ) {
			$id              = (string) ( $q['sys_id'] ?? $q['sys.id'] );
			$t['requests'][] = 'one:' . $id;
			$items           = isset( $t['entries'][ $id ] ) ? [ $t['entries'][ $id ] ] : [];

			return ac_ct_response( 200, [ 'total' => count( $items ), 'items' => $items ] );
		}

		// A full fetch of one type.
		$type            = (string) ( $q['content_type'] ?? '' );
		$t['requests'][] = 'all:' . $type;
		$items           = array_values( array_filter( $t['entries'], static fn( $e ) => $e['sys']['contentType']['sys']['id'] === $type ) );

		return ac_ct_response( 200, [ 'total' => count( $items ), 'skip' => 0, 'limit' => 100, 'items' => $items ] );
	},
	10,
	3
);

// Only the two test types: a talk that links to a person.
add_filter(
	'ac_content_types',
	static function (): array {
		return [
			AC_CT_TALK => [
				'post_type'    => 'ac_test_talk',
				'slug'         => 'ac-test-talk',
				'page_size'    => 100,
				'dependencies' => [ AC_CT_PERSON ],
				'map'          => static fn( array $e ): array => [
					'post' => [ 'post_title' => $e['fields']['title'], 'post_name' => $e['sys']['id'] ],
					'meta' => [],
				],
			],
		];
	},
	PHP_INT_MAX
);
AC_Types::register_post_types();

// ---------------------------------------------------------------- harness

$GLOBALS['ac_failures'] = 0;

function ac_check( string $label, bool $ok, string $detail = '' ): void {
	if ( ! $ok ) {
		$GLOBALS['ac_failures']++;
	}
	WP_CLI::log( sprintf( '  %s %s%s', $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '' ) );
}

function ac_ct_title( string $id ): string {
	$post_id = AC_Sync::find_post_by_entry_id( $id );

	return $post_id ? get_the_title( $post_id ) . ' [' . get_post_status( $post_id ) . ']' : '(none)';
}

function ac_ct_count( string $request ): int {
	return count( array_filter( $GLOBALS['ac_ct']['requests'], static fn( $r ) => $r === $request ) );
}

function ac_ct_run(): array {
	$GLOBALS['ac_ct']['requests'] = [];

	return AC_Contentful_Changes::run();
}

function ac_ct_report( array $r ): string {
	return sprintf( 'read %d, applied %d, synced %d, errors %d, queued %d%s', $r['fetched'], $r['processed'], $r['synced'], $r['errors'], $r['queued'], $r['messages'] ? '; ' . implode( '; ', $r['messages'] ) : '' );
}

function ac_ct_set( string $id, string $type, string $title, int $rev ): void {
	$GLOBALS['ac_ct']['entries'][ $id ] = ac_ct_entry( $id, $type, $title, $rev );
}

$saved_settings  = get_option( AC_Settings::OPTION );
$saved_last_sync = get_option( AC_Sync::LOG_OPTION );
$saved_state     = get_option( AC_Contentful_Changes::STATE );

update_option( AC_Settings::OPTION, [ 'space_id' => 'test', 'environment' => 'test', 'delivery_token' => 'test', 'webhook_secret' => 'test-secret' ] );
AC_Contentful_Changes::reset();
wp_clear_scheduled_hook( AC_Contentful_Changes::RUN );

try {
	ac_ct_set( 't1', AC_CT_TALK, 'Talk one', 1 );
	ac_ct_set( 't2', AC_CT_TALK, 'Talk two', 1 );
	ac_ct_set( 'p1', AC_CT_PERSON, 'Person one', 1 );
	$GLOBALS['ac_ct']['initial'] = [
		[ ac_ct_change( 'Entry', 't1', AC_CT_TALK ), ac_ct_change( 'Entry', 't2', AC_CT_TALK ) ],
		[ ac_ct_change( 'Entry', 'p1', AC_CT_PERSON ) ],
	];

	WP_CLI::log( '1. First run: reads the full list, queues nothing, then runs one full sync' );
	$r     = ac_ct_run();
	$state = AC_Contentful_Changes::state();
	ac_check( 'read both pages of the first list', 3 === $r['fetched'] && 1 === ac_ct_count( 'sync:initial' ) && 1 === ac_ct_count( 'sync:init-1' ), ac_ct_report( $r ) );
	ac_check( 'ready, with a delta token, nothing queued, no full sync due', $state['ready'] && str_starts_with( $state['token'], 'delta-' ) && ! $state['pending'] && ! $state['full'] );
	ac_check( 'the full sync created both talks', 'Talk one [publish]' === ac_ct_title( 't1' ) && 'Talk two [publish]' === ac_ct_title( 't2' ) );

	WP_CLI::log( '2. Nothing changed: one cheap read, nothing synced' );
	$r = ac_ct_run();
	ac_check( 'one sync read, no entry fetches', 1 === count( $GLOBALS['ac_ct']['requests'] ) && 0 === $r['synced'], ac_ct_report( $r ) );

	WP_CLI::log( '3. A talk is edited: only that talk is re-synced' );
	ac_ct_set( 't1', AC_CT_TALK, 'Talk one, edited', 2 );
	$GLOBALS['ac_ct']['changes'] = [ ac_ct_change( 'Entry', 't1', AC_CT_TALK ) ];
	$r                           = ac_ct_run();
	ac_check( 'talk one updated', 'Talk one, edited [publish]' === ac_ct_title( 't1' ), ac_ct_report( $r ) );
	ac_check( 'no other fetches', 1 === ac_ct_count( 'one:t1' ) && 0 === ac_ct_count( 'one:t2' ) && 0 === ac_ct_count( 'all:' . AC_CT_TALK ) );

	WP_CLI::log( '4. A person changes: only the talks that link to them are re-synced' );
	$GLOBALS['ac_ct']['links']['entry:p1'] = [ [ 't1', AC_CT_TALK ], [ 't2', AC_CT_TALK ] ];
	ac_ct_set( 't2', AC_CT_TALK, 'Talk two, new speaker card', 1 );
	$GLOBALS['ac_ct']['changes'] = [ ac_ct_change( 'Entry', 'p1', AC_CT_PERSON ) ];
	$r                           = ac_ct_run();
	ac_check( 'both linked talks re-synced, even at the same revision', 'Talk two, new speaker card [publish]' === ac_ct_title( 't2' ) && 1 === ac_ct_count( 'one:t1' ) && 1 === ac_ct_count( 'one:t2' ), ac_ct_report( $r ) );
	ac_check( 'no full sync', 0 === ac_ct_count( 'all:' . AC_CT_TALK ) );

	WP_CLI::log( '5. A headshot changes: asset -> person -> talks' );
	$GLOBALS['ac_ct']['links']['asset:a1'] = [ [ 'p1', AC_CT_PERSON ] ];
	$GLOBALS['ac_ct']['changes']           = [ ac_ct_change( 'Asset', 'a1' ) ];
	$r                                     = ac_ct_run();
	ac_check( 'followed two links to both talks', 1 === ac_ct_count( 'links:asset:a1' ) && 1 === ac_ct_count( 'links:entry:p1' ) && 1 === ac_ct_count( 'one:t1' ) && 1 === ac_ct_count( 'one:t2' ), ac_ct_report( $r ) );

	WP_CLI::log( '6. A burst: two people on the same talk, and the talk itself, sync the talk once' );
	$GLOBALS['ac_ct']['links']['entry:p2'] = [ [ 't1', AC_CT_TALK ] ];
	$GLOBALS['ac_ct']['changes']           = [ ac_ct_change( 'Entry', 'p1', AC_CT_PERSON ), ac_ct_change( 'Entry', 'p2', AC_CT_PERSON ), ac_ct_change( 'Entry', 't1', AC_CT_TALK ), ac_ct_change( 'Entry', 't1', AC_CT_TALK ) ];
	$r                                     = ac_ct_run();
	ac_check( 'three changes applied, talk one fetched once', 3 === $r['processed'] && 1 === ac_ct_count( 'one:t1' ), ac_ct_report( $r ) );

	WP_CLI::log( '7. A talk is unpublished: its post is drafted' );
	unset( $GLOBALS['ac_ct']['entries']['t2'] );
	$GLOBALS['ac_ct']['changes'] = [ ac_ct_change( 'DeletedEntry', 't2' ) ];
	$r                           = ac_ct_run();
	ac_check( 'talk two drafted', 'Talk two, new speaker card [draft]' === ac_ct_title( 't2' ), ac_ct_report( $r ) );

	WP_CLI::log( '8. A person is unpublished: links to them can\'t be looked up, so one full sync runs' );
	$GLOBALS['ac_ct']['changes'] = [ ac_ct_change( 'DeletedEntry', 'p9', AC_CT_PERSON ) ];
	$r                           = ac_ct_run();
	$state                       = AC_Contentful_Changes::state();
	ac_check( 'full sync ran once and is no longer due', 1 === ac_ct_count( 'all:' . AC_CT_TALK ) && ! $state['full'] && 0 === $r['errors'], ac_ct_report( $r ) );

	WP_CLI::log( '9. Something no synced type uses is unpublished: nothing to do' );
	$GLOBALS['ac_ct']['changes'] = [ ac_ct_change( 'DeletedEntry', 'x1', 'somethingElse' ) ];
	$r                           = ac_ct_run();
	ac_check( 'no full sync', 0 === ac_ct_count( 'all:' . AC_CT_TALK ) && ! AC_Contentful_Changes::state()['full'], ac_ct_report( $r ) );

	WP_CLI::log( '10. A failed lookup is kept and retried a minute later' );
	wp_clear_scheduled_hook( AC_Contentful_Changes::RUN );
	$GLOBALS['ac_ct']['fail']['entry:p1'] = true;
	$GLOBALS['ac_ct']['changes']          = [ ac_ct_change( 'Entry', 'p1', AC_CT_PERSON ) ];
	$r                                    = ac_ct_run();
	$next                                 = (int) wp_next_scheduled( AC_Contentful_Changes::RUN );
	ac_check( 'still queued, error reported', 1 === $r['queued'] && $r['errors'] > 0, ac_ct_report( $r ) );
	ac_check( 'next run in about a minute', $next >= time() + 50 && $next <= time() + 70 );
	unset( $GLOBALS['ac_ct']['fail']['entry:p1'] );
	$r = ac_ct_run();
	ac_check( 'applied on the retry', 0 === $r['queued'] && 0 === $r['errors'] && 1 === ac_ct_count( 'one:t1' ), ac_ct_report( $r ) );

	WP_CLI::log( '11. A lookup that keeps failing is dropped after three tries' );
	$GLOBALS['ac_ct']['fail']['entry:p1'] = true;
	$GLOBALS['ac_ct']['changes']          = [ ac_ct_change( 'Entry', 'p1', AC_CT_PERSON ) ];
	ac_ct_run();
	ac_ct_run();
	$r = ac_ct_run();
	ac_check( 'queue empty after three failures', 0 === $r['queued'], ac_ct_report( $r ) );
	unset( $GLOBALS['ac_ct']['fail']['entry:p1'] );

	WP_CLI::log( '12. A rejected token starts over with the full list and a full sync' );
	$state          = AC_Contentful_Changes::state();
	$state['token'] = 'expired';
	update_option( AC_Contentful_Changes::STATE, $state, false );
	$r     = ac_ct_run();
	$state = AC_Contentful_Changes::state();
	ac_check( 'restarted and caught up', $state['ready'] && str_starts_with( $state['token'], 'delta-' ) && 1 === ac_ct_count( 'sync:initial' ) && 1 === ac_ct_count( 'all:' . AC_CT_TALK ), ac_ct_report( $r ) );

	WP_CLI::log( '13. Switching environment drops the old token' );
	update_option( AC_Settings::OPTION, array_merge( get_option( AC_Settings::OPTION ), [ 'environment' => 'other' ] ) );
	ac_check( 'state is blank for the new environment', ! AC_Contentful_Changes::state()['ready'] && '' === AC_Contentful_Changes::state()['token'] );
	update_option( AC_Settings::OPTION, array_merge( get_option( AC_Settings::OPTION ), [ 'environment' => 'test' ] ) );
	ac_check( 'switching back finds it again', AC_Contentful_Changes::state()['ready'] );

	WP_CLI::log( '14. A second run while one holds the lock backs off' );
	wp_clear_scheduled_hook( AC_Contentful_Changes::RUN );
	set_transient( 'ac_contentful_changes_lock', time(), 600 );
	wp_cache_add( 'ac_contentful_changes_lock', time(), 'ac_changes', 600 );
	$r = ac_ct_run();
	ac_check( 'did nothing, retries in a minute', 0 === count( $GLOBALS['ac_ct']['requests'] ) && wp_next_scheduled( AC_Contentful_Changes::RUN ) > time() + 50, ac_ct_report( $r ) );
	delete_transient( 'ac_contentful_changes_lock' );
	wp_cache_delete( 'ac_contentful_changes_lock', 'ac_changes' );

	WP_CLI::log( '15. The webhook answers at once and schedules one run' );
	wp_clear_scheduled_hook( AC_Contentful_Changes::RUN );
	$GLOBALS['ac_ct']['requests'] = [];
	$send                         = static function ( string $secret ) {
		$request = new WP_REST_Request( 'POST', '/' . AC_Contentful_Webhook::NAMESPACE . AC_Contentful_Webhook::ROUTE );
		$request->set_header( 'X-AC-Secret', $secret );
		$request->set_header( 'X-Contentful-Topic', 'ContentManagement.Entry.publish' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( ac_ct_change( 'Entry', 'p1', AC_CT_PERSON ) ) );

		return rest_do_request( $request );
	};
	$first  = $send( 'test-secret' );
	$second = $send( 'test-secret' );
	$denied = $send( 'wrong' );
	$cron   = array_filter( _get_cron_array(), static fn( $hooks ) => isset( $hooks[ AC_Contentful_Changes::RUN ] ) );
	ac_check( '202, twice', 202 === $first->get_status() && 202 === $second->get_status() );
	ac_check( 'one run scheduled, about ten seconds out', 1 === count( $cron ) && (int) array_key_first( $cron ) <= time() + 15 );
	ac_check( 'no Contentful requests during the webhook', 0 === count( $GLOBALS['ac_ct']['requests'] ) );
	ac_check( 'wrong secret is refused', 401 === $denied->get_status() );

	WP_CLI::log( '16. The safety-net run is nightly, at 3am site time' );
	wp_clear_scheduled_hook( AC_Contentful_Changes::SWEEP );
	wp_schedule_event( time() + 300, 'hourly', AC_Contentful_Changes::SWEEP );
	AC_Contentful_Changes::schedule_sweep();
	$sweep = (int) wp_next_scheduled( AC_Contentful_Changes::SWEEP );
	ac_check( 'an old schedule is replaced with daily', 'daily' === wp_get_schedule( AC_Contentful_Changes::SWEEP ) );
	ac_check( 'next run is at 3am, within a day', '03:00' === wp_date( 'H:i', $sweep ) && $sweep > time() && $sweep <= time() + DAY_IN_SECONDS );

	WP_CLI::log( '17. A Sync API outage tries again in five minutes' );
	wp_clear_scheduled_hook( AC_Contentful_Changes::RUN );
	$state          = AC_Contentful_Changes::state();
	$saved_token    = $state['token'];
	$state['token'] = 'down';
	update_option( AC_Contentful_Changes::STATE, $state, false );
	$GLOBALS['ac_ct']['down'] = true;
	$r                        = ac_ct_run();
	$next                     = (int) wp_next_scheduled( AC_Contentful_Changes::RUN );
	ac_check( 'error reported, token kept, next run in about five minutes', $r['errors'] > 0 && 'down' === AC_Contentful_Changes::state()['token'] && $next >= time() + 290 && $next <= time() + 310, ac_ct_report( $r ) );
	$GLOBALS['ac_ct']['down'] = false;
} finally {
	foreach ( get_posts( [ 'post_type' => 'ac_test_talk', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids' ] ) as $test_post_id ) {
		wp_delete_post( $test_post_id, true );
	}

	wp_clear_scheduled_hook( AC_Contentful_Changes::RUN );
	wp_clear_scheduled_hook( AC_Contentful_Changes::SWEEP );

	foreach ( [ AC_Settings::OPTION => $saved_settings, AC_Sync::LOG_OPTION => $saved_last_sync, AC_Contentful_Changes::STATE => $saved_state ] as $option => $value ) {
		if ( false === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
}

WP_CLI::log( '' );
if ( $GLOBALS['ac_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d check(s) failed.', $GLOBALS['ac_failures'] ) );
}
WP_CLI::success( 'All checks passed.' );
