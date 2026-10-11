<?php
/**
 * Member Center seams: group terms, topic/practice-area meta, AFR_Query::for_groups(),
 * and `afr_topic_taxonomy` assigning only existing terms. Uses the locally synced
 * reports; leaves no trace (the stand-in taxonomy and its terms are removed).
 *
 * npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/groups-topics-test.php
 */

$fail = 0;
$ok   = static function ( bool $cond, string $label ) use ( &$fail ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$fail += $cond ? 0 : 1;
};

$posts = get_posts( [ 'post_type' => AFR_CPT::POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1 ] );
$ok( count( $posts ) > 0, 'there are synced reports to test against' );

foreach ( $posts as $post ) {
	$entry  = AFR_Renderer::data( $post->ID );
	$want_t = wp_list_pluck( AFR_Query::entry_topics( $entry ), 'slug' );
	$ok( AFR_Query::topic_slugs( $post->ID ) === array_values( array_unique( $want_t ) ), "#{$post->ID} topic meta matches the entry" );

	$want_g = [];
	$want_a = [];
	foreach ( array_merge( [ AFR_Contentful::field( $entry, 'primaryCommunity' ) ], (array) AFR_Contentful::field( $entry, 'additionalCommunities', [] ) ) as $c ) {
		$want_g = array_merge( $want_g, (array) AFR_Contentful::field( $c, 'memberCenterGroups', [] ) );
		$want_a[] = (string) AFR_Contentful::field( $c, 'practiceArea', '' );
	}
	$have_g = AFR_Query::groups( $post->ID );
	sort( $have_g );
	$want_g = array_values( array_unique( $want_g ) );
	sort( $want_g );
	$ok( $have_g === $want_g, "#{$post->ID} group terms = communities' memberCenterGroups (" . implode( ',', $have_g ) . ')' );
	$ok( array_values( (array) get_post_meta( $post->ID, AFR_CPT::META_PRACTICE_AREAS, false ) ) === array_values( array_unique( array_filter( $want_a ) ) ), "#{$post->ID} practice-area meta" );

	foreach ( $have_g as $g ) {
		$ok( in_array( $post->ID, wp_list_pluck( AFR_Query::for_groups( [ $g ], -1 ), 'ID' ), true ), "#{$post->ID} is listed for group {$g}" );
	}
}

// A group no report carries returns nothing; an empty list returns nothing.
$ok( [] === AFR_Query::for_groups( [ 'no-such-group' ] ), 'unknown group lists nothing' );
$ok( [] === AFR_Query::for_groups( [] ), 'empty group list lists nothing' );

// A report from another Board is never listed for a group it doesn't carry.
foreach ( AFR_Query::for_groups( [ 'aeo' ], -1 ) as $p ) {
	$ok( in_array( 'aeo', AFR_Query::groups( $p->ID ), true ), "#{$p->ID} listed for aeo carries aeo" );
}

// afr_topic_taxonomy: a stand-in closed vocabulary holding all but one of a report's topics.
$target = null;
foreach ( $posts as $post ) {
	if ( count( AFR_Query::topic_slugs( $post->ID ) ) >= 2 ) {
		$target = $post;
		break;
	}
}
$ok( null !== $target, 'a report with 2+ topics exists' );
if ( $target ) {
	$tax   = 'afr-test-topic';
	$slugs = AFR_Query::topic_slugs( $target->ID );
	register_taxonomy( $tax, [ AFR_CPT::POST_TYPE ], [ 'public' => false ] );
	$known = array_slice( $slugs, 0, -1 );
	foreach ( $known as $s ) {
		wp_insert_term( $s, $tax, [ 'slug' => $s ] );
	}
	$filter = static fn () => $tax;
	add_filter( 'afr_topic_taxonomy', $filter );
	$seen = 0;
	$hook = static function () use ( &$seen ) { $seen++; };
	add_action( 'afr_synced', $hook );
	AFR_Sync::sync_one( (string) get_post_meta( $target->ID, AFR_CPT::META_ENTRY_ID, true ), true, 'test' );
	remove_filter( 'afr_topic_taxonomy', $filter );
	remove_action( 'afr_synced', $hook );

	$assigned = wp_get_object_terms( $target->ID, $tax, [ 'fields' => 'slugs' ] );
	sort( $assigned );
	sort( $known );
	$ok( $assigned === $known, 'site taxonomy gets exactly the existing terms (' . implode( ',', $assigned ) . ')' );
	$ok( ! get_term_by( 'slug', end( $slugs ), $tax ), 'a slug missing from the site vocabulary is not created' );
	$ok( 1 === $seen, 'afr_synced fired once' );

	wp_delete_object_term_relationships( $target->ID, $tax );
	foreach ( get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids' ] ) as $id ) {
		wp_delete_term( $id, $tax );
	}
	unregister_taxonomy( $tax );
}

// The directory carries group slugs.
$dir = AFR_Communities::all();
$ok( isset( $dir['learning-development']['groups'] ) && [ 'learning-development' ] === $dir['learning-development']['groups'], 'directory: L&D carries its group' );
$ok( isset( $dir['esg-csr']['groups'] ) && 2 === count( $dir['esg-csr']['groups'] ), 'directory: ESG carries both groups' );

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
