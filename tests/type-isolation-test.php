<?php
/**
 * Type isolation between the real types: a playbook sync never drafts a Field
 * Report, and a Field Report sync never drafts a playbook. Full syncs, a sync of
 * every type, and the single-entry (webhook) path, in both directions.
 *
 * Runs against a fake Delivery API that serves the site's own synced entries back
 * (from `_ac_data`), so nothing reaches Contentful. Creates one throwaway post
 * of each type, deletes them at the end, and puts back the status of any post
 * that changed.
 *
 * Local only:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/type-isolation-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['ac_iso'] = [ 'pass' => 0, 'fail' => 0, 'serve' => [], 'down' => [] ];

function ac_iso_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['ac_iso']['pass']++;
		return;
	}
	$GLOBALS['ac_iso']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

/** Published entries of a type, from the posts already synced here. */
function ac_iso_entries( string $post_type ): array {
	$entries = [];
	foreach ( get_posts( [ 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ] ) as $id ) {
		$data = AC_Post_Type_Content::data( (int) $id );
		if ( $data && ! str_starts_with( (string) ( $data['sys']['id'] ?? '' ), 'ac-iso-' ) ) {
			$entries[] = $data;
		}
	}

	return $entries;
}

/** post ID => status, for every post of the synced types. */
function ac_iso_statuses(): array {
	$out = [];
	foreach ( get_posts( [ 'post_type' => AC_Types::post_types(), 'post_status' => 'any', 'numberposts' => -1, 'suppress_filters' => true ] ) as $p ) {
		$out[ $p->ID ] = $p->post_type . ':' . $p->post_status;
	}

	return $out;
}

function ac_iso_changed( array $before, array $after, string $post_type ): array {
	return array_keys( array_filter( $after, static fn( $s, $id ) => str_starts_with( $s, $post_type . ':' ) && ( $before[ $id ] ?? '' ) !== $s, ARRAY_FILTER_USE_BOTH ) );
}

// The fake Delivery API: each type serves the entries in $GLOBALS['ac_iso']['serve'], complete,
// unless the type is listed as down (HTTP 503).
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( (string) $url, 'cdn.contentful.com' ) ) {
			return $pre;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$t    = $GLOBALS['ac_iso'];
		$type = (string) ( $q['content_type'] ?? '' );
		$id   = (string) ( $q['sys_id'] ?? '' ); // parse_str() turns 'sys.id' into 'sys_id'.
		$res  = static fn( int $code, array $body ) => [ 'headers' => [], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => $code, 'message' => 'x' ], 'cookies' => [], 'filename' => null ];

		if ( $id !== '' ) {
			foreach ( $t['serve'] as $items ) {
				foreach ( $items as $item ) {
					if ( $item['sys']['id'] === $id ) {
						return $res( 200, [ 'total' => 1, 'items' => [ $item ] ] );
					}
				}
			}
			return $res( 200, [ 'total' => 0, 'items' => [] ] );
		}

		if ( in_array( $type, $t['down'], true ) || ! isset( $t['serve'][ $type ] ) ) {
			return $res( 503, [ 'message' => 'test: offline' ] );
		}

		$items = array_slice( $t['serve'][ $type ], (int) ( $q['skip'] ?? 0 ), (int) ( $q['limit'] ?? 100 ) );

		return $res( 200, [ 'total' => count( $t['serve'][ $type ] ), 'skip' => (int) ( $q['skip'] ?? 0 ), 'items' => array_values( $items ) ] );
	},
	10,
	3
);

$ac_reports   = ac_iso_entries( AC_Field_Report_Type::POST_TYPE );
$ac_playbooks = ac_iso_entries( AC_Playbook_Type::POST_TYPE );
$ac_original  = ac_iso_statuses();
$ac_temp      = [];

if ( ! $ac_reports ) {
	WP_CLI::error( 'No published Field Reports. Run `wp assemble-content sync --all` first.' );
}

// One throwaway, published post of each type whose entry Contentful "no longer publishes".
foreach ( [ AC_Field_Report_Type::POST_TYPE => 'ac-iso-report', AC_Playbook_Type::POST_TYPE => 'ac-iso-playbook' ] as $ac_pt => $ac_eid ) {
	$ac_id = wp_insert_post( [ 'post_type' => $ac_pt, 'post_status' => 'publish', 'post_title' => $ac_eid, 'post_name' => $ac_eid ] );
	update_post_meta( $ac_id, AC_Post_Type_Content::META_ENTRY_ID, $ac_eid );
	$ac_temp[ $ac_pt ] = (int) $ac_id;
}

