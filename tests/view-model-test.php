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

$GLOBALS['afr_vm'] = [ 'pass' => 0, 'fail' => 0 ];

function afr_vm_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['afr_vm']['pass']++;
		return;
	}
	$GLOBALS['afr_vm']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

/** Long sentences from the members-only parts of a report: none may reach public or denied. */
function afr_vm_gated_phrases( array $entry ): array {
	$texts = [
		AFR_RichText::to_text( AFR_Contentful::field( $entry, 'focusBody' ) ),
		AFR_RichText::to_text( AFR_Contentful::field( $entry, 'takeawaysStandard' ) ),
		(string) AFR_Contentful::field( $entry, 'pullquote', '' ),
		(string) AFR_Contentful::field( $entry, 'takeawaysShort', '' ),
	];
	foreach ( (array) AFR_Contentful::field( $entry, 'mdTakes', [] ) as $take ) {
		$texts[] = (string) AFR_Contentful::field( is_array( $take ) ? $take : null, 'fullTake', '' );
	}

	// The teaser is public by design; never use a sentence it shares.
	$dek = (string) AFR_Contentful::field( $entry, 'newsletterSetup', '' );

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

$afr_gated = [ 'byline', 'note', 'story_in_brief', 'glance', 'picking_up', 'pullquote', 'charts', 'focus', 'takeaways', 'community', 'author', 'engagement' ];
$afr_views = [ AFR_Audience::VIEW_STANDARD, AFR_Audience::VIEW_COUNCIL, AFR_Audience::VIEW_DELEGATE, AFR_Audience::VIEW_PUBLIC, AFR_Audience::VIEW_DENIED ];
$afr_ids   = get_posts( [ 'post_type' => AFR_CPT::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ] );
$afr_mode  = get_option( AFR_Bypass::OPTION_MODE, null );

if ( ! $afr_ids ) {
	WP_CLI::error( 'No published reports. Run `wp assemble-content sync --all` first.' );
}

// Gating must not depend on the bypass being on.
update_option( AFR_Bypass::OPTION_MODE, AFR_Bypass::MODE_OFF );

try {
	// 1. Every view, built directly: only allowed keys; gated keys and text absent from public and denied.
	wp_set_current_user( 1 );
	foreach ( $afr_ids as $afr_id ) {
		$afr_entry   = AFR_Renderer::data( $afr_id );
		$afr_phrases = afr_vm_gated_phrases( $afr_entry );
		afr_vm_check( count( $afr_phrases ) > 0, "post $afr_id has gated phrases to look for" );

		foreach ( $afr_views as $afr_view ) {
			$afr_s    = AFR_Renderer::sections( $afr_id, $afr_entry, $afr_view );
			$afr_keys = array_keys( $afr_s );

			afr_vm_check( ! array_diff( $afr_keys, AFR_Renderer::view_sections( $afr_view ) ), "post $afr_id $afr_view: only allowed keys" );
			afr_vm_check( isset( $afr_s['headline'] ), "post $afr_id $afr_view: has headline" );

			if ( in_array( $afr_view, [ AFR_Audience::VIEW_PUBLIC, AFR_Audience::VIEW_DENIED ], true ) ) {
				afr_vm_check( ! array_intersect( $afr_keys, $afr_gated ), "post $afr_id $afr_view: no gated keys (" . implode( ',', array_intersect( $afr_keys, $afr_gated ) ) . ')' );

				$afr_json = html_entity_decode( wp_strip_all_tags( (string) wp_json_encode( $afr_s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), ENT_QUOTES, 'UTF-8' );
				foreach ( $afr_phrases as $afr_phrase ) {
					afr_vm_check( ! str_contains( $afr_json, $afr_phrase ), "post $afr_id $afr_view: gated text absent: " . mb_substr( $afr_phrase, 0, 50 ) );
				}
			} else {
				// Positive control: the same search finds the gated text where it is allowed.
				$afr_json  = html_entity_decode( wp_strip_all_tags( (string) wp_json_encode( $afr_s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), ENT_QUOTES, 'UTF-8' );
				$afr_found = array_filter( $afr_phrases, static fn( $p ) => str_contains( $afr_json, $p ) );
				afr_vm_check( count( $afr_found ) > count( $afr_phrases ) / 2, "post $afr_id $afr_view: control finds gated text (" . count( $afr_found ) . '/' . count( $afr_phrases ) . ')' );

				foreach ( [ 'picking_up', 'focus', 'takeaways', 'story_in_brief' ] as $afr_key ) {
					afr_vm_check( isset( $afr_s[ $afr_key ] ), "post $afr_id $afr_view: has $afr_key" );
				}
			}

			afr_vm_check( isset( $afr_s['gate'] ) === ( AFR_Audience::VIEW_DENIED === $afr_view ), "post $afr_id $afr_view: gate only on denied" );
			afr_vm_check( isset( $afr_s['join'], $afr_s['setup'] ) === ( AFR_Audience::VIEW_PUBLIC === $afr_view ), "post $afr_id $afr_view: join and setup only on public" );
			afr_vm_check( isset( $afr_s['glance'] ) === ( AFR_Audience::VIEW_COUNCIL === $afr_view ), "post $afr_id $afr_view: glance only on council" );
			afr_vm_check( isset( $afr_s['note'] ) === in_array( $afr_view, [ AFR_Audience::VIEW_COUNCIL, AFR_Audience::VIEW_DELEGATE ], true ), "post $afr_id $afr_view: note only on council and delegate" );
		}
	}

	// 2. Resolved views. Anonymous, bypass off: none of the samples is Public, so every report is denied,
	// and an anonymous ?afr_as= is ignored.
	wp_set_current_user( 0 );
	foreach ( $afr_ids as $afr_id ) {
		$afr_public = in_array( 'Public', AFR_Audience::report_audiences( $afr_id ), true );
		$afr_expect = $afr_public ? AFR_Audience::VIEW_PUBLIC : AFR_Audience::VIEW_DENIED;

		$afr_vm = AFR_Renderer::view_model( $afr_id );
		afr_vm_check( $afr_vm['view'] === $afr_expect, "anonymous post $afr_id: $afr_expect (got {$afr_vm['view']})" );
		afr_vm_check( '' === $afr_vm['notices']['preview'] && '' === $afr_vm['notices']['bypass'], "anonymous post $afr_id: no admin notices" );

		$_GET['afr_as'] = 'board-member';
		$afr_vm         = AFR_Renderer::view_model( $afr_id );
		afr_vm_check( $afr_vm['view'] === $afr_expect, "anonymous post $afr_id with ?afr_as: still $afr_expect" );
		unset( $_GET['afr_as'] );
	}

	// 3. Administrator previews resolve to the requested view where the report allows it.
	wp_set_current_user( 1 );
	$afr_preview = [
		'board-member'  => AFR_Audience::VIEW_STANDARD,
		'council-chair' => AFR_Audience::VIEW_COUNCIL,
		'delegate'      => AFR_Audience::VIEW_DELEGATE,
		'public'        => AFR_Audience::VIEW_DENIED, // No sample is ticked Public.
	];
	foreach ( $afr_ids as $afr_id ) {
		$afr_allowed = AFR_Audience::report_audiences( $afr_id );
		foreach ( $afr_preview as $afr_as => $afr_expect ) {
			$afr_audience = ucwords( str_replace( '-', ' ', $afr_as ) );
			if ( 'public' !== $afr_as && ! in_array( $afr_audience, $afr_allowed, true ) ) {
				$afr_expect = AFR_Audience::VIEW_DENIED;
			}
			if ( 'public' === $afr_as && in_array( 'Public', $afr_allowed, true ) ) {
				$afr_expect = AFR_Audience::VIEW_PUBLIC;
			}

			$_GET['afr_as'] = $afr_as;
			$afr_vm         = AFR_Renderer::view_model( $afr_id );
			unset( $_GET['afr_as'] );

			afr_vm_check( $afr_vm['view'] === $afr_expect, "admin post $afr_id as $afr_as: $afr_expect (got {$afr_vm['view']})" );
			afr_vm_check( str_contains( $afr_vm['notices']['preview'], 'afr-preview-bar' ), "admin post $afr_id: preview switcher present" );
		}
	}

	// 4. The opt-out: with afr_filter_the_content false, a singular main-query the_content gets the teaser only.
	wp_set_current_user( 1 );
	$afr_id                   = $afr_ids[0];
	$GLOBALS['wp_query']      = new WP_Query( [ 'p' => $afr_id, 'post_type' => AFR_CPT::POST_TYPE ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_the_query']  = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_query']->the_post();
	afr_vm_check( is_singular( AFR_CPT::POST_TYPE ) && is_main_query(), 'opt-out: simulated a singular main query' );

	// Set any theme's own opt-out aside, so both branches are tested whatever theme is active.
	$afr_saved = $GLOBALS['wp_filter']['afr_filter_the_content'] ?? null;
	remove_all_filters( 'afr_filter_the_content' );

	$afr_full = apply_filters( 'the_content', get_post_field( 'post_content', $afr_id ) );
	afr_vm_check( str_contains( $afr_full, 'afr-doc' ), 'opt-out: default renders the document' );

	add_filter( 'afr_filter_the_content', '__return_false' );
	$afr_teaser = apply_filters( 'the_content', get_post_field( 'post_content', $afr_id ) );
	remove_filter( 'afr_filter_the_content', '__return_false' );

	if ( $afr_saved ) {
		$GLOBALS['wp_filter']['afr_filter_the_content'] = $afr_saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring what was removed above.
	}
	afr_vm_check( str_contains( $afr_teaser, 'afr-teaser' ) && ! str_contains( $afr_teaser, 'afr-doc' ), 'opt-out: the_content returns the teaser only' );
	foreach ( afr_vm_gated_phrases( AFR_Renderer::data( $afr_id ) ) as $afr_phrase ) {
		afr_vm_check( ! str_contains( html_entity_decode( wp_strip_all_tags( $afr_teaser ), ENT_QUOTES, 'UTF-8' ), $afr_phrase ), 'opt-out teaser: gated text absent' );
	}
	wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query

	// 5. Reading time counts only what the view shows.
	$afr_entry = AFR_Renderer::data( $afr_id );
	$afr_std   = AFR_Renderer::sections( $afr_id, $afr_entry, AFR_Audience::VIEW_STANDARD );
	$afr_pub   = AFR_Renderer::sections( $afr_id, $afr_entry, AFR_Audience::VIEW_PUBLIC );
	afr_vm_check( isset( $afr_std['picking_up'] ) && ! isset( $afr_pub['picking_up'] ), 'reading: standard has the body, public does not' );

	// 6. The bypass, when open, gives anonymous visitors the full report (the beta's setting) and says so.
	update_option( AFR_Bypass::OPTION_MODE, AFR_Bypass::MODE_OPEN );
	wp_set_current_user( 0 );
	$afr_vm = AFR_Renderer::view_model( $afr_ids[0] );
	afr_vm_check( AFR_Audience::VIEW_STANDARD === $afr_vm['view'], 'bypass open: anonymous gets standard' );
	afr_vm_check( str_contains( $afr_vm['notices']['bypass'], 'afr-bypass-banner' ), 'bypass open: banner present' );
} finally {
	unset( $_GET['afr_as'] );
	if ( null === $afr_mode ) {
		delete_option( AFR_Bypass::OPTION_MODE );
	} else {
		update_option( AFR_Bypass::OPTION_MODE, $afr_mode );
	}
	wp_set_current_user( 0 );
}

$afr_r = $GLOBALS['afr_vm'];
if ( $afr_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $afr_r['pass'], $afr_r['fail'] ) );
}
WP_CLI::success( sprintf( 'View model: %d checks passed.', $afr_r['pass'] ) );
