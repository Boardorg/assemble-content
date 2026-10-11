<?php
/**
 * `afr_public_full_text` (1.7.0): a site can give the public view of a report
 * ticked Public its body sections, for a free-read meter. Off by default, and
 * never affects a report that isn't Public. Local only; changes nothing stored.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/public-full-text-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['afr_pft'] = [ 'pass' => 0, 'fail' => 0 ];
function afr_pft_check( bool $ok, string $label ): void {
	$GLOBALS['afr_pft'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

if ( 'off' !== AFR_Bypass::mode() ) {
	WP_CLI::error( 'Set afr_bypass_mode to off locally first.' );
}

$ids = get_posts( [ 'post_type' => AFR_CPT::POST_TYPE, 'numberposts' => 2, 'fields' => 'ids', 'post_status' => 'publish' ] );
if ( count( $ids ) < 2 ) {
	WP_CLI::error( 'Needs two synced reports (wp assemble-content sync --all).' );
}
list( $public_id, $members_id ) = $ids;

// Pretend the first report is ticked Public (in memory only) and the second members-only.
$fake = static function ( $value, $object_id, $meta_key ) use ( $public_id, $members_id ) {
	if ( AFR_CPT::META_AUDIENCES !== $meta_key ) {
		return $value;
	}
	if ( (int) $object_id === $public_id ) {
		return [ [ 'Public', 'Board Member' ] ];
	}
	if ( (int) $object_id === $members_id ) {
		return [ [ 'Board Member' ] ];
	}
	return $value;
};
add_filter( 'get_post_metadata', $fake, 10, 3 );

// Whatever the active site does, test both branches here.
$saved = $GLOBALS['wp_filter']['afr_public_full_text'] ?? null;
remove_all_filters( 'afr_public_full_text' );
wp_set_current_user( 0 );

$body = [ 'story_in_brief', 'picking_up', 'focus', 'takeaways', 'author' ];

// Off (default): the public view is unchanged.
$vm = AFR_Renderer::view_model( $public_id );
afr_pft_check( 'public' === $vm['view'], 'the Public report resolves to the public view' );
afr_pft_check( false === $vm['full_text'], 'off: full_text is false' );
afr_pft_check( [] === array_intersect( $body, array_keys( $vm['sections'] ) ), 'off: no body sections' );
afr_pft_check( isset( $vm['sections']['setup'], $vm['sections']['join'] ), 'off: setup and join as before' );
$words_off = $vm['words'];

// On: the public view carries the body, never engagement.
add_filter( 'afr_public_full_text', '__return_true' );
$vm = AFR_Renderer::view_model( $public_id );
afr_pft_check( 'public' === $vm['view'], 'on: still the public view' );
afr_pft_check( true === $vm['full_text'], 'on: full_text is true' );
afr_pft_check( isset( $vm['sections']['picking_up'] ), 'on: body sections present' );
afr_pft_check( ! isset( $vm['sections']['engagement'] ), 'on: no engagement' );
afr_pft_check( isset( $vm['sections']['join'] ), 'on: join kept for the meter prompt' );
afr_pft_check( $vm['words'] > $words_off, 'on: reading time covers the body' );

// On, members-only report: still denied, no body.
$vm = AFR_Renderer::view_model( $members_id );
afr_pft_check( 'denied' === $vm['view'], 'on: a members-only report is still denied' );
afr_pft_check( false === $vm['full_text'] && [] === array_intersect( $body, array_keys( $vm['sections'] ) ), 'on: a members-only report gets no body' );

// The plugin's own HTML render is untouched by the filter.
$entry = AFR_Renderer::data( $public_id );
afr_pft_check( ! str_contains( AFR_Renderer::render( $public_id, $entry, 'public' ), 'Focus of the Discussion' ), 'on: the plugin render of the public view has no body' );

remove_filter( 'afr_public_full_text', '__return_true' );
remove_filter( 'get_post_metadata', $fake, 10 );
if ( $saved ) {
	$GLOBALS['wp_filter']['afr_public_full_text'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
}

$t = $GLOBALS['afr_pft'];
$t['fail'] ? WP_CLI::error( "{$t['fail']} failed, {$t['pass']} passed." ) : WP_CLI::success( "{$t['pass']} checks passed." );