try {
	$ac_serve_all = [ 'fieldReport' => $ac_reports, 'playbook' => $ac_playbooks ];

	// 1. A playbook sync (complete) drafts the stale playbook and no Field Report.
	$GLOBALS['ac_iso']['serve'] = $ac_serve_all;
	$ac_before = ac_iso_statuses();
	$ac_r      = AC_Sync::sync_all( false, 'isolation test', 'playbook' );
	$ac_after  = ac_iso_statuses();
	ac_iso_check( 0 === $ac_r['errors'], 'playbook sync: no errors' );
	ac_iso_check( 'draft' === get_post_status( $ac_temp['playbook'] ), 'playbook sync: drafts the stale playbook' );
	ac_iso_check( [] === ac_iso_changed( $ac_before, $ac_after, AC_Field_Report_Type::POST_TYPE ), 'playbook sync: no Field Report changed' );
	ac_iso_check( 'publish' === get_post_status( $ac_temp['field_report'] ), 'playbook sync: the stale Field Report is left alone' );

	// 2. A Field Report sync (complete) drafts the stale report and no playbook.
	wp_update_post( [ 'ID' => $ac_temp['playbook'], 'post_status' => 'publish' ] );
	$ac_before = ac_iso_statuses();
	$ac_r      = AC_Sync::sync_all( false, 'isolation test', 'fieldReport' );
	$ac_after  = ac_iso_statuses();
	ac_iso_check( 0 === $ac_r['errors'], 'Field Report sync: no errors' );
	ac_iso_check( 'draft' === get_post_status( $ac_temp['field_report'] ), 'Field Report sync: drafts the stale report' );
	ac_iso_check( [] === ac_iso_changed( $ac_before, $ac_after, AC_Playbook_Type::POST_TYPE ), 'Field Report sync: no playbook changed' );
	ac_iso_check( 'publish' === get_post_status( $ac_temp['playbook'] ), 'Field Report sync: the stale playbook is left alone' );
	ac_iso_check( 0 === $ac_r['created'] && $ac_r['unchanged'] === count( $ac_reports ), 'Field Report sync: real reports unchanged' );

	// 3. Everything synced while playbooks are down: no playbook drafted, reports still sync.
	wp_update_post( [ 'ID' => $ac_temp['field_report'], 'post_status' => 'publish' ] );
	$GLOBALS['ac_iso']['down'] = [ 'playbook' ];
	$ac_before                 = ac_iso_statuses();
	AC_Sync::sync_all( false, 'isolation test' );
	$ac_after = ac_iso_statuses();
	ac_iso_check( [] === ac_iso_changed( $ac_before, $ac_after, AC_Playbook_Type::POST_TYPE ), 'all types, playbooks down: no playbook changed' );
	ac_iso_check( 'draft' === get_post_status( $ac_temp['field_report'] ), 'all types, playbooks down: reports still orphan-drafted' );

	// 4. The reverse: Field Reports down, playbooks synced.
	wp_update_post( [ 'ID' => $ac_temp['field_report'], 'post_status' => 'publish' ] );
	$GLOBALS['ac_iso']['down'] = [ 'fieldReport' ];
	$ac_before                 = ac_iso_statuses();
	AC_Sync::sync_all( false, 'isolation test' );
	$ac_after = ac_iso_statuses();
	ac_iso_check( [] === ac_iso_changed( $ac_before, $ac_after, AC_Field_Report_Type::POST_TYPE ), 'all types, reports down: no Field Report changed' );
	ac_iso_check( 'draft' === get_post_status( $ac_temp['playbook'] ), 'all types, reports down: playbooks still orphan-drafted' );
	$GLOBALS['ac_iso']['down'] = [];

	// 5. Single-entry (webhook) path: unpublishing one entry drafts only its own post.
	wp_update_post( [ 'ID' => $ac_temp['playbook'], 'post_status' => 'publish' ] );
	$ac_before = ac_iso_statuses();
	AC_Sync::sync_one( 'ac-iso-playbook', false, 'isolation test' );
	ac_iso_check( [ $ac_temp['playbook'] ] === array_keys( array_diff_assoc( ac_iso_statuses(), $ac_before ) ), 'webhook, playbook unpublished: only that playbook drafted' );
	$ac_before = ac_iso_statuses();
	AC_Sync::sync_one( 'ac-iso-report', false, 'isolation test' );
	ac_iso_check( [ $ac_temp['field_report'] ] === array_keys( array_diff_assoc( ac_iso_statuses(), $ac_before ) ), 'webhook, report unpublished: only that report drafted' );

	// 6. A webhook for a real entry of each type touches nothing else.
	foreach ( array_filter( [ $ac_playbooks[0] ?? null, $ac_reports[0] ] ) as $ac_e ) {
		$ac_before = ac_iso_statuses();
		$ac_r      = AC_Sync::sync_one( $ac_e['sys']['id'], false, 'isolation test' );
		ac_iso_check( 1 === $ac_r['unchanged'] && ac_iso_statuses() === $ac_before, 'webhook, ' . $ac_e['sys']['contentType']['sys']['id'] . ' entry: unchanged, nothing else touched' );
	}
	if ( ! $ac_playbooks ) {
		WP_CLI::log( 'Note: no synced playbook, so the real-playbook webhook check was skipped.' );
	}
} finally {
	foreach ( $ac_temp as $ac_id ) {
		wp_delete_post( $ac_id, true );
	}
	// Put back any status a failed check left changed.
	foreach ( ac_iso_statuses() as $ac_id => $ac_s ) {
		if ( isset( $ac_original[ $ac_id ] ) && $ac_original[ $ac_id ] !== $ac_s ) {
			wp_update_post( [ 'ID' => $ac_id, 'post_status' => explode( ':', $ac_original[ $ac_id ] )[1] ] );
			delete_post_meta( $ac_id, '_ac_drafted_reason' );
			WP_CLI::warning( "Restored the status of #$ac_id." );
		}
	}
}

$ac_r = $GLOBALS['ac_iso'];
if ( $ac_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $ac_r['pass'], $ac_r['fail'] ) );
}
WP_CLI::success( sprintf( 'Type isolation: %d checks passed.', $ac_r['pass'] ) );
