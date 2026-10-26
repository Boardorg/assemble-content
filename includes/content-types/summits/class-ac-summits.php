<?php
/**
 * Summits, read from Executive Platforms' summit API.
 *
 * EP stays the source of truth for summit facts. This copies EP's public summit
 * index into an option, hourly and on demand (`wp assemble-content summits
 * refresh`), and never writes to EP. Shared by every site that runs this plugin.
 *
 * One request through the WP-JSON source: GET /wp-json/ep/v1/summits?v=1, sent
 * with the stored ETag, so an unchanged index comes back as a 304 with no body.
 *
 * A refresh that looks wrong keeps the last good copy: an unreachable API, a
 * body that isn't the expected JSON, or a list that is empty or under half as
 * long as before replaces nothing. A summit with no current edition or no start
 * date is left out with a warning.
 *
 * Full editions (agenda, streams, speakers) are read by AC_Summit_Editions, for
 * the codes a site asks for. Assemble's own fields per summit come from
 * AC_Summit_Extras. Neither ever overrides an EP fact.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The EP summit index: refresh, storage, links and the admin notice.
 */
class AC_Summits extends AC_Option_Content {

	public const OPTION        = 'ac_summits';
	public const STATUS_OPTION = 'ac_summits_status';
	public const CRON          = 'ac_summits_refresh';
	public const API           = 'https://www.executiveplatforms.com/wp-json/ep/v1/summits?v=1';

	/**
	 * Summits have their own hourly schedule, so a full Contentful sync leaves them out.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'label'     => 'Summits',
				'singular'  => 'Summit',
				'source'    => 'wp-json',
				'full_sync' => false,
			]
		);
	}

	/**
	 * Register the cron, schedule and admin notice hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::CRON, [ $this, 'scheduled_refresh' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
		add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
	}

	// ----------------------------------------------------------------- reading

	/**
	 * Summits from the last good refresh, soonest first.
	 *
	 * @param bool $upcoming_only Leave out summits whose end date has passed.
	 * @return array<int,array>
	 */
	public static function all( bool $upcoming_only = true ): array {
		$summits = self::stored();
		$today   = wp_date( 'Y-m-d' );

		if ( $upcoming_only ) {
			$summits = array_values( array_filter( $summits, static fn( $s ) => ( $s['end'] ?: $s['start'] ) >= $today ) );
		}

		usort( $summits, static fn( $a, $b ) => strcmp( $a['start'], $b['start'] ) );

		/**
		 * Filter the summits list (e.g. to hide one, or to correct a value EP shows wrongly).
		 *
		 * @param array $summits
		 */
		return (array) apply_filters( 'ac_summits', $summits );
	}

	/**
	 * Summits with Assemble's extras merged in. EP's facts win: an extra never
	 * replaces a key the importer filled.
	 *
	 * @param bool $upcoming_only Leave out summits whose end date has passed.
	 * @return array<int,array>
	 */
	public static function with_extras( bool $upcoming_only = true ): array {
		$extras = AC_Summit_Extras::all();

		return array_map( static fn( array $summit ): array => AC_Summit_Extras::merge( $summit, $extras ), self::all( $upcoming_only ) );
	}

	/**
	 * One summit by EP code, with extras, including past ones.
	 *
	 * @param string $code EP summit code.
	 * @return array|null Null if unknown.
	 */
	public static function get( string $code ): ?array {
		foreach ( self::with_extras( false ) as $summit ) {
			if ( $summit['code'] === $code ) {
				return $summit;
			}
		}

		return null;
	}

	/**
	 * Where a summit link goes: its executiveplatforms.com page, EP's registration
	 * page or its agenda. Summit pages on Assemble change only this.
	 *
	 * @param array  $summit A stored summit.
	 * @param string $which  'details', 'register' or 'agenda'.
	 * @return string
	 */
	public static function url( array $summit, string $which = 'details' ): string {
		$url = (string) ( $summit['url'] ?? '' );

		if ( 'register' === $which && '' !== (string) ( $summit['register_url'] ?? '' ) ) {
			$url = (string) $summit['register_url'];
		} elseif ( 'agenda' === $which && '' !== (string) ( $summit['agenda_url'] ?? '' ) ) {
			$url = (string) $summit['agenda_url'];
		}

		/**
		 * Filter a summit link.
		 *
		 * @param string $url
		 * @param array  $summit
		 * @param string $which
		 */
		return (string) apply_filters( 'ac_summit_url', $url, $summit, $which );
	}

