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

$ac_full  = in_array( 'full', (array) ( $args ?? [] ), true );
$ac_views = [ AC_Audience::VIEW_STANDARD, AC_Audience::VIEW_COUNCIL, AC_Audience::VIEW_DELEGATE, AC_Audience::VIEW_PUBLIC, AC_Audience::VIEW_DENIED ];
$ac_ids   = get_posts( [ 'post_type' => AC_Field_Report_Type::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );

// Anonymous first, then an administrator (related-report lists and the gate's sign-in link differ).
foreach ( [ 0, 1 ] as $ac_user ) {
	wp_set_current_user( $ac_user );

	foreach ( $ac_ids as $ac_id ) {
		$GLOBALS['post'] = get_post( $ac_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_permalink() inside the renderer reads it.
		setup_postdata( $GLOBALS['post'] );
		$ac_entry = AC_Post_Type_Content::data( $ac_id );

		foreach ( $ac_views as $ac_view ) {
			$ac_html = AC_Field_Report_Renderer::render( $ac_id, $ac_entry, $ac_view );
			if ( $ac_full ) {
				printf( "=== user %d post %d %s\n%s\n", $ac_user, $ac_id, $ac_view, $ac_html );
			} else {
				printf( "u%d\t%d\t%s\t%s\n", $ac_user, $ac_id, $ac_view, md5( $ac_html ) );
			}
		}

		printf( "u%d\t%d\treport\t%s\n", $ac_user, $ac_id, md5( AC_Field_Report_Renderer::render_report( $ac_entry ) ) );
	}
}
wp_reset_postdata();
