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
 * Refreshed by every full sync, and by the webhook whenever a community changes
 * (community is a Field Report dependency, so that triggers a full sync).
 */

defined( 'ABSPATH' ) || exit;

class AFR_Communities {

	public const OPTION = 'afr_communities';

	/**
	 * Re-fetch every published community from the delivery API.
	 *
	 * A failed or incomplete fetch leaves the cached directory as it was.
	 *
	 * @return array{count:int,error:string}
	 */
	public static function sync(): array {
		$fetch = AFR_Contentful::get_all( 'community', 1, 100, 'fields.name,sys.id' );

		if ( $fetch['error'] !== '' || ! $fetch['complete'] ) {
			return [ 'count' => 0, 'error' => $fetch['error'] ?: 'incomplete fetch' ];
		}

		$communities = [];
		foreach ( $fetch['items'] as $entry ) {
			$name = trim( (string) ( AFR_Contentful::field( $entry, 'name' ) ?? '' ) );
			if ( $name === '' ) {
				continue;
			}

			$slug = sanitize_title( (string) ( AFR_Contentful::field( $entry, 'slug' ) ?? $name ) );

			$communities[ $slug ] = [
				'id'            => (string) ( $entry['sys']['id'] ?? '' ),
				'slug'          => $slug,
				'name'          => $name,
				'short_name'    => (string) ( AFR_Contentful::field( $entry, 'shortName' ) ?? '' ),
				'practice_area' => sanitize_key( (string) ( AFR_Contentful::field( $entry, 'practiceArea' ) ?? '' ) ),
				'boilerplate'   => (string) ( AFR_Contentful::field( $entry, 'boilerplate' ) ?? '' ),
				'logo'          => AFR_Contentful::field( $entry, 'logo' ),
			];
		}

		update_option( self::OPTION, $communities, false );

		return [ 'count' => count( $communities ), 'error' => '' ];
	}

	/**
	 * Every cached community, keyed by slug, sorted by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return array_filter( (array) get_option( self::OPTION, [] ), 'is_array' );
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

	/**
	 * A report's practice area: its primary community's, else the first tagged
	 * community that has one. '' when none is known.
	 */
	public static function practice_area_for_post( int $post_id ): string {
		$entry   = AFR_Renderer::data( $post_id );
		$primary = AFR_Contentful::field( $entry, 'primaryCommunity' );

		$candidates = is_array( $primary ) ? [ $primary ] : [];
		foreach ( (array) AFR_Contentful::field( $entry, 'additionalCommunities', [] ) as $extra ) {
			if ( is_array( $extra ) ) {
				$candidates[] = $extra;
			}
		}

		foreach ( $candidates as $community ) {
			// The synced entry carries practiceArea itself; the directory is the fallback
			// for reports synced before the field existed.
			$area = sanitize_key( (string) ( AFR_Contentful::field( $community, 'practiceArea' ) ?? '' ) );
			if ( $area === '' ) {
				$slug = sanitize_title( (string) ( AFR_Contentful::field( $community, 'slug' ) ?? '' ) );
				$area = (string) ( self::get( $slug )['practice_area'] ?? '' );
			}
			if ( $area !== '' ) {
				return $area;
			}
		}

		return '';
	}
}
