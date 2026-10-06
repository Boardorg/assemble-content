<?php
/**
 * The Contentful webhook endpoint.
 *
 * Contentful posts the *management* shape of an entry, which has unresolved links
 * and localized field values. Rather than parse it, the handler takes only the
 * entry ID and content type from the payload and re-fetches from the delivery API —
 * so what lands in WordPress is always what the CDA actually serves.
 *
 * Authentication is a shared secret in the `X-AFR-Secret` header, compared with
 * hash_equals. No secret configured means the route rejects everything.
 */

defined( 'ABSPATH' ) || exit;

class AFR_REST {

	public const NAMESPACE = 'assemble/v1';
	public const ROUTE     = '/contentful';

	private const SECRET_HEADER = 'X-AFR-Secret';
	private const LOCK_KEY      = 'afr_webhook_lock';

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
		$expected = AFR_Settings::all()['webhook_secret'];

		if ( $expected === '' ) {
			return new WP_Error(
				'afr_no_secret',
				'Webhook secret is not configured on this site.',
				[ 'status' => 503 ]
			);
		}

		$provided = (string) $request->get_header( self::SECRET_HEADER );

		if ( $provided === '' || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error(
				'afr_bad_secret',
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

		// Contentful retries and can fire several webhooks for one editorial save.
		if ( get_transient( self::LOCK_KEY ) === $entry_id ) {
			return new WP_REST_Response(
				[ 'ok' => true, 'skipped' => 'duplicate delivery within 10s', 'entry' => $entry_id ],
				200
			);
		}
		set_transient( self::LOCK_KEY, $entry_id, 10 );

		// Assets have no content type; a changed chart or headshot affects every
		// report that embeds it, so treat it like a dependency change.
		// Which types are dependencies comes from the registry (e.g. a changed `person`
		// re-renders every Field Report that cites them).
		$is_dependency = in_array( $content_type, AFR_Types::dependency_types(), true ) || $type === 'Asset';

		if ( $content_type === 'siteFeature' ) {
			// Site Features live in one option, so there is no per-entry path —
			// refetching all of them is a single CDA call.
			$features = AFR_Features::sync();

			return new WP_REST_Response(
				[
					'ok'      => $features['error'] === '',
					'topic'   => $topic,
					'entry'   => $entry_id,
					'scope'   => 'site-features',
					'result'  => [ 'features' => $features['count'] ],
					'details' => $features['error'] !== '' ? [ $features['error'] ] : [],
				],
				$features['error'] === '' ? 200 : 500
			);
		}

		if ( AFR_Types::get( $content_type ) ) {
			$result = AFR_Sync::sync_one( $entry_id, true, 'webhook: ' . ( $topic ?: 'unknown' ) );
			$scope  = 'entry';
		} elseif ( $is_dependency ) {
			// The dataset is small enough that a full re-sync is cheaper than
			// working out which reports reference the changed entry.
			$result = AFR_Sync::sync_all( true, 'webhook dependency: ' . ( $topic ?: 'unknown' ) );
			$scope  = 'all';
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
