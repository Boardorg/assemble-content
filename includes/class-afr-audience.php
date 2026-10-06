<?php
/**
 * Who is allowed to see which version of a report.
 *
 * Outcomes per (user, report) pair:
 *   'standard' — the complete report
 *   'council'  — summary first (Story in Brief + Takeaways at a Glance), full
 *                report behind an expander
 *   'delegate' — the complete report, but peer engagement locked behind an upgrade
 *   'public'   — kicker + headline + opening excerpt, then the join prompt
 *   'denied'   — headline and gate only
 */

defined( 'ABSPATH' ) || exit;

class AFR_Audience {

	public const VIEW_STANDARD = 'standard';
	public const VIEW_COUNCIL  = 'council';
	public const VIEW_DELEGATE = 'delegate';
	public const VIEW_PUBLIC   = 'public';
	public const VIEW_DENIED   = 'denied';

	private const PREVIEW_PARAM = 'afr_as';

	/** Audiences the current visitor belongs to. */
	public static function current_audiences(): array {
		// An explicit preview request wins over everything, including the bypass,
		// so an administrator can still inspect a single audience's view.
		$preview = self::preview_audience();
		if ( $preview !== null ) {
			return $preview === 'Public' ? [ 'Public' ] : [ 'Public', $preview ];
		}

		// The deliberate, switchable gate bypass — see AFR_Bypass.
		if ( AFR_Bypass::active() ) {
			return AFR_Settings::audiences();
		}

		// Admins see everything unless they asked to preview a specific audience.
		if ( current_user_can( 'manage_options' ) ) {
			return AFR_Settings::audiences();
		}

		$audiences = [ 'Public' ];
		$user      = wp_get_current_user();

		if ( $user && $user->exists() ) {
			$roles = (array) $user->roles;
			foreach ( AFR_Settings::audience_roles() as $audience => $mapped ) {
				if ( $mapped && array_intersect( $roles, (array) $mapped ) ) {
					$audiences[] = $audience;
				}
			}
		}

		/**
		 * Filter the audiences resolved for the current visitor. The hook to use
		 * if membership lives somewhere other than WordPress roles.
		 *
		 * @param string[] $audiences
		 */
		return array_values( array_unique( (array) apply_filters( 'afr_current_audiences', $audiences ) ) );
	}

	/**
	 * The `?afr_as=` override. Administrators only — this is a preview tool, and
	 * honouring it for anyone else would be an access-control hole.
	 */
	public static function preview_audience(): ?string {
		if ( ! isset( $_GET[ self::PREVIEW_PARAM ] ) || ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$requested = strtolower( str_replace( [ '_', '-' ], ' ', sanitize_text_field( wp_unslash( (string) $_GET[ self::PREVIEW_PARAM ] ) ) ) );

		foreach ( AFR_Settings::audiences() as $audience ) {
			if ( strtolower( $audience ) === $requested ) {
				return $audience;
			}
		}

		return null;
	}

	/** Audiences a given report is published to. */
	public static function report_audiences( int $post_id ): array {
		$stored = get_post_meta( $post_id, AFR_CPT::META_AUDIENCES, true );

		return is_array( $stored ) ? $stored : [];
	}

	/** Which view the current visitor gets for this report. */
	public static function view_for( int $post_id ): string {
		$allowed = self::report_audiences( $post_id );
		$mine    = self::current_audiences();
		$shared  = array_intersect( $allowed, $mine );

		if ( ! $shared ) {
			return self::VIEW_DENIED;
		}

		// Council Chairs get the summary-first treatment, but only if that is the
		// strongest match — a Board Chair previewing as Council Chair should see it.
		if ( in_array( 'Council Chair', $shared, true ) && ! self::prefers_standard( $shared, 'Council Chair' ) ) {
			return self::VIEW_COUNCIL;
		}

		// Delegates read the whole report; peer engagement stays locked.
		if ( in_array( 'Delegate', $shared, true ) && ! self::prefers_standard( $shared, 'Delegate' ) ) {
			return self::VIEW_DELEGATE;
		}

		$member = array_diff( $shared, [ 'Public' ] );

		return $member ? self::VIEW_STANDARD : self::VIEW_PUBLIC;
	}

	/**
	 * When someone matches several member audiences at once (an admin, typically),
	 * show the plain standard report rather than a narrower variant — unless they
	 * explicitly asked to preview that variant.
	 */
	private static function prefers_standard( array $shared, string $variant ): bool {
		if ( self::preview_audience() === $variant ) {
			return false;
		}

		return count( array_diff( $shared, [ 'Public', 'Council Chair', 'Delegate' ] ) ) > 0;
	}

	/** True when the visitor is an admin looking through someone else's eyes. */
	public static function is_previewing(): bool {
		return self::preview_audience() !== null;
	}

	/** Preview switcher links for administrators. */
	public static function preview_links( int $post_id ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$base    = (string) get_permalink( $post_id );
		$current = self::preview_audience();
		$allowed = self::report_audiences( $post_id );

		$links = [ sprintf(
			'<a href="%s"%s>Administrator</a>',
			esc_url( $base ),
			$current === null ? ' aria-current="true" class="afr-active"' : ''
		) ];

		foreach ( AFR_Settings::audiences() as $audience ) {
			$slug     = sanitize_title( $audience );
			$is_now   = $current === $audience;
			$in_scope = in_array( $audience, $allowed, true );

			$links[] = sprintf(
				'<a href="%s"%s title="%s">%s%s</a>',
				esc_url( add_query_arg( self::PREVIEW_PARAM, $slug, $base ) ),
				$is_now ? ' aria-current="true" class="afr-active"' : '',
				$in_scope ? 'Included in this report&rsquo;s availableTo' : 'Not in this report&rsquo;s availableTo — will show the gate',
				esc_html( $audience ),
				$in_scope ? '' : ' &middot;'
			);
		}

		return sprintf(
			'<div class="afr-preview-bar"><span class="afr-preview-label">Preview as</span> %s'
			. '<span class="afr-preview-note">Visible to administrators only. Audiences marked &middot; are outside this report&rsquo;s <code>availableTo</code>.</span></div>',
			implode( ' <span class="afr-sep">/</span> ', $links )
		);
	}
}
