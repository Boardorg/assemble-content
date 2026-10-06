<?php
/**
 * Builds report HTML from the stored Contentful payload.
 *
 * Layout follows the reference journey document
 * (field-report-journey-aeo-technical-seo.html): community logo, italic kicker,
 * Spectral headline, author byline, then all-caps Inter section labels with the
 * authored subhead in Spectral italic beneath each. The Story in Brief renders as
 * a lead quote beside the speaker's hedcut.
 *
 * Section order follows the rendering rules in field-report-contentful-plan.md.
 * Rendering happens on every request rather than being baked into post_content,
 * because which sections a visitor gets depends on who they are.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Renderer {

	/**
	 * Community slug => brand accent token value.
	 *
	 * Colour follows content: every community in the model today is a
	 * marketing / social / data community, which is the aqua functional colour.
	 */
	/**
	 * Primary colours from the function grid in the 2026 Brand Identity
	 * Guidelines (§2.5). Colour follows content: every community in the model
	 * today is a marketing or social community, so all four map to Marketing.
	 * Add a community under a different function and give it that function's
	 * primary — do not invent a shade.
	 *
	 *   Technology #9A1B37   Finance #065E31   Mfg & Supply Chain #1D458B
	 *   Human Resources #572F8B   Marketing #00575D
	 */
	private const COMMUNITY_ACCENTS = [
		'aeo'                                      => '#00575D', // Marketing
		'socialmedia-org'                          => '#00575D',
		'socialmedia-org-health'                   => '#00575D',
		'digital-marketing-communications-council' => '#00575D',
	];

	private const ACCENT_DEFAULT = '#00575D';

	public static function init(): void {
		add_filter( 'the_content', [ self::class, 'filter_content' ], 9 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		if ( ! is_singular( AFR_CPT::POST_TYPE ) && ! is_post_type_archive( AFR_CPT::POST_TYPE ) ) {
			return;
		}

		// Spectral for display, Inter for everything else. The theme loads neither.
		wp_enqueue_style(
			'afr-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Spectral:ital,wght@0,400;0,500;0,600;1,400;1,500&display=swap',
			[],
			null
		);

		wp_enqueue_style(
			'afr-field-report',
			AFR_URL . 'assets/field-report.css',
			[ 'afr-fonts' ],
			AFR_VERSION
		);
	}

	/**
	 * Replace stored content with the audience-appropriate render.
	 *
	 * Applies to every context — singular, archive, feed — so a gated report
	 * cannot leak through a listing or an RSS item.
	 */
	public static function filter_content( string $content ): string {
		$post = get_post();

		if ( ! $post || $post->post_type !== AFR_CPT::POST_TYPE ) {
			return $content;
		}

		// Leave the editor and REST alone; this is a presentation-layer filter.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $content;
		}

		$data = self::data( $post->ID );
		if ( ! $data ) {
			return $content;
		}

		$view = AFR_Audience::view_for( $post->ID );

		// Listings and feeds get the teaser, never the body.
		if ( ! is_singular( AFR_CPT::POST_TYPE ) || ! is_main_query() ) {
			return self::teaser( $data, $view );
		}

		return AFR_Audience::preview_links( $post->ID )
			. AFR_Bypass::banner()
			. self::render( $post->ID, $data, $view );
	}

	/** The decoded Contentful payload for a post, or null. */
	public static function data( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, AFR_CPT::META_DATA, true );

		if ( ! $raw ) {
			return null;
		}

		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );

		return is_array( $data ) ? $data : null;
	}

	// ------------------------------------------------------------------- views

	/** Dispatch to the right version. */
	public static function render( int $post_id, array $entry, string $view ): string {
		$body = match ( $view ) {
			AFR_Audience::VIEW_COUNCIL  => self::render_council( $post_id, $entry ),
			AFR_Audience::VIEW_DELEGATE => self::render_standard( $post_id, $entry, AFR_Audience::VIEW_DELEGATE ),
			AFR_Audience::VIEW_STANDARD => self::render_standard( $post_id, $entry, AFR_Audience::VIEW_STANDARD ),
			AFR_Audience::VIEW_PUBLIC   => self::render_public( $entry ),
			default                     => self::render_gate( $entry ),
		};

		return self::doc( $entry, $view, $body );
	}

	/**
	 * The full report. Used verbatim for the sync's stored post_content, so it
	 * takes no post ID by default.
	 */
	public static function render_report( array $entry ): string {
		return self::doc( $entry, AFR_Audience::VIEW_STANDARD, self::render_standard( 0, $entry, AFR_Audience::VIEW_STANDARD ) );
	}

	/** Complete report, with the engagement footer for this view. */
	private static function render_standard( int $post_id, array $entry, string $view ): string {
		$note = $view === AFR_Audience::VIEW_DELEGATE
			? '<div class="afr-same-note">Delegates read the full story, charts, and key takeaways — engaging with peers and the Membership Director is open to Board and Council members.</div>'
			: '';

		return $note
			. self::masthead( $entry )
			. self::story_in_brief( $entry )
			. self::body_sections( $entry )
			. self::boilerplate( $entry )
			. ( $post_id ? AFR_Engagement::render( $post_id, $entry, $view ) : '' )
			. self::colophon( $entry );
	}

	/**
	 * Council Chairs: built for senior leaders. Story in Brief and the headline
	 * takeaways first, the full report behind an expander.
	 */
	private static function render_council( int $post_id, array $entry ): string {
		$full = self::body_sections( $entry ) . self::boilerplate( $entry );

		$expander = $full === ''
			? ''
			: '<details class="afr-expand">'
				. '<summary>Expand the full Field Report</summary>'
				. '<div class="afr-expand-body">' . $full . '</div>'
				. '</details>';

		return '<div class="afr-same-note">Built for senior leaders: start with the summary, then expand for the full Field Report.</div>'
			. self::masthead( $entry )
			. self::story_in_brief( $entry )
			. self::takeaways_at_a_glance( $entry )
			. $expander
			. AFR_Engagement::render( $post_id, $entry, AFR_Audience::VIEW_COUNCIL )
			. self::colophon( $entry );
	}

	/**
	 * Public version: a marketing kicker, the headline, and the opening of Picking
	 * Up the Story as the setup — then the join prompt. Two paragraphs, matching
	 * the reference, rather than the whole section.
	 */
	private static function render_public( array $entry ): string {
		$picking = AFR_Contentful::field( $entry, 'pickingUpBody' );
		$setup   = AFR_RichText::first_paragraphs( $picking, self::public_paragraphs() );

		// Fall back to the compressed newsletter setup if there is no rich text.
		if ( $setup === '' ) {
			$compressed = trim( (string) ( AFR_Contentful::field( $entry, 'newsletterSetup' ) ?? '' ) );
			$setup      = $compressed !== '' ? wpautop( esc_html( $compressed ) ) : '';
		}

		return self::public_kicker( $entry )
			. self::headline( $entry )
			. $setup
			. AFR_Engagement::join_gate( $entry )
			. self::colophon( $entry );
	}

	/** How many opening paragraphs the public version shows. */
	private static function public_paragraphs(): int {
		return max( 1, (int) apply_filters( 'afr_public_setup_paragraphs', 2 ) );
	}

	/** No overlap at all: headline, source line, and the member gate. */
	private static function render_gate( array $entry ): string {
		$bypass = AFR_Bypass::button();

		return self::masthead( $entry, false )
			. '<div class="afr-gate">'
			. '<p class="afr-gate-lock">Members only</p>'
			. '<h5 class="afr-gate-title">This Field Report is available to specific member audiences.</h5>'
			. '<p class="afr-gate-actions">'
			. ( is_user_logged_in()
				? ''
				: sprintf( '<a class="afr-btn" href="%s">Sign in</a>', esc_url( wp_login_url( (string) get_permalink() ) ) ) )
			. $bypass
			. '</p>'
			. ( $bypass !== ''
				? '<p class="afr-gate-note">Reviewing this site before launch? The button above shows you the complete report.</p>'
				: '' )
			. '</div>'
			. self::colophon( $entry );
	}

	/** Short teaser for archives and feeds. */
	private static function teaser( array $entry, string $view ): string {
		$setup = (string) ( AFR_Contentful::field( $entry, 'newsletterSetup' ) ?? '' );

		if ( $setup === '' ) {
			$setup = wp_trim_words( AFR_RichText::to_text( AFR_Contentful::field( $entry, 'pickingUpBody' ) ), 40 );
		}

		$html = '<p class="afr-teaser">' . esc_html( $setup ) . '</p>';

		if ( $view === AFR_Audience::VIEW_DENIED ) {
			$html .= '<p class="afr-teaser-note"><em>Members only.</em></p>';
		}

		return $html;
	}

	// ---------------------------------------------------------------- assembly

	/** Wrap a version in the branded document shell, carrying the accent. */
	private static function doc( array $entry, string $view, string $body ): string {
		return sprintf(
			'<div class="afr-doc afr-doc--%s" style="--afr-accent:%s">%s</div>',
			esc_attr( $view ),
			esc_attr( self::accent( $entry ) ),
			$body
		);
	}

	/** Public accessor so listing cards can match the report they link to. */
	public static function accent_for( array $entry ): string {
		return self::accent( $entry );
	}

	/** Community accent colour — colour follows content. */
	private static function accent( array $entry ): string {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );
		$slug      = is_array( $community ) ? sanitize_title( (string) ( AFR_Contentful::field( $community, 'slug' ) ?? '' ) ) : '';
		$accent    = self::COMMUNITY_ACCENTS[ $slug ] ?? self::ACCENT_DEFAULT;

		/**
		 * Filter the accent colour for a report.
		 *
		 * @param string $accent Hex value from the Assemble functional palette.
		 * @param string $slug   Primary community slug.
		 */
		return (string) apply_filters( 'afr_accent_color', $accent, $slug );
	}

	/** Logo, kicker, headline, byline. */
	private static function masthead( array $entry, bool $with_byline = true ): string {
		return self::community_logo( $entry )
			. self::kicker( $entry )
			. self::headline( $entry )
			. ( $with_byline ? self::byline( $entry ) : '' );
	}

	private static function community_logo( array $entry ): string {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );

		if ( ! is_array( $community ) ) {
			return '';
		}

		$logo = AFR_Contentful::asset_url( AFR_Contentful::field( $community, 'logo' ) );
		if ( $logo === '' ) {
			return '';
		}

		return sprintf(
			'<img class="afr-logo" src="%s" alt="%s" />',
			esc_url( $logo ),
			esc_attr( (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' ) )
		);
	}

	/** The source line, set as the italic kicker above the headline. */
	private static function kicker( array $entry ): string {
		$line = trim( (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' ) );

		return $line === '' ? '' : '<p class="afr-kicker">' . esc_html( $line ) . '</p>';
	}

	/**
	 * The public kicker is deliberately not the source line — the open web gets
	 * an attribution line, not the internal discussion citation.
	 */
	private static function public_kicker( array $entry ): string {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );
		$name      = is_array( $community ) ? (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' ) : '';

		if ( $name === '' ) {
			return '';
		}

		$when = self::report_month( $entry );

		return '<p class="afr-kicker">' . esc_html(
			$when !== ''
				? sprintf( 'Insight from Assemble’s %s · %s', $name, $when )
				: sprintf( 'Insight from Assemble’s %s', $name )
		) . '</p>';
	}

	private static function headline( array $entry ): string {
		$headline = trim( (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? '' ) );

		if ( $headline === '' ) {
			return '';
		}

		/**
		 * The report prints its own headline, and the CSS hides the theme's H1 to
		 * avoid a duplicate. Return false here if a theme template should own it.
		 */
		if ( ! apply_filters( 'afr_render_own_headline', true ) ) {
			return '';
		}

		return '<h1 class="afr-headline">' . esc_html( $headline ) . '</h1>';
	}

	private static function byline( array $entry ): string {
		$author = AFR_Contentful::field( $entry, 'author' );

		if ( ! is_array( $author ) ) {
			return '';
		}

		$name = trim( (string) ( AFR_Contentful::field( $author, 'name' ) ?? '' ) );
		if ( $name === '' ) {
			return '';
		}

		$role  = trim( (string) ( AFR_Contentful::field( $author, 'role' ) ?? '' ) );
		$date  = self::report_date( $entry );
		$meta  = implode( ' · ', array_filter( [ $role, $date ] ) );
		$image = self::person_image( $author );

		return '<div class="afr-byline">'
			. ( $image ? sprintf( '<div class="afr-byline-ava"><img src="%s" alt="%s" loading="lazy" /></div>', esc_url( $image ), esc_attr( $name ) ) : '' )
			. '<div class="afr-byline-text">'
			. '<span class="afr-byline-name">' . esc_html( 'By ' . $name ) . '</span>'
			. ( $meta !== '' ? '<span class="afr-byline-meta">' . esc_html( $meta ) . '</span>' : '' )
			. '</div></div>';
	}

	// ---------------------------------------------------------------- sections

	/** The Story in Brief — each MD take as a lead quote beside its speaker. */
	private static function story_in_brief( array $entry ): string {
		$takes = AFR_Contentful::field( $entry, 'mdTakes', [] );

		if ( ! is_array( $takes ) || ! $takes ) {
			return '';
		}

		$blocks = '';
		foreach ( $takes as $take ) {
			if ( ! is_array( $take ) ) {
				continue;
			}

			$body = trim( (string) ( AFR_Contentful::field( $take, 'fullTake' ) ?? '' ) );
			if ( $body === '' ) {
				continue;
			}

			// Quoted in the reference layout; don't double up if already quoted.
			$quoted = preg_match( '/^["\x{201C}]/u', $body ) ? $body : '“' . $body . '”';
			$quote  = '<p class="afr-lead-quote">' . esc_html( $quoted ) . '</p>';
			$person = self::speaker_figure( AFR_Contentful::field( $take, 'speaker' ) );

			$blocks .= $person === ''
				? $quote
				: '<div class="afr-quote-row">' . $person . $quote . '</div>';
		}

		return $blocks === '' ? '' : self::label( 'The Story in Brief' ) . $blocks;
	}

	/** Hedcut, name and title in the narrow column beside a lead quote. */
	private static function speaker_figure( $person ): string {
		if ( ! is_array( $person ) ) {
			return '';
		}

		$name = trim( (string) ( AFR_Contentful::field( $person, 'name' ) ?? '' ) );
		if ( $name === '' ) {
			return '';
		}

		$role  = trim( (string) ( AFR_Contentful::field( $person, 'role' ) ?? '' ) );
		$org   = trim( (string) ( AFR_Contentful::field( $person, 'organization' ) ?? '' ) );
		$image = self::person_image( $person );
		$title = implode( ', ', array_filter( [ $role, $org ] ) );

		return '<figure class="afr-qr-person">'
			. ( $image ? sprintf( '<div class="afr-qr-ava"><img src="%s" alt="%s" loading="lazy" /></div>', esc_url( $image ), esc_attr( $name ) ) : '' )
			. '<figcaption class="afr-qr-cap">'
			. '<span class="afr-qr-name">' . esc_html( $name ) . '</span>'
			. ( $title !== '' ? '<span class="afr-qr-title">' . esc_html( $title ) . '</span>' : '' )
			. '</figcaption></figure>';
	}

	/** Picking Up the Story, pullquote, charts, Focus, Key Takeaways. */
	private static function body_sections( array $entry ): string {
		return self::labelled_richtext( 'Picking Up the Story', 'pickingUpSubhead', 'pickingUpBody', $entry )
			. self::pullquote( $entry )
			. self::charts( $entry )
			. self::labelled_richtext( 'Focus of the Discussion', 'focusSubhead', 'focusBody', $entry )
			. self::takeaways( $entry );
	}

	/** Label, then the authored subhead in Spectral italic, then the body. */
	private static function labelled_richtext( string $label, string $subhead_field, string $body_field, array $entry ): string {
		$body = AFR_RichText::to_html( AFR_Contentful::field( $entry, $body_field ) );

		if ( $body === '' ) {
			return '';
		}

		$subhead = trim( (string) ( AFR_Contentful::field( $entry, $subhead_field ) ?? '' ) );

		return self::label( $label )
			. ( $subhead !== '' ? '<p class="afr-sub-italic">' . esc_html( $subhead ) . '</p>' : '' )
			. $body;
	}

	private static function pullquote( array $entry ): string {
		$quote = trim( (string) ( AFR_Contentful::field( $entry, 'pullquote' ) ?? '' ) );

		if ( $quote === '' ) {
			return '';
		}

		// Anonymous by design — the model has no attribution field.
		$quoted = preg_match( '/^["\x{201C}]/u', $quote ) ? $quote : '“' . $quote . '”';

		return '<p class="afr-lead-quote">' . esc_html( $quoted ) . '</p>';
	}

	private static function charts( array $entry ): string {
		$charts = AFR_Contentful::field( $entry, 'charts', [] );

		if ( ! is_array( $charts ) || ! $charts ) {
			return '';
		}

		$figures = '';
		foreach ( $charts as $chart ) {
			$url = AFR_Contentful::asset_url( is_array( $chart ) ? $chart : null );
			if ( $url === '' ) {
				continue;
			}

			$title = (string) ( $chart['fields']['title'] ?? '' );
			$desc  = (string) ( $chart['fields']['description'] ?? '' );

			$figures .= sprintf(
				'<figure class="afr-chart"><img src="%s" alt="%s" loading="lazy" />%s</figure>',
				esc_url( $url ),
				esc_attr( $desc !== '' ? $desc : $title ),
				$title !== '' ? '<figcaption>' . esc_html( $title ) . '</figcaption>' : ''
			);
		}

		return $figures === '' ? '' : '<div class="afr-charts">' . $figures . '</div>';
	}

	/** Numbered takeaways with the accent numeral and bold action lead. */
	private static function takeaways( array $entry ): string {
		$body = AFR_RichText::to_html(
			AFR_Contentful::field( $entry, 'takeawaysStandard' ),
			[ 'ol_class' => 'afr-takeaways' ]
		);

		return $body === '' ? '' : self::label( 'Key Takeaways' ) . $body;
	}

	/** Council Chair at-a-glance list, one headline per line. */
	private static function takeaways_at_a_glance( array $entry ): string {
		$short = (string) ( AFR_Contentful::field( $entry, 'takeawaysShort' ) ?? '' );
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $short ) ?: [] ) ) );

		// Fall back to the lead sentences of the standard takeaways.
		if ( ! $lines ) {
			$lines = AFR_RichText::list_items( AFR_Contentful::field( $entry, 'takeawaysStandard' ) );
			$lines = array_map(
				static fn( $line ) => preg_match( '/^(.+?[.!?])(\s|$)/u', $line, $m ) ? $m[1] : $line,
				$lines
			);
		}

		if ( ! $lines ) {
			return '';
		}

		$items = '';
		foreach ( $lines as $line ) {
			// Strip any hand-typed numbering; the counter supplies it.
			$items .= '<li>' . esc_html( (string) preg_replace( '/^\s*\d+[.)]\s*/', '', $line ) ) . '</li>';
		}

		return self::label( 'Key Takeaways at a Glance' ) . '<ol class="afr-tk-short">' . $items . '</ol>';
	}

	/** About the community, About the author. */
	private static function boilerplate( array $entry ): string {
		return self::about_community( $entry ) . self::about_author( $entry );
	}

	private static function about_community( array $entry ): string {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );

		if ( ! is_array( $community ) ) {
			return '';
		}

		$boilerplate = trim( (string) ( AFR_Contentful::field( $community, 'boilerplate' ) ?? '' ) );
		if ( $boilerplate === '' ) {
			return '';
		}

		$name = (string) ( AFR_Contentful::field( $community, 'name' ) ?? 'the Community' );

		return self::label( sprintf( 'About %s and Assemble', $name ) )
			. self::boiler_paragraphs( $boilerplate );
	}

	private static function about_author( array $entry ): string {
		$author = AFR_Contentful::field( $entry, 'author' );

		if ( ! is_array( $author ) ) {
			return '';
		}

		$name = trim( (string) ( AFR_Contentful::field( $author, 'name' ) ?? '' ) );
		if ( $name === '' ) {
			return '';
		}

		$role  = trim( (string) ( AFR_Contentful::field( $author, 'role' ) ?? '' ) );
		$bio   = trim( (string) ( AFR_Contentful::field( $author, 'biography' ) ?? '' ) );
		$image = self::person_image( $author );

		if ( $bio === '' && $image === '' ) {
			return '';
		}

		return self::label( 'About the Author — ' . implode( ', ', array_filter( [ $name, $role ] ) ) )
			. '<div class="afr-author-card">'
			. ( $image ? sprintf( '<div class="afr-au-ava"><img src="%s" alt="%s" loading="lazy" /></div>', esc_url( $image ), esc_attr( $name ) ) : '' )
			. self::boiler_paragraphs( $bio )
			. '</div>';
	}

	/** The Assemble mark alongside the community mark, on black. */
	private static function colophon( array $entry ): string {
		$mark = self::assemble_mark();

		if ( $mark === '' ) {
			return '';
		}

		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );
		$logo      = is_array( $community ) ? AFR_Contentful::asset_url( AFR_Contentful::field( $community, 'logo' ) ) : '';
		$name      = is_array( $community ) ? (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' ) : '';

		return '<div class="afr-colophon">'
			. '<span class="afr-assemble-mark">' . $mark . '</span>'
			. ( $logo
				? '<span class="afr-fam-sep" aria-hidden="true"></span>'
					. sprintf( '<img class="afr-fam-mark" src="%s" alt="%s" loading="lazy" />', esc_url( $logo ), esc_attr( $name ) )
				: '' )
			. '</div>';
	}

	/** Inlined so the mark can inherit currentColor. Read once per request. */
	private static function assemble_mark(): string {
		static $svg = null;

		if ( $svg === null ) {
			$path = AFR_DIR . 'assets/assemble-mark.svg';
			$raw  = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
			$svg  = str_starts_with( trim( $raw ), '<svg' ) ? trim( $raw ) : '';
		}

		return $svg;
	}

	// ----------------------------------------------------------------- helpers

	private static function label( string $text ): string {
		return '<p class="afr-sub">' . esc_html( $text ) . '</p>';
	}

	private static function boiler_paragraphs( string $text ): string {
		if ( trim( $text ) === '' ) {
			return '';
		}

		return str_replace( '<p>', '<p class="afr-boiler">', wpautop( esc_html( $text ) ) );
	}

	/** Hedcut is the editorial illustration and is preferred; headshot is the photo. */
	private static function person_image( $person ): string {
		if ( ! is_array( $person ) ) {
			return '';
		}

		return AFR_Contentful::asset_url( AFR_Contentful::field( $person, 'hedcut' ) )
			?: AFR_Contentful::asset_url( AFR_Contentful::field( $person, 'headshot' ) );
	}

	/** Publication date for the byline, from the source line where parseable. */
	private static function report_date( array $entry ): string {
		$ts = self::report_timestamp( $entry );

		return $ts ? gmdate( 'F j, Y', $ts ) : '';
	}

	private static function report_month( array $entry ): string {
		$ts = self::report_timestamp( $entry );

		return $ts ? gmdate( 'F Y', $ts ) : '';
	}

	private static function report_timestamp( array $entry ): int {
		$source = (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' );

		if ( preg_match( '/\b([A-Z][a-z]+ \d{1,2},? \d{4})\b/', $source, $m ) ) {
			$ts = strtotime( $m[1] );
			if ( $ts ) {
				return $ts;
			}
		}

		$created = (string) ( $entry['sys']['createdAt'] ?? '' );
		$ts      = $created ? strtotime( $created ) : false;

		return $ts ?: 0;
	}
}
