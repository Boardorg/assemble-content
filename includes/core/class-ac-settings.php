<?php
/**
 * Configuration. Values resolve in this order:
 *   1. wp-config.php constants (AC_SPACE_ID, AC_ENVIRONMENT, AC_DELIVERY_TOKEN, AC_PREVIEW_TOKEN,
 *      AC_WEBHOOK_SECRET, AC_SITE_CHANNEL), or their AFR_* names from before 2.0.0
 *   2. the `ac_settings` option (set via `wp option patch` or Settings → Assemble Content)
 *
 * The audience -> WordPress roles map lives here too. Nothing in Contentful knows
 * about WordPress roles, so this map is the whole translation layer.
 */

defined( 'ABSPATH' ) || exit;

class AC_Settings {

	public const OPTION = 'ac_settings';

	/**
	 * Contentful `availableTo` values mapped to WordPress role slugs.
	 *
	 * Ultimate Member on this site currently defines no member roles, so most of
	 * these slugs do not exist yet — they are the names UM would generate for
	 * roles of those titles. Update the option (or the ac_audience_roles filter)
	 * once the real roles exist; no code change needed.
	 */
	public const DEFAULT_AUDIENCE_ROLES = [
		'Public'         => [],
		'Board Member'   => [ 'um_board-member' ],
		'Board Chair'    => [ 'um_board-chair' ],
		'Delegate'       => [ 'um_delegate' ],
		'Network Member' => [ 'um_network-member' ],
		'Council Member' => [ 'um_council-member' ],
		'Council Chair'  => [ 'um_council-chair' ],
	];

	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return [
			'space_id'       => self::resolve( 'AC_SPACE_ID', $stored, 'space_id' ),
			'environment'    => self::resolve( 'AC_ENVIRONMENT', $stored, 'environment' ) ?: 'master',
			'delivery_token' => self::resolve( 'AC_DELIVERY_TOKEN', $stored, 'delivery_token' ),
			'preview_token'  => self::resolve( 'AC_PREVIEW_TOKEN', $stored, 'preview_token' ),
			'webhook_secret' => self::resolve( 'AC_WEBHOOK_SECRET', $stored, 'webhook_secret' ),
			'site_channel'   => self::site_channel(),
		];
	}

	/**
	 * Which site this is: 'Public website' (or 'Assemble Site') or 'Member Center'.
	 * A site only syncs pieces published to it (AC_Placement); with none set it syncs none.
	 */
	public static function site_channel(): string {
		$stored = get_option( self::OPTION, [] );

		return trim( (string) apply_filters( 'ac_site_channel', self::resolve( 'AC_SITE_CHANNEL', is_array( $stored ) ? $stored : [], 'site_channel' ) ) );
	}

	private static function resolve( string $constant, array $stored, string $key ): string {
		$value = AC_Compat::constant( $constant );
		if ( $value !== '' ) {
			return $value;
		}

		return isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
	}

	public static function is_configured(): bool {
		$s = self::all();

		return $s['space_id'] !== '' && $s['delivery_token'] !== '';
	}

	public static function audience_roles(): array {
		$stored = get_option( self::OPTION, [] );
		$map    = isset( $stored['audience_roles'] ) && is_array( $stored['audience_roles'] )
			? $stored['audience_roles']
			: self::DEFAULT_AUDIENCE_ROLES;

		/**
		 * Filter the audience -> role slugs map.
		 *
		 * @param array<string,string[]> $map
		 */
		return (array) apply_filters( 'ac_audience_roles', $map );
	}

	/** Every audience name the content model can emit, in display order. */
	public static function audiences(): array {
		return array_keys( self::DEFAULT_AUDIENCE_ROLES );
	}
}
