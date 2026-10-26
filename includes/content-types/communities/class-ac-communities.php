<?php
/**
 * The community directory: every published Contentful `community`, cached whole
 * in one option, like Site Features.
 *
 * Reports only carry the communities they are tagged with, so a site that wants
 * to list communities that have no reports yet (homepage explorer, empty states)
 * needs the full list. It also carries each community's `practiceArea`, the key
 * a site turns into colour (`data-area`); the feed never decides colour itself.
 *
 * Refreshed first in every full sync, and by the webhook whenever a community
 * changes (community is a Field Report dependency, so that triggers a full sync).
 * A failed or incomplete fetch leaves the cached directory as it was.
 */

defined( 'ABSPATH' ) || exit;

class AC_Communities extends AC_Option_Content {

	public const OPTION = 'ac_communities';

	public static function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'label'    => 'Communities',
				'singular' => 'Community',
				'include'  => 1,
				'order'    => 'fields.name,sys.id',
			]
		);
	}

	protected function map_item( array $raw, array &$warnings ): ?array {
		$name = trim( (string) ( AC_Contentful::field( $raw, 'name' ) ?? '' ) );
		if ( $name === '' ) {
			return null;
		}

		return [
			'id'            => (string) ( $raw['sys']['id'] ?? '' ),
			'slug'          => sanitize_title( (string) ( AC_Contentful::field( $raw, 'slug' ) ?? $name ) ),
			'name'          => $name,
			'short_name'    => (string) ( AC_Contentful::field( $raw, 'shortName' ) ?? '' ),
			'practice_area' => sanitize_key( (string) ( AC_Contentful::field( $raw, 'practiceArea' ) ?? '' ) ),
			'groups'        => array_values( array_filter( array_map( 'sanitize_title', (array) ( AC_Contentful::field( $raw, 'memberCenterGroups' ) ?? [] ) ) ) ),
			'boilerplate'   => (string) ( AC_Contentful::field( $raw, 'boilerplate' ) ?? '' ),
			'logo'          => AC_Contentful::field( $raw, 'logo' ),
		];
	}

	protected function item_key( array $item ): string {
		return $item['slug'];
	}

	/**
	 * Every cached community, keyed by slug, sorted by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return self::stored();
	}

	/** One community by slug, or null. */
	public static function get( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	/**
	 * Communities in one practice area, sorted by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function in_area( string $area ): array {
		return array_filter(
			self::all(),
			static fn ( $c ) => ( $c['practice_area'] ?? '' ) === $area
		);
	}

	/** Fill the directory now, for a site that reads it before its first sync. */
	public static function refresh(): array {
		$type = AC_Types::type( 'community' );

		return $type instanceof self ? $type->sync() : [ 'ok' => false, 'count' => 0, 'error' => 'communities are not synced on this site' ];
	}

	/**
	 * A report's practice area: its primary community's, else the first tagged
	 * community that has one. '' when none is known.
	 */
	public static function practice_area_for_post( int $post_id ): string {
		$entry   = AC_Post_Type_Content::data( $post_id );
		// Field Reports name it primaryCommunity; playbooks, community.
		$primary = AC_Contentful::field( $entry, 'primaryCommunity' ) ?? AC_Contentful::field( $entry, 'community' );

		$candidates = is_array( $primary ) ? [ $primary ] : [];
		foreach ( (array) AC_Contentful::field( $entry, 'additionalCommunities', [] ) as $extra ) {
			if ( is_array( $extra ) ) {
				$candidates[] = $extra;
			}
		}

		foreach ( $candidates as $community ) {
			// The synced entry carries practiceArea itself; the directory is the fallback
			// for reports synced before the field existed.
			$area = sanitize_key( (string) ( AC_Contentful::field( $community, 'practiceArea' ) ?? '' ) );
			if ( $area === '' ) {
				$slug = sanitize_title( (string) ( AC_Contentful::field( $community, 'slug' ) ?? '' ) );
				$area = (string) ( self::get( $slug )['practice_area'] ?? '' );
			}
			if ( $area !== '' ) {
				return $area;
			}
		}

		return '';
	}
}
