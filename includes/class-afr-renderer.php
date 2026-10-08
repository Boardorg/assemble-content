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
 *
 * Two outputs share one gate. `sections()` decides, per view, which sections a
 * visitor may have (VIEW_SECTIONS) and returns them as data. `view_model()` hands
 * that data to a theme that renders its own markup; `render()` formats the same
 * data as the plugin's own `.afr-doc` document. A theme can only print what the
 * view model contains, so it can't leak a section the view doesn't allow.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Renderer {

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

	/**
	 * The gate: which sections each view may have. Everything a visitor can be
	 * shown is listed here, and nowhere else.
	 *
	 * Every view gets the report's identity (headline, community, topics, date,
	 * teaser, featured image), which listings and feeds already show to anyone. The body sections
	 * (story_in_brief … author) are members only. The public view gets an
	 * attribution line, the opening paragraphs and the join prompt; the denied
	 * view gets the source line and the gate.
	 */
	private const VIEW_SECTIONS = [
		AFR_Audience::VIEW_STANDARD => [ 'headline', 'kicker', 'topics', 'dek', 'date', 'image', 'source_line', 'byline', 'story_in_brief', 'picking_up', 'pullquote', 'charts', 'focus', 'takeaways', 'community', 'author', 'engagement' ],
		AFR_Audience::VIEW_DELEGATE => [ 'headline', 'kicker', 'topics', 'dek', 'date', 'image', 'source_line', 'byline', 'note', 'story_in_brief', 'picking_up', 'pullquote', 'charts', 'focus', 'takeaways', 'community', 'author', 'engagement' ],
		AFR_Audience::VIEW_COUNCIL  => [ 'headline', 'kicker', 'topics', 'dek', 'date', 'image', 'source_line', 'byline', 'note', 'story_in_brief', 'glance', 'picking_up', 'pullquote', 'charts', 'focus', 'takeaways', 'community', 'author', 'engagement' ],
		AFR_Audience::VIEW_PUBLIC   => [ 'headline', 'kicker', 'topics', 'dek', 'date', 'image', 'attribution', 'setup', 'join' ],
		AFR_Audience::VIEW_DENIED   => [ 'headline', 'kicker', 'topics', 'dek', 'date', 'image', 'source_line', 'gate' ],
	];

	/** Sections counted towards the reading time. */
	private const READING_SECTIONS = [ 'story_in_brief', 'picking_up', 'pullquote', 'focus', 'takeaways', 'setup' ];

	public static function init(): void {
		add_filter( 'the_content', [ self::class, 'filter_content' ], 9 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		if ( ! is_singular( AFR_CPT::POST_TYPE ) && ! is_post_type_archive( AFR_CPT::POST_TYPE ) ) {
			return;
		}

		// A theme that renders the report itself doesn't want the document's styles.
		if ( is_singular( AFR_CPT::POST_TYPE ) && ! self::filters_content( get_queried_object_id() ) ) {
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
	 * Does the plugin print its own document for this report's single page?
	 *
	 * A theme that renders reports from view_model() returns false from the
	 * `afr_filter_the_content` filter. Listings, feeds and the stored
	 * post_content are not affected: they always get the teaser.
	 */
	public static function filters_content( int $post_id ): bool {
		/**
		 * Filter whether the plugin renders a report's single page through `the_content`.
		 *
		 * @param bool $filter  Default true.
		 * @param int  $post_id The report.
		 */
		return (bool) apply_filters( 'afr_filter_the_content', true, $post_id );
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

		// The theme renders this page from view_model(). If anything still calls
		// the_content here, it gets the teaser: never the stored body.
		if ( ! self::filters_content( $post->ID ) ) {
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

	// -------------------------------------------------------------- view model

	/**
	 * Everything a theme needs to render one report for the current visitor, and
	 * nothing the visitor may not see.
	 *
	 * - `view`: standard, council, delegate, public or denied.
	 * - `sections`: only the sections VIEW_SECTIONS allows for that view, each
	 *   raw data or plugin-rendered Rich Text HTML. Empty sections are left out.
	 * - `words`: word count of the readable sections, for a reading time.
	 * - `notices`: the admin preview switcher and the bypass banner, as HTML
	 *   (empty strings for most visitors).
	 *
	 * Section shapes:
	 *   headline       string
	 *   kicker         { name, short_name, slug, practice_area, logo: asset|null, logo_url }
	 *   topics         [ { name, slug } ]
	 *   dek            string (newsletterSetup, else the first 40 words of Picking Up)
	 *   date           { timestamp, iso, display, month }
	 *   image          asset (featuredImage)
	 *   source_line    string
	 *   attribution    string (public view's kicker, instead of the source line)
	 *   byline         person
	 *   note           string (delegate and council)
	 *   story_in_brief [ { quote, speaker: person|null } ]
	 *   glance         [ string ] (council)
	 *   picking_up     { label, subhead, html }
	 *   pullquote      string
	 *   charts         [ { asset, url, caption, alt } ]
	 *   focus          { label, subhead, html }
	 *   takeaways      { label, html }
	 *   community      { name, boilerplate }
	 *   author         person, with bio
	 *   engagement     [ block ] (see AFR_Engagement::data())
	 *   setup          HTML (public: the opening paragraphs)
	 *   join           { url, community, bypass: button|null } (public)
	 *   gate           { sign_in_url, bypass: button|null } (denied)
	 *
	 *   person = { name, role, organization, bio, image: asset|null, image_url }
	 *   button = { url, label }
	 *
	 * @return array{view:string,post_id:int,sections:array<string,mixed>,words:int,notices:array{preview:string,bypass:string}}
	 */
	public static function view_model( int $post_id ): array {
		$entry = self::data( $post_id );

		if ( ! $entry ) {
			return [
				'view'     => '',
				'post_id'  => $post_id,
				'sections' => [],
				'words'    => 0,
				'notices'  => [ 'preview' => '', 'bypass' => '' ],
			];
		}

		$view     = AFR_Audience::view_for( $post_id );
		$sections = self::sections( $post_id, $entry, $view );

		return [
			'view'     => $view,
			'post_id'  => $post_id,
			'sections' => $sections,
			'words'    => self::words( $sections ),
			'notices'  => [
				'preview' => AFR_Audience::preview_links( $post_id ),
				'bypass'  => AFR_Bypass::banner(),
			],
		];
	}

	/** Section keys a view may show. Unknown views get the denied set. */
	public static function view_sections( string $view ): array {
		return self::VIEW_SECTIONS[ $view ] ?? self::VIEW_SECTIONS[ AFR_Audience::VIEW_DENIED ];
	}

	/**
	 * The sections a view may show, built from the entry. Nothing outside
	 * view_sections( $view ) is ever built.
	 *
	 * @return array<string,mixed>
	 */
	public static function sections( int $post_id, array $entry, string $view ): array {
		$sections = [];

		foreach ( self::view_sections( $view ) as $key ) {
			$value = self::section( $key, $post_id, $entry, $view );

			if ( $value !== null && $value !== '' && $value !== [] ) {
				$sections[ $key ] = $value;
			}
		}

		return $sections;
	}

	/** One section's data, or null. */
	private static function section( string $key, int $post_id, array $entry, string $view ) {
		return match ( $key ) {
			'headline'       => trim( (string) ( AFR_Contentful::field( $entry, 'headline' ) ?? '' ) ),
			'kicker'         => self::community_data( $entry ),
			'topics'         => AFR_Query::entry_topics( $entry ),
			'dek'            => self::dek( $entry ),
			'date'           => self::date_data( $entry ),
			'image'          => self::featured_image( $entry ),
			'source_line'    => trim( (string) ( AFR_Contentful::field( $entry, 'sourceLine' ) ?? '' ) ),
			'attribution'    => self::attribution( $entry ),
			'byline'         => self::person( AFR_Contentful::field( $entry, 'author' ) ),
			'note'           => self::note( $view ),
			'story_in_brief' => self::takes( $entry ),
			'glance'         => self::glance( $entry ),
			'picking_up'     => self::richtext_section( 'Picking Up the Story', 'pickingUpSubhead', 'pickingUpBody', $entry ),
			'pullquote'      => trim( (string) ( AFR_Contentful::field( $entry, 'pullquote' ) ?? '' ) ),
			'charts'         => self::chart_data( $entry ),
			'focus'          => self::richtext_section( 'Focus of the Discussion', 'focusSubhead', 'focusBody', $entry ),
			'takeaways'      => self::takeaways_data( $entry ),
			'community'      => self::community_about( $entry ),
			'author'         => self::author_data( $entry ),
			'engagement'     => $post_id ? AFR_Engagement::data( $post_id, $entry, $view ) : null,
			'setup'          => self::setup( $entry ),
			'join'           => AFR_Engagement::join_data( $entry ),
			'gate'           => self::gate_data(),
			default          => null,
		};
	}

	/** Words in the sections a reader reads. */
	private static function words( array $sections ): int {
		$text = [];

		foreach ( self::READING_SECTIONS as $key ) {
			$value = $sections[ $key ] ?? null;

			if ( is_string( $value ) ) {
				$text[] = $value;
			} elseif ( $key === 'story_in_brief' && is_array( $value ) ) {
				$text = array_merge( $text, array_column( $value, 'quote' ) );
			} elseif ( is_array( $value ) ) {
				$text[] = (string) ( $value['subhead'] ?? '' ) . ' ' . (string) ( $value['html'] ?? '' );
			}
		}

		$plain = html_entity_decode( wp_strip_all_tags( implode( ' ', $text ) ), ENT_QUOTES, 'UTF-8' );

		return count( preg_split( '/\s+/u', trim( $plain ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
	}

	// ------------------------------------------------------------ section data

	/** The primary community: name, slug, practice area and logo. */
	private static function community_data( array $entry ): ?array {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );

		if ( ! is_array( $community ) ) {
			return null;
		}

		$name = (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' );
		$logo = AFR_Contentful::field( $community, 'logo' );

		return [
			'name'          => $name,
			'short_name'    => (string) ( AFR_Contentful::field( $community, 'shortName' ) ?? '' ),
			'slug'          => sanitize_title( (string) ( AFR_Contentful::field( $community, 'slug' ) ?? '' ) ),
			'practice_area' => (string) ( AFR_Contentful::field( $community, 'practiceArea' ) ?? '' ),
			'logo'          => is_array( $logo ) ? $logo : null,
			'logo_url'      => AFR_Contentful::asset_url( is_array( $logo ) ? $logo : null ),
		];
	}

	/** The teaser: the same text listings and feeds show. */
	private static function dek( array $entry ): string {
		$setup = trim( (string) ( AFR_Contentful::field( $entry, 'newsletterSetup' ) ?? '' ) );

		return $setup !== '' ? $setup : wp_trim_words( AFR_RichText::to_text( AFR_Contentful::field( $entry, 'pickingUpBody' ) ), 40 );
	}

	/** The featured image asset, when it has a file. */
	private static function featured_image( array $entry ): ?array {
		$asset = AFR_Contentful::field( $entry, 'featuredImage' );

		return is_array( $asset ) && AFR_Contentful::asset_url( $asset ) !== '' ? $asset : null;
	}

	private static function date_data( array $entry ): ?array {
		$ts = self::report_timestamp( $entry );

		return $ts ? [
			'timestamp' => $ts,
			'iso'       => gmdate( 'Y-m-d', $ts ),
			'display'   => gmdate( 'F j, Y', $ts ),
			'month'     => gmdate( 'F Y', $ts ),
		] : null;
	}

	/**
	 * The public kicker is deliberately not the source line — the open web gets
	 * an attribution line, not the internal discussion citation.
	 */
	private static function attribution( array $entry ): string {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );
		$name      = is_array( $community ) ? (string) ( AFR_Contentful::field( $community, 'name' ) ?? '' ) : '';

		if ( $name === '' ) {
			return '';
		}

		$when = self::report_month( $entry );

		return $when !== ''
			? sprintf( 'Insight from Assemble’s %s · %s', $name, $when )
			: sprintf( 'Insight from Assemble’s %s', $name );
	}

	/** A person, or null without a name. */
	private static function person( $person ): ?array {
		if ( ! is_array( $person ) ) {
			return null;
		}

		$name = trim( (string) ( AFR_Contentful::field( $person, 'name' ) ?? '' ) );
		if ( $name === '' ) {
			return null;
		}

		// Hedcut is the editorial illustration and is preferred; headshot is the photo.
		$image = null;
		foreach ( [ 'hedcut', 'headshot' ] as $field ) {
			$asset = AFR_Contentful::field( $person, $field );
			if ( is_array( $asset ) && AFR_Contentful::asset_url( $asset ) !== '' ) {
				$image = $asset;
				break;
			}
		}

		return [
			'name'         => $name,
			'role'         => trim( (string) ( AFR_Contentful::field( $person, 'role' ) ?? '' ) ),
			'organization' => trim( (string) ( AFR_Contentful::field( $person, 'organization' ) ?? '' ) ),
			'bio'          => trim( (string) ( AFR_Contentful::field( $person, 'biography' ) ?? '' ) ),
			'image'        => $image,
			'image_url'    => AFR_Contentful::asset_url( $image ),
		];
	}

	private static function note( string $view ): string {
		return match ( $view ) {
			AFR_Audience::VIEW_DELEGATE => 'Delegates read the full story, charts, and key takeaways — engaging with peers and the Membership Director is open to Board and Council members.',
			AFR_Audience::VIEW_COUNCIL  => 'Built for senior leaders: start with the summary, then expand for the full Field Report.',
			default                     => '',
		};
	}

	/** The Story in Brief: each MD take with its speaker. */
	private static function takes( array $entry ): array {
		$takes = AFR_Contentful::field( $entry, 'mdTakes', [] );
		$out   = [];

		foreach ( is_array( $takes ) ? $takes : [] as $take ) {
			if ( ! is_array( $take ) ) {
				continue;
			}

			$quote = trim( (string) ( AFR_Contentful::field( $take, 'fullTake' ) ?? '' ) );
			if ( $quote === '' ) {
				continue;
			}

			$out[] = [
				'quote'   => $quote,
				'speaker' => self::person( AFR_Contentful::field( $take, 'speaker' ) ),
			];
		}

		return $out;
	}

	/** Council Chair at-a-glance list, one headline per line. */
	private static function glance( array $entry ): array {
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

		// Strip any hand-typed numbering; the list supplies it.
		return array_map( static fn( $line ) => (string) preg_replace( '/^\s*\d+[.)]\s*/', '', (string) $line ), $lines );
	}

	/** A labelled Rich Text section, or null when the body is empty. */
	private static function richtext_section( string $label, string $subhead_field, string $body_field, array $entry ): ?array {
		$html = AFR_RichText::to_html( AFR_Contentful::field( $entry, $body_field ) );

		if ( $html === '' ) {
			return null;
		}

		return [
			'label'   => $label,
			'subhead' => trim( (string) ( AFR_Contentful::field( $entry, $subhead_field ) ?? '' ) ),
			'html'    => $html,
		];
	}

	/** Charts: asset title is the caption, description the alt text. */
	private static function chart_data( array $entry ): array {
		$charts = AFR_Contentful::field( $entry, 'charts', [] );
		$out    = [];

		foreach ( is_array( $charts ) ? $charts : [] as $chart ) {
			$url = AFR_Contentful::asset_url( is_array( $chart ) ? $chart : null );
			if ( $url === '' ) {
				continue;
			}

			$title = (string) ( $chart['fields']['title'] ?? '' );
			$desc  = (string) ( $chart['fields']['description'] ?? '' );

			$out[] = [
				'asset'   => $chart,
				'url'     => $url,
				'caption' => $title,
				'alt'     => $desc !== '' ? $desc : $title,
			];
		}

		return $out;
	}

	private static function takeaways_data( array $entry ): ?array {
		$html = AFR_RichText::to_html( AFR_Contentful::field( $entry, 'takeawaysStandard' ) );

		return $html === '' ? null : [ 'label' => 'Key Takeaways', 'html' => $html ];
	}

	/** "About {Community} and Assemble", when the community has boilerplate. */
	private static function community_about( array $entry ): ?array {
		$community = AFR_Contentful::field( $entry, 'primaryCommunity' );

		if ( ! is_array( $community ) ) {
			return null;
		}

		$boilerplate = trim( (string) ( AFR_Contentful::field( $community, 'boilerplate' ) ?? '' ) );
		if ( $boilerplate === '' ) {
			return null;
		}

		return [
			'name'        => (string) ( AFR_Contentful::field( $community, 'name' ) ?? 'the Community' ),
			'boilerplate' => $boilerplate,
		];
	}

	/** About the author: only when there's a bio or a picture to show. */
	private static function author_data( array $entry ): ?array {
		$author = self::person( AFR_Contentful::field( $entry, 'author' ) );

		return $author && ( $author['bio'] !== '' || $author['image_url'] !== '' ) ? $author : null;
	}

	/**
	 * The public version's setup: the opening of Picking Up the Story. Two
	 * paragraphs, matching the reference, rather than the whole section.
	 */
	private static function setup( array $entry ): string {
		$setup = AFR_RichText::first_paragraphs( AFR_Contentful::field( $entry, 'pickingUpBody' ), self::public_paragraphs() );

		// Fall back to the compressed newsletter setup if there is no rich text.
		if ( $setup === '' ) {
			$compressed = trim( (string) ( AFR_Contentful::field( $entry, 'newsletterSetup' ) ?? '' ) );
			$setup      = $compressed !== '' ? wpautop( esc_html( $compressed ) ) : '';
		}

		return $setup;
	}

	/** How many opening paragraphs the public version shows. */
	private static function public_paragraphs(): int {
		return max( 1, (int) apply_filters( 'afr_public_setup_paragraphs', 2 ) );
	}

	/** The denied view's gate: sign in (when logged out) and the beta bypass. */
	private static function gate_data(): array {
		return [
			'sign_in_url' => is_user_logged_in() ? '' : wp_login_url( (string) get_permalink() ),
			'bypass'      => AFR_Bypass::button_data(),
		];
	}

	// ------------------------------------------------------------------- views

	/** Dispatch to the right version. */
	public static function render( int $post_id, array $entry, string $view ): string {
		$s = self::sections( $post_id, $entry, $view );

		$body = match ( $view ) {
			AFR_Audience::VIEW_COUNCIL  => self::render_council( $post_id, $s ),
			AFR_Audience::VIEW_DELEGATE => self::render_standard( $post_id, $s, AFR_Audience::VIEW_DELEGATE ),
			AFR_Audience::VIEW_STANDARD => self::render_standard( $post_id, $s, AFR_Audience::VIEW_STANDARD ),
			AFR_Audience::VIEW_PUBLIC   => self::render_public( $s ),
			default                     => self::render_gate( $s ),
		};

		return self::doc( $entry, $view, $body );
	}

	/**
	 * The full report. Used verbatim for the sync's stored post_content, so it
	 * takes no post ID by default.
	 */
	public static function render_report( array $entry ): string {
		$s = self::sections( 0, $entry, AFR_Audience::VIEW_STANDARD );

		return self::doc( $entry, AFR_Audience::VIEW_STANDARD, self::render_standard( 0, $s, AFR_Audience::VIEW_STANDARD ) );
	}

	/** Complete report, with the engagement footer for this view. */
	private static function render_standard( int $post_id, array $s, string $view ): string {
		return self::note_html( $s )
			. self::masthead( $s )
			. self::story_in_brief( $s )
			. self::body_sections( $s )
			. self::boilerplate( $s )
			. self::engagement( $post_id, $s, $view )
			. self::colophon( $s );
	}

	/**
	 * Council Chairs: built for senior leaders. Story in Brief and the headline
	 * takeaways first, the full report behind an expander.
	 */
	private static function render_council( int $post_id, array $s ): string {
		$full = self::body_sections( $s ) . self::boilerplate( $s );

		$expander = $full === ''
			? ''
			: '<details class="afr-expand">'
				. '<summary>Expand the full Field Report</summary>'
				. '<div class="afr-expand-body">' . $full . '</div>'
				. '</details>';

		return self::note_html( $s )
			. self::masthead( $s )
			. self::story_in_brief( $s )
			. self::takeaways_at_a_glance( $s )
			. $expander
			. self::engagement( $post_id, $s, AFR_Audience::VIEW_COUNCIL )
			. self::colophon( $s );
	}

	/**
	 * Public version: a marketing kicker, the headline, and the opening of Picking
	 * Up the Story as the setup — then the join prompt.
	 */
	private static function render_public( array $s ): string {
		return ( isset( $s['attribution'] ) ? '<p class="afr-kicker">' . esc_html( $s['attribution'] ) . '</p>' : '' )
			. self::headline( $s )
			. ( $s['setup'] ?? '' )
			. AFR_Engagement::join_html( $s['join'] ?? [] )
			. self::colophon( $s );
	}

	/** No overlap at all: headline, source line, and the member gate. */
	private static function render_gate( array $s ): string {
		$gate   = $s['gate'] ?? [];
		$bypass = AFR_Bypass::button_html( $gate['bypass'] ?? null );

		return self::masthead( $s )
			. '<div class="afr-gate">'
			. '<p class="afr-gate-lock">Members only</p>'
			. '<h5 class="afr-gate-title">This Field Report is available to specific member audiences.</h5>'
			. '<p class="afr-gate-actions">'
			. ( ! empty( $gate['sign_in_url'] )
				? sprintf( '<a class="afr-btn" href="%s">Sign in</a>', esc_url( $gate['sign_in_url'] ) )
				: '' )
			. $bypass
			. '</p>'
			. ( $bypass !== ''
				? '<p class="afr-gate-note">Reviewing this site before launch? The button above shows you the complete report.</p>'
				: '' )
			. '</div>'
			. self::colophon( $s );
	}

	/** Short teaser for archives and feeds. */
	private static function teaser( array $entry, string $view ): string {
		$html = '<p class="afr-teaser">' . esc_html( self::dek( $entry ) ) . '</p>';

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

	private static function note_html( array $s ): string {
		return isset( $s['note'] ) ? '<div class="afr-same-note">' . $s['note'] . '</div>' : '';
	}

	/** Logo, kicker, headline, byline (the byline is only in views that allow it). */
	private static function masthead( array $s ): string {
		return self::community_logo( $s )
			. ( isset( $s['source_line'] ) ? '<p class="afr-kicker">' . esc_html( $s['source_line'] ) . '</p>' : '' )
			. self::headline( $s )
			. self::byline( $s );
	}

	private static function community_logo( array $s ): string {
		$kicker = $s['kicker'] ?? null;

		if ( ! $kicker || $kicker['logo_url'] === '' ) {
			return '';
		}

		return sprintf(
			'<img class="afr-logo" src="%s" alt="%s" />',
			esc_url( $kicker['logo_url'] ),
			esc_attr( $kicker['name'] )
		);
	}

	private static function headline( array $s ): string {
		if ( ! isset( $s['headline'] ) ) {
			return '';
		}

		/**
		 * The report prints its own headline, and the CSS hides the theme's H1 to
		 * avoid a duplicate. Return false here if a theme template should own it.
		 */
		if ( ! apply_filters( 'afr_render_own_headline', true ) ) {
			return '';
		}

		return '<h1 class="afr-headline">' . esc_html( $s['headline'] ) . '</h1>';
	}

	private static function byline( array $s ): string {
		$author = $s['byline'] ?? null;

		if ( ! $author ) {
			return '';
		}

		$meta = implode( ' · ', array_filter( [ $author['role'], $s['date']['display'] ?? '' ] ) );

		return '<div class="afr-byline">'
			. ( $author['image_url'] ? sprintf( '<div class="afr-byline-ava"><img src="%s" alt="%s" loading="lazy" /></div>', esc_url( $author['image_url'] ), esc_attr( $author['name'] ) ) : '' )
			. '<div class="afr-byline-text">'
			. '<span class="afr-byline-name">' . esc_html( 'By ' . $author['name'] ) . '</span>'
			. ( $meta !== '' ? '<span class="afr-byline-meta">' . esc_html( $meta ) . '</span>' : '' )
			. '</div></div>';
	}

	// ---------------------------------------------------------------- sections

	/** Wrap text in curly quotes unless it already starts with one. */
	public static function quoted( string $text ): string {
		return preg_match( '/^["\x{201C}]/u', $text ) ? $text : '“' . $text . '”';
	}

	/** The Story in Brief — each MD take as a lead quote beside its speaker. */
	private static function story_in_brief( array $s ): string {
		$blocks = '';

		foreach ( $s['story_in_brief'] ?? [] as $take ) {
			$quote  = '<p class="afr-lead-quote">' . esc_html( self::quoted( $take['quote'] ) ) . '</p>';
			$person = self::speaker_figure( $take['speaker'] );

			$blocks .= $person === ''
				? $quote
				: '<div class="afr-quote-row">' . $person . $quote . '</div>';
		}

		return $blocks === '' ? '' : self::label( 'The Story in Brief' ) . $blocks;
	}

	/** Hedcut, name and title in the narrow column beside a lead quote. */
	private static function speaker_figure( ?array $person ): string {
		if ( ! $person ) {
			return '';
		}

		$title = implode( ', ', array_filter( [ $person['role'], $person['organization'] ] ) );

		return '<figure class="afr-qr-person">'
			. ( $person['image_url'] ? sprintf( '<div class="afr-qr-ava"><img src="%s" alt="%s" loading="lazy" /></div>', esc_url( $person['image_url'] ), esc_attr( $person['name'] ) ) : '' )
			. '<figcaption class="afr-qr-cap">'
			. '<span class="afr-qr-name">' . esc_html( $person['name'] ) . '</span>'
			. ( $title !== '' ? '<span class="afr-qr-title">' . esc_html( $title ) . '</span>' : '' )
			. '</figcaption></figure>';
	}

	/** Picking Up the Story, pullquote, charts, Focus, Key Takeaways. */
	private static function body_sections( array $s ): string {
		return self::labelled_richtext( $s['picking_up'] ?? null )
			. ( isset( $s['pullquote'] ) ? '<p class="afr-lead-quote">' . esc_html( self::quoted( $s['pullquote'] ) ) . '</p>' : '' )
			. self::charts( $s )
			. self::labelled_richtext( $s['focus'] ?? null )
			. self::takeaways( $s );
	}

	/** Label, then the authored subhead in Spectral italic, then the body. */
	private static function labelled_richtext( ?array $section ): string {
		if ( ! $section ) {
			return '';
		}

		return self::label( $section['label'] )
			. ( $section['subhead'] !== '' ? '<p class="afr-sub-italic">' . esc_html( $section['subhead'] ) . '</p>' : '' )
			. $section['html'];
	}

	private static function charts( array $s ): string {
		$figures = '';

		foreach ( $s['charts'] ?? [] as $chart ) {
			$figures .= sprintf(
				'<figure class="afr-chart"><img src="%s" alt="%s" loading="lazy" />%s</figure>',
				esc_url( $chart['url'] ),
				esc_attr( $chart['alt'] ),
				$chart['caption'] !== '' ? '<figcaption>' . esc_html( $chart['caption'] ) . '</figcaption>' : ''
			);
		}

		return $figures === '' ? '' : '<div class="afr-charts">' . $figures . '</div>';
	}

	/** Numbered takeaways with the accent numeral and bold action lead. */
	private static function takeaways( array $s ): string {
		if ( ! isset( $s['takeaways'] ) ) {
			return '';
		}

		// The section's HTML has bare <ol>s (text is escaped, so this only matches tags).
		return self::label( $s['takeaways']['label'] )
			. str_replace( '<ol>', '<ol class="afr-takeaways">', $s['takeaways']['html'] );
	}

	private static function takeaways_at_a_glance( array $s ): string {
		if ( empty( $s['glance'] ) ) {
			return '';
		}

		$items = '';
		foreach ( $s['glance'] as $line ) {
			$items .= '<li>' . esc_html( $line ) . '</li>';
		}

		return self::label( 'Key Takeaways at a Glance' ) . '<ol class="afr-tk-short">' . $items . '</ol>';
	}

	/** About the community, About the author. */
	private static function boilerplate( array $s ): string {
		$html = '';

		if ( isset( $s['community'] ) ) {
			$html .= self::label( sprintf( 'About %s and Assemble', $s['community']['name'] ) )
				. self::boiler_paragraphs( $s['community']['boilerplate'] );
		}

		if ( isset( $s['author'] ) ) {
			$author = $s['author'];
			$html  .= self::label( 'About the Author — ' . implode( ', ', array_filter( [ $author['name'], $author['role'] ] ) ) )
				. '<div class="afr-author-card">'
				. ( $author['image_url'] ? sprintf( '<div class="afr-au-ava"><img src="%s" alt="%s" loading="lazy" /></div>', esc_url( $author['image_url'] ), esc_attr( $author['name'] ) ) : '' )
				. self::boiler_paragraphs( $author['bio'] )
				. '</div>';
		}

		return $html;
	}

	private static function engagement( int $post_id, array $s, string $view ): string {
		return $post_id ? AFR_Engagement::filtered_html( $s['engagement'] ?? [], $post_id, $view ) : '';
	}

	/** The Assemble mark alongside the community mark, on black. */
	private static function colophon( array $s ): string {
		$mark = self::assemble_mark();

		if ( $mark === '' ) {
			return '';
		}

		$logo = (string) ( $s['kicker']['logo_url'] ?? '' );
		$name = (string) ( $s['kicker']['name'] ?? '' );

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