	/**
	 * The stored summits, unsorted, as the last good refresh left them.
	 *
	 * @return array<int,array>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored['summits'] ?? null ) ? $stored['summits'] : [];
	}

	/**
	 * When the summits were last refreshed.
	 *
	 * @return string Site timezone, 'Y-m-d H:i', or '' if never.
	 */
	public static function refreshed_at(): string {
		$stored = get_option( self::OPTION, [] );

		return (string) ( $stored['fetched_at'] ?? '' );
	}

	/**
	 * Same as refreshed_at().
	 *
	 * @return string
	 */
	public static function synced_at(): string {
		return self::refreshed_at();
	}

	// ----------------------------------------------------------------- refresh

	/**
	 * Refresh the index, then the editions the site reads, and record the outcome
	 * for the admin notice. A dry run fetches and maps but stores and records nothing.
	 *
	 * @param bool $dry_run Fetch and map, store nothing (and skip the ETag, so the full list is shown).
	 * @return array{ok:bool,stored:bool,unchanged:bool,count:int,items:array,warnings:string[],error:string,editions:array}
	 */
	public static function refresh( bool $dry_run = false ): array {
		$type = AC_Types::type( 'summits' );

		if ( ! $type instanceof self ) {
			return [ 'ok' => false, 'stored' => false, 'unchanged' => false, 'count' => 0, 'items' => [], 'warnings' => [], 'error' => 'Summits are not synced on this site.', 'editions' => [] ];
		}

		return $type->sync( false, $dry_run );
	}

	/**
	 * The index, then each edition the site reads. Recorded once both are done, so
	 * the status carries the editions' warnings too.
	 *
	 * @param bool $force   No effect; every refresh is conditional on the ETag.
	 * @param bool $dry_run Fetch and map, store nothing.
	 * @return array Same shape as AC_Option_Content::sync(), plus `editions` (code => edition result).
	 */
	public function sync( bool $force = false, bool $dry_run = false ): array {
		$result             = parent::sync( $force, $dry_run );
		$result['editions'] = [];

		foreach ( AC_Summit_Editions::codes() as $code ) {
			$edition                     = AC_Summit_Editions::refresh( $code, $dry_run );
			$result['editions'][ $code ] = $edition;

			foreach ( $edition['warnings'] as $warning ) {
				$result['warnings'][] = $code . ': ' . $warning;
			}
			if ( ! $edition['ok'] ) {
				$result['warnings'][] = $code . ': ' . $edition['error'];
			}
		}

		if ( ! $dry_run ) {
			parent::record( $result );
		}

		return $result;
	}

	/**
	 * Recorded by sync() once the editions are done.
	 *
	 * @param array $result The index result.
	 * @return void
	 */
	protected function record( array $result ): void {}

	/**
	 * The index, conditional on the stored ETag unless this is a dry run.
	 *
	 * @param bool $dry_run Skip the ETag, so the full list comes back.
	 * @return array
	 */
	protected function fetch( bool $dry_run ): array {
		$stored = get_option( self::OPTION, [] );
		$fetch  = $this->source()->fetch_all(
			[
				'url'      => self::API,
				'etag'     => $dry_run ? '' : (string) ( $stored['etag'] ?? '' ),
				'list_key' => 'summits',
			]
		);

		if ( $fetch['error'] !== '' ) {
			$status         = (int) ( $fetch['meta']['status'] ?? 0 );
			$fetch['error'] = 0 === $status
				? 'Could not reach the EP summit API: ' . $fetch['error']
				: sprintf( 'The EP summit API answered %d without a summits list. Keeping the stored copy.', $status );
		}

		return $fetch;
	}

