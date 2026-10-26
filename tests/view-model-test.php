<?php
/**
 * View model tests: each view gets only its allowed sections, and the public
 * and denied views never carry gated text.
 *
 * Uses the synced reports. Changes nothing permanently: the bypass option and
 * the current user are restored at the end.
 *
 * Local only:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/view-model-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['ac_vm'] = [ 'pass' => 0, 'fail' => 0 ];

function ac_vm_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['ac_vm']['pass']++;
		return;
	}
	$GLOBALS['ac_vm']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

/** Long sentences from the members-only parts of a report: none may reach public or denied. */
function ac_vm_gated_phrases( array $entry ): array {
	$texts = [
		AC_RichText::to_text( AC_Contentful::field( $entry, 'focusBody' ) ),
		AC_RichText::to_text( AC_Contentful::field( $entry, 'takeawaysStandard' ) ),
		(string) AC_Contentful::field( $entry, 'pullquote', '' ),
		(string) AC_Contentful::field( $entry, 'takeawaysShort', '' ),
	];
	foreach ( (array) AC_Contentful::field( $entry, 'mdTakes', [] ) as $take ) {
		$texts[] = (string) AC_Contentful::field( is_array( $take ) ? $take : null, 'fullTake', '' );
	}

	// The teaser is public by design; never use a sentence it shares.
	$dek = (string) AC_Contentful::field( $entry, 'newsletterSetup', '' );

	$phrases = [];
	foreach ( $texts as $text ) {
		foreach ( preg_split( '/(?<=[.!?])\s+/u', trim( $text ) ) ?: [] as $sentence ) {
			$sentence = trim( $sentence );
			if ( mb_strlen( $sentence ) >= 40 && ! str_contains( $dek, $sentence ) ) {
				$phrases[] = $sentence;
			}
		}
	}

	return $phrases;
}

$ac_gated = [ 'byline', 'note', 'story_in_brief', 'glance', 'picking_up', 'pullquote', 'charts', 'focus', 'takeaways', 'community', 'author', 'engagement' ];
$ac_views = [ AC_Audience::VIEW_STANDARD, AC_Audience::VIEW_COUNCIL, AC_Audience::VIEW_DELEGATE, AC_Audience::VIEW_PUBLIC, AC_Audience::VIEW_DENIED ];
$ac_ids   = get_posts( [ 'post_type' => AC_Field_Report_Type::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] );
$ac_mode  = get_option( AC_Bypass::OPTION_MODE, null );

if ( ! $ac_ids ) {
	WP_CLI::error( 'No published reports. Run `wp assemble-content sync --all` first.' );
}

// Gating must not depend on the bypass being on.
update_option( AC_Bypass::OPTION_MODE, AC_Bypass::MODE_OFF );

