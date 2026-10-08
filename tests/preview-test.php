<?php
/**
 * Draft preview tests, against a fake Contentful Preview API: who may open a
 * preview, signed links (valid, expired, tampered), content-type checks, the
 * view chosen, and that nothing is written to WordPress.
 *
 * Local only. Stores a dummy preview token for the run and restores afr_settings after.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/preview-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['afr_pv'] = [ 'pass' => 0, 'fail' => 0, 'requests' => [] ];

function afr_pv_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['afr_pv']['pass']++;
		return;
	}
	$GLOBALS['afr_pv']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

// A draft: a synced report's data with an unpublished headline, served by the fake Preview API.
$afr_ids = get_posts( [ 'post_type' => AFR_CPT::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ] );
if ( ! $afr_ids ) {
	WP_CLI::error( 'No published reports. Run `wp assemble-content sync --all` first.' );
}
$afr_draft                       = AFR_Renderer::data( $afr_ids[0] );
$afr_draft['sys']['id']          = 'afrTestDraft';
$afr_draft['fields']['headline'] = 'Draft Headline Not Yet Published';
$afr_other                       = [ 'sys' => [ 'id' => 'afrTestFeature', 'contentType' => [ 'sys' => [ 'id' => 'siteFeature' ] ] ], 'fields' => [ 'title' => 'x' ] ];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( $afr_draft, $afr_other ) {
		if ( ! str_contains( $url, 'contentful.com' ) ) {
			return $pre;
		}
		$GLOBALS['afr_pv']['requests'][] = [ 'url' => $url, 'auth' => $args['headers']['Authorization'] ?? '' ];
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$items = [];
		if ( str_starts_with( $url, 'https://preview.contentful.com/' ) ) {
			$items = array_values( array_filter( [ $afr_draft, $afr_other ], static fn( $e ) => $e['sys']['id'] === ( $q['sys_id'] ?? $q['sys.id'] ?? '' ) ) );
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

$afr_settings = get_option( AFR_Settings::OPTION, [] );
$afr_posts    = (int) wp_count_posts( AFR_CPT::POST_TYPE )->publish + (int) wp_count_posts( AFR_CPT::POST_TYPE )->draft;
$afr_q        = static fn( array $extra = [] ) => array_merge( [ 'afr_preview' => 'afrTestDraft' ], $extra );
$afr_signed   = static function ( string $url ): array {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
};

try {
	// 1. Not configured: staff get 503, and nothing reaches Contentful.
	update_option( AFR_Settings::OPTION, array_merge( $afr_settings, [ 'preview_token' => '' ] ) );
	wp_set_current_user( 1 );
	$r = AFR_Preview::resolve( $afr_q() );
	afr_pv_check( 503 === $r['status'], 'no token: 503' );
	afr_pv_check( ! $GLOBALS['afr_pv']['requests'], 'no token: no request made' );

	update_option( AFR_Settings::OPTION, array_merge( $afr_settings, [ 'preview_token' => 'test-preview-token' ] ) );

	// 2. Anonymous without a link: 403, before any request.
	wp_set_current_user( 0 );
	$r = AFR_Preview::resolve( $afr_q() );
	afr_pv_check( 403 === $r['status'] && null === $r['model'], 'anonymous: 403, no model' );
	afr_pv_check( ! $GLOBALS['afr_pv']['requests'], 'anonymous: no request made' );

	// 3. Staff: the draft, from the Preview API with the preview token, standard view by default.
	wp_set_current_user( 1 );
	$r   = AFR_Preview::resolve( $afr_q() );
	$req = end( $GLOBALS['afr_pv']['requests'] );
	afr_pv_check( 200 === $r['status'], 'staff: 200' );
	afr_pv_check( str_starts_with( $req['url'], 'https://preview.contentful.com/' ) && 'Bearer test-preview-token' === $req['auth'], 'staff: Preview API with the preview token' );
	afr_pv_check( 'Draft Headline Not Yet Published' === ( $r['model']['sections']['headline'] ?? '' ), 'staff: draft headline' );
	afr_pv_check( 'standard' === $r['model']['view'] && isset( $r['model']['sections']['takeaways'] ), 'staff: standard view with the body' );
	afr_pv_check( ! isset( $r['model']['sections']['engagement'] ), 'staff: no engagement (no post)' );
	afr_pv_check( str_contains( $r['model']['notices']['preview'], 'Draft preview' ) && str_contains( $r['model']['notices']['preview'], 'afr_sig' ), 'staff: banner with a share link' );

	// 4. Views: allowed sections only.
	foreach ( [ 'council', 'delegate', 'public', 'denied' ] as $view ) {
		$r = AFR_Preview::resolve( $afr_q( [ 'afr_view' => $view ] ) );
		afr_pv_check( $view === $r['model']['view'], "view $view resolves" );
		afr_pv_check( ! array_diff( array_keys( $r['model']['sections'] ), AFR_Renderer::view_sections( $view ) ), "view $view: only allowed keys" );
	}
	$r = AFR_Preview::resolve( $afr_q( [ 'afr_view' => 'nonsense' ] ) );
	afr_pv_check( 'standard' === $r['model']['view'], 'unknown view falls back to standard' );

	// 5. Signed links: valid works anonymously; expired, tampered or for another entry don't.
	$link = $afr_signed( AFR_Preview::signed_url( 'afrTestDraft', DAY_IN_SECONDS ) );
	wp_set_current_user( 0 );
	$r = AFR_Preview::resolve( $link );
	afr_pv_check( 200 === $r['status'] && $r['signed'], 'signed link: 200 anonymously' );
	afr_pv_check( ! str_contains( $r['model']['notices']['preview'], 'Share link' ), 'signed link: no share link offered to non-staff' );

	$r = AFR_Preview::resolve( array_merge( $link, [ 'afr_sig' => str_repeat( '0', 64 ) ] ) );
	afr_pv_check( 403 === $r['status'], 'tampered signature: 403' );

	$r = AFR_Preview::resolve( array_merge( $link, [ 'afr_exp' => (string) ( (int) $link['afr_exp'] + 3600 ) ] ) );
	afr_pv_check( 403 === $r['status'], 'extended expiry: 403' );

	$r = AFR_Preview::resolve( array_merge( $link, [ 'afr_preview' => 'afrTestFeature' ] ) );
	afr_pv_check( 403 === $r['status'], 'link reused for another entry: 403' );

	$expired = time() - 10;
	$r       = AFR_Preview::resolve( [ 'afr_preview' => 'afrTestDraft', 'afr_exp' => (string) $expired, 'afr_sig' => hash_hmac( 'sha256', 'afrTestDraft|' . $expired, wp_salt( 'auth' ) . 'afr-preview' ) ] );
	afr_pv_check( 403 === $r['status'], 'expired link: 403' );

	// 6. Entries the site doesn't render, unknown or malformed IDs: 404.
	wp_set_current_user( 1 );
	afr_pv_check( 404 === AFR_Preview::resolve( [ 'afr_preview' => 'afrTestFeature' ] )['status'], 'siteFeature entry: 404' );
	afr_pv_check( 404 === AFR_Preview::resolve( [ 'afr_preview' => 'doesNotExist' ] )['status'], 'missing entry: 404' );
	afr_pv_check( 404 === AFR_Preview::resolve( [ 'afr_preview' => '../etc' . str_repeat( 'x', 70 ) ] )['status'], 'malformed ID: 404' );

	// 7. Nothing was written: no new posts, and the live data is untouched.
	$after = (int) wp_count_posts( AFR_CPT::POST_TYPE )->publish + (int) wp_count_posts( AFR_CPT::POST_TYPE )->draft;
	afr_pv_check( $after === $afr_posts, 'no posts created' );
	afr_pv_check( 'Draft Headline Not Yet Published' !== ( AFR_Renderer::data( $afr_ids[0] )['fields']['headline'] ?? '' ), 'live data untouched' );

	// 8. The delivery path still uses the CDN and the delivery token.
	AFR_Contentful::get_entry( 'afrTestDraft' );
	$req = end( $GLOBALS['afr_pv']['requests'] );
	afr_pv_check( str_starts_with( $req['url'], 'https://cdn.contentful.com/' ) && 'Bearer test-preview-token' !== $req['auth'], 'delivery path unchanged' );
} finally {
	update_option( AFR_Settings::OPTION, $afr_settings );
	wp_set_current_user( 0 );
}

$afr_r = $GLOBALS['afr_pv'];
if ( $afr_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $afr_r['pass'], $afr_r['fail'] ) );
}
WP_CLI::success( sprintf( 'Draft preview: %d checks passed.', $afr_r['pass'] ) );
