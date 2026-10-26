<?php
/**
 * Settings → Synced Content: the last sync, a "Sync now" button, each content
 * type's state and a read-only list of synced posts.
 *
 * Synced posts have no edit screens; the source is the only place to change
 * them. A site adds its own context through filters rather than its own page:
 *
 *   - ac_sync_report_intro         (string)  The paragraph under the title. Plain text.
 *   - ac_sync_report_notices       (array)   [ [ 'type' => error|warning|success|info, 'message' => plain text ] ].
 *   - ac_sync_report_summary_rows  (array)   Extra "Last sync" rows: label => plain-text value.
 *   - ac_sync_report_columns       (array)   The posts table: key => [ 'label' => string,
 *                                            'render' => fn( WP_Post ): string ]. `render`
 *                                            returns escaped HTML.
 *   - ac_sync_report_query_args    (array)   WP_Query args for the posts table.
 *   - ac_sync_report_after         (action)  After the posts table, for a site's own sections.
 */

defined( 'ABSPATH' ) || exit;

class AC_Sync_Report {

	public const SLUG        = 'ac-synced-content';
	public const SYNC_ACTION = 'ac_sync_now';
	public const PER_PAGE    = 50;

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
		add_action( 'admin_post_' . self::SYNC_ACTION, [ self::class, 'handle_sync_now' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'options-general.php' ) );
	}

	public static function add_page(): void {
		add_options_page(
			__( 'Assemble Synced', 'assemble-content' ),
			__( 'Assemble Synced', 'assemble-content' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/** Sync everything that changed since the last sync, then return to the page. */
	public static function handle_sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to sync content.', 'assemble-content' ) );
		}

		check_admin_referer( self::SYNC_ACTION );

		AC_Sync::sync_all( false, 'admin: sync now' );

		wp_safe_redirect( self::url( [ 'synced' => '1' ] ) );
		exit;
	}

	/**
	 * Notices above the page, as filtered by the site.
	 *
	 * @return array<int,array{type:string,message:string}>
	 */
	private static function notices( bool $synced ): array {
		$notices = [];

		if ( $synced ) {
			$notices[] = [ 'type' => 'success', 'message' => __( 'Sync finished. The results are below.', 'assemble-content' ) ];
		}

		if ( ! AC_Settings::is_configured() ) {
			$notices[] = [ 'type' => 'error', 'message' => __( 'Contentful is not configured on this site. Set the AC_SPACE_ID, AC_ENVIRONMENT and AC_DELIVERY_TOKEN constants, or the credentials under Settings → Assemble Content.', 'assemble-content' ) ];
		}

		/**
		 * Filter the notices on the Synced Content page.
		 *
		 * @param array $notices [ [ 'type' => error|warning|success|info, 'message' => plain text ] ].
		 * @param bool  $synced  Whether the page follows a "Sync now".
		 */
		return (array) apply_filters( 'ac_sync_report_notices', $notices, $synced );
	}

	/**
	 * The posts table's columns, as filtered by the site.
	 *
	 * @return array<string,array{label:string,render:callable}>
	 */
	private static function columns(): array {
		$types      = AC_Types::post_type_objects();
		$many_types = count( $types ) > 1;
		$columns    = [
			'piece'  => [
				'label'  => __( 'Piece', 'assemble-content' ),
				'render' => static function ( WP_Post $post ) use ( $many_types ): string {
					$title = esc_html( get_the_title( $post ) );
					$html  = 'publish' === $post->post_status ? sprintf( '<a href="%s">%s</a>', esc_url( (string) get_permalink( $post ) ), $title ) : $title;

					if ( $many_types ) {
						$object = get_post_type_object( $post->post_type );
						$html  .= '<br><small>' . esc_html( $object->labels->singular_name ?? $post->post_type ) . '</small>';
					}

					return $html;
				},
			],
			'status' => [
				'label'  => __( 'Status', 'assemble-content' ),
				'render' => static function ( WP_Post $post ): string {
					$reason = (string) get_post_meta( $post->ID, AC_Post_Type_Content::META_DRAFTED_REASON, true );

					return esc_html( $post->post_status ) . ( 'draft' === $post->post_status && '' !== $reason ? '<br><small>' . esc_html( $reason ) . '</small>' : '' );
				},
			],
		];

		if ( array_filter( $types, static fn( $type ) => $type->is_placed() ) ) {
			$columns['places'] = [
				'label'  => __( 'Publish to', 'assemble-content' ),
				'render' => static fn( WP_Post $post ): string => esc_html( implode( ', ', AC_Query::places( $post->ID ) ) ),
			];
		}

		if ( array_filter( $types, static fn( $type ) => $type->is_gated() ) ) {
			$columns['audiences'] = [
				'label'  => __( 'Who can read it', 'assemble-content' ),
				'render' => static fn( WP_Post $post ): string => esc_html( implode( ', ', AC_Audience::report_audiences( $post->ID ) ) ),
			];
		}

		$columns['topics'] = [
			'label'  => __( 'Topics', 'assemble-content' ),
			'render' => static fn( WP_Post $post ): string => esc_html( (string) count( AC_Query::topic_slugs( $post->ID ) ) ),
		];
		$columns['date']   = [
			'label'  => __( 'Date', 'assemble-content' ),
			'render' => static fn( WP_Post $post ): string => esc_html( (string) get_the_date( 'Y-m-d', $post ) ),
		];
		$columns['synced'] = [
			'label'  => __( 'Synced', 'assemble-content' ),
			'render' => static fn( WP_Post $post ): string => esc_html( (string) get_post_meta( $post->ID, AC_Post_Type_Content::META_SYNCED_AT, true ) ),
		];

		/**
		 * Filter the columns of the synced posts table.
		 *
		 * @param array $columns key => [ 'label' => string, 'render' => fn( WP_Post ): string (escaped HTML) ].
		 */
		$columns = (array) apply_filters( 'ac_sync_report_columns', $columns );

		return array_filter( $columns, static fn( $column ) => is_array( $column ) && is_callable( $column['render'] ?? null ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display parameters.
		$synced = isset( $_GET['synced'] );
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$settings = AC_Settings::all();
		$last     = AC_Sync::last();
		$columns  = self::columns();

		/**
		 * Filter the query for the synced posts table.
		 *
		 * @param array $args WP_Query args.
		 */
		$args = (array) apply_filters(
			'ac_sync_report_query_args',
			[
				'post_type'      => AC_Types::post_types() ?: [ '' ],
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);
		$posts = new WP_Query( $args );

		/**
		 * Filter the paragraph under the Synced Content title.
		 *
		 * @param string $intro Plain text.
		 */
		$intro = (string) apply_filters( 'ac_sync_report_intro', __( 'Content is copied here from its source when it is published there. Edit it at the source; changes made here would be overwritten on the next sync.', 'assemble-content' ) );

		$summary_rows = [
			__( 'Source', 'assemble-content' ) => sprintf(
				/* translators: 1: Contentful environment, 2: site channel */
				__( 'Contentful environment %1$s, channel "%2$s"', 'assemble-content' ),
				$settings['environment'],
				$settings['site_channel']
			),
		];

		/**
		 * Filter the extra rows of the "Last sync" table.
		 *
		 * @param array $rows label => plain-text value.
		 */
		$summary_rows = (array) apply_filters( 'ac_sync_report_summary_rows', $summary_rows );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Assemble Synced', 'assemble-content' ); ?></h1>
			<?php if ( '' !== $intro ) : ?>
				<p><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>

			<?php foreach ( self::notices( $synced ) as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( in_array( $notice['type'] ?? '', [ 'error', 'warning', 'success', 'info' ], true ) ? $notice['type'] : 'info' ); ?>"><p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p></div>
			<?php endforeach; ?>

			<h2><?php esc_html_e( 'Last sync', 'assemble-content' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<tr>
						<th style="width:200px"><?php esc_html_e( 'Time', 'assemble-content' ); ?></th>
						<td><?php echo esc_html( $last['time'] ?? __( 'never', 'assemble-content' ) ); ?></td>
					</tr>
					<?php if ( ! empty( $last ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Trigger', 'assemble-content' ); ?></th>
							<td><?php echo esc_html( $last['trigger'] ?? '' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Result', 'assemble-content' ); ?></th>
							<td>
								<?php
								printf(
									/* translators: Sync counts, in order: created, updated, unchanged, drafted, errors. */
									esc_html__( 'created %1$d, updated %2$d, unchanged %3$d, drafted %4$d, errors %5$d', 'assemble-content' ),
									(int) ( $last['created'] ?? 0 ),
									(int) ( $last['updated'] ?? 0 ),
									(int) ( $last['unchanged'] ?? 0 ),
									(int) ( $last['drafted'] ?? 0 ),
									(int) ( $last['errors'] ?? 0 )
								);
								?>
							</td>
						</tr>
						<?php if ( ! empty( $last['messages'] ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Log', 'assemble-content' ); ?></th>
								<td>
									<details>
										<summary><?php esc_html_e( 'Last 25 messages', 'assemble-content' ); ?></summary>
										<pre style="white-space:pre-wrap"><?php echo esc_html( implode( "\n", (array) $last['messages'] ) ); ?></pre>
									</details>
								</td>
							</tr>
						<?php endif; ?>
					<?php endif; ?>
					<?php foreach ( $summary_rows as $label => $value ) : ?>
						<tr>
							<th><?php echo esc_html( (string) $label ); ?></th>
							<td><?php echo esc_html( (string) $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SYNC_ACTION ); ?>">
				<?php wp_nonce_field( self::SYNC_ACTION ); ?>
				<?php submit_button( __( 'Sync now', 'assemble-content' ), 'primary', 'submit', true, AC_Settings::is_configured() ? [] : [ 'disabled' => 'disabled' ] ); ?>
			</form>

			<h2><?php esc_html_e( 'Content types', 'assemble-content' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<?php foreach ( AC_Types::types() as $type ) : ?>
						<tr>
							<th style="width:200px"><?php echo esc_html( $type->label() ); ?></th>
							<td>
								<?php echo esc_html( $type->summary() ); ?>
								<br><small>
									<?php
									printf(
										/* translators: 1: source name, 2: how the type is synced */
										esc_html__( 'From %1$s; %2$s.', 'assemble-content' ),
										esc_html( $type->source() ? $type->source()->label() : '?' ),
										$type->in_full_sync() ? esc_html__( 'synced on publish and by "Sync now"', 'assemble-content' ) : esc_html__( 'refreshed on its own schedule', 'assemble-content' )
									);
									?>
								</small>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2>
				<?php
				printf(
					/* translators: %d: number of synced posts */
					esc_html__( 'Synced pieces (%d)', 'assemble-content' ),
					(int) $posts->found_posts
				);
				?>
			</h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<?php foreach ( $columns as $column ) : ?>
							<th><?php echo esc_html( (string) ( $column['label'] ?? '' ) ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $posts->have_posts() ) : ?>
						<tr><td colspan="<?php echo (int) count( $columns ); ?>"><?php esc_html_e( 'Nothing synced yet.', 'assemble-content' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $posts->posts as $post ) : ?>
						<tr>
							<?php foreach ( $columns as $column ) : ?>
								<td><?php echo wp_kses_post( (string) call_user_func( $column['render'], $post ) ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pagination = paginate_links(
				[
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => (int) $posts->max_num_pages,
				]
			);
			?>
			<?php if ( $pagination ) : ?>
				<div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post( $pagination ); ?></div></div>
			<?php endif; ?>

			<?php
			/**
			 * After the synced posts table: a site's own sections.
			 */
			do_action( 'ac_sync_report_after' );
			?>
		</div>
		<?php
	}
}
