<?php
/**
 * Draft preview tests, against a fake Contentful Preview API: who may open a
 * preview, signed links (valid, expired, tampered), content-type checks, the
 * view chosen, and that nothing is written to WordPress.
 *
 * Local only. Stores a dummy preview token for the run and restores ac_settings after.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/preview-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['ac_pv'] = [ 'pass' => 0, 'fail' => 0, 'requests' => [] ];

function ac_pv_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['ac_pv']['pass']++;
		return;
	}
	$GLOBALS['ac_pv']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

// A draft: a synced report's data with an unpublished headline, served by the fake Preview API.
$ac_ids = get_posts( [ 'post_type' => AC_Field_Report_Type::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ] );
if ( ! $ac_ids ) {
	WP_CLI::error( 'No published reports. Run `wp assemble-content sync --all` first.' );
}
$ac_draft                       = AC_Post_Type_Content::data( $ac_ids[0] );
$ac_draft['sys']['id']          = 'acTestDraft';
$ac_draft['fields']['headline'] = 'Draft Headline Not Yet Published';
$ac_other                       = [ 'sys' => [ 'id' => 'acTestFeature', 'contentType' => [ 'sys' => [ 'id' => 'siteFeature' ] ] ], 'fields' => [ 'title' => 'x' ] ];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $ac_draft, $ac_other ) {
		if ( ! str_contains( $url, 'contentful.com' ) ) {
			return $pre;
		}
		$GLOBALS['ac_pv']['requests'][] = [ 'url' => $url, 'auth' => $args['headers']['Authorization'] ?? '' ];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$items = [];
		if ( str_starts_with( $url, 'https://preview.contentful.com/' ) ) {
			$items = array_values( array_filter( [ $ac_draft, $ac_other ], static fn( $e ) => $e['sys']['id'] === ( $q['sys_id'] ?? $q['sys.id'] ?? '' ) ) );
		}
		return [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'items' => $items, 'total' => count( $items ), 'includes' => [] ] ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

$ac_settings = get_option( AC_Settings::OPTION, [] );
$ac_posts    = (int) wp_count_posts( AC_Field_Report_Type::POST_TYPE )->publish + (int) wp_count_posts( AC_Field_Report_Type::POST_TYPE )->draft;
$ac_q        = static fn( array $extra = [] ) => array_merge( [ 'ac_preview' => 'acTestDraft' ], $extra );
$ac_signed   = static function ( string $url ): array {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
};

try {
	// 1. Not configured: staff get 503, and nothing reaches Contentful.
	update_option( AC_Settings::OPTION, array_merge( $ac_settings, [ 'preview_token' => '' ] ) );
	wp_set_current_user( 1 );
	$r = AC_Preview::resolve( $ac_q() );
	ac_pv_check( 503 === $r['status'], 'no token: 503' );
	ac_pv_check( ! $GLOBALS['ac_pv']['requests'], 'no token: no request made' );

	update_option( AC_Settings::OPTION, array_merge( $ac_settings, [ 'preview_token' => 'test-preview-token' ] ) );

	// 2. Anonymous without a link: 403, before any request.
	wp_set_current_user( 0 );
	$r = AC_Preview::resolve( $ac_q() );
	ac_pv_check( 403 === $r['status'] && null === $r['model'], 'anonymous: 403, no model' );
	ac_pv_check( ! $GLOBALS['ac_pv']['requests'], 'anonymous: no request made' );

	// 3. Staff: the draft, from the Preview API with the preview token, standard view by default.
	wp_set_current_user( 1 );
	$r   = AC_Preview::resolve( $ac_q() );
	$req = end( $GLOBALS['ac_pv']['requests'] );
	ac_pv_check( 200 === $r['status'], 'staff: 200' );
	ac_pv_check( str_starts_with( $req['url'], 'https://preview.contentful.com/' ) && 'Bearer test-preview-token' === $req['auth'], 'staff: Preview API with the preview token' );
	ac_pv_check( 'Draft Headline Not Yet Published' === ( $r['model']['sections']['headline'] ?? '' ), 'staff: draft headline' );
	ac_pv_check( 'standard' === $r['model']['view'] && isset( $r['model']['sections']['takeaways'] ), 'staff: standard view with the body' );
	ac_pv_check( ! isset( $r['model']['sections']['engagement'] ), 'staff: no engagement (no post)' );
	ac_pv_check( str_contains( $r['model']['notices']['preview'], 'Draft preview' ) && str_contains( $r['model']['notices']['preview'], 'ac_sig' ), 'staff: banner with a share link' );

	// 4. Views: allowed sections only.
	foreach ( [ 'council', 'delegate', 'public', 'denied' ] as $view ) {
		$r = AC_Preview::resolve( $ac_q( [ 'ac_view' => $view ] ) );
		ac_pv_check( $view === $r['model']['view'], "view $view resolves" );
		ac_pv_check( ! array_diff( array_keys( $r['model']['sections'] ), AC_Field_Report_Renderer::view_sections( $view ) ), "view $view: only allowed keys" );
	}
	$r = AC_Preview::resolve( $ac_q( [ 'ac_view' => 'nonsense' ] ) );
	ac_pv_check( 'standard' === $r['model']['view'], 'unknown view falls back to standard' );

	// 5. Signed links: valid works anonymously; expired, tampered or for another entry don't.
	$link = $ac_signed( AC_Preview::signed_url( 'acTestDraft', DAY_IN_SECONDS ) );
	wp_set_current_user( 0 );
	$r = AC_Preview::resolve( $link );
	ac_pv_check( 200 === $r['status'] && $r['signed'], 'signed link: 200 anonymously' );
	ac_pv_check( ! str_contains( $r['model']['notices']['preview'], 'Share link' ), 'signed link: no share link offered to non-staff' );

	$r = AC_Preview::resolve( array_merge( $link, [ 'ac_sig' => str_repeat( '0', 64 ) ] ) );
	ac_pv_check( 403 === $r['status'], 'tampered signature: 403' );

	$r = AC_Preview::resolve( array_merge( $link, [ 'ac_exp' => (string) ( (int) $link['ac_exp'] + 3600 ) ] ) );
	ac_pv_check( 403 === $r['status'], 'extended expiry: 403' );

	$r = AC_Preview::resolve( array_merge( $link, [ 'ac_preview' => 'acTestFeature' ] ) );
	ac_pv_check( 403 === $r['status'], 'link reused for another entry: 403' );

	$expired = time() - 10;
	$r       = AC_Preview::resolve( [ 'ac_preview' => 'acTestDraft', 'ac_exp' => (string) $expired, 'ac_sig' => hash_hmac( 'sha256', 'acTestDraft|' . $expired, wp_salt( 'auth' ) . 'ac-preview' ) ] );
	ac_pv_check( 403 === $r['status'], 'expired link: 403' );

	// 6. Entries the site doesn't render, unknown or malformed IDs: 404.
	wp_set_current_user( 1 );
	ac_pv_check( 404 === AC_Preview::resolve( [ 'ac_preview' => 'acTestFeature' ] )['status'], 'siteFeature entry: 404' );
	ac_pv_check( 404 === AC_Preview::resolve( [ 'ac_preview' => 'doesNotExist' ] )['status'], 'missing entry: 404' );
	ac_pv_check( 404 === AC_Preview::resolve( [ 'ac_preview' => '../etc' . str_repeat( 'x', 70 ) ] )['status'], 'malformed ID: 404' );

	// 7. Nothing was written: no new posts, and the live data is untouched.
	$after = (int) wp_count_posts( AC_Field_Report_Type::POST_TYPE )->publish + (int) wp_count_posts( AC_Field_Report_Type::POST_TYPE )->draft;
	ac_pv_check( $after === $ac_posts, 'no posts created' );
	ac_pv_check( 'Draft Headline Not Yet Published' !== ( AC_Post_Type_Content::data( $ac_ids[0] )['fields']['headline'] ?? '' ), 'live data untouched' );

	// 8. The delivery path still uses the CDN and the delivery token.
	AC_Contentful::get_entry( 'acTestDraft' );
	$req = end( $GLOBALS['ac_pv']['requests'] );
	ac_pv_check( str_starts_with( $req['url'], 'https://cdn.contentful.com/' ) && 'Bearer test-preview-token' !== $req['auth'], 'delivery path unchanged' );
} finally {
	update_option( AC_Settings::OPTION, $ac_settings );
	wp_set_current_user( 0 );
}

$ac_r = $GLOBALS['ac_pv'];
if ( $ac_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $ac_r['pass'], $ac_r['fail'] ) );
}
WP_CLI::success( sprintf( 'Draft preview: %d checks passed.', $ac_r['pass'] ) );