	/**
	 * One summit from the index, or null (with a warning) when it has no usable current edition.
	 *
	 * @param array    $raw      One entry of the index's `summits` list.
	 * @param string[] $warnings Warnings so far.
	 * @return array|null
	 */
	protected function map_item( array $raw, array &$warnings ): ?array {
		$summit = self::from_api( $raw );

		if ( null === $summit ) {
			$warnings[] = sprintf( '%s: no current edition with a start date; left out.', (string) ( $raw['code'] ?? '?' ) );
		}

		return $summit;
	}

	/**
	 * Refuse an empty list, or one under half as long as before.
	 *
	 * @param array $items  The mapped summits.
	 * @param array $before The stored summits.
	 * @param array $fetch  The fetch result.
	 * @return string
	 */
	protected function validate( array $items, array $before, array $fetch ): string {
		if ( $fetch['error'] !== '' ) {
			return $fetch['error'];
		}

		// A 304 reaches here only when there is no stored copy to keep.
		if ( $fetch['not_modified'] ) {
			return 'The EP summit API answered 304 without a summits list. Keeping the stored copy.';
		}

		if ( ! $items || count( $items ) < count( $before ) / 2 ) {
			return sprintf( 'The EP summit API returned %d usable summits (previously %d). Keeping the stored copy.', count( $items ), count( $before ) );
		}

		return '';
	}

	/**
	 * Replace the stored index.
	 *
	 * @param array $items The mapped summits.
	 * @param array $fetch The fetch result.
	 * @return void
	 */
	protected function store( array $items, array $fetch ): void {
		$body = is_array( $fetch['meta']['body'] ?? null ) ? $fetch['meta']['body'] : [];

		update_option(
			self::OPTION,
			[
				'fetched_at'   => wp_date( 'Y-m-d H:i' ),
				'source'       => self::API,
				'generated_at' => (string) ( $body['generated_at'] ?? '' ),
				'etag'         => (string) ( $fetch['meta']['etag'] ?? '' ),
				'summits'      => array_values( $items ),
			],
			false
		);
	}

	/**
	 * Unchanged since the last refresh: note that it was checked.
	 *
	 * @return void
	 */
	protected function touch(): void {
		update_option( self::OPTION, array_merge( (array) get_option( self::OPTION, [] ), [ 'fetched_at' => wp_date( 'Y-m-d H:i' ) ] ), false );
	}

	/**
	 * The summit's display name. EP's `title` and `site_name` each name the edition
	 * for only some summits, so take whichever ends in "… Edition", else `title`.
	 * The dash before "Edition" is set as an en dash, as on EP's own pages.
	 *
	 * @param string $title     EP's `title`.
	 * @param string $site_name EP's `site_name`.
	 * @return string
	 */
	public static function title( string $title, string $site_name ): string {
		$edition = '/\s+[-–—]\s+((?:Spring|Fall)\s+Edition)$/u';
		$name    = preg_match( $edition, $site_name ) ? $site_name : ( '' !== $title ? $title : $site_name );

		return (string) preg_replace( $edition, ' – $1', $name );
	}

