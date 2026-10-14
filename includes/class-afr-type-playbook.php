<?php
/**
 * How a Contentful `playbook` entry maps onto a `playbook` post.
 *
 * Registered in AFR_Types. The sync engine handles matching, revisions, the
 * shared `_afr_*` bookkeeping meta and orphan drafting; this class only says
 * which post fields and meta a playbook carries. Playbooks are listed on their
 * own index, so they get no community terms.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Playbook_Type {

	public const POST_TYPE = 'playbook';

	/**
	 * @return array{post: array, meta: array<string,mixed>}
	 */
	public static function map( array $entry ): array {
		$title = trim( (string) ( AFR_Contentful::field( $entry, 'title' ) ?? '' ) );

		return [
			'post' => [
				'post_title'   => $title,
				'post_name'    => sanitize_title( (string) ( AFR_Contentful::field( $entry, 'slug' ) ?? $title ) ),
				// A rendered copy so search and Yoast have something to read. Display always
				// goes through AFR_Playbook::view_model(), so this is never served raw.
				'post_content' => AFR_Playbook::render_document( $entry ),
				'post_excerpt' => AFR_Playbook::dek( $entry ),
			],
			'meta' => [
				AFR_CPT::META_AUDIENCES => (array) ( AFR_Contentful::field( $entry, 'availableTo', [] ) ),
			],
		];
	}

	public static function after_save( int $post_id, array $entry ): void {
		$areas = [];
		foreach ( AFR_Placement::functional_areas( $entry ) as $community ) {
			$areas[] = sanitize_key( (string) ( AFR_Contentful::field( $community, 'practiceArea' ) ?? '' ) );
		}

		self::set_multi_meta( $post_id, AFR_CPT::META_PRACTICE_AREAS, $areas );
		self::set_multi_meta( $post_id, AFR_CPT::META_TOPICS, wp_list_pluck( AFR_Query::entry_topics( $entry ), 'slug' ) );
	}

	/** Replace a multi-row meta key with one row per unique, non-empty value. */
	private static function set_multi_meta( int $post_id, string $key, array $values ): void {
		delete_post_meta( $post_id, $key );
		foreach ( array_unique( array_filter( array_map( 'strval', $values ) ) ) as $value ) {
			add_post_meta( $post_id, $key, $value );
		}
	}
}
