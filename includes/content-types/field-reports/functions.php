<?php
/**
 * Global wrappers so the site's card shortcodes can call these without knowing
 * about the plugin's classes, and degrade to their existing behaviour if the
 * plugin is deactivated.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ac_community_accent' ) ) {
	function ac_community_accent( ?int $post_id = null ): string {
		return AC_Listing::accent( $post_id );
	}
}

if ( ! function_exists( 'ac_community_label' ) ) {
	function ac_community_label( ?int $post_id = null ): string {
		return AC_Listing::label( $post_id );
	}
}
