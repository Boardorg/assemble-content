<?php
/**
 * Summits engine tests, offline: a fake EP summit API answers in the shape of
 * the live one (EP-SUMMITS-API-SPEC.md in the hub). Checks the index mapping (nulls,
 * dates, URLs), the ETag round trip, that a bad answer never replaces the stored
 * copy, the Assemble-only extras, the admin notice, and the full-edition reader
 * (logistics dropped, untitled parts skipped, TBA people, streams, chairs).
 *
 * Local only. Restores the stored summits and editions afterwards.
 *   npx @wordpress/env run cli wp eval-file wp-content/plugins/assemble-content/tests/summits-test.php
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	echo "Run this with WP-CLI on a local site only.\n";
	return;
}

$GLOBALS['sm_t'] = [ 'pass' => 0, 'fail' => 0, 'answer' => null, 'edition' => null, 'sent' => [], 'url' => '' ];

function sm_check( bool $ok, string $label ): void {
	$GLOBALS['sm_t'][ $ok ? 'pass' : 'fail' ]++;
	if ( ! $ok ) {
		WP_CLI::warning( 'FAIL: ' . $label );
	}
}

/** One index entry, as the live API sends it. $edition null means no current edition. */
function sm_summit( string $code, ?array $edition, ?string $season = null ): array {
	return [
		'id'              => $code,
		'code'            => $code,
		'short_name'      => strtoupper( $code ),
		'title'           => 'Summit ' . strtoupper( $code ) . ' - Fall Edition',
		'season'          => $season,
		'url'             => "https://www.executiveplatforms.com/$code/",
		'api_url'         => "https://www.executiveplatforms.com/$code/wp-json/ep/v1/summit?v=1",
		'logo_white'      => [ 'url' => "https://www.executiveplatforms.com/wp-content/uploads/$code-white.png", 'width' => 100, 'height' => 20 ],
		'current_edition' => $edition,
		'editions'        => [],
		'modified'        => '2026-10-14T13:23:20Z',
		'stale'           => false,
	];
}

function sm_edition( string $code, string $start, ?string $end, array $more = [] ): array {
	return array_merge(
		[
			'id'               => "$code-2027",
			'year'             => 2027,
			'start_date'       => $start,
			'end_date'         => $end,
			'status'           => 'upcoming',
			'timezone'         => 'America/Chicago',
			'venue'            => [ 'name' => 'Grand Hotel', 'city' => 'Austin', 'state' => 'TX' ],
			'agenda_available' => true,
			'agenda_url'       => "https://www.executiveplatforms.com/$code/event/2027-agenda/",
			'register_url'     => "https://www.executiveplatforms.com/$code/register/",
		],
		$more
	);
}

/** Make the fake API answer with $code and $body (an array is JSON-encoded), or fail to connect when $code is 0. */
function sm_answer( int $code, $body = '', string $etag = 'W/"v1"', string $slot = 'answer' ): void {
	$GLOBALS['sm_t'][ $slot ] = [ $code, is_array( $body ) ? wp_json_encode( $body ) : (string) $body, $etag ];
}

/** One person on a session, as the live API sends it. */
function sm_person( string $id, string $role, bool $tba = false ): array {
	return [ 'speaker_id' => $id, 'role' => $role, 'label' => null, 'tba' => $tba, 'hidden' => [], 'name' => $tba ? null : 'Pat Lee', 'job_title' => $tba ? null : 'CLO', 'organization' => [ 'name' => 'Acme' ] ];
}

