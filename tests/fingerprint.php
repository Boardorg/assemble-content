<?php
/**
 * Prints one hash per synced post: content, excerpt, date, slug, status, terms
 * and every `_afr_*` meta except sync timestamps. Run before and after a code
 * change plus `sync --all --force` to prove the mapping didn't change.
 *
 * wp eval-file wp-content/plugins/assemble-content/tests/fingerprint.php
 */

foreach ( AFR_Types::post_types() as $post_type ) {
	$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ] );
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		$meta = array_filter(
			get_post_meta( $id ),
			static fn( $k ) => str_starts_with( $k, '_afr_' ) && ! in_array( $k, [ '_afr_synced_at' ], true ),
			ARRAY_FILTER_USE_KEY
		);
		ksort( $meta );
		$terms = wp_get_object_terms( $id, get_object_taxonomies( $post_type ), [ 'fields' => 'slugs' ] );
		$parts = [ $post->post_title, $post->post_name, $post->post_status, $post->post_date, $post->post_excerpt, $post->post_content, wp_json_encode( $meta ), wp_json_encode( $terms ), (string) get_post_thumbnail_id( $id ) ];
		printf( "%s\t%d\t%s\n", $post_type, $id, md5( implode( "\x1f", $parts ) ) );
	}
}
