<?php
/**
 * Where a piece is published, and which Boards it is about.
 *
 * Two editor-facing checkbox fields drive this:
 *
 * - `publishTo`: the places a piece appears. "Assemble Site" is the public
 *   website; every other value is a Member Center place (a Network, Board or
 *   Council), matched by name. Each site syncs only the pieces placed on it.
 * - `functionalAreas`: the Boards a piece is about, by Community entry name.
 *   The Community entry still supplies the name, logo, boilerplate and practice
 *   area; this class resolves each name to it from the cached directory.
 *
 * Entries from before these fields existed carry `showOn` and the Community
 * links instead. Both are read as a fallback, so a site can run this release
 * before Contentful is migrated.
 *
 * Who may read a piece is still `availableTo`. Placement only decides where a
 * piece is listed; it never opens a body.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Placement {

	/** The `publishTo` value for the public website. */
	public const ASSEMBLE_SITE = 'Assemble Site';

	/** Hidden taxonomy of place names, on every synced post type. */
	public const TAXONOMY = 'afr_place';

	/** Channels that mean the public website. 'Public website' is the `showOn` name. */
	private const SITE_CHANNELS = [ 'Public website', self::ASSEMBLE_SITE ];

	/** The channel that means any Member Center place. */
	private const MEMBER_CENTER_CHANNEL = 'Member Center';

	/** Content types that link one community as `community` rather than primary + additional. */
	private const SINGLE_COMMUNITY_TYPES = [ 'playbook' ];

	public static function init(): void {
		// With the other taxonomies, ahead of the post types at 10.
		add_action( 'init', [ self::class, 'register_taxonomy' ], 9 );
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			AFR_Types::post_types(),
			[
				'labels'            => [
					'name'          => 'Places',
					'singular_name' => 'Place',
				],
				'public'            => false,
				'hierarchical'      => false,
				'show_ui'           => false,
				'show_admin_column' => false,
				'show_in_rest'      => false,
				'query_var'         => false,
				'rewrite'           => false,
			]
		);
	}

	// ---------------------------------------------------------------- reading

	/**
	 * The places an entry is published to: `publishTo`, else derived from `showOn`
	 * ("Public website" is the Assemble Site; "Member Center" is each functional
	 * area's Board).
	 *
	 * @return string[]
	 */
	public static function places( array $entry ): array {
		$places = self::names( $entry, 'publishTo' );
		if ( $places ) {
			return $places;
		}

		$legacy = [];
		foreach ( self::names( $entry, 'showOn' ) as $channel ) {
			if ( in_array( $channel, self::SITE_CHANNELS, true ) ) {
				$legacy[] = self::ASSEMBLE_SITE;
			} elseif ( self::MEMBER_CENTER_CHANNEL === $channel ) {
				foreach ( self::functional_areas( $entry ) as $community ) {
					$legacy[] = trim( (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' ) );
				}
			}
		}

		return array_values( array_unique( array_filter( $legacy ) ) );
	}

	/**
	 * Is this entry placed on this site? False when the site has no channel.
	 *
	 * 'Public website' / 'Assemble Site' sites take Assemble Site pieces; a
	 * 'Member Center' site takes pieces with any other place; any other channel
	 * takes pieces placed under that exact name.
	 */
	public static function on_site( array $entry ): bool {
		$channel = AFR_Settings::site_channel();
		if ( '' === $channel ) {
			return false;
		}

		$places = self::places( $entry );

		if ( in_array( $channel, self::SITE_CHANNELS, true ) ) {
			$on = in_array( self::ASSEMBLE_SITE, $places, true );
		} elseif ( self::MEMBER_CENTER_CHANNEL === $channel ) {
			$on = (bool) array_diff( $places, [ self::ASSEMBLE_SITE ] );
		} else {
			$on = in_array( $channel, $places, true );
		}

		/**
		 * Filter whether an entry is placed on this site.
		 *
		 * @param bool     $on      Whether the entry syncs here.
		 * @param array    $entry   The resolved entry.
		 * @param string[] $places  Its places.
		 * @param string   $channel This site's channel.
		 */
		return (bool) apply_filters( 'afr_on_site', $on, $entry, $places, $channel );
	}

	/**
	 * The entry's functional areas as resolved Community entries, in the editor's
	 * order: `functionalAreas`, else the Community links.
	 *
	 * @return array<int,array>
	 */
	public static function functional_areas( array $entry ): array {
		$names = self::names( $entry, 'functionalAreas' );

		if ( $names ) {
			return array_values( array_filter( array_map( [ self::class, 'community_entry' ], $names ) ) );
		}

		$communities = [];
		foreach ( [ 'primaryCommunity', 'community' ] as $field ) {
			$linked = AFR_Contentful::field( $entry, $field );
			if ( is_array( $linked ) ) {
				$communities[] = $linked;
				break;
			}
		}

		foreach ( (array) AFR_Contentful::field( $entry, 'additionalCommunities', [] ) as $extra ) {
			if ( is_array( $extra ) ) {
				$communities[] = $extra;
			}
		}

		return $communities;
	}

	/**
	 * Copy resolved functional areas into the Community link fields the renderers
	 * read, so every view keeps working from one stored payload. The first ticked
	 * Board fills the single-community slot.
	 */
	public static function normalize( array $entry ): array {
		if ( ! self::names( $entry, 'functionalAreas' ) ) {
			return $entry;
		}

		$communities = self::functional_areas( $entry );
		if ( ! $communities ) {
			return $entry;
		}

		$type = (string) ( $entry['sys']['contentType']['sys']['id'] ?? '' );

		if ( in_array( $type, self::SINGLE_COMMUNITY_TYPES, true ) ) {
			$entry['fields']['community'] = $communities[0];
		} else {
			$entry['fields']['primaryCommunity']      = $communities[0];
			$entry['fields']['additionalCommunities'] = array_slice( $communities, 1 );
		}

		return $entry;
	}

	/**
	 * Problems an editor should hear about. The entry still syncs.
	 *
	 * @return string[]
	 */
	public static function warnings( array $entry ): array {
		$warnings = [];

		if ( ! self::places( $entry ) ) {
			$warnings[] = 'no place ticked in Publish to';
		}

		foreach ( self::names( $entry, 'functionalAreas' ) as $name ) {
			if ( ! self::community_entry( $name ) ) {
				$warnings[] = sprintf( 'functional area "%s" has no Community entry', $name );
			}
		}

		/**
		 * Filter the placement warnings for an entry, e.g. a site's own checks.
		 *
		 * @param string[] $warnings
		 * @param array    $entry
		 */
		return array_values( (array) apply_filters( 'afr_placement_warnings', $warnings, $entry ) );
	}

	// ----------------------------------------------------------------- posts

	/** Store a post's places as terms. */
	public static function apply( int $post_id, array $entry ): void {
		wp_set_object_terms( $post_id, self::places( $entry ), self::TAXONOMY, false );
	}

	/**
	 * A post's place names.
	 *
	 * @return string[]
	 */
	public static function post_places( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, self::TAXONOMY, [ 'fields' => 'names' ] );

		// Term names are stored HTML-escaped ("ESG &amp; CSR Board").
		return is_wp_error( $terms ) ? [] : array_map( 'wp_specialchars_decode', array_values( $terms ) );
	}

	/** The term slug for a place name, for tax_query. */
	public static function slug( string $place ): string {
		return sanitize_title( $place );
	}

	// --------------------------------------------------------------- helpers

	/**
	 * A Community from the cached directory as an entry-shaped array, matched by
	 * name (case-insensitive). Null when there's none.
	 */
	public static function community_entry( string $name ): ?array {
		static $synced = false;

		$directory = AFR_Communities::all();

		// First use on a fresh site: fill the directory once.
		if ( ! $directory && ! $synced ) {
			$synced = true;
			AFR_Communities::sync();
			$directory = AFR_Communities::all();
		}

		$key = strtolower( trim( $name ) );

		foreach ( $directory as $community ) {
			if ( strtolower( (string) ( $community['name'] ?? '' ) ) !== $key ) {
				continue;
			}

			return [
				'sys'    => [
					'id'          => (string) ( $community['id'] ?? '' ),
					'type'        => 'Entry',
					'contentType' => [ 'sys' => [ 'id' => 'community' ] ],
				],
				'fields' => [
					'name'               => (string) ( $community['name'] ?? '' ),
					'shortName'          => (string) ( $community['short_name'] ?? '' ),
					'slug'               => (string) ( $community['slug'] ?? '' ),
					'practiceArea'       => (string) ( $community['practice_area'] ?? '' ),
					'memberCenterGroups' => (array) ( $community['groups'] ?? [] ),
					'boilerplate'        => (string) ( $community['boilerplate'] ?? '' ),
					'logo'               => $community['logo'] ?? null,
				],
			];
		}

		return null;
	}

	/**
	 * A checkbox field's values: trimmed, non-empty, unique, in order.
	 *
	 * @return string[]
	 */
	private static function names( array $entry, string $field ): array {
		$values = array_map( static fn( $v ) => trim( (string) $v ), (array) AFR_Contentful::field( $entry, $field, [] ) );

		return array_values( array_unique( array_filter( $values, static fn( $v ) => '' !== $v ) ) );
	}
}
