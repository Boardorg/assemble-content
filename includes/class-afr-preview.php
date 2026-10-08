<?php
/**
 * Draft preview: an unpublished (or edited) entry rendered on the site straight
 * from Contentful's Preview API, through the same template and view model as
 * the live page. Nothing is stored, synced or cached.
 *
 * URL: /?afr_preview=<entry id>  (register it in Contentful → Settings →
 * Content preview, see contentful_url_template()).
 *
 * Who can see it:
 *   - a signed-in user with `manage_options` (filter `afr_preview_capability`),
 *     who can switch views with &afr_view=… and copy a share link; or
 *   - anyone holding a signed link (&afr_exp=…&afr_sig=…), an HMAC of the entry
 *     ID and expiry with this site's salts. Links expire (default 7 days) and
 *     stop working everywhere when the salts change.
 * Everyone else gets a 403. Previews are always noindex and sent uncacheable.
 *
 * A theme renders the page by hooking `afr_render_preview` (it receives the
 * view model); otherwise the plugin prints its own document.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Preview {

	public const QUERY_ARG = 'afr_preview';
	public const VIEW_ARG  = 'afr_view';
	private const EXP_ARG  = 'afr_exp';
	private const SIG_ARG  = 'afr_sig';
	private const LINK_TTL = WEEK_IN_SECONDS;

	/** The view model being previewed on this request, once resolved. */
	private static ?array $current = null;

	public static function init(): void {
		// Before AFR_Bypass (1) and redirect_canonical (10); the request ends here.
		add_action( 'template_redirect', [ self::class, 'handle_request' ], 0 );
	}

	/** Is draft preview configured (a Preview API token is stored)? */
	public static function is_configured(): bool {
		return AFR_Settings::all()['preview_token'] !== '';
	}

	/** The URL to register in Contentful, with its entry ID placeholder. */
	public static function contentful_url_template(): string {
		return add_query_arg( self::QUERY_ARG, '{entry.sys.id}', home_url( '/' ) );
	}

	/** The previewed view model on this request, or null when this isn't a preview. */
	public static function current(): ?array {
		return self::$current;
	}

	/** A link anyone can open until it expires. */
	public static function signed_url( string $entry_id, int $ttl = self::LINK_TTL, string $view = '' ): string {
		$expires = time() + max( HOUR_IN_SECONDS, $ttl );
		$args    = [
			self::QUERY_ARG => $entry_id,
			self::EXP_ARG   => $expires,
			self::SIG_ARG   => self::signature( $entry_id, $expires ),
		];

		if ( $view !== '' ) {
			$args[ self::VIEW_ARG ] = $view;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	private static function signature( string $entry_id, int $expires ): string {
		return hash_hmac( 'sha256', $entry_id . '|' . $expires, wp_salt( 'auth' ) . 'afr-preview' );
	}

	public static function verify( string $entry_id, int $expires, string $signature ): bool {
		return $expires > time() && $signature !== '' && hash_equals( self::signature( $entry_id, $expires ), $signature );
	}

	private static function capability(): string {
		/**
		 * Filter the capability that may open any draft preview without a signed link.
		 *
		 * @param string $capability
		 */
		return (string) apply_filters( 'afr_preview_capability', 'manage_options' );
	}

	/**
	 * Decide one preview request. Separate from handle_request() so it can be tested.
	 *
	 * @param array<string,string> $query The request's query args.
	 * @return array{status:int,message:string,model:?array,entry_id:string,signed:bool,post_type:string}
	 */
	public static function resolve( array $query ): array {
		$entry_id = sanitize_text_field( (string) ( $query[ self::QUERY_ARG ] ?? '' ) );
		$result   = [ 'status' => 404, 'message' => 'No such draft.', 'model' => null, 'entry_id' => $entry_id, 'signed' => false, 'post_type' => '' ];

		if ( $entry_id === '' || ! preg_match( '/^[A-Za-z0-9._-]{1,64}$/', $entry_id ) ) {
			return $result;
		}

		$staff  = current_user_can( self::capability() );
		$signed = self::verify( $entry_id, (int) ( $query[ self::EXP_ARG ] ?? 0 ), (string) ( $query[ self::SIG_ARG ] ?? '' ) );

		if ( ! $staff && ! $signed ) {
			return array_merge( $result, [ 'status' => 403, 'message' => 'Sign in to WordPress as an administrator to preview drafts, or ask one for a share link. Share links expire after a week.' ] );
		}

		if ( ! self::is_configured() ) {
			return array_merge( $result, [ 'status' => 503, 'message' => 'Draft preview is not set up on this site yet: it needs a Contentful Content Preview API token.' ] );
		}

		$entry = AFR_Contentful::get_preview_entry( $entry_id );
		$type  = is_array( $entry ) ? (string) ( $entry['sys']['contentType']['sys']['id'] ?? '' ) : '';
		$types = AFR_Types::all();

		// Only types this site renders, and only Field Reports have a template today.
		if ( ! $entry || ! isset( $types[ $type ] ) || ( $types[ $type ]['post_type'] ?? '' ) !== AFR_CPT::POST_TYPE ) {
			return $result;
		}

		$view = sanitize_key( (string) ( $query[ self::VIEW_ARG ] ?? AFR_Audience::VIEW_STANDARD ) );
		if ( ! in_array( $view, [ AFR_Audience::VIEW_STANDARD, AFR_Audience::VIEW_COUNCIL, AFR_Audience::VIEW_DELEGATE, AFR_Audience::VIEW_PUBLIC, AFR_Audience::VIEW_DENIED ], true ) ) {
			$view = AFR_Audience::VIEW_STANDARD;
		}

		$model                       = AFR_Renderer::view_model_for( $entry, $view );
		$model['notices']['preview'] = self::banner( $entry_id, $view, $staff, $query );
		$model['entry']              = $entry;

		return [ 'status' => 200, 'message' => '', 'model' => $model, 'entry_id' => $entry_id, 'signed' => $signed, 'post_type' => AFR_CPT::POST_TYPE ];
	}

	/** Answer a preview request and end it. */
	public static function handle_request(): void {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, authorised below.
			return;
		}

		// Never cached by WP Engine or a browser, never indexed.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );

		$query  = array_map( static fn( $v ) => is_scalar( $v ) ? (string) wp_unslash( $v ) : '', $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = self::resolve( $query );

		if ( $result['status'] !== 200 ) {
			$message = esc_html( $result['message'] );
			if ( $result['status'] === 403 && ! is_user_logged_in() ) {
				$message .= sprintf( ' <a href="%s">Sign in</a>', esc_url( wp_login_url( add_query_arg( self::QUERY_ARG, $result['entry_id'], home_url( '/' ) ) ) ) );
			}
			wp_die( $message, 'Draft preview', [ 'response' => $result['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}

		self::$current = $result['model'];
		status_header( 200 );

		if ( has_action( 'afr_render_preview' ) ) {
			/**
			 * Render a draft preview page. The theme prints the whole page (header
			 * to footer) from the view model, exactly as for a live report.
			 *
			 * @param array  $model     View model (AFR_Renderer::view_model_for()), plus `entry`.
			 * @param string $post_type The post type the entry would sync to.
			 */
			do_action( 'afr_render_preview', $result['model'], $result['post_type'] );
		} else {
			get_header();
			echo $result['model']['notices']['preview']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in banner().
			echo AFR_Renderer::render( 0, $result['model']['entry'], $result['model']['view'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's escaped document.
			get_footer();
		}

		exit;
	}

	/** "Draft preview" banner: the view switcher, and a share link for staff. */
	private static function banner( string $entry_id, string $view, bool $staff, array $query ): string {
		$keep  = array_intersect_key( $query, array_flip( [ self::QUERY_ARG, self::EXP_ARG, self::SIG_ARG ] ) );
		$base  = add_query_arg( array_map( 'rawurlencode', $keep ), home_url( '/' ) );
		$views = [];

		foreach ( [ AFR_Audience::VIEW_STANDARD, AFR_Audience::VIEW_COUNCIL, AFR_Audience::VIEW_DELEGATE, AFR_Audience::VIEW_PUBLIC, AFR_Audience::VIEW_DENIED ] as $option ) {
			$views[] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( self::VIEW_ARG, $option, $base ) ),
				$option === $view ? ' aria-current="true" class="afr-active"' : '',
				esc_html( ucfirst( $option ) )
			);
		}

		$share = '';
		if ( $staff ) {
			$share = sprintf(
				'<span class="afr-preview-note">Share link (works without signing in, expires %s): <a href="%s" rel="nofollow">copy this link</a></span>',
				esc_html( wp_date( 'F j', time() + self::LINK_TTL ) ),
				esc_url( self::signed_url( $entry_id, self::LINK_TTL, $view ) )
			);
		}

		return '<div class="afr-bypass-banner afr-draft-banner"><span class="afr-bypass-badge">Draft preview</span>'
			. '<span class="afr-bypass-text">The latest draft from Contentful, not the published page. Not cached or indexed.</span></div>'
			. '<div class="afr-preview-bar"><span class="afr-preview-label">View as</span> '
			. implode( ' <span class="afr-sep">/</span> ', $views )
			. $share
			. '</div>';
	}
}
