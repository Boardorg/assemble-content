<?php
/**
 * A deliberate, switchable bypass of the audience gate.
 *
 * The beta has no member roles yet, so nobody but an administrator can see a
 * complete report. This lets colleagues review the work without accounts.
 *
 * It is an access-control bypass by design, so:
 *   - it defaults to `off` and has to be turned on explicitly,
 *   - while it is on, every report carries a visible banner saying so, and
 *   - turning it off immediately invalidates any bypass already in effect.
 *
 * Modes:
 *   off    — normal gating (default; the only safe state for production)
 *   button — the gate shows a "view anyway" button; clicking it opts that
 *            visitor in for the rest of their session via a cookie
 *   open   — every visitor sees complete reports, no click needed
 */

defined( 'ABSPATH' ) || exit;

class AFR_Bypass {

	public const OPTION_MODE = 'afr_bypass_mode';

	public const MODE_OFF    = 'off';
	public const MODE_BUTTON = 'button';
	public const MODE_OPEN   = 'open';

	/**
	 * The `wordpress_` prefix is deliberate and required on WP Engine.
	 *
	 * WP Engine's edge strips cookies that are not on its allowlist before the
	 * request reaches PHP, so a plainly-named cookie is invisible to this plugin
	 * for exactly the anonymous visitors the bypass exists for. Cookies starting
	 * with `wordpress_` are passed through (and make the request cache-exempt,
	 * which is also what we want here).
	 */
	private const COOKIE     = 'wordpress_afr_bypass';
	private const QUERY_ARG  = 'afr_bypass';
	private const COOKIE_TTL = WEEK_IN_SECONDS;

	public static function init(): void {
		// Early enough that the cookie is set before any output.
		add_action( 'template_redirect', [ self::class, 'handle_request' ], 1 );
	}

	public static function mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, self::MODE_OFF );

		if ( ! in_array( $mode, [ self::MODE_OFF, self::MODE_BUTTON, self::MODE_OPEN ], true ) ) {
			return self::MODE_OFF;
		}

		/**
		 * Filter the bypass mode. Return 'off' to force normal gating — the hook
		 * to use in wp-config or an mu-plugin if this plugin ever runs in
		 * production, so a stray option value cannot open the gate there.
		 *
		 * @param string $mode
		 */
		return (string) apply_filters( 'afr_bypass_mode', $mode );
	}

	/** Is the gate currently bypassed for this visitor? */
	public static function active(): bool {
		$mode = self::mode();

		if ( $mode === self::MODE_OPEN ) {
			return true;
		}

		if ( $mode === self::MODE_BUTTON ) {
			return isset( $_COOKIE[ self::COOKIE ] ) && $_COOKIE[ self::COOKIE ] === '1';
		}

		return false;
	}

	/** Whether to advertise the button on the gate. */
	public static function offers_button(): bool {
		return self::mode() === self::MODE_BUTTON && ! self::active();
	}

	/**
	 * `?afr_bypass=1` opts in, `?afr_bypass=0` opts out. Both set the cookie and
	 * redirect to the clean URL so the parameter does not linger in shared links
	 * or get captured by page caches.
	 */
	public static function handle_request(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}

		$wanted = (string) $_GET[ self::QUERY_ARG ] === '1';

		// Honour opt-in only while the button mode is on; opting out always works.
		if ( $wanted && self::mode() !== self::MODE_BUTTON ) {
			return;
		}

		// WP Engine strips Set-Cookie from responses it considers cacheable, which
		// would silently drop the opt-in. Mark this one uncacheable first.
		nocache_headers();

		setcookie(
			self::COOKIE,
			$wanted ? '1' : '',
			[
				'expires'  => $wanted ? time() + self::COOKIE_TTL : time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			]
		);

		wp_safe_redirect( remove_query_arg( self::QUERY_ARG ) );
		exit;
	}

	/** The opt-in button shown inside the gate. */
	public static function button( string $label = 'View the full report anyway' ): string {
		return self::button_html( self::button_data( $label ) );
	}

	/**
	 * The opt-in button as data, for a theme that prints its own markup.
	 *
	 * @return array{url:string,label:string}|null Null unless the button mode is offering it.
	 */
	public static function button_data( string $label = 'View the full report anyway' ): ?array {
		if ( ! self::offers_button() ) {
			return null;
		}

		return [
			'url'   => add_query_arg( self::QUERY_ARG, '1' ),
			'label' => $label,
		];
	}

	/** The plugin's markup for button_data(). */
	public static function button_html( ?array $button ): string {
		if ( ! $button ) {
			return '';
		}

		return sprintf(
			'<a class="afr-btn afr-btn--bypass" href="%s" rel="nofollow">%s</a>',
			esc_url( $button['url'] ),
			esc_html( $button['label'] )
		);
	}

	/**
	 * Banner shown on every report while the bypass is in effect, so nobody
	 * mistakes an ungated report for how the live site behaves.
	 */
	public static function banner(): string {
		if ( ! self::active() ) {
			return '';
		}

		$open = self::mode() === self::MODE_OPEN;

		return '<div class="afr-bypass-banner">'
			. '<span class="afr-bypass-badge">Beta preview</span>'
			. '<span class="afr-bypass-text">Member gating is switched off on this site, so you are seeing the complete report. '
			. 'On the live site this would be visible only to the audiences it is published to.</span>'
			. ( $open
				? ''
				: sprintf(
					'<a class="afr-bypass-off" href="%s" rel="nofollow">Turn the gate back on for me</a>',
					esc_url( add_query_arg( self::QUERY_ARG, '0' ) )
				) )
			. '</div>';
	}
}