try {
	// 1. Every view, built directly: only allowed keys; gated keys and text absent from public and denied.
	wp_set_current_user( 1 );
	foreach ( $ac_ids as $ac_id ) {
		$ac_entry   = AC_Post_Type_Content::data( $ac_id );
		$ac_phrases = ac_vm_gated_phrases( $ac_entry );
		ac_vm_check( count( $ac_phrases ) > 0, "post $ac_id has gated phrases to look for" );

		foreach ( $ac_views as $ac_view ) {
			$ac_s    = AC_Field_Report_Renderer::sections( $ac_id, $ac_entry, $ac_view );
			$ac_keys = array_keys( $ac_s );

			ac_vm_check( ! array_diff( $ac_keys, AC_Field_Report_Renderer::view_sections( $ac_view ) ), "post $ac_id $ac_view: only allowed keys" );
			ac_vm_check( isset( $ac_s['headline'] ), "post $ac_id $ac_view: has headline" );

			if ( in_array( $ac_view, [ AC_Audience::VIEW_PUBLIC, AC_Audience::VIEW_DENIED ], true ) ) {
				ac_vm_check( ! array_intersect( $ac_keys, $ac_gated ), "post $ac_id $ac_view: no gated keys (" . implode( ',', array_intersect( $ac_keys, $ac_gated ) ) . ')' );

				$ac_json = html_entity_decode( wp_strip_all_tags( (string) wp_json_encode( $ac_s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), ENT_QUOTES, 'UTF-8' );
				foreach ( $ac_phrases as $ac_phrase ) {
					ac_vm_check( ! str_contains( $ac_json, $ac_phrase ), "post $ac_id $ac_view: gated text absent: " . mb_substr( $ac_phrase, 0, 50 ) );
				}
			} else {
				// Positive control: the same search finds the gated text where it is allowed.
				$ac_json  = html_entity_decode( wp_strip_all_tags( (string) wp_json_encode( $ac_s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), ENT_QUOTES, 'UTF-8' );
				$ac_found = array_filter( $ac_phrases, static fn( $p ) => str_contains( $ac_json, $p ) );
				ac_vm_check( count( $ac_found ) > count( $ac_phrases ) / 2, "post $ac_id $ac_view: control finds gated text (" . count( $ac_found ) . '/' . count( $ac_phrases ) . ')' );

				foreach ( [ 'picking_up', 'focus', 'takeaways', 'story_in_brief' ] as $ac_key ) {
					ac_vm_check( isset( $ac_s[ $ac_key ] ), "post $ac_id $ac_view: has $ac_key" );
				}
			}

			ac_vm_check( isset( $ac_s['gate'] ) === ( AC_Audience::VIEW_DENIED === $ac_view ), "post $ac_id $ac_view: gate only on denied" );
			ac_vm_check( isset( $ac_s['join'], $ac_s['setup'] ) === ( AC_Audience::VIEW_PUBLIC === $ac_view ), "post $ac_id $ac_view: join and setup only on public" );
			ac_vm_check( isset( $ac_s['glance'] ) === ( AC_Audience::VIEW_COUNCIL === $ac_view ), "post $ac_id $ac_view: glance only on council" );
			ac_vm_check( isset( $ac_s['note'] ) === in_array( $ac_view, [ AC_Audience::VIEW_COUNCIL, AC_Audience::VIEW_DELEGATE ], true ), "post $ac_id $ac_view: note only on council and delegate" );
		}
	}

	// 2. Resolved views. Anonymous, bypass off: none of the samples is Public, so every report is denied,
	// and an anonymous ?ac_as= is ignored.
	wp_set_current_user( 0 );
	foreach ( $ac_ids as $ac_id ) {
		$ac_public = in_array( 'Public', AC_Audience::report_audiences( $ac_id ), true );
		$ac_expect = $ac_public ? AC_Audience::VIEW_PUBLIC : AC_Audience::VIEW_DENIED;

		$ac_vm = AC_Field_Report_Renderer::view_model( $ac_id );
		ac_vm_check( $ac_vm['view'] === $ac_expect, "anonymous post $ac_id: $ac_expect (got {$ac_vm['view']})" );
		ac_vm_check( '' === $ac_vm['notices']['preview'] && '' === $ac_vm['notices']['bypass'], "anonymous post $ac_id: no admin notices" );

		$_GET['ac_as'] = 'board-member';
		$ac_vm         = AC_Field_Report_Renderer::view_model( $ac_id );
		ac_vm_check( $ac_vm['view'] === $ac_expect, "anonymous post $ac_id with ?ac_as: still $ac_expect" );
		unset( $_GET['ac_as'] );
	}

	// 3. Administrator previews resolve to the requested view where the report allows it.
	wp_set_current_user( 1 );
	$ac_preview = [
		'board-member'  => AC_Audience::VIEW_STANDARD,
		'council-chair' => AC_Audience::VIEW_COUNCIL,
		'delegate'      => AC_Audience::VIEW_DELEGATE,
		'public'        => AC_Audience::VIEW_DENIED, // No sample is ticked Public.
	];
	foreach ( $ac_ids as $ac_id ) {
		$ac_allowed = AC_Audience::report_audiences( $ac_id );
		foreach ( $ac_preview as $ac_as => $ac_expect ) {
			$ac_audience = ucwords( str_replace( '-', ' ', $ac_as ) );
			if ( 'public' !== $ac_as && ! in_array( $ac_audience, $ac_allowed, true ) ) {
				$ac_expect = AC_Audience::VIEW_DENIED;
			}
			if ( 'public' === $ac_as && in_array( 'Public', $ac_allowed, true ) ) {
				$ac_expect = AC_Audience::VIEW_PUBLIC;
			}

			$_GET['ac_as'] = $ac_as;
			$ac_vm         = AC_Field_Report_Renderer::view_model( $ac_id );
			unset( $_GET['ac_as'] );

			ac_vm_check( $ac_vm['view'] === $ac_expect, "admin post $ac_id as $ac_as: $ac_expect (got {$ac_vm['view']})" );
			ac_vm_check( str_contains( $ac_vm['notices']['preview'], 'ac-preview-bar' ), "admin post $ac_id: preview switcher present" );
		}
	}

	// 4. The opt-out: with ac_filter_the_content false, a singular main-query the_content gets the teaser only.
	wp_set_current_user( 1 );
	$ac_id                   = $ac_ids[0];
	$GLOBALS['wp_query']      = new WP_Query( [ 'p' => $ac_id, 'post_type' => AC_Field_Report_Type::POST_TYPE ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_the_query']  = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_query']->the_post();
	ac_vm_check( is_singular( AC_Field_Report_Type::POST_TYPE ) && is_main_query(), 'opt-out: simulated a singular main query' );

	// Set any theme's own opt-out aside, so both branches are tested whatever theme is active.
	$ac_saved = $GLOBALS['wp_filter']['ac_filter_the_content'] ?? null;
	remove_all_filters( 'ac_filter_the_content' );

	$ac_full = apply_filters( 'the_content', get_post_field( 'post_content', $ac_id ) );
	ac_vm_check( str_contains( $ac_full, 'ac-doc' ), 'opt-out: default renders the document' );

	add_filter( 'ac_filter_the_content', '__return_false' );
	$ac_teaser = apply_filters( 'the_content', get_post_field( 'post_content', $ac_id ) );
	remove_filter( 'ac_filter_the_content', '__return_false' );

	if ( $ac_saved ) {
		$GLOBALS['wp_filter']['ac_filter_the_content'] = $ac_saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring what was removed above.
	}
	ac_vm_check( str_contains( $ac_teaser, 'ac-teaser' ) && ! str_contains( $ac_teaser, 'ac-doc' ), 'opt-out: the_content returns the teaser only' );
	foreach ( ac_vm_gated_phrases( AC_Post_Type_Content::data( $ac_id ) ) as $ac_phrase ) {
		ac_vm_check( ! str_contains( html_entity_decode( wp_strip_all_tags( $ac_teaser ), ENT_QUOTES, 'UTF-8' ), $ac_phrase ), 'opt-out teaser: gated text absent' );
	}
	wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query

	// 5. Reading time counts only what the view shows.
	$ac_entry = AC_Post_Type_Content::data( $ac_id );
	$ac_std   = AC_Field_Report_Renderer::sections( $ac_id, $ac_entry, AC_Audience::VIEW_STANDARD );
	$ac_pub   = AC_Field_Report_Renderer::sections( $ac_id, $ac_entry, AC_Audience::VIEW_PUBLIC );
	ac_vm_check( isset( $ac_std['picking_up'] ) && ! isset( $ac_pub['picking_up'] ), 'reading: standard has the body, public does not' );

	// 6. The bypass, when open, gives anonymous visitors the full report (the beta's setting) and says so.
	update_option( AC_Bypass::OPTION_MODE, AC_Bypass::MODE_OPEN );
	wp_set_current_user( 0 );
	$ac_vm = AC_Field_Report_Renderer::view_model( $ac_ids[0] );
	ac_vm_check( AC_Audience::VIEW_STANDARD === $ac_vm['view'], 'bypass open: anonymous gets standard' );
	ac_vm_check( str_contains( $ac_vm['notices']['bypass'], 'ac-bypass-banner' ), 'bypass open: banner present' );
} finally {
	unset( $_GET['ac_as'] );
	if ( null === $ac_mode ) {
		delete_option( AC_Bypass::OPTION_MODE );
	} else {
		update_option( AC_Bypass::OPTION_MODE, $ac_mode );
	}
	wp_set_current_user( 0 );
}

$ac_r = $GLOBALS['ac_vm'];
if ( $ac_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $ac_r['pass'], $ac_r['fail'] ) );
}
WP_CLI::success( sprintf( 'View model: %d checks passed.', $ac_r['pass'] ) );
