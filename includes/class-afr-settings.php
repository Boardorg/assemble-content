<?php
/**
 * Configuration. Values resolve in this order:
 *   1. wp-config.php constants (AFR_SPACE_ID, AFR_ENVIRONMENT, AFR_DELIVERY_TOKEN, AFR_WEBHOOK_SECRET)
 *   2. the `afr_settings` option (set via `wp option patch` or the settings screen)
 *
 * The audience -> WordPress roles map lives here too. Nothing in Contentful knows
 * about WordPress roles, so this map is the whole translation layer.
 */

defined( 'ABSPATH' ) || exit;

class AFR_Settings {

	public const OPTION = 'afr_settings';

	/**
	 * Contentful `availableTo` values mapped to WordPress role slugs.
	 *
	 * Ultimate Member on this site currently defines no member roles, so most of
	 * these slugs do not exist yet — they are the names UM would generate for
	 * roles of those titles. Update the option (or the afr_audience_roles filter)
	 * once the real roles exist; no code change needed.
	 */
	public const DEFAULT_AUDIENCE_ROLES = [
		'Public'         => [],
		'Board Member'   => [ 'um_board-member' ],
		'Board Chair'    => [ 'um_board-chair' ],
		'Delegate'       => [ 'um_delegate' ],
		'Network Member' => [ 'um_network-member' ],
		'Council Chair'  => [ 'um_council-chair' ],
	];

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return [
			'space_id'       => self::resolve( 'AFR_SPACE_ID', $stored, 'space_id' ),
			'environment'    => self::resolve( 'AFR_ENVIRONMENT', $stored, 'environment' ) ?: 'master',
			'delivery_token' => self::resolve( 'AFR_DELIVERY_TOKEN', $stored, 'delivery_token' ),
			'webhook_secret' => self::resolve( 'AFR_WEBHOOK_SECRET', $stored, 'webhook_secret' ),
		];
	}

	private static function resolve( string $constant, array $stored, string $key ): string {
		if ( defined( $constant ) && constant( $constant ) ) {
			return (string) constant( $constant );
		}

		return isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
	}

	public static function is_configured(): bool {
		$s = self::all();

		return $s['space_id'] !== '' && $s['delivery_token'] !== '';
	}

	public static function audience_roles(): array {
		$stored = get_option( self::OPTION, [] );
		$map    = isset( $stored['audience_roles'] ) && is_array( $stored['audience_roles'] )
			? $stored['audience_roles']
			: self::DEFAULT_AUDIENCE_ROLES;

		/**
		 * Filter the audience -> role slugs map.
		 *
		 * @param array<string,string[]> $map
		 */
		return (array) apply_filters( 'afr_audience_roles', $map );
	}

	/** Every audience name the content model can emit, in display order. */
	public static function audiences(): array {
		return array_keys( self::DEFAULT_AUDIENCE_ROLES );
	}

	// ---------------------------------------------------------------- admin UI

	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . AFR_CPT::POST_TYPE,
			'Contentful Sync',
			'Contentful Sync',
			'manage_options',
			'afr-settings',
			[ self::class, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			'afr_settings_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public static function sanitize( $input ): array {
		$existing = get_option( self::OPTION, [] );
		$input    = is_array( $input ) ? $input : [];

		$clean = [
			'space_id'       => sanitize_text_field( $input['space_id'] ?? '' ),
			'environment'    => sanitize_text_field( $input['environment'] ?? '' ),
			'delivery_token' => sanitize_text_field( $input['delivery_token'] ?? '' ),
			'webhook_secret' => sanitize_text_field( $input['webhook_secret'] ?? '' ),
		];

		// Blank token/secret fields mean "leave as-is" so the form never wipes them.
		foreach ( [ 'delivery_token', 'webhook_secret' ] as $secret ) {
			if ( $clean[ $secret ] === '' && ! empty( $existing[ $secret ] ) ) {
				$clean[ $secret ] = $existing[ $secret ];
			}
		}

		$clean['audience_roles'] = $existing['audience_roles'] ?? self::DEFAULT_AUDIENCE_ROLES;

		return $clean;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$s        = self::all();
		$counts   = wp_count_posts( AFR_CPT::POST_TYPE );
		$last     = get_option( 'afr_last_sync', [] );
		$hook_url = AFR_REST::webhook_url();
		?>
		<div class="wrap">
			<h1>Field Reports &mdash; Contentful Sync</h1>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
				<tr><th style="width:220px">Configured</th><td><?php echo self::is_configured() ? '&#10003; yes' : '&#10007; missing space ID or delivery token'; ?></td></tr>
				<tr><th>Space / environment</th><td><code><?php echo esc_html( $s['space_id'] ?: '—' ); ?></code> / <code><?php echo esc_html( $s['environment'] ); ?></code></td></tr>
				<tr><th>Published reports</th><td><?php echo (int) ( $counts->publish ?? 0 ); ?> published, <?php echo (int) ( $counts->draft ?? 0 ); ?> draft</td></tr>
				<tr><th>Last sync</th><td>
					<?php if ( empty( $last ) ) : ?>
						never
					<?php else : ?>
						<?php echo esc_html( $last['time'] ?? '?' ); ?>
						— created <?php echo (int) ( $last['created'] ?? 0 ); ?>,
						updated <?php echo (int) ( $last['updated'] ?? 0 ); ?>,
						unchanged <?php echo (int) ( $last['unchanged'] ?? 0 ); ?>,
						errors <?php echo (int) ( $last['errors'] ?? 0 ); ?>
						(<?php echo esc_html( $last['trigger'] ?? 'manual' ); ?>)
					<?php endif; ?>
				</td></tr>
				<tr><th>Webhook URL</th><td><code><?php echo esc_html( $hook_url ); ?></code></td></tr>
				<tr><th>Webhook secret</th><td><?php echo $s['webhook_secret'] ? 'set — send it as the <code>X-AFR-Secret</code> header' : '<strong>not set</strong> — webhook is disabled until it is'; ?></td></tr>
				</tbody>
			</table>

			<h2>Credentials</h2>
			<p>Leave a token field blank to keep the stored value. Constants in <code>wp-config.php</code> override these.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'afr_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="afr_space">Space ID</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[space_id]" id="afr_space" type="text" class="regular-text" value="<?php echo esc_attr( $s['space_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="afr_env">Environment</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[environment]" id="afr_env" type="text" class="regular-text" value="<?php echo esc_attr( $s['environment'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="afr_token">Delivery token</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[delivery_token]" id="afr_token" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $s['delivery_token'] ? '•••••• stored' : 'CDA token'; ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="afr_secret">Webhook secret</label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]" id="afr_secret" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $s['webhook_secret'] ? '•••••• stored' : 'shared secret'; ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Gate bypass</h2>
			<?php $mode = AFR_Bypass::mode(); ?>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
				<tr>
					<th style="width:220px">Current mode</th>
					<td>
						<code><?php echo esc_html( $mode ); ?></code>
						<?php if ( $mode !== AFR_Bypass::MODE_OFF ) : ?>
							&mdash; <strong style="color:#b32d2e">the audience gate is open on this site</strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>What each mode does</th>
					<td>
						<code>off</code> &mdash; normal gating. The only correct setting for production.<br>
						<code>button</code> &mdash; the gate shows a &ldquo;view anyway&rdquo; button; a visitor who clicks it sees complete reports for a week.<br>
						<code>open</code> &mdash; every visitor sees complete reports with no click.
					</td>
				</tr>
				<tr>
					<th>Switch it</th>
					<td><pre style="margin:0">wp option update <?php echo esc_html( AFR_Bypass::OPTION_MODE ); ?> button
wp option update <?php echo esc_html( AFR_Bypass::OPTION_MODE ); ?> off</pre></td>
				</tr>
				</tbody>
			</table>
			<p>While a bypass is active, every report carries a visible banner saying gating is off,
				so an ungated report is never mistaken for live behaviour. Turning the mode back to
				<code>off</code> revokes access immediately, including for anyone already opted in.</p>

			<h2>Audience mapping</h2>
			<p>Contentful <code>availableTo</code> values are matched against WordPress roles using this map.
				Administrators always see everything and can preview any audience by appending
				<code>?afr_as=&lt;audience&gt;</code> to a report URL.</p>
			<table class="widefat striped" style="max-width:820px">
				<thead><tr><th style="width:220px">Contentful audience</th><th>WordPress roles</th><th>Exists on this site?</th></tr></thead>
				<tbody>
				<?php
				$wp_roles = array_keys( wp_roles()->roles );
				foreach ( self::audience_roles() as $audience => $roles ) :
					$missing = array_diff( (array) $roles, $wp_roles );
					?>
					<tr>
						<td><strong><?php echo esc_html( $audience ); ?></strong></td>
						<td><code><?php echo esc_html( $roles ? implode( ', ', (array) $roles ) : '— anyone —' ); ?></code></td>
						<td><?php
							if ( ! $roles ) {
								echo 'n/a';
							} elseif ( $missing ) {
								echo '<span style="color:#b32d2e">missing: ' . esc_html( implode( ', ', $missing ) ) . '</span>';
							} else {
								echo '&#10003;';
							}
							?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Sync</h2>
			<p>Run from the command line:</p>
			<pre style="background:#f6f7f7;padding:12px;max-width:820px">wp field-report sync --all
wp field-report status
wp field-report audiences</pre>
		</div>
		<?php
	}
}
