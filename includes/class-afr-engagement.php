<?php
/**
 * The engagement footer that closes each version of a report.
 *
 * Structure and copy follow the reference journey document: members get four
 * routes into peer intelligence, Council members get three Council-specific
 * routes, Delegates get the same block locked behind an upgrade, and the public
 * version gets the join prompt instead.
 *
 * Only items whose destinations genuinely exist are rendered. "Explore related
 * topics" is derived from other synced reports in the same community, so it is
 * real. Membership-director contact, forum threads and upcoming sessions have no
 * source in Contentful or WordPress yet — they stay out until configured through
 * `afr_engagement_links`, rather than shipping dead links.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Engagement {

	/**
	 * Configured destinations. Every key is optional; anything absent is skipped.
	 *
	 * Example:
	 *   add_filter( 'afr_engagement_links', function ( $links ) {
	 *       $links['md_booking']  = 'https://…/book';
	 *       $links['md_message']  = 'https://…/message';
	 *       $links['forum']       = 'https://…/forum';
	 *       $links['upcoming']    = [ [ 'title' => '…', 'url' => '…', 'when' => 'Jun 24, 2026' ] ];
	 *       $links['join']        = 'https://…/join';
	 *       $links['upgrade']     = 'https://…/upgrade';
	 *       $links['council_1on1']     = 'https://…';
	 *       $links['council_benchmark'] = 'https://…';
	 *       return $links;
	 *   } );
	 *
	 * @return array<string,mixed>
	 */
	public static function links(): array {
		return (array) apply_filters( 'afr_engagement_links', [] );
	}

	private static function link( string $key ): string {
		$links = self::links();
		$value = $links[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/** The engagement block for a given view. */
	public static function render( int $post_id, array $entry, string $view ): string {
		$block = match ( $view ) {
			AFR_Audience::VIEW_COUNCIL  => self::council( $post_id, $entry ),
			AFR_Audience::VIEW_DELEGATE => self::delegate( $post_id, $entry ),
			AFR_Audience::VIEW_STANDARD => self::member( $post_id, $entry ),
			default                     => '',
		};

		/**
		 * Filter the rendered engagement footer.
		 *
		 * @param string $block
		 * @param int    $post_id
		 * @param string $view
		 */
		return (string) apply_filters( 'afr_engagement_html', $block, $post_id, $view );
	}

	// ------------------------------------------------------------------ member

	private static function member( int $post_id, array $entry ): string {
		$community = self::community_name( $entry );
		$items     = [];

		$md_booking = self::link( 'md_booking' );
		$md_message = self::link( 'md_message' );

		if ( $md_booking || $md_message ) {
			$actions = '';
			if ( $md_booking ) {
				$actions .= self::button( $md_booking, 'Book a 1:1', 'afr-solid' );
			}
			if ( $md_message ) {
				$actions .= self::button( $md_message, 'Message your MD', 'afr-ghost' );
			}

			$items[] = self::item(
				'Get 1-1 support from your Membership Director',
				'Bring a question from your own work to your Membership Director, or suggest a topic for an upcoming discussion.',
				'<div class="afr-fl-actions">' . $actions . '</div>'
			);
		}

		$forum = self::link( 'forum' );
		if ( $forum ) {
			$items[] = self::item(
				'Join an existing discussion',
				'Pick up the thread with peers already working through this.',
				'<ul class="afr-fl-links"><li>' . self::anchor( $forum, sprintf( 'Post a question to %s', $community ) ) . '</li></ul>'
			);
		}

		$related = self::related_reports( $post_id );
		if ( $related !== '' ) {
			$items[] = self::item(
				'Explore related topics',
				sprintf( 'Earlier %s discussions on the same thread.', $community ),
				$related
			);
		}

		$upcoming = self::upcoming();
		if ( $upcoming !== '' ) {
			$items[] = self::item(
				'Save upcoming discussions to your calendar',
				sprintf( 'Reserve your seat for what&rsquo;s next on %s.', $community ),
				$upcoming
			);
		}

		return self::wrap( 'Access more peer intelligence on this topic', $items );
	}

	// ----------------------------------------------------------------- council

	private static function council( int $post_id, array $entry ): string {
		$items = [];

		$one_on_one = self::link( 'council_1on1' );
		if ( $one_on_one ) {
			$items[] = self::item(
				'Request a 1-1 with your Council Director',
				'Get a direct read on what this means for your organization, and help shape where the Council goes next.',
				'<div class="afr-fl-actions">' . self::button( $one_on_one, 'Request a 1-1', 'afr-solid' ) . '</div>'
			);
		}

		$benchmark = self::link( 'council_benchmark' );
		if ( $benchmark ) {
			$items[] = self::item(
				'Review your personalized benchmark results',
				'See how your organization&rsquo;s answers compare against the full Council on this topic.',
				'<div class="afr-fl-actions">' . self::button( $benchmark, 'View your results', 'afr-ghost' ) . '</div>'
			);
		}

		// No destination needed — this one is an instruction, not a link.
		$items[] = self::item(
			'Bring this to your next Council session',
			'Add the discussion to your agenda and invite a peer who should be in the room.',
			''
		);

		$related = self::related_reports( $post_id );
		if ( $related !== '' ) {
			$items[] = self::item(
				'Explore related topics',
				'Earlier discussions on the same thread.',
				$related
			);
		}

		return self::wrap( 'Go deeper as a Council member', $items );
	}

	// ---------------------------------------------------------------- delegate

	/**
	 * Delegates see the member block, blurred, with an upgrade prompt over it.
	 * Related reports stay readable — they are links to content Delegates can
	 * already open, so locking them would be misleading.
	 */
	private static function delegate( int $post_id, array $entry ): string {
		$community = self::community_name( $entry );
		$locked    = self::wrap(
			'Access more peer intelligence on this topic',
			[
				self::item(
					'Get 1-1 support from your Membership Director',
					'Bring a question from your own work to your Membership Director, or suggest a topic for an upcoming discussion.',
					''
				),
				self::item(
					'Join an existing discussion',
					'Pick up the thread with peers already working through this.',
					''
				),
				self::item(
					'Save upcoming discussions to your calendar',
					sprintf( 'Reserve your seat for what&rsquo;s next on %s.', $community ),
					''
				),
			]
		);

		if ( $locked === '' ) {
			return '';
		}

		// The delegate treatment exists to drive upgrades, so an overlay with no
		// action defeats it — fall back to the site's own membership page.
		$upgrade = self::link( 'upgrade' ) ?: self::default_join_url();

		$overlay = '<div class="afr-gate-overlay">'
			. '<span class="afr-go-badge">Members only</span>'
			. '<div class="afr-go-title">Unlock peer access</div>'
			. '<div class="afr-go-sub">1-1 time with your Membership Director, joining discussions, and saving upcoming sessions are open to Board and Council members.</div>'
			. ( $upgrade ? self::button( $upgrade, 'Upgrade to membership', 'afr-solid' ) : '' )
			. '</div>';

		$html = '<div class="afr-gated-block">' . $locked . $overlay . '</div>';

		// Anything a Delegate can genuinely act on sits outside the lock.
		$related = self::related_reports( $post_id );
		if ( $related !== '' ) {
			$html .= self::wrap(
				'Explore related topics',
				[ self::item( 'Earlier discussions on the same thread', '', $related ) ]
			);
		}

		return $html;
	}

	// ------------------------------------------------------------------ public

	/** The public version closes with the join prompt rather than an engagement block. */
	public static function join_gate( array $entry ): string {
		$community = self::community_article( self::community_name( $entry ) );
		$join      = self::link( 'join' ) ?: self::default_join_url();

		return '<div class="afr-gate">'
			. '<p class="afr-gate-lock">Read the full report</p>'
			. '<h5 class="afr-gate-title">' . sprintf(
				/* translators: %s: community name, with article where it reads naturally */
				esc_html__( 'Join the Assemble network to read this report in full — and the rest of our peer intelligence from %s.', 'assemble-field-reports' ),
				esc_html( $community )
			) . '</h5>'
			. ( $join ? self::button( $join, 'Join the Assemble network', '', true ) : '' )
			// While the beta bypass is on, offer a way past this prompt too.
			. AFR_Bypass::button( 'View the full report anyway' )
			. '</div>';
	}

	/**
	 * "AEO Board" reads as "the AEO Board" in a sentence, but "SocialMedia.org"
	 * takes no article. Named community types get one; brand names don't.
	 */
	private static function community_article( string $name ): string {
		return preg_match( '/\b(Board|Council|Network|Community)$/', $name )
			? 'the ' . $name
			: $name;
	}

	/**
	 * A real destination for the join CTA rather than a dead link: the site's own
	 * registration page if one exists. Override with the `join` key.
	 */
	private static function default_join_url(): string {
		foreach ( [ 'register', 'join', 'membership' ] as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && $page->post_status === 'publish' ) {
				return (string) get_permalink( $page );
			}
		}

		return get_option( 'users_can_register' ) ? wp_registration_url() : '';
	}

	// ----------------------------------------------------------------- helpers

	/**
	 * Other reports sharing this one's communities, newest first, that the current
	 * visitor is actually allowed to open.
	 */
	private static function related_reports( int $post_id, int $limit = 3 ): string {
		$terms = wp_get_object_terms( $post_id, AFR_CPT::TAXONOMY, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		$posts = get_posts(
			[
				'post_type'        => AFR_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => $limit + 5,
				'post__not_in'     => [ $post_id ],
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'tax_query'        => [
					[
						'taxonomy' => AFR_CPT::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $terms,
					],
				],
			]
		);

		$rows = '';
		$used = 0;

		foreach ( $posts as $post ) {
			// Never advertise a report the visitor would be refused.
			if ( AFR_Audience::view_for( $post->ID ) === AFR_Audience::VIEW_DENIED ) {
				continue;
			}

			$rows .= '<li>'
				. self::anchor( (string) get_permalink( $post ), get_the_title( $post ) )
				. '<span class="afr-when">' . esc_html( get_the_date( 'M j, Y', $post ) ) . '</span>'
				. '</li>';

			if ( ++$used >= $limit ) {
				break;
			}
		}

		return $rows === '' ? '' : '<ul class="afr-fl-links">' . $rows . '</ul>';
	}

	/** Upcoming sessions, if any have been configured. */
	private static function upcoming(): string {
		$links = self::links();
		$items = $links['upcoming'] ?? [];

		if ( ! is_array( $items ) || ! $items ) {
			return '';
		}

		$rows = '';
		foreach ( $items as $item ) {
			$title = (string) ( $item['title'] ?? '' );
			$url   = (string) ( $item['url'] ?? '' );

			if ( $title === '' || $url === '' ) {
				continue;
			}

			$rows .= '<li>' . self::anchor( $url, $title );
			if ( ! empty( $item['when'] ) ) {
				$rows .= '<span class="afr-when">' . esc_html( (string) $item['when'] ) . '</span>';
			}
			$rows .= '</li>';
		}

		return $rows === '' ? '' : '<ul class="afr-fl-links">' . $rows . '</ul>';
	}

	private static function community_name( array $entry ): string {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );
		$name      = is_array( $community ) ? (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' ) : '';

		return $name !== '' ? $name : 'the community';
	}

	private static function item( string $heading, string $body, string $extra ): string {
		return '<li>'
			. '<div class="afr-fl-mark" aria-hidden="true">&rarr;</div>'
			. '<div class="afr-fl-body">'
			. '<h6>' . esc_html( $heading ) . '</h6>'
			. ( $body !== '' ? '<p>' . wp_kses( $body, [] ) . '</p>' : '' )
			. $extra
			. '</div>'
			. '</li>';
	}

	private static function wrap( string $title, array $items ): string {
		$items = array_filter( $items );

		if ( ! $items ) {
			return '';
		}

		return '<div class="afr-engage">'
			. '<h5 class="afr-engage-title">' . esc_html( $title ) . '</h5>'
			. '<ol class="afr-flow-list">' . implode( '', $items ) . '</ol>'
			. '</div>';
	}

	private static function button( string $url, string $label, string $variant, bool $large = false ): string {
		return sprintf(
			'<a class="%s%s" href="%s">%s</a>',
			$large ? 'afr-btn' : 'afr-btn-sm',
			$variant !== '' ? ' ' . $variant : '',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	private static function anchor( string $url, string $label ): string {
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
	}
}
