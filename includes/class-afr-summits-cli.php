<?php
/**
 * WP-CLI: wp assemble-content summits <command>
 */

defined( 'ABSPATH' ) || exit;

/**
 * Summits read from Executive Platforms' summit API.
 */
class AFR_Summits_CLI {

	/**
	 * Re-read the summits index, and the editions this site reads, from EP's summit API.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be stored without storing it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content summits refresh
	 *     wp assemble-content summits refresh --dry-run
	 *
	 * @param array $args  Positional arguments (none).
	 * @param array $assoc Flags.
	 * @return void
	 */
	public function refresh( array $args, array $assoc ): void {
		$result = AFR_Summits::refresh_and_record( isset( $assoc['dry-run'] ) );

		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['error'] );
		}

		self::table( $result['summits'] );

		foreach ( $result['editions'] as $code => $edition ) {
			if ( $edition['ok'] ) {
				WP_CLI::line( sprintf( 'Edition %s: %s.', $code, $edition['unchanged'] ? 'unchanged (304)' : self::edition_summary( $edition['edition'] ) ) );
			}
		}

		if ( $result['unchanged'] ) {
			WP_CLI::success( sprintf( 'Index unchanged since the last refresh (304); %d summits kept.', count( $result['summits'] ) ) );
			return;
		}

		WP_CLI::success( sprintf( '%d summits %s.', count( $result['summits'] ), $result['stored'] ? 'stored' : 'read (dry run, nothing stored)' ) );
	}

	/**
	 * List the stored summits.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include summits that have ended.
	 *
	 * @param array $args  Positional arguments (none).
	 * @param array $assoc Flags.
	 * @return void
	 */
	public function list( array $args, array $assoc ): void {
		self::table( AFR_Summits::with_extras( ! isset( $assoc['all'] ) ) );
		WP_CLI::line( 'Last refreshed: ' . ( AFR_Summits::refreshed_at() ?: 'never' ) );
		WP_CLI::line( 'Next scheduled refresh: ' . ( wp_next_scheduled( AFR_Summits::CRON ) ? wp_date( 'Y-m-d H:i', (int) wp_next_scheduled( AFR_Summits::CRON ) ) : 'none' ) );
		WP_CLI::line( 'Editions read on this site: ' . ( implode( ', ', AFR_Summit_Editions::codes() ) ?: 'none' ) );

		$problem = AFR_Summits::problem();
		if ( '' !== $problem ) {
			WP_CLI::warning( $problem );
		}
	}

	/**
	 * Read one summit's full edition (agenda, streams, speakers).
	 *
	 * Stores it unless --dry-run. Codes not in `afr_summit_edition_codes` aren't
	 * refreshed hourly, so delete their copy when done.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : EP summit code, e.g. nales.
	 *
	 * [--year=<year>]
	 * : A past or other edition.
	 *
	 * [--dry-run]
	 * : Show what would be stored without storing it.
	 *
	 * [--delete]
	 * : Remove the stored copy instead.
	 *
	 * [--format=<format>]
	 * : `summary` (default), `sessions` or `json`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp assemble-content summits edition nales --dry-run
	 *     wp assemble-content summits edition nales --format=sessions
	 *
	 * @param array $args  Positional arguments: the summit code.
	 * @param array $assoc Flags.
	 * @return void
	 */
	public function edition( array $args, array $assoc ): void {
		$code = (string) $args[0];

		if ( isset( $assoc['delete'] ) ) {
			AFR_Summit_Editions::delete( $code );
			WP_CLI::success( "Deleted the stored edition for $code." );
			return;
		}

		$result = AFR_Summit_Editions::refresh( $code, isset( $assoc['dry-run'] ), (int) ( $assoc['year'] ?? 0 ) );

		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}

		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['error'] );
		}

		$edition = $result['edition'];
		$format  = (string) ( $assoc['format'] ?? 'summary' );

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $edition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( 'sessions' === $format ) {
			$rows = [];
			foreach ( $edition['days'] as $day ) {
				foreach ( $day['sessions'] as $session ) {
					foreach ( $session['parts'] ?: [ $session ] as $item ) {
						$rows[] = [
							'day'    => $day['label'] . ( $day['members_only'] ? ' (members)' : '' ),
							'time'   => trim( $session['start'] . '–' . $session['end'], '–' ),
							'kind'   => $session['kind'],
							'stream' => $item['stream_label'] ?? '',
							'title'  => $item['title'] ?: $session['label'],
							'people' => count( $item['people'] ),
						];
					}
				}
			}
			WP_CLI\Utils\format_items( 'table', $rows, [ 'day', 'time', 'kind', 'stream', 'title', 'people' ] );
		}

		WP_CLI::success( sprintf( '%s %d: %s%s.', $code, $edition['year'], self::edition_summary( $edition ), $result['stored'] ? ', stored' : ( $result['unchanged'] ? ', unchanged (304)' : ', dry run' ) ) );
	}

	/**
	 * A one-line count of an edition's days, sessions and speakers.
	 *
	 * @param array $edition A stored edition.
	 * @return string
	 */
	private static function edition_summary( array $edition ): string {
		$sessions = 0;
		foreach ( $edition['days'] as $day ) {
			foreach ( $day['sessions'] as $session ) {
				$sessions += max( 1, count( $session['parts'] ) );
			}
		}

		return sprintf( '%d days, %d sessions, %d speakers', count( $edition['days'] ), $sessions, count( $edition['speakers'] ) );
	}

	/**
	 * Print summits as a table.
	 *
	 * @param array $summits Stored summits, with or without extras.
	 * @return void
	 */
	private static function table( array $summits ): void {
		WP_CLI\Utils\format_items(
			'table',
			array_map(
				static fn( $s ) => [
					'code'   => $s['code'],
					'start'  => $s['start'],
					'end'    => $s['end'],
					'city'   => trim( $s['city'] . ', ' . $s['state'], ', ' ),
					'venue'  => $s['venue'],
					'season' => $s['season'],
					'agenda' => '' !== ( $s['agenda_url'] ?? '' ) ? 'yes' : 'no',
					'area'   => $s['area'] ?? '',
					'title'  => $s['title'],
				],
				$summits
			),
			[ 'code', 'start', 'end', 'city', 'venue', 'season', 'agenda', 'area', 'title' ]
		);
	}
}
