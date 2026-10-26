<?php
/**
 * Renames the data written before 2.0.0 from `afr` to `ac`, once per site.
 *
 * Runs on `init` priority 0, ahead of everything that reads the data, because
 * VIP loads plugins from code where activation hooks never fire. It must run
 * before the first sync: posts are matched to entries by `_ac_entry_id`, so a
 * sync that found only `_afr_entry_id` would create every post again.
 *
 * Every step is idempotent, an `add_option()` row is the lock (the insert is
 * atomic, so two requests can't both run it), and `ac_db_version` records that
 * it finished. `wp assemble-content migrate` runs it by hand.
 *
 *   - options: copied to the new name when that is still empty, then deleted;
 *   - post meta and the `afr_place` taxonomy: renamed in place with one UPDATE
 *     each (WordPress has no API that renames a meta key or a taxonomy), then
 *     the affected caches are cleared;
 *   - the old summits cron event: unscheduled (AC_Summits schedules its own).
 */

defined( 'ABSPATH' ) || exit;

class AC_Migration {

	public const VERSION        = 1;
	public const VERSION_OPTION = 'ac_db_version';
	public const LOCK_OPTION    = 'ac_migration_lock';

	/** A lock older than this belongs to a request that died. */
	private const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

	public const OPTIONS = [
		'afr_settings'                => 'ac_settings',
		'afr_last_sync'               => 'ac_last_sync',
		'afr_bypass_mode'             => 'ac_bypass_mode',
		'afr_placeholder_image_id'    => 'ac_placeholder_image_id',
		'afr_communities'             => 'ac_communities',
		'afr_site_features'           => 'ac_site_features',
		'afr_site_features_synced_at' => 'ac_site_features_synced_at',
		'afr_summits'                 => 'ac_summits',
		'afr_summits_status'          => 'ac_summits_status',
	];

	public const META_KEYS = [
		'_afr_entry_id'        => '_ac_entry_id',
		'_afr_revision'        => '_ac_revision',
		'_afr_updated_at'      => '_ac_updated_at',
		'_afr_data'            => '_ac_data',
		'_afr_available_to'    => '_ac_available_to',
		'_afr_source_line'     => '_ac_source_line',
		'_afr_pullquote'       => '_ac_pullquote',
		'_afr_takeaways_short' => '_ac_takeaways_short',
		'_afr_synced_at'       => '_ac_synced_at',
		'_afr_featured'        => '_ac_featured',
		'_afr_featured_rank'   => '_ac_featured_rank',
		'_afr_topic'           => '_ac_topic',
		'_afr_practice_area'   => '_ac_practice_area',
		'_afr_drafted_reason'  => '_ac_drafted_reason',
	];

	public const TAXONOMIES = [
		'afr_place' => 'ac_place',
	];

	public const CRON_HOOKS = [ 'afr_summits_refresh' ];

	public static function init(): void {
		add_action( 'init', [ self::class, 'maybe_run' ], 0 );
	}

	public static function is_done(): bool {
		return (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION;
	}

	public static function maybe_run(): void {
		if ( ! self::is_done() ) {
			self::run();
		}
	}

	/**
	 * Run every step, unless another request is running them.
	 *
	 * @return array{ran:bool,messages:string[]}
	 */
	public static function run(): array {
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			if ( (int) get_option( self::LOCK_OPTION, 0 ) > time() - self::LOCK_TTL ) {
				return [ 'ran' => false, 'messages' => [ 'another request is migrating' ] ];
			}

			update_option( self::LOCK_OPTION, time(), false );
		}

		$messages = array_merge(
			self::migrate_options(),
			self::migrate_meta(),
			self::migrate_taxonomies()
		);

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );
		delete_option( self::LOCK_OPTION );

		return [ 'ran' => true, 'messages' => $messages ];
	}

	/** @return string[] */
	private static function migrate_options(): array {
		$messages = [];
		$options  = self::OPTIONS;

		// Each stored summit edition has its own option.
		$summits = (array) get_option( 'afr_summits', get_option( 'ac_summits', [] ) );
		$codes   = array_merge(
			array_column( (array) ( $summits['summits'] ?? [] ), 'code' ),
			(array) apply_filters( 'ac_summit_edition_codes', [] )
		);
		foreach ( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $codes ) ) ) ) as $code ) {
			$options[ 'afr_summit_edition_' . $code ] = 'ac_summit_edition_' . $code;
		}

		foreach ( $options as $old => $new ) {
			$value = get_option( $old, null );
			if ( null === $value ) {
				continue;
			}

			if ( null === get_option( $new, null ) ) {
				update_option( $new, $value, 'ac_settings' === $new || 'ac_bypass_mode' === $new );
				$messages[] = sprintf( 'option %s → %s', $old, $new );
			}

			delete_option( $old );
		}

		return $messages;
	}

	/** @return string[] */
	private static function migrate_meta(): array {
		global $wpdb;

		$old_keys     = array_keys( self::META_KEYS );
		$placeholders = implode( ',', array_fill( 0, count( $old_keys ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- a one-time rename; no API renames a meta key. Placeholders are built above.
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)", $old_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $post_ids ) {
			return [];
		}

		$rows = 0;
		foreach ( self::META_KEYS as $old => $new ) {
			$rows += (int) $wpdb->update( $wpdb->postmeta, [ 'meta_key' => $new ], [ 'meta_key' => $old ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		}
		// phpcs:enable

		foreach ( $post_ids as $post_id ) {
			wp_cache_delete( (int) $post_id, 'post_meta' );
		}
		wp_cache_set_posts_last_changed();

		return [ sprintf( 'post meta: %d rows on %d posts renamed', $rows, count( $post_ids ) ) ];
	}

	/** @return string[] */
	private static function migrate_taxonomies(): array {
		global $wpdb;

		$messages = [];

		foreach ( self::TAXONOMIES as $old => $new ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a one-time rename; no API renames a taxonomy.
			$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $old ) );

			if ( ! $term_ids ) {
				continue;
			}

			$wpdb->update( $wpdb->term_taxonomy, [ 'taxonomy' => $new ], [ 'taxonomy' => $old ] );
			// phpcs:enable

			clean_term_cache( array_map( 'intval', $term_ids ), $new );
			$messages[] = sprintf( 'taxonomy %s → %s: %d terms', $old, $new, count( $term_ids ) );
		}

		return $messages;
	}
}
