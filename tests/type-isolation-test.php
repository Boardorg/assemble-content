<?php
/**
 * Type isolation between the real types: a playbook sync never drafts a Field
 * Report, and a Field Report sync never drafts a playbook. Full syncs, a sync of
 * every type, and the single-entry (webhook) path, in both directions.
 *
 * Runs against a fake Delivery API that serves the site's own synced entries back
 * (from `_afr_data`), so nothing reaches Contentful. Creates one throwaway post
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

$GLOBALS['afr_iso'] = [ 'pass' => 0, 'fail' => 0, 'serve' => [], 'down' => [] ];

function afr_iso_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['afr_iso']['pass']++;
		return;
	}
	$GLOBALS['afr_iso']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

/** Published entries of a type, from the posts already synced here. */
function afr_iso_entries( string $post_type ): array {
	$entries = [];
	foreach ( get_posts( [ 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ] ) as $id ) {
		$data = AFR_Renderer::data( (int) $id );
		if ( $data && ! str_starts_with( (string) ( $data['sys']['id'] ?? '' ), 'afr-iso-' ) ) {
			$entries[] = $data;
		}
	}

	return $entries;
}

/** post ID => status, for every post of the synced types. */
function afr_iso_statuses(): array {
	$out = [];
	foreach ( get_posts( [ 'post_type' => AFR_Types::post_types(), 'post_status' => 'any', 'numberposts' => -1, 'suppress_filters' => true ] ) as $p ) {
		$out[ $p->ID ] = $p->post_type . ':' . $p->post_status;
	}

	return $out;
}

function afr_iso_changed( array $before, array $after, string $post_type ): array {
	return array_keys( array_filter( $after, static fn( $s, $id ) => str_starts_with( $s, $post_type . ':' ) && ( $before[ $id ] ?? '' ) !== $s, ARRAY_FILTER_USE_BOTH ) );
}

