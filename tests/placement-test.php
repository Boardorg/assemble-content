<?php
/**
 * Placement (`publishTo`, `functionalAreas` and their `showOn` / Community
 * fallbacks), post dates, a failed fetch versus an unpublished entry, and
 * webhook de-duplication.
 *
 * Runs against a fake Delivery API with one throwaway placed type. Options it
 * touches are restored and everything it creates is deleted at the end.
 *
 * Local only:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/placement-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

const AC_PT_TYPE = 'acTestPlaced';

// Fresh entry IDs each run, so a delivery remembered by an earlier run can't be skipped.
$id1 = 'pt1-' . wp_generate_password( 8, false, false );
$id2 = 'pt2-' . wp_generate_password( 8, false, false );

$fail = 0;
$ok   = static function ( bool $cond, string $label ) use ( &$fail ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$fail += $cond ? 0 : 1;
};
$as = static function ( string $channel ) {
	remove_all_filters( 'ac_site_channel' );
	add_filter( 'ac_site_channel', static fn () => $channel );
};

$saved = [
	'communities' => get_option( AC_Communities::OPTION, null ),
	'timezone'    => get_option( 'timezone_string', '' ),
	'gmt_offset'  => get_option( 'gmt_offset', 0 ),
	'last_sync'   => get_option( 'ac_last_sync', null ),
];

update_option(
	AC_Communities::OPTION,
	[
		'learning-development' => [ 'id' => 'c1', 'slug' => 'learning-development', 'name' => 'Learning & Development Board', 'short_name' => 'L&D', 'practice_area' => 'human-resources', 'groups' => [ 'learning-development' ], 'boilerplate' => 'LDB boilerplate', 'logo' => null ],
		'aeo'                  => [ 'id' => 'c2', 'slug' => 'aeo', 'name' => 'AEO Board', 'short_name' => 'AEO', 'practice_area' => 'marketing', 'groups' => [ 'aeo' ], 'boilerplate' => 'AEO boilerplate', 'logo' => null ],
	],
	false
);

$entry = static function ( string $id, array $fields, string $type = AC_PT_TYPE, int $rev = 1 ): array {
	return [
		'sys'    => [
			'id'          => $id,
			'revision'    => $rev,
			'createdAt'   => '2026-09-01T12:00:00Z',
			'updatedAt'   => '2026-09-01T12:00:00Z',
			'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => $type ] ],
		],
		'fields' => array_merge( [ 'title' => 'Test ' . $id ], $fields ),
	];
};

// ---------------------------------------------------------------- places
echo "1. Places\n";
$ok( [ 'Assemble Site', 'HR Network' ] === AC_Placement::places( $entry( 'a', [ 'publishTo' => [ 'Assemble Site', 'HR Network' ], 'showOn' => [ 'Member Center' ] ] ) ), 'publishTo wins over showOn' );
$ok( [ 'Assemble Site' ] === AC_Placement::places( $entry( 'a', [ 'showOn' => [ 'Public website' ] ] ) ), 'showOn "Public website" is the Assemble Site' );
$ok(
	[ 'Learning & Development Board', 'AEO Board' ] === AC_Placement::places( $entry( 'a', [ 'showOn' => [ 'Member Center' ], 'functionalAreas' => [ 'Learning & Development Board', 'AEO Board' ] ] ) ),
	'showOn "Member Center" is each functional area\'s Board'
);
$ok( [] === AC_Placement::places( $entry( 'a', [] ) ), 'nothing ticked: no places' );

echo "2. Which site an entry syncs to\n";
$both = $entry( 'a', [ 'publishTo' => [ 'Assemble Site', 'HR Network' ] ] );
$site = $entry( 'a', [ 'publishTo' => [ 'Assemble Site' ] ] );
$hr   = $entry( 'a', [ 'publishTo' => [ 'HR Network' ] ] );
$as( 'Public website' );
$ok( AC_Placement::on_site( $both ) && AC_Placement::on_site( $site ) && ! AC_Placement::on_site( $hr ), 'Public website: only Assemble Site pieces' );
$as( 'Assemble Site' );
$ok( AC_Placement::on_site( $site ) && ! AC_Placement::on_site( $hr ), 'Assemble Site channel: same as Public website' );
$as( 'Member Center' );
$ok( AC_Placement::on_site( $both ) && AC_Placement::on_site( $hr ) && ! AC_Placement::on_site( $site ), 'Member Center: any place but the Assemble Site' );
$as( 'HR Network' );
$ok( AC_Placement::on_site( $hr ) && ! AC_Placement::on_site( $site ), 'a named channel: that place only' );
$as( '' );
$ok( ! AC_Placement::on_site( $both ), 'no channel: nothing' );

// ------------------------------------------------------- functional areas
echo "3. Functional areas\n";
$fa = $entry( 'b', [ 'functionalAreas' => [ 'aeo board', 'Learning & Development Board', 'Nope Board' ], 'publishTo' => [ 'HR Network' ] ], 'fieldReport' );
$n  = AC_Placement::normalize( $fa );
$ok( 'AEO Board' === ( $n['fields']['primaryCommunity']['fields']['name'] ?? '' ), 'first ticked Board fills primaryCommunity (case-insensitive name)' );
$ok( 1 === count( $n['fields']['additionalCommunities'] ?? [] ) && 'human-resources' === ( $n['fields']['additionalCommunities'][0]['fields']['practiceArea'] ?? '' ), 'the rest fill additionalCommunities, with practice area' );
$ok( [ 'functional area "Nope Board" has no Community entry' ] === AC_Placement::warnings( $n ), 'unknown Board is a warning' );
$pb = AC_Placement::normalize( $entry( 'c', [ 'functionalAreas' => [ 'Learning & Development Board' ] ], 'playbook' ) );
$ok( 'Learning & Development Board' === ( $pb['fields']['community']['fields']['name'] ?? '' ) && ! isset( $pb['fields']['primaryCommunity'] ), 'playbooks: first Board fills community' );
$legacy = $entry( 'd', [ 'primaryCommunity' => [ 'fields' => [ 'name' => 'Linked' ] ] ], 'fieldReport' );
$ok( $legacy === AC_Placement::normalize( $legacy ), 'no functionalAreas: entry untouched' );
$ok( [ 'no place ticked in Publish to' ] === AC_Placement::warnings( $legacy ), 'no place is a warning' );

$vm = AC_Field_Report_Renderer::view_model_for( $n, AC_Audience::VIEW_DENIED );
$ok( [ 'AEO Board', 'Learning & Development Board' ] === array_column( $vm['functional_areas'], 'name' ), 'view model: every functional area, in order, for any view' );
$ok( 'AEO Board' === ( $vm['sections']['kicker']['name'] ?? '' ) && [ 'HR Network' ] === $vm['places'], 'view model: kicker is the first; places listed' );

// ----------------------------------------------------------------- dates
echo "4. Post dates\n";
update_option( 'timezone_string', 'America/New_York' );
$d = AC_Post_Date::for_entry( $entry( 'e', [ 'publishDate' => '2026-06-16' ] ) );
$ok( '2026-06-16 00:00:00' === $d['post_date'] && '2026-06-16 04:00:00' === $d['post_date_gmt'], 'publishDate (date only): local midnight' );
$d = AC_Post_Date::for_entry( $entry( 'e', [ 'sourceLine' => 'From the June 16, 2026 L&D Board discussion.' ] ) );
$ok( '2026-06-16 00:00:00' === $d['post_date'], 'source line date: local midnight' );
$d = AC_Post_Date::for_entry( $entry( 'e', [] ) );
$ok( '2026-09-01 08:00:00' === $d['post_date'] && '2026-09-01 12:00:00' === $d['post_date_gmt'], 'createdAt: converted from UTC, never stored as local' );
$late = $entry( 'e', [] );
$late['sys']['createdAt'] = gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 );
$d = AC_Post_Date::for_entry( $late );
$ok( $d['post_date_gmt'] <= gmdate( 'Y-m-d H:i:s' ), 'a report created a minute ago is not in the future (the old UTC bug)' );
$d = AC_Post_Date::for_entry( $entry( 'e', [ 'publishDate' => '2099-01-01' ] ) );
$ok( $d['post_date_gmt'] <= gmdate( 'Y-m-d H:i:s' ), 'a future publishDate is clamped to now' );
$ok( '2026-06-16' === gmdate( 'Y-m-d', AC_Post_Date::timestamp( $entry( 'e', [ 'publishDate' => '2026-06-16' ] ) ) ), 'display timestamp shows the local day' );
update_option( 'timezone_string', $saved['timezone'] );

// ------------------------------------------------- sync against a fake CDA
$GLOBALS['ac_pt'] = [ 'items' => [], 'status' => 200 ];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( (string) $url, 'cdn.contentful.com' ) ) {
			return $pre;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$t     = $GLOBALS['ac_pt'];
		$items = array_values( $t['items'] );

		if ( isset( $q['sys_id'] ) || isset( $q['sys.id'] ) ) {
			$id    = (string) ( $q['sys_id'] ?? $q['sys.id'] );
			$items = array_values( array_filter( $items, static fn( $e ) => $e['sys']['id'] === $id ) );
		} elseif ( ( $q['content_type'] ?? '' ) !== AC_PT_TYPE ) {
			return [ 'headers' => [], 'body' => wp_json_encode( [ 'message' => 'offline' ] ), 'response' => [ 'code' => 503, 'message' => 'Error' ], 'cookies' => [], 'filename' => null ];
		}

		$body = 200 === $t['status'] ? [ 'total' => count( $items ), 'items' => $items ] : [ 'message' => 'test failure' ];

		return [ 'headers' => [], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => $t['status'], 'message' => 'x' ], 'cookies' => [], 'filename' => null ];
	},
	10,
	3
);

add_filter(
	'ac_content_types',
	static function ( array $types ): array {
		$types[ AC_PT_TYPE ] = [
			'post_type' => 'ac_test_placed',
			'slug'      => 'ac-test-placed',
			'placed'    => true,
			'map'       => static fn( array $e ): array => [ 'post' => [ 'post_title' => $e['fields']['title'] ], 'meta' => [] ],
		];

		return $types;
	}
);
AC_Types::register_post_types();
AC_Placement::register_taxonomy();

$put = static function ( array ...$entries ) {
	$GLOBALS['ac_pt']['items'] = [];
	foreach ( $entries as $e ) {
		$GLOBALS['ac_pt']['items'][ $e['sys']['id'] ] = $e;
	}
};

try {
	echo "5. A failed fetch is not an unpublish\n";
	$as( 'Member Center' );
	$put( $entry( $id1, [ 'publishTo' => [ 'HR Network' ] ] ) );
	$r    = AC_Sync::sync_one( $id1, true, 'test' );
	$post = AC_Sync::find_post_by_entry_id( $id1 );
	$ok( 1 === $r['created'] && 'publish' === get_post_status( $post ), 'placed entry created' );
	$ok( [ 'HR Network' ] === AC_Query::places( $post ) && [ $post ] === wp_list_pluck( AC_Query::for_places( [ 'HR Network' ], 5, [], [ 'ac_test_placed' ] ), 'ID' ), 'places stored and queryable' );

	foreach ( [ 500, 429, 401 ] as $code ) {
		$GLOBALS['ac_pt']['status'] = $code;
		$r = AC_Sync::sync_one( $id1, true, 'test' );
		$ok( 1 === $r['errors'] && 0 === $r['drafted'] && 'publish' === get_post_status( $post ), "HTTP {$code}: error reported, post left published" );
	}
	$GLOBALS['ac_pt']['status'] = 200;

	$put();
	$r = AC_Sync::sync_one( $id1, true, 'test' );
	$ok( 1 === $r['drafted'] && 'draft' === get_post_status( $post ), 'really unpublished: drafted' );

	echo "6. Off-site entries are drafted with a reason\n";
	$put( $entry( $id1, [ 'publishTo' => [ 'HR Network' ] ], AC_PT_TYPE, 2 ), $entry( $id2, [ 'publishTo' => [ 'Assemble Site' ] ] ) );
	AC_Sync::sync_one( $id1, true, 'test' );
	$as( 'Public website' );
	AC_Sync::sync_one( $id2, true, 'test' );
	$post2 = AC_Sync::find_post_by_entry_id( $id2 );
	$as( 'Member Center' );
	$r = AC_Sync::sync_all( true, 'test', AC_PT_TYPE );
	$ok( 'publish' === get_post_status( $post ) && 'draft' === get_post_status( $post2 ), 'Member Center full sync: HR Network piece kept, Assemble-Site-only piece drafted' );
	$ok( 'not published to this site' === get_post_meta( $post2, '_ac_drafted_reason', true ), 'drafted reason says why' );

	echo "7. Webhook de-duplication\n";
	$hook = static function ( string $topic, string $id, int $rev ): WP_REST_Response {
		$req = new WP_REST_Request( 'POST', '/assemble/v1/contentful' );
		$req->set_header( 'X-Contentful-Topic', $topic );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( [ 'sys' => [ 'id' => $id, 'type' => 'Entry', 'revision' => $rev, 'contentType' => [ 'sys' => [ 'id' => AC_PT_TYPE ] ] ] ] ) );

		return AC_Contentful_Webhook::handle( $req );
	};

	$put( $entry( $id1, [ 'publishTo' => [ 'HR Network' ] ], AC_PT_TYPE, 3 ) );
	$res = $hook( 'ContentManagement.Entry.publish', $id1, 3 );
	$ok( 200 === $res->get_status() && 'publish' === get_post_status( $post ), 'publish handled' );
	$res = $hook( 'ContentManagement.Entry.publish', $id1, 3 );
	$ok( 'duplicate delivery' === ( $res->get_data()['skipped'] ?? '' ), 'the same delivery again: skipped' );
	$put();
	$res = $hook( 'ContentManagement.Entry.unpublish', $id1, 3 );
	$ok( 200 === $res->get_status() && 'draft' === get_post_status( $post ), 'unpublish straight after publish: handled, not dropped' );

	wp_cache_add( $id1, 1, 'ac_webhook', 60 );
	$res = $hook( 'ContentManagement.Entry.publish', $id1, 4 );
	$ok( 503 === $res->get_status(), 'entry already syncing: 503 so Contentful retries' );
	wp_cache_delete( $id1, 'ac_webhook' );

	$GLOBALS['ac_pt']['status'] = 500;
	$put( $entry( $id1, [ 'publishTo' => [ 'HR Network' ] ], AC_PT_TYPE, 5 ) );
	$res = $hook( 'ContentManagement.Entry.publish', $id1, 5 );
	$GLOBALS['ac_pt']['status'] = 200;
	$res2 = $hook( 'ContentManagement.Entry.publish', $id1, 5 );
	$ok( 500 === $res->get_status() && 200 === $res2->get_status() && 'publish' === get_post_status( $post ), 'a failed delivery is not remembered: the retry syncs' );
} finally {
	foreach ( get_posts( [ 'post_type' => 'ac_test_placed', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( [ 'HR Network', 'Assemble Site' ] as $place ) {
		$term = get_term_by( 'slug', AC_Placement::slug( $place ), AC_Placement::TAXONOMY );
		if ( $term && 0 === (int) $term->count ) {
			wp_delete_term( (int) $term->term_id, AC_Placement::TAXONOMY );
		}
	}
	null === $saved['communities'] ? delete_option( AC_Communities::OPTION ) : update_option( AC_Communities::OPTION, $saved['communities'], false );
	null === $saved['last_sync'] ? delete_option( 'ac_last_sync' ) : update_option( 'ac_last_sync', $saved['last_sync'], false );
	update_option( 'timezone_string', $saved['timezone'] );
	update_option( 'gmt_offset', $saved['gmt_offset'] );
	remove_all_filters( 'ac_site_channel' );
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
