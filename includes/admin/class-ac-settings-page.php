<?php
/**
 * Settings → Assemble Content: connection status, Contentful credentials, the gate
 * bypass and the audience map. Synced content itself is listed on Settings →
 * Synced Content (AC_Sync_Report).
 *
 * Synced post types have no admin screens, because the source is the only place
 * to edit them.
 */

defined( 'ABSPATH' ) || exit;

class AC_Settings_Page {

	public const SLUG = 'ac-settings';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
	}

	public static function add_menu(): void {
		add_options_page(
			'Assemble Content',
			'Assemble Content',
			'manage_options',
			self::SLUG,
			[ self::class, 'render_page' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			'ac_settings_group',
			AC_Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	public static function sanitize( $input ): array {
		$existing = get_option( AC_Settings::OPTION, [] );
		$input    = is_array( $input ) ? $input : [];

		$clean = [
			'space_id'       => sanitize_text_field( $input['space_id'] ?? '' ),
			'environment'    => sanitize_text_field( $input['environment'] ?? '' ),
			'delivery_token' => sanitize_text_field( $input['delivery_token'] ?? '' ),
			'preview_token'  => sanitize_text_field( $input['preview_token'] ?? '' ),
			'webhook_secret' => sanitize_text_field( $input['webhook_secret'] ?? '' ),
		];

		// Blank token/secret fields mean "leave as-is" so the form never wipes them.
		foreach ( [ 'delivery_token', 'preview_token', 'webhook_secret' ] as $secret ) {
			if ( $clean[ $secret ] === '' && ! empty( $existing[ $secret ] ) ) {
				$clean[ $secret ] = $existing[ $secret ];
			}
		}

		$clean['audience_roles'] = $existing['audience_roles'] ?? AC_Settings::DEFAULT_AUDIENCE_ROLES;

		// Set by constant or WP-CLI, not the form; saving the form keeps it.
		if ( isset( $existing['site_channel'] ) ) {
			$clean['site_channel'] = (string) $existing['site_channel'];
		}

		return $clean;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$s        = AC_Settings::all();
		$last     = AC_Sync::last();
		$hook_url = AC_Contentful_Webhook::webhook_url();
		$option   = AC_Settings::OPTION;
		?>
		<div class="wrap">
			<h1>Assemble Content</h1>
			<p>Published content is copied here as a hidden, read-only cache. Edit content at its source (Field Reports and playbooks in
				<a href="<?php echo esc_url( sprintf( 'https://app.contentful.com/spaces/%s/environments/%s/entries', rawurlencode( $s['space_id'] ), rawurlencode( $s['environment'] ) ) ); ?>" target="_blank" rel="noopener">Contentful</a>);
				changes made in WordPress would be overwritten on the next sync.
				<a href="<?php echo esc_url( AC_Sync_Report::url() ); ?>">See what has synced</a>.</p>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
				<tr><th style="width:220px">Configured</th><td><?php echo AC_Settings::is_configured() ? '&#10003; yes' : '&#10007; missing space ID or delivery token'; ?></td></tr>
				<tr><th>Site channel</th><td><?php echo $s['site_channel'] !== '' ? '<code>' . esc_html( $s['site_channel'] ) . '</code> &mdash; only pieces ticked for it in &ldquo;Publish to&rdquo; sync here' : '&#10007; not set: no pieces sync. Set <code>AC_SITE_CHANNEL</code> or <code>ac_settings[site_channel]</code>.'; ?></td></tr>
				<tr><th>Space / environment</th><td><code><?php echo esc_html( $s['space_id'] ?: '—' ); ?></code> / <code><?php echo esc_html( $s['environment'] ); ?></code></td></tr>
				<?php foreach ( AC_Types::types() as $key => $type ) : ?>
					<tr><th><?php echo esc_html( $type->label() ); ?></th><td>
						<?php echo esc_html( $type->summary() ); ?>
						&mdash; <code><?php echo esc_html( $key ); ?></code> from <?php echo esc_html( $type->source() ? $type->source()->label() : '?' ); ?>
						<?php if ( $type instanceof AC_Post_Type_Content ) : ?>
							&rarr; <code><?php echo esc_html( $type->post_type() ); ?></code><?php $archive = get_post_type_archive_link( $type->post_type() ); ?><?php if ( $archive ) : ?>, <a href="<?php echo esc_url( $archive ); ?>">view archive</a><?php endif; ?>
						<?php endif; ?>
					</td></tr>
				<?php endforeach; ?>
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
				<tr><th>Draft preview</th><td><?php echo $s['preview_token'] ? 'on — register this as the Field Report preview URL in Contentful (Settings → Content preview):' : '<strong>off</strong> — needs a Content Preview API token. Preview URL to register once it is set:'; ?><br><code><?php echo esc_html( AC_Preview::contentful_url_template() ); ?></code></td></tr>
				<tr><th>Webhook secret</th><td><?php echo $s['webhook_secret'] ? 'set — send it as the <code>X-AC-Secret</code> header' : '<strong>not set</strong> — webhook is disabled until it is'; ?></td></tr>
				</tbody>
			</table>

			<h2>Credentials</h2>
			<p>Leave a token field blank to keep the stored value. Constants in <code>wp-config.php</code> override these.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'ac_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ac_space">Space ID</label></th>
						<td><input name="<?php echo esc_attr( $option ); ?>[space_id]" id="ac_space" type="text" class="regular-text" value="<?php echo esc_attr( $s['space_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ac_env">Environment</label></th>
						<td><input name="<?php echo esc_attr( $option ); ?>[environment]" id="ac_env" type="text" class="regular-text" value="<?php echo esc_attr( $s['environment'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ac_token">Delivery token</label></th>
						<td><input name="<?php echo esc_attr( $option ); ?>[delivery_token]" id="ac_token" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $s['delivery_token'] ? '•••••• stored' : 'CDA token'; ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ac_preview_token">Preview token</label></th>
						<td><input name="<?php echo esc_attr( $option ); ?>[preview_token]" id="ac_preview_token" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $s['preview_token'] ? '•••••• stored' : 'CPA token (draft preview)'; ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ac_secret">Webhook secret</label></th>
						<td><input name="<?php echo esc_attr( $option ); ?>[webhook_secret]" id="ac_secret" type="password" class="regular-text" value="" autocomplete="off" placeholder="<?php echo $s['webhook_secret'] ? '•••••• stored' : 'shared secret'; ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Gate bypass</h2>
			<?php $mode = AC_Bypass::mode(); ?>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
				<tr>
					<th style="width:220px">Current mode</th>
					<td>
						<code><?php echo esc_html( $mode ); ?></code>
						<?php if ( $mode !== AC_Bypass::MODE_OFF ) : ?>
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
					<td><pre style="margin:0">wp option update <?php echo esc_html( AC_Bypass::OPTION_MODE ); ?> button
wp option update <?php echo esc_html( AC_Bypass::OPTION_MODE ); ?> off</pre></td>
				</tr>
				</tbody>
			</table>
			<p>While a bypass is active, every report carries a visible banner saying gating is off,
				so an ungated report is never mistaken for live behaviour. Turning the mode back to
				<code>off</code> revokes access immediately, including for anyone already opted in.</p>

			<h2>Audience mapping</h2>
			<p>Contentful <code>availableTo</code> values are matched against WordPress roles using this map.
				Administrators always see everything and can preview any audience by appending
				<code>?ac_as=&lt;audience&gt;</code> to a report URL.</p>
			<table class="widefat striped" style="max-width:820px">
				<thead><tr><th style="width:220px">Contentful audience</th><th>WordPress roles</th><th>Exists on this site?</th></tr></thead>
				<tbody>
				<?php
				$wp_roles = array_keys( wp_roles()->roles );
				foreach ( AC_Settings::audience_roles() as $audience => $roles ) :
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
			<p>Run from the command line, or use <a href="<?php echo esc_url( AC_Sync_Report::url() ); ?>">Settings → Assemble Synced</a>:</p>
			<pre style="background:#f6f7f7;padding:12px;max-width:820px">wp assemble-content sync --all
wp assemble-content sync --type=fieldReport
wp assemble-content sync --all --dry-run
wp assemble-content summits refresh
wp assemble-content status
wp assemble-content types
wp assemble-content audiences</pre>
		</div>
		<?php
	}
}
