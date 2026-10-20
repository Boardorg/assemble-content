<?php
/**
 * Full summit editions from EP's summit API: days, sessions, streams, chairs and
 * speakers, for the summits a site lists in `afr_summit_edition_codes`.
 *
 * GET /{code}/wp-json/ep/v1/summit?v=1, with the stored ETag. Each edition is
 * stored in its own option (not autoloaded) and refreshed with the index.
 *
 * Mapping rules, shared with the Delegate Sales Tool:
 *   - logistics are dropped: `hospitality` slots and `announcement` sessions;
 *   - parts with no title yet (`title_tba`) are skipped;
 *   - one-to-one meeting slots keep their description and lose their parts;
 *   - a part's stream label comes from the edition's streams, by number;
 *   - the members' forum is the day with `members_only`, never its label;
 *   - "to be announced" people keep `tba` and their company, with no name.
 *
 * Part IDs are positional (EP renumbers lunch tables), so anything stored
 * against a session should match by title first.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Summit_Editions {

	public const OPTION_PREFIX = 'afr_summit_edition_';
	public const BASE          = 'https://www.executiveplatforms.com/';

	/**
	 * The summit codes whose full editions this site reads. None by default.
	 *
	 * @return string[]
	 */
	public static function codes(): array {
		/**
		 * Filter the EP summit codes whose full editions are read and stored.
		 *
		 * @param string[] $codes
		 */
		$codes = (array) apply_filters( 'afr_summit_edition_codes', [] );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $codes ) ) ) ) );
	}

	/** The stored edition for a code, or null. */
	public static function get( string $code ): ?array {
		$stored = get_option( self::OPTION_PREFIX . sanitize_key( $code ), [] );

		return is_array( $stored['edition'] ?? null ) ? $stored['edition'] : null;
	}

	/** One speaker from a stored edition, or null. */
	public static function speaker( array $edition, string $speaker_id ): ?array {
		return is_array( $edition['speakers'][ $speaker_id ] ?? null ) ? $edition['speakers'][ $speaker_id ] : null;
	}

	/**
	 * Read one summit's current edition (or a given year) and store it if sound.
	 *
	 * An edition with no days, where the stored copy of the same year has days,
	 * keeps the stored days.
	 *
	 * @return array{ok:bool,stored:bool,unchanged:bool,edition:?array,warnings:string[],error:string}
	 */
	public static function refresh( string $code, bool $dry_run = false, int $year = 0 ): array {
		$result = [ 'ok' => false, 'stored' => false, 'unchanged' => false, 'edition' => null, 'warnings' => [], 'error' => '' ];
		$code   = sanitize_key( $code );
		$option = self::OPTION_PREFIX . $code;
		$stored = get_option( $option, [] );
		$before = is_array( $stored['edition'] ?? null ) ? $stored['edition'] : null;
		$same   = $before && ( 0 === $year || (int) $before['year'] === $year );
		$etag   = $dry_run || ! $same ? '' : (string) ( $stored['etag'] ?? '' );

		if ( '' === $code ) {
			$result['error'] = 'No summit code.';
			return $result;
		}

		$url = self::BASE . $code . '/wp-json/ep/v1/summit?v=1' . ( $year > 0 ? '&edition=' . $year : '' );

		$response = AFR_Summits::request( $url, $etag );

		if ( is_wp_error( $response ) ) {
			$result['error'] = 'Could not reach the EP summit API: ' . $response->get_error_message();
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $status && $before ) {
			$result = array_merge( $result, [ 'ok' => true, 'unchanged' => true, 'edition' => $before ] );
			if ( ! $dry_run ) {
				update_option( $option, array_merge( $stored, [ 'fetched_at' => wp_date( 'Y-m-d H:i' ) ] ), false );
			}
			return $result;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $body ) || ! is_array( $body['edition'] ?? null ) ) {
			$result['error'] = sprintf( 'The EP summit API answered %d without an edition. Keeping the stored copy.', $status );
			return $result;
		}

		$edition = self::map( $body );

		if ( '' === $edition['code'] || ! AFR_Summits::is_date( $edition['start'] ) ) {
			$result['error'] = 'The edition has no code or start date. Keeping the stored copy.';
			return $result;
		}

		if ( ! $edition['days'] && $before && $before['days'] && $before['year'] === $edition['year'] ) {
			$edition['days']      = $before['days'];
			$edition['speakers']  = $before['speakers'];
			$result['warnings'][] = 'EP sent no agenda days; kept the stored agenda.';
		}

		$result['ok']      = true;
		$result['edition'] = $edition;

		if ( ! $dry_run ) {
			update_option(
				$option,
				[
					'fetched_at' => wp_date( 'Y-m-d H:i' ),
					'source'     => $url,
					'etag'       => (string) wp_remote_retrieve_header( $response, 'etag' ),
					'edition'    => $edition,
				],
				false
			);
			$result['stored'] = true;
		}

		return $result;
	}

	/** Forget a stored edition. */
	public static function delete( string $code ): void {
		delete_option( self::OPTION_PREFIX . sanitize_key( $code ) );
	}

	/**
	 * An edition response in the stored shape.
	 *
	 * @param array $body The decoded response: `edition`, `speakers`, `generated_at`.
	 */
	public static function map( array $body ): array {
		$e       = (array) $body['edition'];
		$venue   = is_array( $e['venue'] ?? null ) ? $e['venue'] : [];
		$text    = [ 'AFR_Summits', 'text' ];
		$https   = [ 'AFR_Summits', 'https' ];
		$streams = [];

		foreach ( (array) ( $e['streams'] ?? [] ) as $stream ) {
			$number = (int) ( $stream['number'] ?? 0 );
			if ( $number > 0 ) {
				$streams[ $number ] = [
					'number' => $number,
					'name'   => $text( $stream['name'] ?? '' ),
					'label'  => $text( $stream['label'] ?? '' ),
				];
			}
		}

		$days = [];
		foreach ( (array) ( $e['days'] ?? [] ) as $day ) {
			if ( is_array( $day ) ) {
				$days[] = self::map_day( $day, $streams );
			}
		}

		$speakers = [];
		foreach ( (array) ( $body['speakers'] ?? [] ) as $id => $speaker ) {
			if ( is_array( $speaker ) ) {
				$speakers[ (string) $id ] = self::map_speaker( (string) $id, $speaker );
			}
		}

		$start = (string) ( $e['start_date'] ?? '' );
		$end   = (string) ( $e['end_date'] ?? '' );

		return [
			'code'              => sanitize_key( (string) ( $e['code'] ?? '' ) ),
			'year'              => (int) ( $e['year'] ?? 0 ),
			'title'             => $text( $e['title'] ?? '' ),
			'short_name'        => $text( $e['short_name'] ?? '' ),
			'start'             => $start,
			'end'               => AFR_Summits::is_date( $end ) && $end >= $start ? $end : $start,
			'timezone'          => $text( $e['timezone'] ?? '' ),
			'status'            => $text( $e['status'] ?? '' ),
			'venue'             => $text( $venue['name'] ?? '' ),
			'city'              => $text( $venue['city'] ?? '' ),
			'state'             => $text( $venue['state'] ?? '' ),
			'url'               => $https( $e['url'] ?? '' ),
			'register_url'      => $https( $e['register_url'] ?? '' ),
			'agenda_url'        => ! empty( $e['agenda_available'] ) ? $https( $e['agenda_url'] ?? '' ) : '',
			'speakers_url'      => $https( $e['speakers_url'] ?? '' ),
			'logo_white'        => $https( $e['logo_white']['url'] ?? '' ),
			'logo_reverse'      => $https( $e['logo_reverse']['url'] ?? '' ),
			'logo_color'        => $https( $e['logo_color']['url'] ?? '' ),
			'member_board_logo' => $https( $e['member_board']['logos'][0]['url'] ?? '' ),
			'streams'           => array_values( $streams ),
			'days'              => $days,
			'featured_speakers' => array_values( array_filter( array_map( 'strval', (array) ( $e['featured_speakers'] ?? [] ) ) ) ),
			'speakers'          => $speakers,
			'modified'          => $text( $e['modified'] ?? '' ),
			'generated_at'      => $text( $body['generated_at'] ?? '' ),
		];
	}

	private static function map_day( array $day, array $streams ): array {
		$sessions = [];
		foreach ( (array) ( $day['sessions'] ?? [] ) as $session ) {
			$mapped = is_array( $session ) ? self::map_session( $session, $streams ) : null;
			if ( null !== $mapped ) {
				$sessions[] = $mapped;
			}
		}

		$chairs = [];
		foreach ( (array) ( $day['chairs'] ?? [] ) as $chair ) {
			$chairs[] = [
				'stream'     => (int) ( $chair['stream_number'] ?? 0 ),
				'role'       => AFR_Summits::text( $chair['role'] ?? '' ),
				'label'      => AFR_Summits::text( $chair['label'] ?? '' ),
				'speaker_id' => AFR_Summits::text( $chair['speaker_id'] ?? '' ),
				'tba'        => ! empty( $chair['tba'] ),
			];
		}

		return [
			'id'           => AFR_Summits::text( $day['id'] ?? '' ),
			'label'        => AFR_Summits::text( $day['label'] ?? '' ),
			'date'         => AFR_Summits::text( $day['date'] ?? '' ),
			'members_only' => ! empty( $day['members_only'] ),
			'chairs'       => $chairs,
			'sessions'     => $sessions,
		];
	}

	/** One time slot, or null for logistics and for a slot left with nothing to show. */
	private static function map_session( array $s, array $streams ): ?array {
		$kind = AFR_Summits::text( $s['kind'] ?? '' );
		$type = AFR_Summits::text( $s['type'] ?? '' );

		if ( 'hospitality' === $kind || 'announcement' === $type ) {
			return null;
		}

		$parts = [];
		if ( 'meetings' !== $kind ) {
			foreach ( (array) ( $s['parts'] ?? [] ) as $part ) {
				if ( ! is_array( $part ) || ! empty( $part['title_tba'] ) || '' === AFR_Summits::text( $part['title'] ?? '' ) ) {
					continue;
				}

				$number  = (int) ( $part['stream']['number'] ?? 0 );
				$parts[] = [
					'id'               => AFR_Summits::text( $part['id'] ?? '' ),
					'kind'             => AFR_Summits::text( $part['kind'] ?? '' ),
					'stream'           => $number,
					'stream_label'     => $streams[ $number ]['label'] ?? AFR_Summits::text( $part['stream']['name'] ?? '' ),
					'label'            => AFR_Summits::text( $part['label'] ?? '' ),
					'title'            => AFR_Summits::text( $part['title'] ),
					'description_html' => wp_kses_post( (string) ( $part['description_html'] ?? '' ) ),
					'people'           => self::map_people( (array) ( $part['people'] ?? [] ) ),
				];
			}
		}

		$title = ! empty( $s['title_tba'] ) ? '' : AFR_Summits::text( $s['title'] ?? '' );

		if ( '' === $title && ! $parts && 'meetings' !== $kind ) {
			return null;
		}

		return [
			'id'               => AFR_Summits::text( $s['id'] ?? '' ),
			'kind'             => $kind,
			'type'             => $type,
			'type_label'       => AFR_Summits::text( $s['type_label'] ?? '' ),
			'label'            => AFR_Summits::text( $s['label'] ?? '' ),
			'start'            => AFR_Summits::text( $s['start'] ?? '' ),
			'end'              => AFR_Summits::text( $s['end'] ?? '' ),
			'title'            => $title,
			'description_html' => wp_kses_post( (string) ( $s['description_html'] ?? '' ) ),
			'people'           => self::map_people( (array) ( $s['people'] ?? [] ) ),
			'parts'            => $parts,
		];
	}

	private static function map_people( array $people ): array {
		$out = [];
		foreach ( $people as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}

			$tba   = ! empty( $person['tba'] );
			$out[] = [
				'speaker_id'   => AFR_Summits::text( $person['speaker_id'] ?? '' ),
				'role'         => AFR_Summits::text( $person['role'] ?? '' ),
				'label'        => AFR_Summits::text( $person['label'] ?? '' ),
				'tba'          => $tba,
				'name'         => $tba ? '' : AFR_Summits::text( $person['name'] ?? '' ),
				'job_title'    => $tba ? '' : AFR_Summits::text( $person['job_title'] ?? '' ),
				'organization' => AFR_Summits::text( $person['organization']['name'] ?? '' ),
			];
		}

		return $out;
	}

	private static function map_speaker( string $id, array $s ): array {
		$tba      = ! empty( $s['tba'] );
		$headshot = is_array( $s['headshot'] ?? null ) ? $s['headshot'] : [];

		return [
			'id'                => $id,
			'tba'               => $tba,
			'name'              => $tba ? '' : AFR_Summits::text( $s['name'] ?? '' ),
			'job_title'         => $tba ? '' : AFR_Summits::text( $s['job_title'] ?? '' ),
			'organization'      => AFR_Summits::text( $s['organization']['name'] ?? '' ),
			'organization_logo' => AFR_Summits::https( $s['organization']['logo']['url'] ?? '' ),
			'headshot'          => $tba ? '' : AFR_Summits::https( $headshot['medium']['url'] ?? ( $headshot['url'] ?? '' ) ),
			'bio_html'          => $tba ? '' : wp_kses_post( (string) ( $s['bio_html'] ?? '' ) ),
			'linkedin_url'      => $tba ? '' : AFR_Summits::https( $s['linkedin_url'] ?? '' ),
			'years'             => array_values( array_map( 'intval', (array) ( $s['years'] ?? [] ) ) ),
		];
	}
}
