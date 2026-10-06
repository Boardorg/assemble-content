<?php
/**
 * The content-type registry: which Contentful types this site carries, and how
 * each one becomes a WordPress post.
 *
 * Fetching, syncing, webhook routing, orphan drafting, the CLI and the status
 * screen all read this one list, so adding a Contentful-backed type means adding
 * an entry here (or through the `afr_content_types` filter), not a new code path.
 *
 * Synced post types are a hidden, read-only cache of Contentful: no admin menus
 * or edit screens, no REST exposure, no authoring in WordPress.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Types {

	/**
	 * Keys every entry may carry:
	 *
	 * - post_type     (string)   WordPress post type the entries become.
	 * - label         (string)   Plural label, used for the post type and status output.
	 * - singular      (string)   Singular label.
	 * - slug          (string)   URL base, also the archive slug.
	 * - include       (int)      CDA link depth to resolve.
	 * - page_size     (int)      Entries per CDA page. Lower it if responses near the ~7MB cap.
	 * - order         (string)   CDA order for the full fetch. Keep it immutable (default sys.id).
	 * - gated         (bool)     Whether entries carry audience-gated content.
	 * - dependencies  (string[]) Content types whose changes re-sync every entry of this type.
	 * - map           (callable) fn( array $entry ): array{post: array, meta: array} — post
	 *                            fields (title required) and type-specific post meta.
	 * - after_save    (callable|null) fn( int $post_id, array $entry ): void — terms etc.
	 * - register      (bool)     Register the post type from this entry (default true).
	 * - post_type_args (array)   Extra register_post_type() args, merged over the defaults.
	 *
	 * @return array<string,array> Keyed by Contentful content type ID.
	 */
	public static function all(): array {
		$types = [
			'fieldReport' => [
				'post_type'    => AFR_CPT::POST_TYPE,
				'label'        => 'Field Reports',
				'singular'     => 'Field Report',
				'slug'         => 'field-reports',
				'include'      => 3,
				'page_size'    => 100,
				'gated'        => true,
				'dependencies' => [ 'mdTake', 'person', 'community' ],
				'map'          => [ AFR_Field_Report_Type::class, 'map' ],
				'after_save'   => [ AFR_Field_Report_Type::class, 'after_save' ],
				// The July theme reads has_post_thumbnail() for its placeholder image.
				'post_type_args' => [
					'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
				],
			],
		];

		/**
		 * Filter the content types this site syncs from Contentful.
		 *
		 * Remove an entry to stop syncing that type on this site; add one to carry a
		 * new type. Keys are Contentful content type IDs.
		 *
		 * @param array<string,array> $types
		 */
		$types = (array) apply_filters( 'afr_content_types', $types );

		foreach ( $types as $id => $type ) {
			$types[ $id ] = wp_parse_args(
				(array) $type,
				[
					'post_type'      => '',
					'label'          => (string) $id,
					'singular'       => (string) $id,
					'slug'           => '',
					'include'        => 2,
					'page_size'      => 100,
					'order'          => 'sys.id',
					'gated'          => false,
					'dependencies'   => [],
					'map'            => null,
					'after_save'     => null,
					'register'       => true,
					'post_type_args' => [],
				]
			);
		}

		return array_filter(
			$types,
			static fn( $type ) => $type['post_type'] !== '' && is_callable( $type['map'] )
		);
	}

	/** One registry entry by Contentful content type ID. */
	public static function get( string $content_type ): ?array {
		return self::all()[ $content_type ] ?? null;
	}

	/** The Contentful content type ID that a post type is synced from. */
	public static function content_type_for_post_type( string $post_type ): string {
		foreach ( self::all() as $id => $type ) {
			if ( $type['post_type'] === $post_type ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Resolve a CLI-style type argument: a Contentful ID (`fieldReport`) or a post
	 * type (`field_report`). Returns the Contentful ID, or '' when unknown.
	 */
	public static function resolve( string $name ): string {
		if ( self::get( $name ) ) {
			return $name;
		}

		return self::content_type_for_post_type( $name );
	}

	/** @return string[] Every synced post type. */
	public static function post_types(): array {
		return array_values( array_unique( array_column( self::all(), 'post_type' ) ) );
	}

	/** @return string[] Content types whose changes trigger a full re-sync. */
	public static function dependency_types(): array {
		$deps = [];
		foreach ( self::all() as $type ) {
			$deps = array_merge( $deps, (array) $type['dependencies'] );
		}

		return array_values( array_unique( $deps ) );
	}

	// ---------------------------------------------------------- registration

	public static function init(): void {
		// Priority 10, after taxonomies at 9. See the note in AFR_CPT::init().
		add_action( 'init', [ self::class, 'register_post_types' ], 10 );
	}

	public static function register_post_types(): void {
		foreach ( self::all() as $type ) {
			if ( ! $type['register'] || post_type_exists( $type['post_type'] ) ) {
				continue;
			}

			register_post_type( $type['post_type'], self::post_type_args( $type ) );
		}
	}

	/**
	 * Defaults shared by every synced type.
	 *
	 * `public` stays true so URLs, archives, queries and Yoast sitemaps work.
	 * `show_ui` is false because Contentful is the only source of truth: there is
	 * nothing to manage here. `show_in_rest` is false because REST would serve
	 * `content.rendered` to anonymous visitors, bypassing the audience gate.
	 */
	private static function post_type_args( array $type ): array {
		$args = [
			'labels'          => [
				'name'          => $type['label'],
				'singular_name' => $type['singular'],
			],
			'public'            => true,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'show_in_nav_menus' => false,
			'show_in_admin_bar' => false,
			'show_in_rest'      => false,
			'has_archive'       => $type['slug'] !== '' ? $type['slug'] : true,
			'rewrite'           => [
				'slug'       => $type['slug'] !== '' ? $type['slug'] : $type['post_type'],
				'with_front' => false,
			],
			'supports'          => [ 'title', 'editor', 'excerpt', 'custom-fields' ],
			'capability_type'   => 'post',
			'map_meta_cap'      => true,
			'capabilities'      => [ 'create_posts' => 'do_not_allow' ],
		];

		// Shallow merge: an entry that sets `supports` or `labels` replaces the default list whole.
		$args = array_merge( $args, (array) $type['post_type_args'] );

		// Never let a registry entry re-open REST on a gated type.
		if ( $type['gated'] ) {
			$args['show_in_rest'] = false;
		}

		return $args;
	}
}