// The fake Delivery API: each type serves the entries in $GLOBALS['afr_iso']['serve'], complete,
// unless the type is listed as down (HTTP 503).
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! str_contains( (string) $url, 'cdn.contentful.com' ) ) {
			return $pre;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$t    = $GLOBALS['afr_iso'];
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

$afr_reports   = afr_iso_entries( AFR_CPT::POST_TYPE );
$afr_playbooks = afr_iso_entries( AFR_Playbook_Type::POST_TYPE );
$afr_original  = afr_iso_statuses();
$afr_temp      = [];

if ( ! $afr_reports ) {
	WP_CLI::error( 'No published Field Reports. Run `wp assemble-content sync --all` first.' );
}

// One throwaway, published post of each type whose entry Contentful "no longer publishes".
foreach ( [ AFR_CPT::POST_TYPE => 'afr-iso-report', AFR_Playbook_Type::POST_TYPE => 'afr-iso-playbook' ] as $afr_pt => $afr_eid ) {
	$afr_id = wp_insert_post( [ 'post_type' => $afr_pt, 'post_status' => 'publish', 'post_title' => $afr_eid, 'post_name' => $afr_eid ] );
	update_post_meta( $afr_id, AFR_CPT::META_ENTRY_ID, $afr_eid );
	$afr_temp[ $afr_pt ] = (int) $afr_id;
}

try {
	$afr_serve_all = [ 'fieldReport' => $afr_reports, 'playbook' => $afr_playbooks ];

	// 1. A playbook sync (complete) drafts the stale playbook and no Field Report.
	$GLOBALS['afr_iso']['serve'] = $afr_serve_all;
	$afr_before = afr_iso_statuses();
	$afr_r      = AFR_Sync::sync_all( false, 'isolation test', 'playbook' );
	$afr_after  = afr_iso_statuses();
	afr_iso_check( 0 === $afr_r['errors'], 'playbook sync: no errors' );
	afr_iso_check( 'draft' === get_post_status( $afr_temp['playbook'] ), 'playbook sync: drafts the stale playbook' );
	afr_iso_check( [] === afr_iso_changed( $afr_before, $afr_after, AFR_CPT::POST_TYPE ), 'playbook sync: no Field Report changed' );
	afr_iso_check( 'publish' === get_post_status( $afr_temp['field_report'] ), 'playbook sync: the stale Field Report is left alone' );

	// 2. A Field Report sync (complete) drafts the stale report and no playbook.
	wp_update_post( [ 'ID' => $afr_temp['playbook'], 'post_status' => 'publish' ] );
	$afr_before = afr_iso_statuses();
	$afr_r      = AFR_Sync::sync_all( false, 'isolation test', 'fieldReport' );
	$afr_after  = afr_iso_statuses();
	afr_iso_check( 0 === $afr_r['errors'], 'Field Report sync: no errors' );
	afr_iso_check( 'draft' === get_post_status( $afr_temp['field_report'] ), 'Field Report sync: drafts the stale report' );
	afr_iso_check( [] === afr_iso_changed( $afr_before, $afr_after, AFR_Playbook_Type::POST_TYPE ), 'Field Report sync: no playbook changed' );
	afr_iso_check( 'publish' === get_post_status( $afr_temp['playbook'] ), 'Field Report sync: the stale playbook is left alone' );
	afr_iso_check( 0 === $afr_r['created'] && $afr_r['unchanged'] === count( $afr_reports ), 'Field Report sync: real reports unchanged' );

	// 3. Everything synced while playbooks are down: no playbook drafted, reports still sync.
	wp_update_post( [ 'ID' => $afr_temp['field_report'], 'post_status' => 'publish' ] );
	$GLOBALS['afr_iso']['down'] = [ 'playbook' ];
	$afr_before                 = afr_iso_statuses();
	AFR_Sync::sync_all( false, 'isolation test' );
	$afr_after = afr_iso_statuses();
	afr_iso_check( [] === afr_iso_changed( $afr_before, $afr_after, AFR_Playbook_Type::POST_TYPE ), 'all types, playbooks down: no playbook changed' );
	afr_iso_check( 'draft' === get_post_status( $afr_temp['field_report'] ), 'all types, playbooks down: reports still orphan-drafted' );

	// 4. The reverse: Field Reports down, playbooks synced.
	wp_update_post( [ 'ID' => $afr_temp['field_report'], 'post_status' => 'publish' ] );
	$GLOBALS['afr_iso']['down'] = [ 'fieldReport' ];
	$afr_before                 = afr_iso_statuses();
	AFR_Sync::sync_all( false, 'isolation test' );
	$afr_after = afr_iso_statuses();
	afr_iso_check( [] === afr_iso_changed( $afr_before, $afr_after, AFR_CPT::POST_TYPE ), 'all types, reports down: no Field Report changed' );
	afr_iso_check( 'draft' === get_post_status( $afr_temp['playbook'] ), 'all types, reports down: playbooks still orphan-drafted' );
	$GLOBALS['afr_iso']['down'] = [];

	// 5. Single-entry (webhook) path: unpublishing one entry drafts only its own post.
	wp_update_post( [ 'ID' => $afr_temp['playbook'], 'post_status' => 'publish' ] );
	$afr_before = afr_iso_statuses();
	AFR_Sync::sync_one( 'afr-iso-playbook', false, 'isolation test' );
	afr_iso_check( [ $afr_temp['playbook'] ] === array_keys( array_diff_assoc( afr_iso_statuses(), $afr_before ) ), 'webhook, playbook unpublished: only that playbook drafted' );
	$afr_before = afr_iso_statuses();
	AFR_Sync::sync_one( 'afr-iso-report', false, 'isolation test' );
	afr_iso_check( [ $afr_temp['field_report'] ] === array_keys( array_diff_assoc( afr_iso_statuses(), $afr_before ) ), 'webhook, report unpublished: only that report drafted' );

	// 6. A webhook for a real entry of each type touches nothing else.
	foreach ( array_filter( [ $afr_playbooks[0] ?? null, $afr_reports[0] ] ) as $afr_e ) {
		$afr_before = afr_iso_statuses();
		$afr_r      = AFR_Sync::sync_one( $afr_e['sys']['id'], false, 'isolation test' );
		afr_iso_check( 1 === $afr_r['unchanged'] && afr_iso_statuses() === $afr_before, 'webhook, ' . $afr_e['sys']['contentType']['sys']['id'] . ' entry: unchanged, nothing else touched' );
	}
	if ( ! $afr_playbooks ) {
		WP_CLI::log( 'Note: no synced playbook, so the real-playbook webhook check was skipped.' );
	}
} finally {
	foreach ( $afr_temp as $afr_id ) {
		wp_delete_post( $afr_id, true );
	}
	// Put back any status a failed check left changed.
	foreach ( afr_iso_statuses() as $afr_id => $afr_s ) {
		if ( isset( $afr_original[ $afr_id ] ) && $afr_original[ $afr_id ] !== $afr_s ) {
			wp_update_post( [ 'ID' => $afr_id, 'post_status' => explode( ':', $afr_original[ $afr_id ] )[1] ] );
			delete_post_meta( $afr_id, '_afr_drafted_reason' );
			WP_CLI::warning( "Restored the status of #$afr_id." );
		}
	}
}

$afr_r = $GLOBALS['afr_iso'];
if ( $afr_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $afr_r['pass'], $afr_r['fail'] ) );
}
WP_CLI::success( sprintf( 'Type isolation: %d checks passed.', $afr_r['pass'] ) );