/** A full edition response for code aaa, in the live shape. */
function sm_full_edition( array $days ): array {
	return [
		'generated_at' => '2026-10-20T10:00:00Z',
		'edition'      => [
			'code'              => 'aaa',
			'year'              => 2027,
			'title'             => 'Summit AAA',
			'short_name'        => 'AAA',
			'start_date'        => '2027-01-19',
			'end_date'          => '2027-01-21',
			'timezone'          => 'America/Los_Angeles',
			'status'            => 'upcoming',
			'agenda_available'  => true,
			'venue'             => [ 'name' => 'Hilton', 'city' => 'San Diego', 'state' => 'CA', 'address' => null ],
			'url'               => 'https://www.executiveplatforms.com/aaa/',
			'register_url'      => 'https://www.executiveplatforms.com/aaa/register/',
			'agenda_url'        => 'https://www.executiveplatforms.com/aaa/event/2027-agenda/',
			'logo_color'        => [ 'url' => 'https://www.executiveplatforms.com/aaa-color.png' ],
			'member_board'      => [ 'name' => null, 'logos' => [ [ 'url' => 'https://www.executiveplatforms.com/board.png' ] ] ],
			'streams'           => [
				[ 'number' => 1, 'name' => 'Skills', 'label' => 'Stream One – Skills' ],
				[ 'number' => 2, 'name' => null, 'label' => 'Stream Two – Leaders' ],
			],
			'days'              => $days,
			'featured_speakers' => [ 'aaa:1' ],
			'modified'          => '2026-10-19T12:00:00Z',
		],
		'speakers'     => [
			'aaa:1' => [ 'id' => 'aaa:1', 'name' => 'Pat Lee', 'tba' => false, 'job_title' => 'CLO', 'organization' => [ 'name' => 'Acme', 'logo' => [ 'url' => 'https://x.example/acme.png' ] ], 'headshot' => [ 'url' => 'https://x.example/pat.png', 'medium' => [ 'url' => 'https://x.example/pat-300.png' ] ], 'bio_html' => '<p>Bio<script>x</script></p>', 'linkedin_url' => 'https://www.linkedin.com/in/pat', 'years' => [ 2026, 2027 ] ],
			'aaa:2' => [ 'id' => 'aaa:2', 'name' => 'Hidden Name', 'tba' => true, 'job_title' => 'VP', 'organization' => [ 'name' => 'Globex' ], 'headshot' => null, 'bio_html' => '<p>secret</p>', 'linkedin_url' => null, 'years' => [ 2027 ] ],
		],
	];
}

