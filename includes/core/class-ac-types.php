<?php
/**
 * The content-type registry: which content this site carries, where each kind
 * comes from, and how it is stored.
 *
 * Syncing, webhook routing, orphan drafting, the CLI and the admin screens all
 * read this one list, so adding a type means adding an AC_Content_Type subclass
 * and an entry here (or through the `ac_content_types` filter), not a new code
 * path. Keys are Contentful content type IDs for Contentful types.
 *
 * The registry also holds the sources (AC_Source) types fetch from, by ID.
 */

defined( 'ABSPATH' ) || exit;

class AC_Types {

	/** Hook everything up: each registered type's init(), after themes and plugins have filtered the list. */
	public static function init(): void {
		add_action( 'init', [ self::class, 'boot' ], 1 );
	}

	public static function boot(): void {
		foreach ( self::types() as $type ) {
			$type->init();
		}
	}

	/**
	 * Register every synced post type and its taxonomies now, in the order the
	 * init hooks use: for activation, and for a type added after `init`.
	 */
	public static function register_post_types(): void {
		$types = self::post_type_objects();

		foreach ( $types as $type ) {
			$type->register_taxonomies();
		}
		AC_Placement::register_taxonomy();
		foreach ( $types as $type ) {
			$type->register_post_type();
		}
	}

	/**
	 * Every type's config, keyed by registry key, as the `ac_content_types` filter
	 * left it. Each config names its `class`; the keys each class reads are listed
	 * on AC_Content_Type::base_defaults() and its storage kind's base_defaults().
	 *
	 * @return array<string,array>
	 */
	public static function definitions(): array {
		$types = [
			// First: functional areas are resolved from the community directory.
			'community'   => AC_Communities::defaults(),
			'fieldReport' => AC_Field_Report_Type::defaults(),
			// Rendered by the site from AC_Playbook_Renderer::view_model().
			'playbook'    => AC_Playbook_Type::defaults(),
			'siteFeature' => AC_Site_Features::defaults(),
			'summits'     => AC_Summits::defaults(),
		];

		/**
		 * Filter the content types this site syncs.
		 *
		 * Remove an entry to stop syncing that type on this site; add one to carry a
		 * new type. A config with a `map` callable and no `class` becomes an
		 * AC_Generic_Post_Type.
		 *
		 * @param array<string,array> $types Configs keyed by registry key.
		 */
		$types = (array) apply_filters( 'ac_content_types', $types );

		foreach ( $types as $key => $type ) {
			$type = (array) $type;

			// Before 1.9.0 a placed type named its `showOn` field instead.
			if ( ! empty( $type['channel_field'] ) && ! isset( $type['placed'] ) ) {
				$type['placed'] = true;
			}

			if ( empty( $type['class'] ) && isset( $type['map'] ) ) {
				$type['class'] = AC_Generic_Post_Type::class;
			}

			$types[ $key ] = $type;
		}

		return $types;
	}

	/**
	 * Every valid registered type, built from its config.
	 *
	 * @return array<string,AC_Content_Type>
	 */
	public static function types(): array {
		$built = [];

		foreach ( self::definitions() as $key => $config ) {
			$class = (string) ( $config['class'] ?? '' );

			if ( ! is_a( $class, AC_Content_Type::class, true ) || ( new ReflectionClass( $class ) )->isAbstract() ) {
				continue;
			}

			$type = new $class( (string) $key, $config );

			if ( $type->is_valid() ) {
				$built[ (string) $key ] = $type;
			}
		}

		return $built;
	}

	/** One registered type by key. */
	public static function type( string $key ): ?AC_Content_Type {
		return self::types()[ $key ] ?? null;
	}

	/**
	 * The registered types stored as posts.
	 *
	 * @return array<string,AC_Post_Type_Content>
	 */
	public static function post_type_objects(): array {
		return array_filter( self::types(), static fn( $type ) => $type instanceof AC_Post_Type_Content );
	}

	/** The registered type stored in a post type. */
	public static function for_post_type( string $post_type ): ?AC_Post_Type_Content {
		foreach ( self::post_type_objects() as $type ) {
			if ( $type->post_type() === $post_type ) {
				return $type;
			}
		}

		return null;
	}

	// ------------------------------------------------- post types, as config

	/**
	 * The post-type configs, keyed by registry key. The shape callers read before
	 * 2.0.0: post_type, label, slug, gated, placed, dependencies and so on.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		return array_map( static fn( $type ) => $type->config(), self::post_type_objects() );
	}

	/** One post-type config by registry key. */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/** The registry key a post type is synced from. */
	public static function content_type_for_post_type( string $post_type ): string {
		$type = self::for_post_type( $post_type );

		return $type ? $type->key() : '';
	}

	/**
	 * Resolve a CLI-style type argument: a registry key (`fieldReport`, `summits`)
	 * or a post type (`field_report`). Returns the key, or '' when unknown.
	 */
	public static function resolve( string $name ): string {
		if ( self::type( $name ) ) {
			return $name;
		}

		return self::content_type_for_post_type( $name );
	}

	/** @return string[] Every synced post type. */
	public static function post_types(): array {
		return array_values( array_unique( array_map( static fn( $type ) => $type->post_type(), self::post_type_objects() ) ) );
	}

	/** @return string[] Source types whose changes trigger a full re-sync. */
	public static function dependency_types(): array {
		$deps = [];
		foreach ( self::types() as $type ) {
			$deps = array_merge( $deps, $type->dependencies() );
		}

		return array_values( array_unique( $deps ) );
	}

	// --------------------------------------------------------------- sources

	/**
	 * Every source, keyed by ID.
	 *
	 * @return array<string,AC_Source>
	 */
	public static function sources(): array {
		static $sources = null;

		if ( $sources === null ) {
			$sources = [];

			/**
			 * Filter the sources content types can fetch from.
			 *
			 * @param AC_Source[] $sources
			 */
			foreach ( (array) apply_filters( 'ac_sources', [ new AC_Contentful(), new AC_WP_JSON() ] ) as $source ) {
				if ( $source instanceof AC_Source ) {
					$sources[ $source->id() ] = $source;
				}
			}
		}

		return $sources;
	}

	/** One source by ID. */
	public static function source( string $id ): ?AC_Source {
		return self::sources()[ $id ] ?? null;
	}
}
