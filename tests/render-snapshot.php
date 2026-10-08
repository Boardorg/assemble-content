<?php
/**
 * Prints one hash per (report, view, visitor) of the plugin's rendered HTML,
 * plus the archive teaser. Run before and after a renderer change to prove the
 * output didn't move. Pass `full` to print the HTML instead of hashes.
 *
 * wp eval-file wp-content/plugins/assemble-content/tests/render-snapshot.php [full]
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

$afr_full  = in_array( 'full', (array) ( $args ?? [] ), true );
$afr_views = [ AFR_Audience::VIEW_STANDARD, AFR_Audience::VIEW_COUNCIL, AFR_Audience::VIEW_DELEGATE, AFR_Audience::VIEW_PUBLIC, AFR_Audience::VIEW_DENIED ];
$afr_ids   = get_posts( [ 'post_type' => AFR_CPT::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );

// Anonymous first, then an administrator (related-report lists and the gate's sign-in link differ).
foreach ( [ 0, 1 ] as $afr_user ) {
	wp_set_current_user( $afr_user );

	foreach ( $afr_ids as $afr_id ) {
		$GLOBALS['post'] = get_post( $afr_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_permalink() inside the renderer reads it.
		setup_postdata( $GLOBALS['post'] );
		$afr_entry = AFR_Renderer::data( $afr_id );

		foreach ( $afr_views as $afr_view ) {
			$afr_html = AFR_Renderer::render( $afr_id, $afr_entry, $afr_view );
			if ( $afr_full ) {
				printf( "=== user %d post %d %s\n%s\n", $afr_user, $afr_id, $afr_view, $afr_html );
			} else {
				printf( "u%d\t%d\t%s\t%s\n", $afr_user, $afr_id, $afr_view, md5( $afr_html ) );
			}
		}

		printf( "u%d\t%d\treport\t%s\n", $afr_user, $afr_id, md5( AFR_Renderer::render_report( $afr_entry ) ) );
	}
}
wp_reset_postdata();
