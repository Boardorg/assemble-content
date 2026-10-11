<?php
/**
 * Playbook tests: the map, chapters and TOC anchors, callouts, the view model in
 * every view, and no body text in the public or denied views or through the_content.
 *
 * Uses a synthetic entry, written below, and one throwaway post that is deleted
 * at the end. The bypass option and the current user are restored.
 *
 * Local only:
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/playbook-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['afr_pb'] = [ 'pass' => 0, 'fail' => 0 ];

function afr_pb_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['afr_pb']['pass']++;
		return;
	}
	$GLOBALS['afr_pb']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

function afr_pb_text( string $value, array $marks = [] ): array {
	return [ 'nodeType' => 'text', 'value' => $value, 'marks' => array_map( static fn( $m ) => [ 'type' => $m ], $marks ), 'data' => [] ];
}
function afr_pb_node( string $type, array $content ): array {
	return [ 'nodeType' => $type, 'data' => [], 'content' => $content ];
}
function afr_pb_callout( string $id, array $fields ): array {
	return [
		'nodeType' => 'embedded-entry-block',
		'content'  => [],
		'data'     => [ 'target' => [ 'sys' => [ 'id' => $id, 'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => 'playbookCallout' ] ] ], 'fields' => $fields ] ],
	];
}
function afr_pb_json( $value ): string {
	return html_entity_decode( wp_strip_all_tags( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), ENT_QUOTES, 'UTF-8' );
}

// Markers: OPENING text is public (the first chapter); MEMBERS text is not.
$afr_entry = [
	'sys'    => [
		'id'          => 'afr-test-playbook',
		'revision'    => 1,
		'createdAt'   => '2026-09-01T12:00:00Z',
		'updatedAt'   => '2026-09-02T12:00:00Z',
		'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => 'playbook' ] ],
	],
	'fields' => [
		'title'       => 'Test Playbook Title',
		'slug'        => 'afr-test-playbook',
		'dek'         => 'A dek for the test playbook.',
		'edition'     => '2026 Edition',
		'availableTo' => [ 'Board Member' ],
		'community'   => [ 'sys' => [ 'id' => 'c1' ], 'fields' => [ 'name' => 'Test Board', 'slug' => 'test-board', 'practiceArea' => 'marketing', 'boilerplate' => 'About the Test Board.' ] ],
		'topics'      => [ [ 'sys' => [ 'id' => 't1' ], 'fields' => [ 'name' => 'Test Topic', 'slug' => 'test-topic' ] ] ],
		'author'      => [ 'sys' => [ 'id' => 'p1' ], 'fields' => [ 'name' => 'Ada Writer', 'role' => 'Contributing Editor', 'biography' => 'MEMBERS bio of the author.' ] ],
		'editor'      => [ 'sys' => [ 'id' => 'p2' ], 'fields' => [ 'name' => 'Ed Itor', 'role' => 'Membership Director' ] ],
		'body'        => afr_pb_node(
			'document',
			[
				afr_pb_node( 'heading-2', [ afr_pb_text( 'Executive Summary' ) ] ),
				afr_pb_node( 'paragraph', [ afr_pb_text( 'OPENING lead paragraph.' ) ] ),
				afr_pb_node( 'paragraph', [ afr_pb_text( 'OPENING second paragraph with ' ), afr_pb_text( 'bold', [ 'bold' ] ), afr_pb_text( ' text.' ) ] ),
				afr_pb_callout( 'k1', [ 'kind' => 'Stat', 'stat' => '70%', 'text' => 'OPENING stat line.' ] ),
				afr_pb_node( 'heading-2', [ afr_pb_text( 'Days 0–30: Diagnose and Orient' ) ] ),
				afr_pb_node( 'heading-3', [ afr_pb_text( 'Start With the Machines' ) ] ),
				afr_pb_node( 'paragraph', [ afr_pb_text( 'MEMBERS subtitle in italics.', [ 'italic' ] ) ] ),
				afr_pb_node( 'paragraph', [ afr_pb_text( 'MEMBERS phase body paragraph.' ) ] ),
				afr_pb_callout( 'k2', [ 'kind' => 'Checklist', 'label' => 'Checklist', 'tag' => 'Days 0–30', 'items' => [ 'MEMBERS item one: with a colon', 'MEMBERS item two' ] ] ),
				afr_pb_callout( 'k3', [ 'kind' => 'Table', 'label' => 'Ask: Why', 'items' => [ 'Is it current?: MEMBERS recency matters.', 'No colon row' ] ] ),
				afr_pb_callout( 'k4', [ 'kind' => 'Layers', 'items' => [ 'Phase: Diagnose', 'Play: Machines' ] ] ),
				afr_pb_callout( 'k5', [ 'kind' => 'Stat' ] ), // Empty: dropped.
				afr_pb_callout( 'k6', [ 'kind' => 'Unknown', 'text' => 'x' ] ), // Unknown kind: dropped.
				[ 'nodeType' => 'embedded-entry-block', 'content' => [], 'data' => [ 'target' => null ] ], // Unpublished target: dropped.
				afr_pb_node( 'heading-3', [ afr_pb_text( 'Second Subsection' ) ] ),
				afr_pb_node( 'paragraph', [ afr_pb_text( 'MEMBERS not a subtitle: ' ), afr_pb_text( 'mixed', [ 'italic' ] ) ] ),
				afr_pb_node( 'heading-2', [ afr_pb_text( 'Days 30–60: Organize' ) ] ),
				afr_pb_node( 'paragraph', [ afr_pb_text( 'MEMBERS second phase lead.' ) ] ),
				afr_pb_node( 'heading-2', [ afr_pb_text( 'Executive Summary' ) ] ),
				afr_pb_node( 'unordered-list', [ afr_pb_node( 'list-item', [ afr_pb_node( 'paragraph', [ afr_pb_text( 'MEMBERS list item.' ) ] ) ] ) ] ),
				afr_pb_node( 'paragraph', [] ),
			]
		),
	],
];

$afr_member_markers = [ 'MEMBERS phase body paragraph', 'MEMBERS item one', 'MEMBERS recency', 'MEMBERS second phase lead', 'MEMBERS list item', 'MEMBERS subtitle', 'MEMBERS bio' ];
$afr_mode           = get_option( AFR_Bypass::OPTION_MODE, null );
$afr_post           = 0;

update_option( AFR_Bypass::OPTION_MODE, AFR_Bypass::MODE_OFF );

try {
	// 1. Registry and post type.
	$afr_type = AFR_Types::get( 'playbook' );
	afr_pb_check( is_array( $afr_type ) && 'playbook' === $afr_type['post_type'] && $afr_type['gated'], 'registry: playbook entry, gated' );
	afr_pb_check( 'playbooks' === ( $afr_type['slug'] ?? '' ) && 'showOn' === ( $afr_type['channel_field'] ?? '' ), 'registry: slug playbooks, channel showOn' );
	afr_pb_check( in_array( 'playbookCallout', $afr_type['dependencies'] ?? [], true ), 'registry: callout changes re-sync playbooks' );
	$afr_pto = get_post_type_object( 'playbook' );
	afr_pb_check( $afr_pto && false === $afr_pto->show_in_rest && false === $afr_pto->show_ui, 'post type: no REST, no admin UI' );
	afr_pb_check( 'playbook' === AFR_Types::resolve( 'playbook' ) && 'fieldReport' === AFR_Types::resolve( 'field_report' ), 'registry: CLI names resolve' );

	// 2. Map.
	$afr_map = AFR_Playbook_Type::map( $afr_entry );
	afr_pb_check( 'Test Playbook Title' === $afr_map['post']['post_title'] && 'afr-test-playbook' === $afr_map['post']['post_name'], 'map: title and slug' );
	afr_pb_check( 'A dek for the test playbook.' === $afr_map['post']['post_excerpt'], 'map: excerpt is the dek' );
	afr_pb_check( '2026-09-01 12:00:00' === $afr_map['post']['post_date'], 'map: date from createdAt' );
	afr_pb_check( str_contains( $afr_map['post']['post_content'], 'MEMBERS phase body paragraph' ) && str_contains( $afr_map['post']['post_content'], 'MEMBERS recency' ), 'map: stored content has the body and callouts (for search)' );
	afr_pb_check( [ 'Board Member' ] === $afr_map['meta'][ AFR_CPT::META_AUDIENCES ], 'map: audiences meta' );

	// 3. Chapters, TOC and anchors.
	$afr_ch = AFR_Playbook::chapters( $afr_entry );
	afr_pb_check( 4 === count( $afr_ch ), 'chapters: four H2s, four chapters (got ' . count( $afr_ch ) . ')' );
	afr_pb_check( 'executive-summary' === $afr_ch[0]['id'] && 'executive-summary-2' === $afr_ch[3]['id'], 'anchors: slugged and de-duplicated' );
	afr_pb_check( 'intro' === $afr_ch[0]['kind'] && 0 === $afr_ch[0]['number'], 'chapter 1: intro' );
	afr_pb_check( 'phase' === $afr_ch[1]['kind'] && 1 === $afr_ch[1]['number'] && 'Days 0–30' === $afr_ch[1]['kicker'] && 'Diagnose and Orient' === $afr_ch[1]['rest'], 'chapter 2: phase 1, kicker split at the colon' );
	afr_pb_check( 2 === $afr_ch[2]['number'], 'chapter 3: phase 2' );
	afr_pb_check( 'lead' === $afr_ch[0]['blocks'][0]['type'] && 'OPENING lead paragraph.' === $afr_ch[0]['blocks'][0]['html'], 'blocks: opening paragraph is the lead, unwrapped' );
	afr_pb_check( 'html' === $afr_ch[0]['blocks'][1]['type'] && str_contains( $afr_ch[0]['blocks'][1]['html'], '<strong>bold</strong>' ), 'blocks: marks kept' );
	$afr_sub = $afr_ch[1]['blocks'][0];
	afr_pb_check( 'subhead' === $afr_sub['type'] && 'start-with-the-machines' === $afr_sub['id'] && 'MEMBERS subtitle in italics.' === $afr_sub['subtitle'], 'blocks: H3 with its italic subtitle' );
	afr_pb_check( 'html' === $afr_ch[1]['blocks'][1]['type'] && ! str_contains( $afr_ch[1]['blocks'][1]['html'], 'subtitle' ), 'blocks: subtitle consumed, not repeated' );
	$afr_kinds = array_column( array_filter( $afr_ch[1]['blocks'], static fn( $b ) => 'callout' === $b['type'] ), 'kind' );
	afr_pb_check( [ 'checklist', 'table', 'layers' ] === array_values( $afr_kinds ), 'callouts: empty, unknown and unpublished dropped (' . implode( ',', $afr_kinds ) . ')' );
	$afr_by = array_column( array_filter( $afr_ch[1]['blocks'], static fn( $b ) => 'callout' === $b['type'] ), null, 'kind' );
	afr_pb_check( [ 'MEMBERS item one: with a colon', 'MEMBERS item two' ] === $afr_by['checklist']['items'] && 'Days 0–30' === $afr_by['checklist']['tag'], 'callout checklist: items kept whole, tag' );
	afr_pb_check( [ 'Ask', 'Why' ] === $afr_by['table']['columns'] && '' === $afr_by['table']['label'], 'callout table: columns from the label' );
	afr_pb_check( [ 'label' => 'Is it current?', 'value' => 'MEMBERS recency matters.' ] === $afr_by['table']['rows'][0] && [ 'label' => 'No colon row', 'value' => '' ] === $afr_by['table']['rows'][1], 'callout table: rows split at the first colon' );
	afr_pb_check( 'Phase' === $afr_by['layers']['rows'][0]['label'] && '' === $afr_by['layers']['label'], 'callout layers: rows, label left to the site' );
	$afr_second = $afr_ch[1]['blocks'][ count( $afr_ch[1]['blocks'] ) - 1 ];
	afr_pb_check( 'html' === $afr_second['type'] && str_contains( $afr_second['html'], 'not a subtitle' ), 'blocks: a mixed-italic paragraph is not a subtitle' );

	$afr_toc = AFR_Playbook::view_model_for( $afr_entry, 'standard' )['sections']['toc'];
	afr_pb_check( 4 === count( $afr_toc ) && ! isset( $afr_toc[0]['blocks'] ) && 'days-0-30-diagnose-and-orient' === $afr_toc[1]['id'], 'toc: one row per chapter, ids match, no body' );

	// 4. Views built directly.
	$afr_expect = [
		'standard' => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'people', 'toc', 'chapters', 'community', 'author' ],
		'public'   => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'people', 'intro', 'join' ],
		'denied'   => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'people', 'gate' ],
	];
	foreach ( [ 'standard', 'council', 'delegate', 'public', 'denied' ] as $afr_view ) {
		$afr_m    = AFR_Playbook::view_model_for( $afr_entry, $afr_view );
		$afr_as   = in_array( $afr_view, [ 'council', 'delegate' ], true ) ? 'standard' : $afr_view;
		$afr_json = afr_pb_json( $afr_m['sections'] );

		afr_pb_check( $afr_as === $afr_m['view'], "$afr_view: resolves to $afr_as" );
		afr_pb_check( array_keys( $afr_m['sections'] ) === $afr_expect[ $afr_as ], "$afr_view: exactly the allowed sections (" . implode( ',', array_keys( $afr_m['sections'] ) ) . ')' );
		afr_pb_check( $afr_m['words'] > 20, "$afr_view: reading time counts the whole body" );
		afr_pb_check( ! str_contains( afr_pb_json( $afr_m['sections']['people'] ), 'bio of the author' ), "$afr_view: people carry no bio" );

		foreach ( $afr_member_markers as $afr_marker ) {
			$afr_found = str_contains( $afr_json, $afr_marker );
			afr_pb_check( 'standard' === $afr_as ? $afr_found : ! $afr_found, "$afr_view: " . ( 'standard' === $afr_as ? 'has' : 'lacks' ) . " '$afr_marker'" );
		}
		afr_pb_check( ( 'denied' !== $afr_as ) === str_contains( $afr_json, 'OPENING lead paragraph' ), "$afr_view: first chapter " . ( 'denied' === $afr_as ? 'absent' : 'present' ) );
	}
	$afr_pub = AFR_Playbook::view_model_for( $afr_entry, 'public' )['sections'];
	afr_pb_check( 'executive-summary' === $afr_pub['intro']['id'] && 'stat' === $afr_pub['intro']['blocks'][2]['kind'], 'public: intro is the first chapter, callout included' );

	// 5. A stored post, resolved for real visitors.
	$afr_post = wp_insert_post( [ 'post_type' => 'playbook', 'post_status' => 'publish', 'post_title' => 'Test Playbook Title', 'post_name' => 'afr-test-playbook', 'post_content' => $afr_map['post']['post_content'], 'post_excerpt' => $afr_map['post']['post_excerpt'] ], true );
	afr_pb_check( ! is_wp_error( $afr_post ), 'post: created' );
	update_post_meta( $afr_post, AFR_CPT::META_ENTRY_ID, 'afr-test-playbook' );
	update_post_meta( $afr_post, AFR_CPT::META_DATA, wp_slash( wp_json_encode( $afr_entry ) ) );
	update_post_meta( $afr_post, AFR_CPT::META_AUDIENCES, [ 'Board Member' ] );

	wp_set_current_user( 0 );
	$afr_m = AFR_Playbook::view_model( $afr_post );
	afr_pb_check( 'denied' === $afr_m['view'] && ! str_contains( afr_pb_json( $afr_m ), 'MEMBERS' ), 'anonymous, members-only: denied, no body' );
	afr_pb_check( '' === $afr_m['notices']['preview'] && '' === $afr_m['notices']['bypass'], 'anonymous: no admin notices' );

	$_GET['afr_as'] = 'board-member';
	afr_pb_check( 'denied' === AFR_Playbook::view_model( $afr_post )['view'], 'anonymous ?afr_as= ignored' );
	unset( $_GET['afr_as'] );

	add_filter( 'afr_public_full_text', '__return_true' );
	afr_pb_check( 'denied' === AFR_Playbook::view_model( $afr_post )['view'] && ! AFR_Playbook::view_model( $afr_post )['full_text'], 'full-text filter: no effect on a members-only playbook' );
	remove_filter( 'afr_public_full_text', '__return_true' );

	$afr_stub = static fn( $a ) => array_merge( (array) $a, [ 'Board Member' ] );
	add_filter( 'afr_current_audiences', $afr_stub, 99 );
	$afr_m = AFR_Playbook::view_model( $afr_post );
	afr_pb_check( 'standard' === $afr_m['view'] && str_contains( afr_pb_json( $afr_m ), 'MEMBERS phase body' ), 'stubbed Board Member: standard, full body' );
	remove_filter( 'afr_current_audiences', $afr_stub, 99 );

	update_post_meta( $afr_post, AFR_CPT::META_AUDIENCES, [ 'Public', 'Board Member' ] );
	$afr_m = AFR_Playbook::view_model( $afr_post );
	afr_pb_check( 'public' === $afr_m['view'] && ! $afr_m['full_text'] && ! str_contains( afr_pb_json( $afr_m ), 'MEMBERS' ), 'anonymous, Public: public view, first chapter only' );
	add_filter( 'afr_public_full_text', '__return_true' );
	$afr_m = AFR_Playbook::view_model( $afr_post );
	afr_pb_check( $afr_m['full_text'] && isset( $afr_m['sections']['chapters'], $afr_m['sections']['toc'] ) && ! isset( $afr_m['sections']['intro'] ), 'full-text filter on a Public playbook: chapters and toc, no intro' );
	remove_filter( 'afr_public_full_text', '__return_true' );
	update_post_meta( $afr_post, AFR_CPT::META_AUDIENCES, [ 'Board Member' ] );

	// 6. the_content never serves the stored body, in a loop or on the single page.
	global $post;
	$post = get_post( $afr_post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );
	$afr_html = apply_filters( 'the_content', $post->post_content );
	afr_pb_check( ! str_contains( $afr_html, 'MEMBERS' ) && str_contains( $afr_html, 'A dek for the test playbook.' ), 'the_content: teaser only' );
	$GLOBALS['wp_query'] = new WP_Query( [ 'p' => $afr_post, 'post_type' => 'playbook' ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$afr_html = apply_filters( 'the_content', $post->post_content );
	afr_pb_check( ! str_contains( $afr_html, 'MEMBERS' ), 'the_content on the single page: teaser only' );
	wp_reset_postdata();
	wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query
} finally {
	unset( $_GET['afr_as'] );
	if ( $afr_post && ! is_wp_error( $afr_post ) ) {
		wp_delete_post( $afr_post, true );
	}
	if ( null === $afr_mode ) {
		delete_option( AFR_Bypass::OPTION_MODE );
	} else {
		update_option( AFR_Bypass::OPTION_MODE, $afr_mode );
	}
	wp_set_current_user( 0 );
}

$afr_r = $GLOBALS['afr_pb'];
if ( $afr_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $afr_r['pass'], $afr_r['fail'] ) );
}
WP_CLI::success( sprintf( 'Playbooks: %d checks passed.', $afr_r['pass'] ) );
