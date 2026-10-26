<?php
/**
 * The Contentful webhook endpoint.
 *
 * Contentful posts the *management* shape of an entry, which has unresolved links
 * and localized field values. Rather than parse it, the handler takes only the
 * entry ID and content type from the payload and re-fetches from the delivery API —
 * so what lands in WordPress is always what the CDA actually serves.
 *
 * Authentication is a shared secret in the `X-AC-Secret` header (or, from
 * webhooks set up before 2.0.0, `X-AFR-Secret`), compared with hash_equals. No
 * secret configured means the route rejects everything.
 */

defined( 'ABSPATH' ) || exit;

class AC_Contentful_Webhook {

	public const NAMESPACE = 'assemble/v1';
	public const ROUTE     = '/contentful';

	private const SECRET_HEADER        = 'X-AC-Secret';
	private const LEGACY_SECRET_HEADER = 'X-AFR-Secret';

	/** Object-cache group for per-entry locks, each held at most a minute. */
	private const LOCK_GROUP = 'ac_webhook';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => [ self::class, 'authorize' ],
			]
		);
	}

	public static function webhook_url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	/** Shared-secret check. */
	public static function authorize( WP_REST_Request $request ) {
		$expected = AC_Settings::all()['webhook_secret'];

		if ( $expected === '' ) {
			return new WP_Error(
				'ac_no_secret',
				'Webhook secret is not configured on this site.',
				[ 'status' => 503 ]
			);
		}

		$provided = (string) ( $request->get_header( self::SECRET_HEADER ) ?: $request->get_header( self::LEGACY_SECRET_HEADER ) );

		if ( $provided === '' || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'ac_bad_secret',
				'Invalid or missing webhook secret.',
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$topic   = (string) $request->get_header( 'X-Contentful-Topic' );
		$payload = (array) $request->get_json_params();

		$entry_id     = (string) ( $payload['sys']['id'] ?? '' );
		$content_type = (string) ( $payload['sys']['contentType']['sys']['id'] ?? '' );
		$type         = (string) ( $payload['sys']['type'] ?? '' );

		if ( $entry_id === '' ) {
			return new WP_REST_Response(
				[ 'ok' => false, 'reason' => 'payload had no sys.id' ],
				400
			);
		}

		// Contentful retries and can repeat a delivery. Only the same topic, entry and
		// revision counts as a repeat, and only once it was handled successfully, so a
		// publish followed quickly by an unpublish is never dropped.
		$dedupe_key = 'ac_webhook_' . md5( $topic . '|' . $entry_id . '|' . (string) ( $payload['sys']['revision'] ?? '' ) );
		if ( get_transient( $dedupe_key ) ) {
			return new WP_REST_Response(
				[ 'ok' => true, 'skipped' => 'duplicate delivery', 'entry' => $entry_id ],
				200
			);
		}

		// One delivery per entry at a time, so two can't both create its post. A
		// busy entry answers 503, which Contentful retries.
		if ( ! wp_cache_add( $entry_id, 1, self::LOCK_GROUP, MINUTE_IN_SECONDS ) ) {
			return new WP_REST_Response(
				[ 'ok' => false, 'reason' => 'entry is already syncing; retry', 'entry' => $entry_id ],
				503
			);
		}

		try {
			$response = self::process( $topic, $entry_id, $content_type, $type );
		} finally {
			wp_cache_delete( $entry_id, self::LOCK_GROUP );
		}

		if ( $response->get_status() < 300 ) {
			set_transient( $dedupe_key, 1, MINUTE_IN_SECONDS );
		}

		return $response;
	}

	/** Sync whatever one delivery is about. */
	private static function process( string $topic, string $entry_id, string $content_type, string $type ): WP_REST_Response {
		$synced = AC_Types::type( $content_type );

		// Only types read from Contentful are this webhook's business.
		if ( $synced && $synced->config( 'source' ) !== 'contentful' ) {
			$synced = null;
		}

		// Assets have no content type; a changed chart or headshot affects every
		// report that embeds it, so treat it like a dependency change. Which types
		// are dependencies comes from the registry (e.g. a changed `person`
		// re-renders every Field Report that cites them).
		$is_dependency = in_array( $content_type, AC_Types::dependency_types(), true ) || $type === 'Asset';

		if ( $synced instanceof AC_Post_Type_Content ) {
			$result = AC_Sync::sync_one( $entry_id, true, 'webhook: ' . ( $topic ?: 'unknown' ) );
			$scope  = 'entry';
		} elseif ( $is_dependency ) {
			// The dataset is small enough that a full re-sync is cheaper than
			// working out which reports reference the changed entry. A full sync
			// refreshes the option types too (the community directory first).
			$result = AC_Sync::sync_all( true, 'webhook dependency: ' . ( $topic ?: 'unknown' ) );
			$scope  = 'all';
		} elseif ( $synced instanceof AC_Option_Content ) {
			// Option types live in one option, so there is no per-entry path:
			// refetching all of them is a single CDA call.
			$outcome = $synced->sync();

			return new WP_REST_Response(
				[
					'ok'      => $outcome['error'] === '',
					'topic'   => $topic,
					'entry'   => $entry_id,
					'scope'   => $synced->key(),
					'result'  => [ 'count' => $outcome['count'] ],
					'details' => $outcome['error'] !== '' ? [ $outcome['error'] ] : [],
				],
				$outcome['error'] === '' ? 200 : 500
			);
		} else {
			return new WP_REST_Response(
				[ 'ok' => true, 'skipped' => 'content type not synced', 'content_type' => $content_type ],
				200
			);
		}

		return new WP_REST_Response(
			[
				'ok'      => $result['errors'] === 0,
				'topic'   => $topic,
				'entry'   => $entry_id,
				'scope'   => $scope,
				'result'  => [
					'created'   => $result['created'],
					'updated'   => $result['updated'],
					'unchanged' => $result['unchanged'],
					'drafted'   => $result['drafted'],
					'errors'    => $result['errors'],
				],
				'details' => $result['messages'],
			],
			$result['errors'] === 0 ? 200 : 500
		);
	}
}