	/**
	 * One summit from the API index, in the stored shape. Missing values become ''
	 * rather than null.
	 *
	 * `logo_url` is EP's white wordmark, for dark backgrounds. `date_text` and
	 * `stats` stay empty: the API has no stats counters.
	 *
	 * @param array $item One entry of the index's `summits` list.
	 * @return array|null Null when it has no usable current edition.
	 */
	public static function from_api( array $item ): ?array {
		$edition = is_array( $item['current_edition'] ?? null ) ? $item['current_edition'] : [];
		$venue   = is_array( $edition['venue'] ?? null ) ? $edition['venue'] : [];
		$code    = sanitize_key( (string) ( $item['code'] ?? '' ) );
		$start   = (string) ( $edition['start_date'] ?? '' );
		$end     = (string) ( $edition['end_date'] ?? '' );

		if ( '' === $code || ! self::is_date( $start ) ) {
			return null;
		}

		return [
			'code'         => $code,
			'short_name'   => self::text( $item['short_name'] ?? '' ),
			'title'        => self::title( self::text( $item['title'] ?? '' ), self::text( $item['site_name'] ?? '' ) ),
			'season'       => in_array( $item['season'] ?? '', [ 'spring', 'fall' ], true ) ? $item['season'] : '',
			'url'          => self::https( $item['url'] ?? '' ),
			'register_url' => self::https( $edition['register_url'] ?? '' ),
			'agenda_url'   => ! empty( $edition['agenda_available'] ) ? self::https( $edition['agenda_url'] ?? '' ) : '',
			'api_url'      => self::https( $item['api_url'] ?? '' ),
			'logo_url'     => self::https( $item['logo_white']['url'] ?? '' ),
			'city'         => self::text( $venue['city'] ?? '' ),
			'state'        => self::text( $venue['state'] ?? '' ),
			'venue'        => self::text( $venue['name'] ?? '' ),
			'start'        => $start,
			'end'          => self::is_date( $end ) && $end >= $start ? $end : $start,
			'timezone'     => self::text( $edition['timezone'] ?? '' ),
			'status'       => self::text( $edition['status'] ?? '' ),
			'year'         => (int) ( $edition['year'] ?? 0 ),
			'modified'     => self::text( $item['modified'] ?? '' ),
			'date_text'    => '',
			'stats'        => [],
		];
	}

	/**
	 * The hourly run.
	 *
	 * @return void
	 */
	public function scheduled_refresh(): void {
		$this->sync();
	}

	/**
	 * Schedule the refresh hourly, approximately: WP-Cron runs on site traffic.
	 * Cheap, because an unchanged index or edition is a 304 with no body.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON ) && 'hourly' !== wp_get_schedule( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	// ------------------------------------------------------------------ status

	/**
	 * Why the stored summits may be stale: the last refresh failed, or nothing
	 * has refreshed for three days.
	 *
	 * @return string The reason, or '' when all is well.
	 */
	public static function problem(): string {
		$status = self::last_status();
		$last   = self::refreshed_at();

		if ( isset( $status['ok'] ) && ! $status['ok'] ) {
			return sprintf(
				/* translators: 1: date and time, 2: error message, 3: date and time of the last good copy. */
				__( 'The summits refresh from the EP summit API failed on %1$s: %2$s The site is showing the copy from %3$s.', 'assemble-content' ),
				$status['at'] ?? '',
				rtrim( (string) ( $status['error'] ?? '' ), '.' ) . '.',
				'' !== $last ? $last : __( 'never', 'assemble-content' )
			);
		}

		if ( '' !== $last && strtotime( $last ) < strtotime( wp_date( 'Y-m-d H:i' ) ) - 3 * DAY_IN_SECONDS ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Summits were last refreshed from the EP summit API on %s. Check that WP-Cron is running.', 'assemble-content' ), $last );
		}

		return '';
	}

	/**
	 * Show administrators a warning when the stored summits may be stale.
	 *
	 * @return void
	 */
	public static function admin_notice(): void {
		$problem = current_user_can( 'manage_options' ) ? self::problem() : '';

		if ( '' !== $problem ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s %3$s</p></div>',
				esc_html__( 'Assemble summits:', 'assemble-content' ),
				esc_html( $problem ),
				esc_html__( 'To retry: wp assemble-content summits refresh', 'assemble-content' )
			);
		}
	}

	public function summary(): string {
		$problem = self::problem();

		return sprintf( '%d stored, last refreshed %s', count( self::stored() ), self::refreshed_at() ?: 'never' ) . ( '' !== $problem ? ' — ' . $problem : '' );
	}

	// ----------------------------------------------------------------- helpers

	/**
	 * Whether a string is a 'Y-m-d' date.
	 *
	 * @param string $date The value to check.
	 * @return bool
	 */
	public static function is_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date );
	}

	/**
	 * A trimmed string.
	 *
	 * @param mixed $value Any value from the API.
	 * @return string '' for null and non-scalars.
	 */
	public static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * An https:// URL.
	 *
	 * @param mixed $value Any value from the API.
	 * @return string '' unless it starts with https://.
	 */
	public static function https( $value ): string {
		return is_string( $value ) && str_starts_with( $value, 'https://' ) ? $value : '';
	}
}
