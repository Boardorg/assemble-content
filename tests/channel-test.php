<?php
/**
 * The per-site channel ("Show on"): runs real syncs against the configured
 * Contentful environment with the channel forced through `ac_site_channel`,
 * and leaves the reports as it found them (re-synced on 'Public website').
 * Local only.
 *
 * npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/channel-test.php
 */

$fail = 0;
$ok   = static function ( bool $cond, string $label ) use ( &$fail ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$fail += $cond ? 0 : 1;
};
$as = static function ( string $channel ) {
	remove_all_filters( 'ac_site_channel' );
	add_filter( 'ac_site_channel', static fn () => $channel );
};
$published = static fn () => (int) ( wp_count_posts( AC_Field_Report_Type::POST_TYPE )->publish ?? 0 );

$as( 'Public website' );
$r = AC_Sync::sync_all( true, 'test' );
$baseline = $published();
$ok( $r['errors'] === 0 && $baseline > 0, "Public website: synced {$baseline}, no errors" );

$as( '' );
$r = AC_Sync::sync_all( false, 'test' );
$ok( $r['errors'] > 0 && $r['drafted'] === 0 && $published() === $baseline, 'no channel: error reported, nothing drafted' );

$post  = get_posts( [ 'post_type' => AC_Field_Report_Type::POST_TYPE, 'numberposts' => 1 ] )[0];
$entry = (string) get_post_meta( $post->ID, AC_Post_Type_Content::META_ENTRY_ID, true );
$r = AC_Sync::sync_one( $entry, true, 'test' );
$ok( $r['drafted'] === 0 && get_post_status( $post->ID ) === 'publish', 'no channel: webhook path drafts nothing' );

$as( 'Nowhere' );
$r = AC_Sync::sync_one( $entry, true, 'test' );
$ok( $r['drafted'] === 1 && get_post_status( $post->ID ) === 'draft', 'entry not ticked for this site: webhook path drafts its post' );

$r = AC_Sync::sync_all( false, 'test' );
$ok( $published() === 0, 'channel with no ticked reports: every post drafted (complete fetch, nothing listed)' );

$as( 'Public website' );
AC_Sync::sync_all( true, 'test' );
$ok( $published() === $baseline, 'back on Public website: all restored' );

foreach ( get_posts( [ 'post_type' => AC_Field_Report_Type::POST_TYPE, 'numberposts' => -1 ] ) as $p ) {
	$ok( AC_Placement::on_site( (array) AC_Post_Type_Content::data( $p->ID ) ), "#{$p->ID} is published to the Assemble Site" );
}
remove_all_filters( 'ac_site_channel' );

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
