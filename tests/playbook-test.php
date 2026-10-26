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

$GLOBALS['ac_pb'] = [ 'pass' => 0, 'fail' => 0 ];

function ac_pb_check( bool $ok, string $label ): void {
	if ( $ok ) {
		$GLOBALS['ac_pb']['pass']++;
		return;
	}
	$GLOBALS['ac_pb']['fail']++;
	WP_CLI::warning( 'FAIL: ' . $label );
}

function ac_pb_text( string $value, array $marks = [] ): array {
	return [ 'nodeType' => 'text', 'value' => $value, 'marks' => array_map( static fn( $m ) => [ 'type' => $m ], $marks ), 'data' => [] ];
}
function ac_pb_node( string $type, array $content ): array {
	return [ 'nodeType' => $type, 'data' => [], 'content' => $content ];
}
function ac_pb_callout( string $id, array $fields ): array {
	return [
		'nodeType' => 'embedded-entry-block',
		'content'  => [],
		'data'     => [ 'target' => [ 'sys' => [ 'id' => $id, 'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => 'playbookCallout' ] ] ], 'fields' => $fields ] ],
	];
}
function ac_pb_json( $value ): string {
	return html_entity_decode( wp_strip_all_tags( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), ENT_QUOTES, 'UTF-8' );
}

// Markers: OPENING text is public (the first chapter); MEMBERS text is not.
$ac_entry = [
	'sys'    => [
		'id'          => 'ac-test-playbook',
		'revision'    => 1,
		'createdAt'   => '2026-09-01T12:00:00Z',
		'updatedAt'   => '2026-09-02T12:00:00Z',
		'contentType' => [ 'sys' => [ 'type' => 'Link', 'linkType' => 'ContentType', 'id' => 'playbook' ] ],
	],
	'fields' => [
		'title'       => 'Test Playbook Title',
		'slug'        => 'ac-test-playbook',
		'dek'         => 'A dek for the test playbook.',
		'edition'     => '2026 Edition',
		'availableTo' => [ 'Board Member' ],
		'community'   => [ 'sys' => [ 'id' => 'c1' ], 'fields' => [ 'name' => 'Test Board', 'slug' => 'test-board', 'practiceArea' => 'marketing', 'boilerplate' => 'About the Test Board.' ] ],
		'topics'      => [ [ 'sys' => [ 'id' => 't1' ], 'fields' => [ 'name' => 'Test Topic', 'slug' => 'test-topic' ] ] ],
		'author'      => [ 'sys' => [ 'id' => 'p1' ], 'fields' => [ 'name' => 'Ada Writer', 'role' => 'Contributing Editor', 'biography' => 'MEMBERS bio of the author.' ] ],
		'editor'      => [ 'sys' => [ 'id' => 'p2' ], 'fields' => [ 'name' => 'Ed Itor', 'role' => 'Membership Director' ] ],
		'body'        => ac_pb_node(
			'document',
			[
				ac_pb_node( 'heading-2', [ ac_pb_text( 'Executive Summary' ) ] ),
				ac_pb_node( 'paragraph', [ ac_pb_text( 'OPENING lead paragraph.' ) ] ),
				ac_pb_node( 'paragraph', [ ac_pb_text( 'OPENING second paragraph with ' ), ac_pb_text( 'bold', [ 'bold' ] ), ac_pb_text( ' text.' ) ] ),
				ac_pb_callout( 'k1', [ 'kind' => 'Stat', 'stat' => '70%', 'text' => 'OPENING stat line.' ] ),
				ac_pb_node( 'heading-2', [ ac_pb_text( 'Days 0–30: Diagnose and Orient' ) ] ),
				ac_pb_node( 'heading-3', [ ac_pb_text( 'Start With the Machines' ) ] ),
				ac_pb_node( 'paragraph', [ ac_pb_text( 'MEMBERS subtitle in italics.', [ 'italic' ] ) ] ),
				ac_pb_node( 'paragraph', [ ac_pb_text( 'MEMBERS phase body paragraph.' ) ] ),
				ac_pb_callout( 'k2', [ 'kind' => 'Checklist', 'label' => 'Checklist', 'tag' => 'Days 0–30', 'items' => [ 'MEMBERS item one: with a colon', 'MEMBERS item two' ] ] ),
				ac_pb_callout( 'k3', [ 'kind' => 'Table', 'label' => 'Ask: Why', 'items' => [ 'Is it current?: MEMBERS recency matters.', 'No colon row' ] ] ),
				ac_pb_callout( 'k4', [ 'kind' => 'Layers', 'items' => [ 'Phase: Diagnose', 'Play: Machines' ] ] ),
				ac_pb_callout( 'k5', [ 'kind' => 'Stat' ] ), // Empty: dropped.
				ac_pb_callout( 'k6', [ 'kind' => 'Unknown', 'text' => 'x' ] ), // Unknown kind: dropped.
				[ 'nodeType' => 'embedded-entry-block', 'content' => [], 'data' => [ 'target' => null ] ], // Unpublished target: dropped.
				ac_pb_node( 'heading-3', [ ac_pb_text( 'Second Subsection' ) ] ),
				ac_pb_node( 'paragraph', [ ac_pb_text( 'MEMBERS not a subtitle: ' ), ac_pb_text( 'mixed', [ 'italic' ] ) ] ),
				ac_pb_node( 'heading-2', [ ac_pb_text( 'Days 30–60: Organize' ) ] ),
				ac_pb_node( 'paragraph', [ ac_pb_text( 'MEMBERS second phase lead.' ) ] ),
				ac_pb_node( 'heading-2', [ ac_pb_text( 'Executive Summary' ) ] ),
				ac_pb_node( 'unordered-list', [ ac_pb_node( 'list-item', [ ac_pb_node( 'paragraph', [ ac_pb_text( 'MEMBERS list item.' ) ] ) ] ) ] ),
				ac_pb_node( 'paragraph', [] ),
			]
		),
	],
];

$ac_member_markers = [ 'MEMBERS phase body paragraph', 'MEMBERS item one', 'MEMBERS recency', 'MEMBERS second phase lead', 'MEMBERS list item', 'MEMBERS subtitle', 'MEMBERS bio' ];
$ac_mode           = get_option( AC_Bypass::OPTION_MODE, null );
$ac_post           = 0;

update_option( AC_Bypass::OPTION_MODE, AC_Bypass::MODE_OFF );

try {
	// 1. Registry and post type.
	$ac_type = AC_Types::get( 'playbook' );
	ac_pb_check( is_array( $ac_type ) && 'playbook' === $ac_type['post_type'] && $ac_type['gated'], 'registry: playbook entry, gated' );
	ac_pb_check( 'playbooks' === ( $ac_type['slug'] ?? '' ) && true === ( $ac_type['placed'] ?? null ), 'registry: slug playbooks, placed by Publish to' );
	ac_pb_check( in_array( 'playbookCallout', $ac_type['dependencies'] ?? [], true ), 'registry: callout changes re-sync playbooks' );
	$ac_pto = get_post_type_object( 'playbook' );
	ac_pb_check( $ac_pto && false === $ac_pto->show_in_rest && false === $ac_pto->show_ui, 'post type: no REST, no admin UI' );
	ac_pb_check( 'playbook' === AC_Types::resolve( 'playbook' ) && 'fieldReport' === AC_Types::resolve( 'field_report' ), 'registry: CLI names resolve' );

	// 2. Map.
	$ac_map = AC_Types::type( 'playbook' )->map( $ac_entry );
	ac_pb_check( 'Test Playbook Title' === $ac_map['post']['post_title'] && 'ac-test-playbook' === $ac_map['post']['post_name'], 'map: title and slug' );
	ac_pb_check( 'A dek for the test playbook.' === $ac_map['post']['post_excerpt'], 'map: excerpt is the dek' );
	ac_pb_check( '2026-09-01 12:00:00' === AC_Post_Date::for_entry( $ac_entry )['post_date_gmt'], 'date: from createdAt, in GMT' );
	ac_pb_check( str_contains( $ac_map['post']['post_content'], 'MEMBERS phase body paragraph' ) && str_contains( $ac_map['post']['post_content'], 'MEMBERS recency' ), 'map: stored content has the body and callouts (for search)' );
	ac_pb_check( [ 'Board Member' ] === $ac_map['meta'][ AC_Post_Type_Content::META_AUDIENCES ], 'map: audiences meta' );

	// 3. Chapters, TOC and anchors.
	$ac_ch = AC_Playbook_Renderer::chapters( $ac_entry );
	ac_pb_check( 4 === count( $ac_ch ), 'chapters: four H2s, four chapters (got ' . count( $ac_ch ) . ')' );
	ac_pb_check( 'executive-summary' === $ac_ch[0]['id'] && 'executive-summary-2' === $ac_ch[3]['id'], 'anchors: slugged and de-duplicated' );
	ac_pb_check( 'intro' === $ac_ch[0]['kind'] && 0 === $ac_ch[0]['number'], 'chapter 1: intro' );
	ac_pb_check( 'phase' === $ac_ch[1]['kind'] && 1 === $ac_ch[1]['number'] && 'Days 0–30' === $ac_ch[1]['kicker'] && 'Diagnose and Orient' === $ac_ch[1]['rest'], 'chapter 2: phase 1, kicker split at the colon' );
	ac_pb_check( 2 === $ac_ch[2]['number'], 'chapter 3: phase 2' );
	ac_pb_check( 'lead' === $ac_ch[0]['blocks'][0]['type'] && 'OPENING lead paragraph.' === $ac_ch[0]['blocks'][0]['html'], 'blocks: opening paragraph is the lead, unwrapped' );
	ac_pb_check( 'html' === $ac_ch[0]['blocks'][1]['type'] && str_contains( $ac_ch[0]['blocks'][1]['html'], '<strong>bold</strong>' ), 'blocks: marks kept' );
	$ac_sub = $ac_ch[1]['blocks'][0];
	ac_pb_check( 'subhead' === $ac_sub['type'] && 'start-with-the-machines' === $ac_sub['id'] && 'MEMBERS subtitle in italics.' === $ac_sub['subtitle'], 'blocks: H3 with its italic subtitle' );
	ac_pb_check( 'html' === $ac_ch[1]['blocks'][1]['type'] && ! str_contains( $ac_ch[1]['blocks'][1]['html'], 'subtitle' ), 'blocks: subtitle consumed, not repeated' );
	$ac_kinds = array_column( array_filter( $ac_ch[1]['blocks'], static fn( $b ) => 'callout' === $b['type'] ), 'kind' );
	ac_pb_check( [ 'checklist', 'table', 'layers' ] === array_values( $ac_kinds ), 'callouts: empty, unknown and unpublished dropped (' . implode( ',', $ac_kinds ) . ')' );
	$ac_by = array_column( array_filter( $ac_ch[1]['blocks'], static fn( $b ) => 'callout' === $b['type'] ), null, 'kind' );
	ac_pb_check( [ 'MEMBERS item one: with a colon', 'MEMBERS item two' ] === $ac_by['checklist']['items'] && 'Days 0–30' === $ac_by['checklist']['tag'], 'callout checklist: items kept whole, tag' );
	ac_pb_check( [ 'Ask', 'Why' ] === $ac_by['table']['columns'] && '' === $ac_by['table']['label'], 'callout table: columns from the label' );
	ac_pb_check( [ 'label' => 'Is it current?', 'value' => 'MEMBERS recency matters.' ] === $ac_by['table']['rows'][0] && [ 'label' => 'No colon row', 'value' => '' ] === $ac_by['table']['rows'][1], 'callout table: rows split at the first colon' );
	ac_pb_check( 'Phase' === $ac_by['layers']['rows'][0]['label'] && '' === $ac_by['layers']['label'], 'callout layers: rows, label left to the site' );
	$ac_second = $ac_ch[1]['blocks'][ count( $ac_ch[1]['blocks'] ) - 1 ];
	ac_pb_check( 'html' === $ac_second['type'] && str_contains( $ac_second['html'], 'not a subtitle' ), 'blocks: a mixed-italic paragraph is not a subtitle' );

	$ac_toc = AC_Playbook_Renderer::view_model_for( $ac_entry, 'standard' )['sections']['toc'];
	ac_pb_check( 4 === count( $ac_toc ) && ! isset( $ac_toc[0]['blocks'] ) && 'days-0-30-diagnose-and-orient' === $ac_toc[1]['id'], 'toc: one row per chapter, ids match, no body' );

	// 4. Views built directly.
	$ac_expect = [
		'standard' => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'people', 'toc', 'chapters', 'community', 'author' ],
		'public'   => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'people', 'intro', 'join' ],
		'denied'   => [ 'headline', 'kicker', 'topics', 'dek', 'edition', 'people', 'gate' ],
	];
	foreach ( [ 'standard', 'council', 'delegate', 'public', 'denied' ] as $ac_view ) {
		$ac_m    = AC_Playbook_Renderer::view_model_for( $ac_entry, $ac_view );
		$ac_as   = in_array( $ac_view, [ 'council', 'delegate' ], true ) ? 'standard' : $ac_view;
		$ac_json = ac_pb_json( $ac_m['sections'] );

		ac_pb_check( $ac_as === $ac_m['view'], "$ac_view: resolves to $ac_as" );
		ac_pb_check( array_keys( $ac_m['sections'] ) === $ac_expect[ $ac_as ], "$ac_view: exactly the allowed sections (" . implode( ',', array_keys( $ac_m['sections'] ) ) . ')' );
		ac_pb_check( $ac_m['words'] > 20, "$ac_view: reading time counts the whole body" );
		ac_pb_check( ! str_contains( ac_pb_json( $ac_m['sections']['people'] ), 'bio of the author' ), "$ac_view: people carry no bio" );

		foreach ( $ac_member_markers as $ac_marker ) {
			$ac_found = str_contains( $ac_json, $ac_marker );
			ac_pb_check( 'standard' === $ac_as ? $ac_found : ! $ac_found, "$ac_view: " . ( 'standard' === $ac_as ? 'has' : 'lacks' ) . " '$ac_marker'" );
		}
		ac_pb_check( ( 'denied' !== $ac_as ) === str_contains( $ac_json, 'OPENING lead paragraph' ), "$ac_view: first chapter " . ( 'denied' === $ac_as ? 'absent' : 'present' ) );
	}
	$ac_pub = AC_Playbook_Renderer::view_model_for( $ac_entry, 'public' )['sections'];
	ac_pb_check( 'executive-summary' === $ac_pub['intro']['id'] && 'stat' === $ac_pub['intro']['blocks'][2]['kind'], 'public: intro is the first chapter, callout included' );

	// 5. A stored post, resolved for real visitors.
	$ac_post = wp_insert_post( [ 'post_type' => 'playbook', 'post_status' => 'publish', 'post_title' => 'Test Playbook Title', 'post_name' => 'ac-test-playbook', 'post_content' => $ac_map['post']['post_content'], 'post_excerpt' => $ac_map['post']['post_excerpt'] ], true );
	ac_pb_check( ! is_wp_error( $ac_post ), 'post: created' );
	update_post_meta( $ac_post, AC_Post_Type_Content::META_ENTRY_ID, 'ac-test-playbook' );
	update_post_meta( $ac_post, AC_Post_Type_Content::META_DATA, wp_slash( wp_json_encode( $ac_entry ) ) );
	update_post_meta( $ac_post, AC_Post_Type_Content::META_AUDIENCES, [ 'Board Member' ] );

	wp_set_current_user( 0 );
	$ac_m = AC_Playbook_Renderer::view_model( $ac_post );
	ac_pb_check( 'denied' === $ac_m['view'] && ! str_contains( ac_pb_json( $ac_m ), 'MEMBERS' ), 'anonymous, members-only: denied, no body' );
	ac_pb_check( '' === $ac_m['notices']['preview'] && '' === $ac_m['notices']['bypass'], 'anonymous: no admin notices' );

	$_GET['ac_as'] = 'board-member';
	ac_pb_check( 'denied' === AC_Playbook_Renderer::view_model( $ac_post )['view'], 'anonymous ?ac_as= ignored' );
	unset( $_GET['ac_as'] );

	add_filter( 'ac_public_full_text', '__return_true' );
	ac_pb_check( 'denied' === AC_Playbook_Renderer::view_model( $ac_post )['view'] && ! AC_Playbook_Renderer::view_model( $ac_post )['full_text'], 'full-text filter: no effect on a members-only playbook' );
	remove_filter( 'ac_public_full_text', '__return_true' );

	$ac_stub = static fn( $a ) => array_merge( (array) $a, [ 'Board Member' ] );
	add_filter( 'ac_current_audiences', $ac_stub, 99 );
	$ac_m = AC_Playbook_Renderer::view_model( $ac_post );
	ac_pb_check( 'standard' === $ac_m['view'] && str_contains( ac_pb_json( $ac_m ), 'MEMBERS phase body' ), 'stubbed Board Member: standard, full body' );
	remove_filter( 'ac_current_audiences', $ac_stub, 99 );

	update_post_meta( $ac_post, AC_Post_Type_Content::META_AUDIENCES, [ 'Public', 'Board Member' ] );
	$ac_m = AC_Playbook_Renderer::view_model( $ac_post );
	ac_pb_check( 'public' === $ac_m['view'] && ! $ac_m['full_text'] && ! str_contains( ac_pb_json( $ac_m ), 'MEMBERS' ), 'anonymous, Public: public view, first chapter only' );
	add_filter( 'ac_public_full_text', '__return_true' );
	$ac_m = AC_Playbook_Renderer::view_model( $ac_post );
	ac_pb_check( $ac_m['full_text'] && isset( $ac_m['sections']['chapters'], $ac_m['sections']['toc'] ) && ! isset( $ac_m['sections']['intro'] ), 'full-text filter on a Public playbook: chapters and toc, no intro' );
	remove_filter( 'ac_public_full_text', '__return_true' );
	update_post_meta( $ac_post, AC_Post_Type_Content::META_AUDIENCES, [ 'Board Member' ] );

	// 6. the_content never serves the stored body, in a loop or on the single page.
	global $post;
	$post = get_post( $ac_post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );
	$ac_html = apply_filters( 'the_content', $post->post_content );
	ac_pb_check( ! str_contains( $ac_html, 'MEMBERS' ) && str_contains( $ac_html, 'A dek for the test playbook.' ), 'the_content: teaser only' );
	$GLOBALS['wp_query'] = new WP_Query( [ 'p' => $ac_post, 'post_type' => 'playbook' ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$ac_html = apply_filters( 'the_content', $post->post_content );
	ac_pb_check( ! str_contains( $ac_html, 'MEMBERS' ), 'the_content on the single page: teaser only' );
	wp_reset_postdata();
	wp_reset_query(); // phpcs:ignore WordPress.WP.DiscouragedFunctions.wp_reset_query_wp_reset_query
} finally {
	unset( $_GET['ac_as'] );
	if ( $ac_post && ! is_wp_error( $ac_post ) ) {
		wp_delete_post( $ac_post, true );
	}
	if ( null === $ac_mode ) {
		delete_option( AC_Bypass::OPTION_MODE );
	} else {
		update_option( AC_Bypass::OPTION_MODE, $ac_mode );
	}
	wp_set_current_user( 0 );
}

$ac_r = $GLOBALS['ac_pb'];
if ( $ac_r['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $ac_r['pass'], $ac_r['fail'] ) );
}
WP_CLI::success( sprintf( 'Playbooks: %d checks passed.', $ac_r['pass'] ) );
