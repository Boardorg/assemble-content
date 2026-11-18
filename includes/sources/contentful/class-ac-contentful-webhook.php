<?php
/**
 * The Contentful webhook endpoint.
 *
 * A delivery only asks AC_Contentful_Changes for a background run, which reads
 * what changed from the Sync API. The payload itself is never trusted or parsed
 * beyond its ID, so what lands in WordPress is always what the CDA serves, and a
 * burst of publishes costs one run.
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

	/** Schedule a background run and answer at once, so Contentful never waits on a sync. */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$payload = (array) $request->get_json_params();
		$queued  = AC_Contentful_Changes::request();

		return new WP_REST_Response(
			[
				'ok'     => $queued,
				'queued' => $queued,
				'topic'  => (string) $request->get_header( 'X-Contentful-Topic' ),
				'entry'  => (string) ( $payload['sys']['id'] ?? '' ),
			],
			$queued ? 202 : 500
		);
	}
}
