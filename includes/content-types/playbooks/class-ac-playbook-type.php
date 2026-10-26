<?php
/**
 * Playbooks: Contentful `playbook` entries as `playbook` posts.
 *
 * The sync engine (AC_Post_Type_Content) handles matching, revisions, the shared
 * `_ac_*` bookkeeping meta and orphan drafting; this class only says which post
 * fields and meta a playbook carries. Playbooks are listed on their own index, so
 * they get no community terms. The site renders the page from
 * AC_Playbook_Renderer::view_model(); `the_content` is always the teaser.
 */

defined( 'ABSPATH' ) || exit;

class AC_Playbook_Type extends AC_Post_Type_Content {

	public const POST_TYPE = 'playbook';

	public static function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'post_type'    => self::POST_TYPE,
				'label'        => 'Playbooks',
				'singular'     => 'Playbook',
				'slug'         => 'playbooks',
				'include'      => 3,
				'page_size'    => 50,
				'gated'        => true,
				'dependencies' => [ 'person', 'community', 'topic', 'topicCategory', AC_Playbook_Renderer::CALLOUT_TYPE ],
				'placed'       => true,
			]
		);
	}

	/**
	 * @return array{post: array, meta: array}
	 */
	public function map( array $item ): array {
		$title = trim( (string) ( AC_Contentful::field( $item, 'title' ) ?? '' ) );

		return [
			'post' => [
				'post_title'   => $title,
				'post_name'    => sanitize_title( (string) ( AC_Contentful::field( $item, 'slug' ) ?? $title ) ),
				// A rendered copy so search and Yoast have something to read. Display always
				// goes through AC_Playbook_Renderer::view_model(), so this is never served raw.
				'post_content' => AC_Playbook_Renderer::render_document( $item ),
				'post_excerpt' => AC_Playbook_Renderer::dek( $item ),
			],
			'meta' => [
				self::META_AUDIENCES => (array) ( AC_Contentful::field( $item, 'availableTo', [] ) ),
			],
		];
	}

	public function after_save( int $post_id, array $item ): void {
		$areas = [];
		foreach ( AC_Placement::functional_areas( $item ) as $community ) {
			$areas[] = sanitize_key( (string) ( AC_Contentful::field( $community, 'practiceArea' ) ?? '' ) );
		}

		self::set_multi_meta( $post_id, self::META_PRACTICE_AREAS, $areas );
		self::set_multi_meta( $post_id, self::META_TOPICS, wp_list_pluck( AC_Query::entry_topics( $item ), 'slug' ) );
	}
}
