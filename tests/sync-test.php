<?php
/**
 * Sync engine tests: pagination, safe orphan drafting, and type isolation.
 *
 * Runs against a fake Contentful Delivery API (pre_http_request), with two
 * throwaway test types registered through `afr_content_types`. Real types are
 * forced to fail their fetch, which doubles as a check that a failed fetch never
 * drafts real content. Everything the test creates is deleted at the end.
 *
 * Local only:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/sync-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

const AFR_TEST_PAGED = 'afrTestPaged';
const AFR_TEST_OTHER = 'afrTestOther';

$GLOBALS['afr_test'] = [
	'data'     => [ AFR_TEST_PAGED => [], AFR_TEST_OTHER => [] ],
	'requests' => [],
	'fail_at'  => [],   // type => skip offset whose page returns HTTP 500.
	'truncate' => [],   // type => serve at most N items while still reporting the full total.
	'dupe'     => [],   // type => true: page 2 repeats page 1's last item and drops one.
];

function afr_test_entry( string $type, int $i ): array {
	return [
		'sys'    => [
			'id'          => sprintf( '%s-%03d', $type, $i ),
			'revision'    => 1,
			'updatedAt'   => '2026-01-01T00:00:00Z',
			'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => $type ] ],
		],
		'fields' => [ 'title' => sprintf( 'Test %s %d', $type, $i ) ],
	];
}

function afr_test_set( string $type, int $count ): void {
	// range( 1, 0 ) counts down, so an empty set needs its own branch.
	$GLOBALS['afr_test']['data'][ $type ] = $count > 0
		? array_map( static fn( $i ) => afr_test_entry( $type, $i ), range( 1, $count ) )
		: [];
}

function afr_test_response( int $code, array $body ): array {
	return [
		'headers'  => [],
		'body'     => wp_json_encode( $body ),
		'response' => [ 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Error' ],
		'cookies'  => [],
		'filename' => null,
	];
}

// The fake Delivery API.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( (string) $url, 'cdn.contentful.com' ) ) {
			return $pre;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$type  = (string) ( $q['content_type'] ?? '' );
		$skip  = (int) ( $q['skip'] ?? 0 );
		$limit = (int) ( $q['limit'] ?? 100 );
		$t     = &$GLOBALS['afr_test'];

		$t['requests'][] = $type . '@' . $skip;

		// Real types never reach Contentful from this test.
		if ( ! isset( $t['data'][ $type ] ) ) {
			return afr_test_response( 503, [ 'message' => 'test: real types are offline' ] );
		}

		if ( isset( $t['fail_at'][ $type ] ) && $skip >= $t['fail_at'][ $type ] ) {
			return afr_test_response( 500, [ 'message' => 'test: page failure' ] );
		}

		$all   = $t['data'][ $type ];
		$total = count( $all );

		if ( isset( $t['truncate'][ $type ] ) ) {
			$all = array_slice( $all, 0, $t['truncate'][ $type ] );
		}

		$page = array_slice( $all, $skip, $limit );

		if ( ! empty( $t['dupe'][ $type ] ) && $skip > 0 && $page ) {
			// Simulates an entry jumping pages mid-fetch: one seen twice, one never.
			$page[0] = $all[ $skip - 1 ];
		}

		return afr_test_response( 200, [ 'total' => $total, 'skip' => $skip, 'limit' => $limit, 'items' => array_values( $page ) ] );
	},
	10,
	3
);

// Two throwaway types alongside the real ones.
add_filter(
	'afr_content_types',
	static function ( array $types ): array {
		$map = static fn( array $e ): array => [
			'post' => [ 'post_title' => $e['fields']['title'], 'post_name' => $e['sys']['id'] ],
			'meta' => [],
		];

		$types[ AFR_TEST_PAGED ] = [ 'post_type' => 'afr_test_paged', 'slug' => 'afr-test-paged', 'page_size' => 100, 'map' => $map ];
		$types[ AFR_TEST_OTHER ] = [ 'post_type' => 'afr_test_other', 'slug' => 'afr-test-other', 'page_size' => 100, 'map' => $map ];

		return $types;
	}
);
AFR_Types::register_post_types();

// ---------------------------------------------------------------- harness

// eval-file runs this file inside a function, so shared state lives in $GLOBALS.
$GLOBALS['afr_failures'] = 0;

function afr_check( string $label, bool $ok, string $detail = '' ): void {
	if ( ! $ok ) {
		$GLOBALS['afr_failures']++;
	}
	WP_CLI::log( sprintf( '  %s %s%s', $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '' ) );
}

function afr_published( string $post_type ): int {
	return (int) ( wp_count_posts( $post_type )->publish ?? 0 );
}

function afr_requests_for( string $type ): int {
	return count( array_filter( $GLOBALS['afr_test']['requests'], static fn( $r ) => str_starts_with( $r, $type . '@' ) ) );
}

function afr_reset_faults(): void {
	$GLOBALS['afr_test']['fail_at']  = [];
	$GLOBALS['afr_test']['truncate'] = [];
	$GLOBALS['afr_test']['dupe']     = [];
	$GLOBALS['afr_test']['requests'] = [];
}

function afr_summary( array $r ): string {
	return sprintf( 'created %d, updated %d, unchanged %d, drafted %d, errors %d', $r['created'], $r['updated'], $r['unchanged'], $r['drafted'], $r['errors'] );
}

$saved_last_sync   = get_option( 'afr_last_sync' );
$saved_features    = get_option( AFR_Features::OPTION );
$saved_communities = get_option( AFR_Communities::OPTION );
$real_reports      = afr_published( AFR_CPT::POST_TYPE );

try {
	WP_CLI::log( '1. 150 entries arrive across two pages' );
	afr_test_set( AFR_TEST_PAGED, 150 );
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( 'created all 150', 150 === $r['created'] && 0 === $r['errors'], afr_summary( $r ) );
	afr_check( 'fetched in 2 pages', 2 === afr_requests_for( AFR_TEST_PAGED ) );
	afr_check( '150 published', 150 === afr_published( 'afr_test_paged' ) );

	WP_CLI::log( '2. A re-run changes nothing' );
	afr_reset_faults();
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( '150 unchanged, 0 drafted', 150 === $r['unchanged'] && 0 === $r['drafted'], afr_summary( $r ) );

	WP_CLI::log( '3. Page 2 fails: nothing is drafted' );
	afr_reset_faults();
	afr_test_set( AFR_TEST_PAGED, 150 );
	$GLOBALS['afr_test']['fail_at'][ AFR_TEST_PAGED ] = 100;
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( '0 drafted, error reported', 0 === $r['drafted'] && $r['errors'] > 0, afr_summary( $r ) );
	afr_check( 'still 150 published', 150 === afr_published( 'afr_test_paged' ) );

	WP_CLI::log( '4. Contentful reports 150 but serves 120: nothing is drafted' );
	afr_reset_faults();
	$GLOBALS['afr_test']['truncate'][ AFR_TEST_PAGED ] = 120;
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( '0 drafted, error reported', 0 === $r['drafted'] && $r['errors'] > 0, afr_summary( $r ) );
	afr_check( 'still 150 published', 150 === afr_published( 'afr_test_paged' ) );

	WP_CLI::log( '5. An entry jumps pages mid-fetch (one twice, one never): nothing is drafted' );
	afr_reset_faults();
	$GLOBALS['afr_test']['dupe'][ AFR_TEST_PAGED ] = true;
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( '0 drafted, error reported', 0 === $r['drafted'] && $r['errors'] > 0, afr_summary( $r ) );
	afr_check( 'still 150 published', 150 === afr_published( 'afr_test_paged' ) );

	WP_CLI::log( '6. A complete fetch of 90 still drafts the 60 that were unpublished' );
	afr_reset_faults();
	afr_test_set( AFR_TEST_PAGED, 90 );
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( 'drafted exactly 60', 60 === $r['drafted'] && 0 === $r['errors'], afr_summary( $r ) );
	afr_check( '90 published', 90 === afr_published( 'afr_test_paged' ) );

	WP_CLI::log( '7. Syncing one type never drafts another type' );
	afr_reset_faults();
	afr_test_set( AFR_TEST_OTHER, 5 );
	AFR_Sync::sync_all( false, 'test', AFR_TEST_OTHER );
	afr_check( 'other type has 5', 5 === afr_published( 'afr_test_other' ) );
	afr_test_set( AFR_TEST_PAGED, 0 );
	$GLOBALS['afr_test']['requests'] = [];
	$r = AFR_Sync::sync_all( false, 'test', AFR_TEST_PAGED );
	afr_check( 'paged type emptied (90 drafted)', 90 === $r['drafted'] && 0 === afr_published( 'afr_test_paged' ), afr_summary( $r ) );
	afr_check( 'other type untouched', 5 === afr_published( 'afr_test_other' ) );
	afr_check( 'only the paged type was fetched', 0 === afr_requests_for( AFR_TEST_OTHER ) && 0 === afr_requests_for( 'fieldReport' ) );
	afr_check( 'Field Reports untouched', $real_reports === afr_published( AFR_CPT::POST_TYPE ) );

	WP_CLI::log( '8. A full sync where the real types fail drafts none of them' );
	afr_reset_faults();
	afr_test_set( AFR_TEST_PAGED, 3 );
	$r = AFR_Sync::sync_all( false, 'test' );
	afr_check( 'Field Reports still published', $real_reports === afr_published( AFR_CPT::POST_TYPE ), afr_summary( $r ) );
	afr_check( 'Site Features cache kept', get_option( AFR_Features::OPTION ) === $saved_features );
	afr_check( 'community directory kept', get_option( AFR_Communities::OPTION ) === $saved_communities );
	afr_check( 'test types still synced', 3 === afr_published( 'afr_test_paged' ) && 5 === afr_published( 'afr_test_other' ) );
} finally {
	// Clean up everything the test created.
	foreach ( [ 'afr_test_paged', 'afr_test_other' ] as $post_type ) {
		foreach ( get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
	update_option( 'afr_last_sync', $saved_last_sync, false );
}

if ( $GLOBALS['afr_failures'] > 0 ) {
	WP_CLI::error( sprintf( '%d check(s) failed.', $GLOBALS['afr_failures'] ) );
}

WP_CLI::success( 'All sync checks passed.' );