$sm_index = [
	'generated_at' => '2026-10-15T19:42:11Z',
	'summits'      => [
		sm_summit( 'aaa', sm_edition( 'aaa', '2026-11-16', '2026-11-18' ), 'fall' ),
		sm_summit( 'bbb', sm_edition( 'bbb', '2026-12-30', null, [ 'venue' => null, 'agenda_available' => false, 'timezone' => null ] ) ),
		sm_summit( 'ccc', sm_edition( 'ccc', '2027-01-19', '2027-01-21', [ 'register_url' => 'http://insecure.example/' ] ) ),
		sm_summit( 'ddd', null ),
	],
];

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$is_edition = str_starts_with( $url, AFR_Summit_Editions::BASE ) && str_contains( $url, '/wp-json/ep/v1/summit?v=1' );

		if ( AFR_Summits::API !== $url && ! $is_edition ) {
			return $pre;
		}

		$slot                      = $is_edition ? 'edition' : 'answer';
		$GLOBALS['sm_t']['sent']   = (array) ( $args['headers'] ?? [] );
		$GLOBALS['sm_t']['url']    = $url;
		[ $code, $body, $etag ]    = $GLOBALS['sm_t'][ $slot ];

		if ( 0 === $code ) {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		}

		$matches = 200 === $code && $etag === ( $GLOBALS['sm_t']['sent']['If-None-Match'] ?? '' );

		return [
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( [ 'etag' => $etag ] ),
			'body'     => $matches ? '' : $body,
			'response' => [ 'code' => $matches ? 304 : $code, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

$sm_saved        = get_option( AFR_Summits::OPTION, null );
$sm_saved_status = get_option( AFR_Summits::STATUS_OPTION, null );

try {
	// 1. Mapping one entry into the stored shape.
	$m = AFR_Summits::from_api( sm_summit( 'aaa', sm_edition( 'aaa', '2026-11-16', '2026-11-18' ), 'fall' ) );
	sm_check( 'aaa' === $m['code'] && 'fall' === $m['season'] && '2026-11-16' === $m['start'] && '2026-11-18' === $m['end'], 'map: code, season, dates' );
	sm_check( 'Austin' === $m['city'] && 'TX' === $m['state'] && 'Grand Hotel' === $m['venue'], 'map: venue' );
	sm_check( 'https://www.executiveplatforms.com/aaa/register/' === $m['register_url'] && 'https://www.executiveplatforms.com/aaa/' === $m['url'], 'map: links' );
	sm_check( 'America/Chicago' === $m['timezone'] && 'upcoming' === $m['status'] && 2027 === $m['year'] && str_ends_with( $m['agenda_url'], '/2027-agenda/' ), 'map: timezone, status, year, agenda' );
	sm_check( str_ends_with( $m['logo_url'], 'aaa-white.png' ) && '' === $m['date_text'] && [] === $m['stats'], 'map: white logo; no date text or stats' );

	$m = AFR_Summits::from_api( sm_summit( 'bbb', sm_edition( 'bbb', '2026-12-30', null, [ 'venue' => null, 'agenda_available' => false, 'timezone' => null ] ) ) );
	sm_check( '' === $m['season'] && '2026-12-30' === $m['end'], 'map: null season is empty; null end is the start' );
	sm_check( '' === $m['city'] && '' === $m['venue'] && '' === $m['timezone'] && '' === $m['agenda_url'], 'map: null venue and timezone are empty; no agenda link before one exists' );
	sm_check( '' === AFR_Summits::from_api( sm_summit( 'ccc', sm_edition( 'ccc', '2027-01-19', '2027-01-21', [ 'register_url' => 'http://insecure.example/' ] ) ) )['register_url'], 'map: a non-https link is dropped' );
	sm_check( null === AFR_Summits::from_api( sm_summit( 'ddd', null ) ), 'map: no current edition is left out' );
	sm_check( null === AFR_Summits::from_api( sm_summit( 'eee', sm_edition( 'eee', '05/04/2027', null ) ) ), 'map: a date not in Y-m-d is left out' );
	sm_check( '2027-03-02' === AFR_Summits::from_api( sm_summit( 'fff', sm_edition( 'fff', '2027-03-02', '2027-03-01' ) ) )['end'], 'map: an end before the start becomes the start' );

	sm_check( 'North American HR Executive Summit – Fall Edition' === AFR_Summits::title( 'The Fall Edition of the North American HR Executive Summit', 'North American HR Executive Summit - Fall Edition' ), 'title: site name when it names the edition' );
	sm_check( 'North American Finance Executive Summit – Spring Edition' === AFR_Summits::title( 'North American Finance Executive Summit - Spring Edition', 'North American Finance Executive Summit' ), 'title: title when it names the edition' );
	sm_check( 'Food World Summit: Safety, Quality & Manufacturing' === AFR_Summits::title( 'Food World Summit: Safety, Quality & Manufacturing', 'Food World Summit' ), 'title: title keeps a subtitle' );
	sm_check( 'Only Site Name' === AFR_Summits::title( '', 'Only Site Name' ), 'title: site name when the title is empty' );

	// 2. A full refresh.
	delete_option( AFR_Summits::OPTION );
	sm_answer( 200, $sm_index );
	$r = AFR_Summits::refresh();
	sm_check( $r['ok'] && $r['stored'] && 3 === count( $r['summits'] ), 'refresh: 3 usable summits stored' );
	sm_check( (bool) array_filter( $r['warnings'], static fn( $w ) => str_starts_with( $w, 'ddd:' ) ), 'refresh: the summit with no edition is warned about' );
	sm_check( 'W/"v1"' === get_option( AFR_Summits::OPTION )['etag'] && AFR_Summits::API === get_option( AFR_Summits::OPTION )['source'], 'refresh: ETag and source stored' );
	sm_check( ! isset( $GLOBALS['sm_t']['sent']['If-None-Match'] ), 'refresh: no ETag sent when none is stored' );

	// 3. Unchanged: the stored ETag is sent, the 304 keeps the list and counts as a refresh.
	$before = get_option( AFR_Summits::OPTION );
	update_option( AFR_Summits::OPTION, array_merge( $before, [ 'fetched_at' => '2026-01-01 00:00' ] ), false );
	$r = AFR_Summits::refresh();
	sm_check( 'W/"v1"' === ( $GLOBALS['sm_t']['sent']['If-None-Match'] ?? '' ), '304: stored ETag sent' );
	sm_check( $r['ok'] && $r['unchanged'] && 3 === count( $r['summits'] ), '304: unchanged, list kept' );
	sm_check( get_option( AFR_Summits::OPTION )['summits'] === $before['summits'] && '2026-01-01 00:00' !== AFR_Summits::refreshed_at(), '304: refresh time updated, summits untouched' );

	// 4. Upcoming only, soonest first.
	$codes = array_column( AFR_Summits::all(), 'code' );
	sm_check( [ 'aaa', 'bbb', 'ccc' ] === $codes || [ 'bbb', 'ccc' ] === $codes || [ 'ccc' ] === $codes, 'reader: sorted, past ones dropped' );
	sm_check( 3 === count( AFR_Summits::all( false ) ), 'reader: --all keeps every summit' );

	// 5. Bad answers never replace the stored copy.
	$before = get_option( AFR_Summits::OPTION );
	foreach (
		[
			'unreachable'        => [ 0, '' ],
			'server error'       => [ 500, '<html>Error</html>' ],
			'not JSON'           => [ 200, '<html>Maintenance</html>' ],
			'no summits key'     => [ 200, [ 'code' => 'rest_no_route' ] ],
			'empty list'         => [ 200, [ 'summits' => [] ] ],
			'much shorter list'  => [ 200, [ 'summits' => [ $sm_index['summits'][0] ] ] ],
			'only unusable ones' => [ 200, [ 'summits' => [ sm_summit( 'ddd', null ), sm_summit( 'eee', null ), sm_summit( 'fff', null ) ] ] ],
		] as $sm_label => [ $sm_code, $sm_body ]
	) {
		sm_answer( $sm_code, $sm_body, 'W/"v2"' );
		$r = AFR_Summits::refresh();
		sm_check( ! $r['ok'] && ! $r['stored'] && '' !== $r['error'] && get_option( AFR_Summits::OPTION ) === $before, "bad answer kept the stored copy: $sm_label" );
	}

	// 6. A 304 with nothing stored can't stand in for a list.
	delete_option( AFR_Summits::OPTION );
	sm_answer( 304, '' );
	sm_check( ! AFR_Summits::refresh()['ok'], '304 with nothing stored: not ok' );

	// 7. Dry run stores nothing and sends no ETag.
	sm_answer( 200, $sm_index );
	AFR_Summits::refresh();
	$before = get_option( AFR_Summits::OPTION );
	delete_option( AFR_Summits::OPTION );
	$r = AFR_Summits::refresh( true );
	sm_check( $r['ok'] && ! $r['stored'] && false === get_option( AFR_Summits::OPTION ), 'dry run: nothing stored' );
	update_option( AFR_Summits::OPTION, $before, false );
	AFR_Summits::refresh( true );
	sm_check( ! isset( $GLOBALS['sm_t']['sent']['If-None-Match'] ), 'dry run: no ETag sent, so the full list is shown' );

	// 8. Extras merge in without overriding EP's facts; an unmapped summit falls back.
	$sm_extras = static fn() => [
		'aaa' => [ 'area' => 'finance', 'area_label' => 'Finance', 'communities' => [ '*' ], 'blurb' => 'One line.', 'logo' => 'summits/aaa.png', 'title' => 'Not EP' ],
	];
	add_filter( 'afr_summit_extras', $sm_extras );
	$by = array_column( AFR_Summits::with_extras( false ), null, 'code' );
	remove_filter( 'afr_summit_extras', $sm_extras );
	sm_check( 'finance' === $by['aaa']['area'] && 'One line.' === $by['aaa']['blurb'] && [ '*' ] === $by['aaa']['communities'], 'extras: merged' );
	sm_check( 'Summit AAA – Fall Edition' === $by['aaa']['title'], 'extras: never override an EP fact' );
	sm_check( content_url( 'assets/summits/aaa.png' ) === $by['aaa']['logo'], 'extras: logo from the shared image library' );
	sm_check( '' === $by['bbb']['area'] && '' === $by['bbb']['blurb'] && [] === $by['bbb']['communities'] && '' === $by['bbb']['logo'], 'extras: unmapped summit falls back' );
	sm_check( isset( AFR_Summit_Extras::all()['nasrsf'], AFR_Summit_Extras::all()['namesf'] ) && 16 === count( AFR_Summit_Extras::all() ), 'extras: all 16 EP codes mapped' );

	// 9. The outcome is recorded for the admin notice.
	sm_answer( 200, $sm_index, 'W/"v3"' );
	AFR_Summits::refresh_and_record();
	sm_check( '' === AFR_Summits::problem(), 'status: a good refresh raises no notice' );
	sm_answer( 0, '' );
	AFR_Summits::refresh_and_record();
	sm_check( str_contains( AFR_Summits::problem(), 'failed' ), 'status: a failed refresh raises the notice' );
	sm_check( (bool) wp_next_scheduled( AFR_Summits::CRON ) && 'hourly' === wp_get_schedule( AFR_Summits::CRON ), 'status: the hourly refresh is scheduled' );

	// 10. Links go through one helper; extras carry the ad fields with safe defaults.
	$sm_s = [ 'code' => 'aaa', 'url' => 'https://ep.example/aaa/', 'register_url' => 'https://ep.example/aaa/register/', 'agenda_url' => '' ];
	sm_check( 'https://ep.example/aaa/register/' === AFR_Summits::url( $sm_s, 'register' ) && 'https://ep.example/aaa/' === AFR_Summits::url( $sm_s ), 'url: register and details' );
	sm_check( 'https://ep.example/aaa/' === AFR_Summits::url( $sm_s, 'agenda' ), 'url: no agenda falls back to details' );
	$sm_m = AFR_Summit_Extras::merge( [ 'code' => 'zzz' ], [ 'zzz' => [ 'format' => 'hybrid', 'video_url' => 'http://insecure.example/v', 'image' => 'summits/zzz.jpg', 'ad_headline' => 'Come' ] ] );
	sm_check( 'in-person' === $sm_m['format'] && '' === $sm_m['video_url'] && content_url( 'assets/summits/zzz.jpg' ) === $sm_m['image'] && 'Come' === $sm_m['ad_headline'], 'extras: format, https video, image URL, ad copy' );
	sm_check( 'virtual' === AFR_Summit_Extras::merge( [ 'code' => 'zzz' ], [ 'zzz' => [ 'format' => 'virtual' ] ] )['format'], 'extras: virtual format' );
	sm_check( [] === AFR_Summit_Editions::codes(), 'editions: none read by default' );

	// 11. Full editions.
	$sm_days = [
		[
			'id' => 'aaa-2027:0', 'label' => 'Welcome Day', 'date' => '2027-01-19', 'members_only' => true, 'chairs' => [],
			'sessions' => [
				[ 'id' => 'aaa:10', 'kind' => 'plenary', 'type' => 'practitioner-led-workshops', 'type_label' => 'Practitioner-Led Workshops', 'label' => null, 'start' => '13:00', 'end' => '14:00', 'title' => 'Forum workshop', 'title_tba' => false, 'description_html' => '<p>Members only</p>', 'people' => [ sm_person( 'aaa:1', 'host' ) ], 'parts' => [] ],
			],
		],
		[
			'id' => 'aaa-2027:1', 'label' => 'Day One', 'date' => '2027-01-20', 'members_only' => false,
			'chairs' => [ [ 'position' => 1, 'label' => 'STREAM 1 CHAIR', 'role' => 'chair', 'stream_number' => 1, 'speaker_id' => 'aaa:1', 'tba' => false ], [ 'position' => 2, 'label' => 'STREAM 2 CO-CHAIR', 'role' => 'co_chair', 'stream_number' => 2, 'speaker_id' => 'aaa:2', 'tba' => true ] ],
			'sessions' => [
				[ 'id' => 'aaa:20', 'kind' => 'hospitality', 'type' => 'registration-breakfast', 'type_label' => 'Registration & Breakfast', 'label' => null, 'start' => '07:45', 'end' => '08:15', 'title' => 'Registration', 'title_tba' => false, 'description_html' => null, 'people' => [], 'parts' => [] ],
				[ 'id' => 'aaa:21', 'kind' => 'plenary', 'type' => 'announcement', 'type_label' => 'Announcement', 'label' => null, 'start' => '08:15', 'end' => '08:20', 'title' => 'Opening remarks', 'title_tba' => false, 'description_html' => null, 'people' => [], 'parts' => [] ],
				[ 'id' => 'aaa:22', 'kind' => 'plenary', 'type' => 'keynote', 'type_label' => 'Keynote', 'label' => 'KEYNOTE', 'start' => '08:30', 'end' => null, 'title' => 'The keynote', 'title_tba' => false, 'description_html' => '<ul><li>One</li></ul><script>bad()</script>', 'people' => [ sm_person( 'aaa:1', 'speaker' ), sm_person( 'aaa:2', 'speaker', true ) ], 'parts' => [] ],
				[
					'id' => 'aaa:23', 'kind' => 'streams', 'type' => 'case', 'type_label' => 'Case Study', 'label' => null, 'start' => '09:10', 'end' => '09:45', 'title' => null, 'title_tba' => false, 'description_html' => null, 'people' => [],
					'parts' => [
						[ 'id' => 'aaa:23-1', 'kind' => 'stream', 'stream' => [ 'number' => 1, 'name' => 'Skills' ], 'title' => 'Case one', 'title_tba' => false, 'description_html' => '<p>D</p>', 'people' => [ sm_person( 'aaa:1', 'speaker' ) ] ],
						[ 'id' => 'aaa:23-2', 'kind' => 'stream', 'stream' => [ 'number' => 2, 'name' => null ], 'title' => 'Case two', 'title_tba' => false, 'description_html' => null, 'people' => [] ],
						[ 'id' => 'aaa:23-3', 'kind' => 'stream', 'stream' => [ 'number' => 3, 'name' => 'Short' ], 'title' => 'Not yet', 'title_tba' => true, 'description_html' => null, 'people' => [] ],
						[ 'id' => 'aaa:23-4', 'kind' => 'stream', 'stream' => [ 'number' => 4, 'name' => 'Short' ], 'title' => null, 'title_tba' => false, 'description_html' => null, 'people' => [] ],
					],
				],
				[ 'id' => 'aaa:24', 'kind' => 'meetings', 'type' => 'pre-arranged', 'type_label' => 'Pre-Arranged One-To-One Meetings', 'label' => 'One-to-ones', 'start' => '10:25', 'end' => '12:10', 'title' => null, 'title_tba' => false, 'description_html' => '<p>Slots</p>', 'people' => [], 'parts' => [ [ 'id' => 'aaa:24-1', 'kind' => 'meeting', 'title' => 'Meeting Slot 1', 'title_tba' => false ] ] ],
				[ 'id' => 'aaa:25', 'kind' => 'tables', 'type' => 'theme-lunch', 'type_label' => 'Themed Lunch', 'label' => null, 'start' => '12:50', 'end' => '13:50', 'title' => null, 'title_tba' => false, 'description_html' => null, 'people' => [], 'parts' => [ [ 'id' => 'aaa:25-1', 'kind' => 'table', 'title' => null, 'title_tba' => true, 'people' => [] ] ] ],
			],
		],
	];

	$sm_saved_edition = get_option( AFR_Summit_Editions::OPTION_PREFIX . 'aaa', null );
	AFR_Summit_Editions::delete( 'aaa' );

	$sm_e = AFR_Summit_Editions::map( sm_full_edition( $sm_days ) );
	sm_check( 'aaa' === $sm_e['code'] && 2027 === $sm_e['year'] && 'San Diego' === $sm_e['city'] && 'America/Los_Angeles' === $sm_e['timezone'], 'edition: summary fields' );
	sm_check( 'https://www.executiveplatforms.com/board.png' === $sm_e['member_board_logo'] && str_ends_with( $sm_e['logo_color'], 'aaa-color.png' ), 'edition: logos' );
	sm_check( 2 === count( $sm_e['streams'] ) && 'Stream Two – Leaders' === $sm_e['streams'][1]['label'], 'edition: streams' );
	sm_check( $sm_e['days'][0]['members_only'] && ! $sm_e['days'][1]['members_only'] && 'Welcome Day' === $sm_e['days'][0]['label'], 'edition: the members-only day is flagged' );

	$sm_d1 = $sm_e['days'][1];
	sm_check( [ 'aaa:22', 'aaa:23', 'aaa:24' ] === array_column( $sm_d1['sessions'], 'id' ), 'edition: logistics, announcements and an all-TBA lunch are dropped' );
	$sm_key = $sm_d1['sessions'][0];
	sm_check( 'The keynote' === $sm_key['title'] && '' === $sm_key['end'] && ! str_contains( $sm_key['description_html'], 'script' ), 'edition: null end is empty; description sanitized' );
	sm_check( 'Pat Lee' === $sm_key['people'][0]['name'] && $sm_key['people'][1]['tba'] && '' === $sm_key['people'][1]['name'] && 'Acme' === $sm_key['people'][1]['organization'], 'edition: TBA person keeps company, no name' );
	$sm_parts = $sm_d1['sessions'][1]['parts'];
	sm_check( [ 'aaa:23-1', 'aaa:23-2' ] === array_column( $sm_parts, 'id' ), 'edition: TBA and untitled parts are skipped' );
	sm_check( 'Stream One – Skills' === $sm_parts[0]['stream_label'] && 'Stream Two – Leaders' === $sm_parts[1]['stream_label'] && 1 === $sm_parts[0]['stream'], 'edition: stream labels from the edition, by number' );
	sm_check( [] === $sm_d1['sessions'][2]['parts'] && '<p>Slots</p>' === $sm_d1['sessions'][2]['description_html'], 'edition: one-to-ones keep the description, lose the parts' );
	sm_check( 2 === count( $sm_d1['chairs'] ) && 'co_chair' === $sm_d1['chairs'][1]['role'] && $sm_d1['chairs'][1]['tba'] && 2 === $sm_d1['chairs'][1]['stream'], 'edition: chairs' );
	$sm_sp = AFR_Summit_Editions::speaker( $sm_e, 'aaa:1' );
	sm_check( 'https://x.example/pat-300.png' === $sm_sp['headshot'] && 'https://www.linkedin.com/in/pat' === $sm_sp['linkedin_url'] && ! str_contains( $sm_sp['bio_html'], 'script' ), 'edition: speaker headshot, LinkedIn, safe bio' );
	$sm_sp = AFR_Summit_Editions::speaker( $sm_e, 'aaa:2' );
	sm_check( $sm_sp['tba'] && '' === $sm_sp['name'] && '' === $sm_sp['bio_html'] && 'Globex' === $sm_sp['organization'], 'edition: TBA speaker shows only the company' );

	sm_answer( 200, sm_full_edition( $sm_days ), 'W/"e1"', 'edition' );
	$r = AFR_Summit_Editions::refresh( 'aaa' );
	sm_check( $r['ok'] && $r['stored'] && 'aaa' === AFR_Summit_Editions::get( 'aaa' )['code'], 'edition refresh: stored' );
	sm_check( str_ends_with( $GLOBALS['sm_t']['url'], '/aaa/wp-json/ep/v1/summit?v=1' ) && ! isset( $GLOBALS['sm_t']['sent']['If-None-Match'] ), 'edition refresh: URL, no ETag at first' );
	$r = AFR_Summit_Editions::refresh( 'aaa' );
	sm_check( $r['unchanged'] && 'W/"e1"' === ( $GLOBALS['sm_t']['sent']['If-None-Match'] ?? '' ), 'edition refresh: 304 with the stored ETag' );
	AFR_Summit_Editions::refresh( 'aaa', true, 2026 );
	sm_check( str_ends_with( $GLOBALS['sm_t']['url'], '&edition=2026' ), 'edition refresh: a given year' );

	$sm_before = get_option( AFR_Summit_Editions::OPTION_PREFIX . 'aaa' );
	foreach ( [ 'unreachable' => [ 0, '' ], 'not found' => [ 404, [ 'code' => 'ep_unknown_edition' ] ], 'not JSON' => [ 200, '<html>' ] ] as $sm_label => [ $sm_code, $sm_body ] ) {
		sm_answer( $sm_code, $sm_body, 'W/"e2"', 'edition' );
		$r = AFR_Summit_Editions::refresh( 'aaa' );
		sm_check( ! $r['ok'] && get_option( AFR_Summit_Editions::OPTION_PREFIX . 'aaa' ) === $sm_before, "edition refresh: bad answer kept the stored copy: $sm_label" );
	}

	sm_answer( 200, sm_full_edition( [] ), 'W/"e3"', 'edition' );
	$r = AFR_Summit_Editions::refresh( 'aaa' );
	sm_check( $r['ok'] && $r['warnings'] && 2 === count( AFR_Summit_Editions::get( 'aaa' )['days'] ), 'edition refresh: no days keeps the stored agenda' );

	$sm_codes = static fn() => [ 'aaa', 'AAA', '' ];
	add_filter( 'afr_summit_edition_codes', $sm_codes );
	sm_answer( 200, $sm_index, 'W/"v4"' );
	sm_answer( 200, sm_full_edition( $sm_days ), 'W/"e4"', 'edition' );
	$r = AFR_Summits::refresh_and_record();
	remove_filter( 'afr_summit_edition_codes', $sm_codes );
	sm_check( [ 'aaa' ] === array_keys( $r['editions'] ) && $r['editions']['aaa']['stored'], 'refresh_and_record: refreshes the listed editions once each' );

	AFR_Summit_Editions::delete( 'aaa' );
	if ( null !== $sm_saved_edition ) {
		update_option( AFR_Summit_Editions::OPTION_PREFIX . 'aaa', $sm_saved_edition, false );
	}
} finally {
	foreach ( [ AFR_Summits::OPTION => $sm_saved, AFR_Summits::STATUS_OPTION => $sm_saved_status ] as $sm_option => $sm_value ) {
		if ( null === $sm_value ) {
			delete_option( $sm_option );
		} else {
			update_option( $sm_option, $sm_value, false );
		}
	}
}

if ( $GLOBALS['sm_t']['fail'] ) {
	WP_CLI::error( sprintf( '%d passed, %d failed.', $GLOBALS['sm_t']['pass'], $GLOBALS['sm_t']['fail'] ) );
}
WP_CLI::success( sprintf( 'Summits engine: %d checks passed.', $GLOBALS['sm_t']['pass'] ) );
