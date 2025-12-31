<?php
/**
 * Settings class.
 *
 * @package SafeComms
 */

namespace SafeComms\Admin;

use SafeComms\Logging\Logger;
use SafeComms\API\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'safecomms_options';

	/**
	 * API Client.
	 *
	 * @var Client|null
	 */
	private ?Client $client = null;

	/**
	 * Schema.
	 *
	 * @var array
	 */
	private array $schema;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->schema = $this->define_schema();
	}

	/**
	 * Set API client.
	 *
	 * @param Client $client API Client.
	 * @return void
	 */
	public function set_client( Client $client ): void {
		$this->client = $client;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'network_admin_menu', array( $this, 'register_network_menu' ) );
		add_action( 'network_admin_edit_safecomms_network_options', array( $this, 'save_network_options' ) );
	}

	/**
	 * Get option value.
	 *
	 * @param string $key           Option key.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		if ( 'api_key' === $key ) {
			if ( defined( 'SAFECOMMS_API_KEY' ) ) {
				return SAFECOMMS_API_KEY;
			}

			if ( is_multisite() ) {
				$network_options = get_site_option( 'safecomms_network_options', array() );
				if ( ! empty( $network_options['enforce_network_key'] ) && ! empty( $network_options['api_key'] ) ) {
					return $network_options['api_key'];
				}

				// Fallback to network key if local is empty.
				$local_options = get_option( self::OPTION_KEY, $this->defaults() );
				if ( empty( $local_options['api_key'] ) && ! empty( $network_options['api_key'] ) ) {
					return $network_options['api_key'];
				}
			}
		}

		$options = get_option( self::OPTION_KEY, $this->defaults() );

		if ( ! array_key_exists( $key, $options ) ) {
			return $default_value;
		}

		return $options[ $key ];
	}

	/**
	 * Get default values.
	 *
	 * @return array
	 */
	public function defaults(): array {
		$defaults = array();
		foreach ( $this->schema as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * Define schema.
	 *
	 * @return array
	 */
	private function define_schema(): array {
		return array(
			'api_key'                 => array(
				'type'    => 'string',
				'default' => '',
			),
			'enable_posts'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'enable_comments'         => array(
				'type'    => 'bool',
				'default' => true,
			),
			'auto_scan'               => array(
				'type'    => 'bool',
				'default' => true,
			),
			'notices_enabled'         => array(
				'type'    => 'bool',
				'default' => true,
			),
			'fail_open_comments'      => array(
				'type'    => 'bool',
				'default' => false,
			),
			'enable_text_replacement' => array(
				'type'    => 'bool',
				'default' => false,
			),
			'enable_pii_redaction'    => array(
				'type'    => 'bool',
				'default' => false,
			),
			'enable_non_english'      => array(
				'type'    => 'bool',
				'default' => false,
			),
			'cache_ttl'               => array(
				'type'    => 'int',
				'default' => 600,
			),
			'max_retry_attempts'      => array(
				'type'    => 'int',
				'default' => 3,
			),
			'retry_schedule'          => array(
				'type'    => 'array',
				'default' => array( 300, 900, 2700 ),
			),
			'show_rejection_reason'   => array(
				'type'    => 'bool',
				'default' => false,
			),
			'shortcode_admins_only'   => array(
				'type'    => 'bool',
				'default' => false,
			),
			'profile_post_title'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'profile_post_body'       => array(
				'type'    => 'string',
				'default' => '',
			),
			'profile_comment_body'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'profile_username'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'enable_post_title_scan'  => array(
				'type'    => 'bool',
				'default' => false,
			),
			'enable_post_body_scan'   => array(
				'type'    => 'bool',
				'default' => false,
			),
			'custom_hooks'            => array(
				'type'    => 'array',
				'default' => array(),
			),
			'enable_username_scan'    => array(
				'type'    => 'bool',
				'default' => false,
			),
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'safecomms_options_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->defaults(),
			)
		);

		add_settings_section(
			'safecomms_main_section',
			esc_html__( 'SafeComms Settings', 'safecomms' ),
			function (): void {
				echo '<p>' . esc_html__( 'Configure SafeComms API access and scanning behaviour.', 'safecomms' ) . '</p>';

				if ( $this->client ) {
					$usage = $this->client->get_usage();
					if ( isset( $usage['error'] ) ) {
						if ( 'no_key' !== $usage['error'] ) {
							echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Could not fetch usage: ', 'safecomms' ) . esc_html( $usage['error'] ) . '</p></div>';
						}
					} else {
						$this->render_usage_bar( $usage );
					}
				}
			},
			'safecomms'
		);

		$this->register_field(
			'api_key',
			__( 'API Key', 'safecomms' ),
			function () {
				if ( defined( 'SAFECOMMS_API_KEY' ) ) {
					echo '<p class="description">' . esc_html__( 'API key defined in wp-config.php.', 'safecomms' ) . '</p>';
					return;
				}

				if ( is_multisite() ) {
					$network_options = get_site_option( 'safecomms_network_options', array() );
					if ( ! empty( $network_options['enforce_network_key'] ) && ! empty( $network_options['api_key'] ) ) {
						echo '<p class="description">' . esc_html__( 'API key is managed by the Network Administrator.', 'safecomms' ) . '</p>';
						return;
					}
				}

				$options = get_option( self::OPTION_KEY, $this->defaults() );
				echo '<input type="password" name="' . esc_attr( self::OPTION_KEY ) . '[api_key]" value="" autocomplete="new-password" />';
				if ( ! empty( $options['api_key'] ) ) {
					echo '<p class="description">' . esc_html__( 'Key stored. Leave blank to keep existing.', 'safecomms' ) . '</p>';
				}
				echo '<p class="description">' . esc_html__( 'For enhanced security, define SAFECOMMS_API_KEY in wp-config.php instead of storing in database.', 'safecomms' ) . '</p>';

				if ( is_multisite() ) {
					$network_options = get_site_option( 'safecomms_network_options', array() );
					if ( ! empty( $network_options['api_key'] ) ) {
						echo '<p class="description">' . esc_html__( 'Leave blank to use the Network API Key.', 'safecomms' ) . '</p>';
					}
				}
			}
		);

		$this->register_checkbox_field( 'enable_posts', __( 'Scan posts', 'safecomms' ) );
		$this->register_checkbox_field( 'enable_comments', __( 'Scan comments', 'safecomms' ) );
		$this->register_checkbox_field( 'auto_scan', __( 'Auto-scan on publish/submit', 'safecomms' ) );
		$this->register_checkbox_field( 'notices_enabled', __( 'Show admin notices', 'safecomms' ) );
		$this->register_checkbox_field( 'show_rejection_reason', __( 'Show rejection reason to users', 'safecomms' ) );
		$this->register_checkbox_field( 'shortcode_admins_only', __( 'Restrict shortcode visibility to admins only', 'safecomms' ) );
		$this->register_checkbox_field( 'fail_open_comments', __( 'Allow comments to pass when API unreachable (fail-open)', 'safecomms' ) );

		$this->register_field(
			'enable_text_replacement',
			__( 'Enable Text Replacement', 'safecomms' ),
			function () {
				$options = get_option( self::OPTION_KEY, $this->defaults() );
				$checked = ! empty( $options['enable_text_replacement'] );
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable_text_replacement]" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html__( 'Rewrite unsafe content (Sanitize)', 'safecomms' ) . '</label>';
				echo '<p class="description">' . esc_html__( 'Requires Starter Plan or higher.', 'safecomms' ) . '</p>';
			}
		);

		$this->register_field(
			'enable_pii_redaction',
			__( 'Enable PII Redaction', 'safecomms' ),
			function () {
				$options = get_option( self::OPTION_KEY, $this->defaults() );
				$checked = ! empty( $options['enable_pii_redaction'] );
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable_pii_redaction]" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html__( 'Redact personally identifiable information', 'safecomms' ) . '</label>';
				echo '<p class="description">' . esc_html__( 'Requires Starter Plan or higher.', 'safecomms' ) . '</p>';
			}
		);

		$this->register_field(
			'enable_non_english',
			__( 'Enable Non-English Support', 'safecomms' ),
			function () {
				$options = get_option( self::OPTION_KEY, $this->defaults() );
				$checked = ! empty( $options['enable_non_english'] );
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[enable_non_english]" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html__( 'Support languages other than English', 'safecomms' ) . '</label>';
				echo '<p class="description">' . esc_html__( 'Requires Pro Plan or higher.', 'safecomms' ) . '</p>';
			}
		);

		add_settings_section(
			'safecomms_profiles_section',
			esc_html__( 'Moderation Profiles', 'safecomms' ),
			function (): void {
				echo '<p>' . esc_html__( 'Specify SafeComms Moderation Profile IDs for different content types. Leave blank to use the default profile.', 'safecomms' ) . '</p>';
			},
			'safecomms'
		);

		$this->register_checkbox_field( 'enable_post_title_scan', __( 'Scan Post Title', 'safecomms' ), 'safecomms_profiles_section' );
		$this->register_field(
			'profile_post_title',
			__( 'Post Title Profile ID', 'safecomms' ),
			function () {
				$this->render_text_field( 'profile_post_title' );
			},
			'safecomms_profiles_section'
		);

		$this->register_checkbox_field( 'enable_post_body_scan', __( 'Scan Post Body', 'safecomms' ), 'safecomms_profiles_section' );
		$this->register_field(
			'profile_post_body',
			__( 'Post Body Profile ID', 'safecomms' ),
			function () {
				$this->render_text_field( 'profile_post_body' );
			},
			'safecomms_profiles_section'
		);

		$this->register_field(
			'profile_comment_body',
			__( 'Comment Body Profile ID', 'safecomms' ),
			function () {
				$this->render_text_field( 'profile_comment_body' );
			},
			'safecomms_profiles_section'
		);

		$this->register_checkbox_field( 'enable_username_scan', __( 'Scan Username on Registration', 'safecomms' ), 'safecomms_profiles_section' );
		$this->register_field(
			'profile_username',
			__( 'Username Profile ID', 'safecomms' ),
			function () {
				$this->render_text_field( 'profile_username' );
			},
			'safecomms_profiles_section'
		);

		$this->register_number_field( 'cache_ttl', __( 'Cache TTL (seconds)', 'safecomms' ), 60, 86400 );
		$this->register_number_field( 'max_retry_attempts', __( 'Max retry attempts', 'safecomms' ), 1, 10 );
	}

	/**

	 * Render page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SafeComms Moderation', 'safecomms' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'safecomms_options_group' );
				do_settings_sections( 'safecomms' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register network menu.
	 *
	 * @return void
	 */
	public function register_network_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'SafeComms Network Settings', 'safecomms' ),
			__( 'SafeComms', 'safecomms' ),
			'manage_network_options',
			'safecomms-network',
			array( $this, 'render_network_page' )
		);
	}

	/**
	 * Render network page.
	 *
	 * @return void
	 */
	public function render_network_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$options = get_site_option( 'safecomms_network_options', array() );
		$api_key = $options['api_key'] ?? '';
		$enforce = ! empty( $options['enforce_network_key'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'safecomms' ) . '</p></div>';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SafeComms Network Settings', 'safecomms' ); ?></h1>
			<form method="post" action="edit.php?action=safecomms_network_options">
				<?php wp_nonce_field( 'safecomms_network_options_action' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="safecomms_network_api_key"><?php echo esc_html__( 'Network API Key', 'safecomms' ); ?></label></th>
						<td>
							<input type="password" name="safecomms_network_options[api_key]" id="safecomms_network_api_key" value="" class="regular-text" autocomplete="new-password" />
							<?php if ( ! empty( $api_key ) ) : ?>
								<p class="description"><?php echo esc_html__( 'Key stored. Leave blank to keep existing.', 'safecomms' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php echo esc_html__( 'Enter a SafeComms API key to be used across the network.', 'safecomms' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enforcement', 'safecomms' ); ?></th>
						<td>
							<label for="safecomms_enforce_network_key">
								<input type="checkbox" name="safecomms_network_options[enforce_network_key]" id="safecomms_enforce_network_key" value="1" <?php checked( $enforce ); ?> />
								<?php echo esc_html__( 'Force all sites to use this API key', 'safecomms' ); ?>
							</label>
							<p class="description"><?php echo esc_html__( 'If checked, individual sites cannot override the API key.', 'safecomms' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save network options.
	 *
	 * @return void
	 */
	public function save_network_options(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		check_admin_referer( 'safecomms_network_options_action' );

		$input    = $_POST['safecomms_network_options'] ?? array();
		$existing = get_site_option( 'safecomms_network_options', array() );

		$clean = array(
			'enforce_network_key' => ! empty( $input['enforce_network_key'] ),
		);

		if ( ! empty( $input['api_key'] ) ) {
			$clean['api_key'] = sanitize_text_field( $input['api_key'] );
		} elseif ( ! empty( $existing['api_key'] ) ) {
			$clean['api_key'] = $existing['api_key'];
		} else {
			$clean['api_key'] = '';
		}

		update_site_option( 'safecomms_network_options', $clean );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'safecomms-network',
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize options.
	 *
	 * @param array $input Input options.
	 * @return array
	 */
	public function sanitize_options( array $input ): array {
		$clean = $this->defaults();

		foreach ( $this->schema as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];
			switch ( $field['type'] ) {
				case 'bool':
					$clean[ $key ] = (bool) $value;
					break;
				case 'int':
					$clean[ $key ] = max( 0, (int) $value );
					break;
				case 'array':
					if ( 'retry_schedule' === $key && is_array( $value ) ) {
						$clean[ $key ] = array_values( array_filter( array_map( 'absint', $value ), static fn( $v ) => $v > 0 ) );
						$clean[ $key ] = array_slice( $clean[ $key ], 0, 10 );
						if ( empty( $clean[ $key ] ) ) {
							$clean[ $key ] = $field['default'];
						}
					} elseif ( 'custom_hooks' === $key && is_array( $value ) ) {
						$clean_hooks = array();
						// Blacklist of dangerous hooks that should never be used for content scanning.
						$blacklisted_hooks = array(
							'init',
							'admin_init',
							'wp_login',
							'wp_logout',
							'user_register',
							'wp_authenticate',
							'authenticate',
							'set_current_user',
							'plugins_loaded',
							'muplugins_loaded',
							'setup_theme',
							'after_setup_theme',
							'activated_plugin',
							'deactivated_plugin',
							'uninstall_plugin',
							'wp_loaded',
							'shutdown',
							'wp_footer',
							'wp_head',
							'admin_footer',
							'admin_head',
						);

						foreach ( $value as $hook_config ) {
							if ( ! is_array( $hook_config ) ) {
								continue;
							}

							$hook_name = sanitize_text_field( $hook_config['hook_name'] ?? '' );
							if ( '' === $hook_name || in_array( $hook_name, $blacklisted_hooks, true ) ) {
								continue;
							}

							$hook = array(
								'hook_name'    => $hook_name,
								'type'         => in_array( ( $hook_config['type'] ?? 'filter' ), array( 'filter', 'action' ), true ) ? $hook_config['type'] : 'filter',
								'priority'     => max( 1, absint( $hook_config['priority'] ?? 10 ) ),
								'arg_position' => max( 1, absint( $hook_config['arg_position'] ?? 1 ) ),
								'array_key'    => sanitize_text_field( $hook_config['array_key'] ?? '' ),
								'profile_id'   => sanitize_text_field( $hook_config['profile_id'] ?? '' ),
								'behavior'     => in_array( ( $hook_config['behavior'] ?? 'sanitize' ), array( 'sanitize', 'block' ), true ) ? $hook_config['behavior'] : 'sanitize',
							);

							$clean_hooks[] = $hook;
						}

						$clean[ $key ] = $clean_hooks;
					} else {
						$clean[ $key ] = $field['default'];
					}
					break;
				case 'string':
				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		if ( ! empty( $input['api_key'] ) ) {
			$clean['api_key'] = sanitize_text_field( $input['api_key'] );
		} else {
			$existing = get_option( self::OPTION_KEY, $this->defaults() );
			if ( ! empty( $existing['api_key'] ) ) {
				$clean['api_key'] = $existing['api_key'];
			}
		}

		return $clean;
	}

	/**
	 * Register field.
	 *
	 * @param string   $key             Field key.
	 * @param string   $label           Field label.
	 * @param callable $render_callback Render callback.
	 * @param string   $section         Section ID.
	 * @return void
	 */
	private function register_field( string $key, string $label, callable $render_callback, string $section = 'safecomms_main_section' ): void {
		add_settings_field(
			$key,
			esc_html( $label ),
			$render_callback,
			'safecomms',
			$section
		);
	}

	/**
	 * Render text field.
	 *
	 * @param string $key Field key.
	 * @return void
	 */
	private function render_text_field( string $key ): void {
		$options = get_option( self::OPTION_KEY, $this->defaults() );
		$value   = isset( $options[ $key ] ) ? $options[ $key ] : $this->schema[ $key ]['default'];
		echo '<input type="text" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" class="regular-text" />';
	}

	/**
	 * Register checkbox field.
	 *
	 * @param string $key     Field key.
	 * @param string $label   Field label.
	 * @param string $section Section ID.
	 * @return void
	 */
	private function register_checkbox_field( string $key, string $label, string $section = 'safecomms_main_section' ): void {
		$this->register_field(
			$key,
			$label,
			function () use ( $key ) {
				$options = get_option( self::OPTION_KEY, $this->defaults() );
				$checked = ! empty( $options[ $key ] );
				echo '<label><input type="checkbox" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html__( 'Enabled', 'safecomms' ) . '</label>';
			},
			$section
		);
	}

	/**
	 * Register number field.
	 *
	 * @param string $key   Field key.
	 * @param string $label Field label.
	 * @param int    $min   Minimum value.
	 * @param int    $max   Maximum value.
	 * @return void
	 */
	private function register_number_field( string $key, string $label, int $min, int $max ): void {
		$this->register_field(
			$key,
			$label,
			function () use ( $key, $min, $max ) {
				$options = get_option( self::OPTION_KEY, $this->defaults() );
				$value   = isset( $options[ $key ] ) ? (int) $options[ $key ] : $this->schema[ $key ]['default'];
				echo '<input type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" />';
			}
		);
	}

	/**
	 * Render usage bar.
	 *
	 * @param array $usage Usage data.
	 * @return void
	 */
	private function render_usage_bar( array $usage ): void {
		$used    = $usage['TokensUsed'] ?? 0;
		$limit   = $usage['TokenLimit'] ?? 0;
		$percent = $limit > 0 ? min( 100, ( $used / $limit ) * 100 ) : 0;
		$tier    = $usage['Tier'] ?? 'Free';

		$warnings = array();
		if ( in_array( $tier, array( 'Free', 'Basic', 'Starter' ), true ) ) {
			if ( $this->get( 'enable_non_english' ) ) {
				$warnings[] = __( 'Your plan does not support non-English languages.', 'safecomms' );
			}
		}

		echo '<div class="safecomms-usage-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; max_width: 600px;">';
		echo '<h3>' . esc_html__( 'Account Usage', 'safecomms' ) . ' <span class="badge" style="background: #2271b1; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 12px;">' . esc_html( $tier ) . '</span></h3>';

		echo '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">';
		echo '<span>' . sprintf(
			/* translators: 1: Tokens used, 2: Token limit */
			esc_html__( 'Tokens: %1$s / %2$s', 'safecomms' ),
			number_format( $used ),
			number_format( $limit )
		) . '</span>';
		echo '<span>' . number_format( $percent, 1 ) . '%</span>';
		echo '</div>';

		echo '<div style="background: #f0f0f1; border-radius: 4px; height: 20px; overflow: hidden;">';
		echo '<div style="background: #2271b1; height: 100%; width: ' . esc_attr( (string) $percent ) . '%;"></div>';
		echo '</div>';

		if ( ! empty( $warnings ) ) {
			foreach ( $warnings as $warning ) {
				echo '<p style="color: #d63638; margin-top: 10px;">' . esc_html( $warning ) . '</p>';
			}
		}
		echo '</div>';
	}
}



