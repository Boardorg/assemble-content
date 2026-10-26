<?php
/**
 * A post type described entirely by config: what the registry builds for an
 * `ac_content_types` entry that has a `map` callable and no `class`.
 *
 * Extra config keys:
 *   - map        (callable)      fn( array $item ): array{post: array, meta: array}
 *   - after_save (callable|null) fn( int $post_id, array $item ): void
 *
 * A type with more to say (taxonomies, its own the_content) should subclass
 * AC_Post_Type_Content instead.
 */

defined( 'ABSPATH' ) || exit;

class AC_Generic_Post_Type extends AC_Post_Type_Content {

	public static function base_defaults(): array {
		return array_merge(
			parent::base_defaults(),
			[
				'map'        => null,
				'after_save' => null,
			]
		);
	}

	public function is_valid(): bool {
		return parent::is_valid() && is_callable( $this->config['map'] );
	}

	public function map( array $item ): array {
		return (array) call_user_func( $this->config['map'], $item );
	}

	public function after_save( int $post_id, array $item ): void {
		if ( is_callable( $this->config['after_save'] ) ) {
			call_user_func( $this->config['after_save'], $post_id, $item );
		}
	}
}
