<?php
namespace SafeComms\Admin;

use SafeComms\Logging\Logger;
use SafeComms\API\Client;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    public const OPTION_KEY = 'safecomms_options';

    private Logger $logger;
    private ?Client $client = null;
    private array $schema;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->schema = $this->define_schema();
    }

    public function set_client(Client $client): void
    {
        $this->client = $client;
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('network_admin_menu', [$this, 'register_network_menu']);
        add_action('network_admin_edit_safecomms_network_options', [$this, 'save_network_options']);
    }

    public function get(string $key, $default = null)
    {
        if ($key === 'api_key') {
            if (defined('SAFECOMMS_API_KEY')) {
                return SAFECOMMS_API_KEY;
            }
            
            if (is_multisite()) {
                $network_options = get_site_option('safecomms_network_options', []);
                if (!empty($network_options['enforce_network_key']) && !empty($network_options['api_key'])) {
                    return $network_options['api_key'];
                }
                
                // Fallback to network key if local is empty
                $local_options = get_option(self::OPTION_KEY, $this->defaults());
                if (empty($local_options['api_key']) && !empty($network_options['api_key'])) {
                    return $network_options['api_key'];
                }
            }
        }

        $options = get_option(self::OPTION_KEY, $this->defaults());

        if (!array_key_exists($key, $options)) {
            return $default;
        }

        return $options[$key];
    }

    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->schema as $key => $field) {
            $defaults[$key] = $field['default'];
        }

        return $defaults;
    }

    private function define_schema(): array
    {
        return [
            'api_key' => [
                'type' => 'string',
                'default' => '',
            ],
            'enable_posts' => [
                'type' => 'bool',
                'default' => false,
            ],
            'enable_comments' => [
                'type' => 'bool',
                'default' => true,
            ],
            'auto_scan' => [
                'type' => 'bool',
                'default' => true,
            ],
            'notices_enabled' => [
                'type' => 'bool',
                'default' => true,
            ],
            'fail_open_comments' => [
                'type' => 'bool',
                'default' => false,
            ],
            'enable_text_replacement' => [
                'type' => 'bool',
                'default' => false,
            ],
            'enable_pii_redaction' => [
                'type' => 'bool',
                'default' => false,
            ],
            'enable_non_english' => [
                'type' => 'bool',
                'default' => false,
            ],
            'cache_ttl' => [
                'type' => 'int',
                'default' => 600,
            ],
            'max_retry_attempts' => [
                'type' => 'int',
                'default' => 3,
            ],
            'retry_schedule' => [
                'type' => 'array',
                'default' => [300, 900, 2700],
            ],
            'show_rejection_reason' => [
                'type' => 'bool',
                'default' => false,
            ],
            'shortcode_admins_only' => [
                'type' => 'bool',
                'default' => false,
            ],
            'profile_post_title' => [
                'type' => 'string',
                'default' => '',
            ],
            'profile_post_body' => [
                'type' => 'string',
                'default' => '',
            ],
            'profile_comment_body' => [
                'type' => 'string',
                'default' => '',
            ],
            'profile_username' => [
                'type' => 'string',
                'default' => '',
            ],
            'enable_post_title_scan' => [
                'type' => 'bool',
                'default' => false,
            ],
            'enable_post_body_scan' => [
                'type' => 'bool',
                'default' => false,
            ],
            'custom_hooks' => [
                'type' => 'array',
                'default' => [],
            ],
            'enable_username_scan' => [
                'type' => 'bool',
                'default' => false,
            ],
        ];
    }

    public function register_settings(): void
    {
        register_setting(
            'safecomms_options_group',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => $this->defaults(),
            ]
        );

        add_settings_section(
            'safecomms_main_section',
            esc_html__('SafeComms Settings', 'safecomms'),
            function (): void {
                echo '<p>' . esc_html__('Configure SafeComms API access and scanning behaviour.', 'safecomms') . '</p>';
                
                if ($this->client) {
                    $usage = $this->client->get_usage();
                    if (isset($usage['error'])) {
                        if ($usage['error'] !== 'no_key') {
                             echo '<div class="notice notice-error inline"><p>' . esc_html__('Could not fetch usage: ', 'safecomms') . esc_html($usage['error']) . '</p></div>';
                        }
                    } else {
                        $this->render_usage_bar($usage);
                    }
                }
            },
            'safecomms'
        );

        $this->register_field('api_key', __('API Key', 'safecomms'), function () {
            if (defined('SAFECOMMS_API_KEY')) {
                echo '<p class="description">' . esc_html__('API key defined in wp-config.php.', 'safecomms') . '</p>';
                return;
            }

            if (is_multisite()) {
                $network_options = get_site_option('safecomms_network_options', []);
                if (!empty($network_options['enforce_network_key']) && !empty($network_options['api_key'])) {
                    echo '<p class="description">' . esc_html__('API key is managed by the Network Administrator.', 'safecomms') . '</p>';
                    return;
                }
            }

            $options = get_option(self::OPTION_KEY, $this->defaults());
            echo '<input type="password" name="' . esc_attr(self::OPTION_KEY) . '[api_key]" value="" autocomplete="new-password" />';
            if (!empty($options['api_key'])) {
                echo '<p class="description">' . esc_html__('Key stored. Leave blank to keep existing.', 'safecomms') . '</p>';
            }
            echo '<p class="description">' . esc_html__('For enhanced security, define SAFECOMMS_API_KEY in wp-config.php instead of storing in database.', 'safecomms') . '</p>';
            
            if (is_multisite()) {
                $network_options = get_site_option('safecomms_network_options', []);
                if (!empty($network_options['api_key'])) {
                    echo '<p class="description">' . esc_html__('Leave blank to use the Network API Key.', 'safecomms') . '</p>';
                }
            }
        });

        $this->register_checkbox_field('enable_posts', __('Scan posts', 'safecomms'));
        $this->register_checkbox_field('enable_comments', __('Scan comments', 'safecomms'));
        $this->register_checkbox_field('auto_scan', __('Auto-scan on publish/submit', 'safecomms'));
        $this->register_checkbox_field('notices_enabled', __('Show admin notices', 'safecomms'));
        $this->register_checkbox_field('show_rejection_reason', __('Show rejection reason to users', 'safecomms'));
        $this->register_checkbox_field('shortcode_admins_only', __('Restrict shortcode visibility to admins only', 'safecomms'));
        $this->register_checkbox_field('fail_open_comments', __('Allow comments to pass when API unreachable (fail-open)', 'safecomms'));
        
        $this->register_field('enable_text_replacement', __('Enable Text Replacement', 'safecomms'), function () {
            $options = get_option(self::OPTION_KEY, $this->defaults());
            $checked = !empty($options['enable_text_replacement']);
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[enable_text_replacement]" value="1" ' . checked($checked, true, false) . ' /> ' . esc_html__('Rewrite unsafe content (Sanitize)', 'safecomms') . '</label>';
            echo '<p class="description">' . esc_html__('Requires Starter Plan or higher.', 'safecomms') . '</p>';
        });

        $this->register_field('enable_pii_redaction', __('Enable PII Redaction', 'safecomms'), function () {
            $options = get_option(self::OPTION_KEY, $this->defaults());
            $checked = !empty($options['enable_pii_redaction']);
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[enable_pii_redaction]" value="1" ' . checked($checked, true, false) . ' /> ' . esc_html__('Redact personally identifiable information', 'safecomms') . '</label>';
            echo '<p class="description">' . esc_html__('Requires Starter Plan or higher.', 'safecomms') . '</p>';
        });

        $this->register_field('enable_non_english', __('Enable Non-English Support', 'safecomms'), function () {
            $options = get_option(self::OPTION_KEY, $this->defaults());
            $checked = !empty($options['enable_non_english']);
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[enable_non_english]" value="1" ' . checked($checked, true, false) . ' /> ' . esc_html__('Support languages other than English', 'safecomms') . '</label>';
            echo '<p class="description">' . esc_html__('Requires Pro Plan or higher.', 'safecomms') . '</p>';
        });
        
        add_settings_section(
            'safecomms_profiles_section',
            esc_html__('Moderation Profiles', 'safecomms'),
            function (): void {
                echo '<p>' . esc_html__('Specify SafeComms Moderation Profile IDs for different content types. Leave blank to use the default profile.', 'safecomms') . '</p>';
            },
            'safecomms'
        );

        $this->register_checkbox_field('enable_post_title_scan', __('Scan Post Title', 'safecomms'), 'safecomms_profiles_section');
        $this->register_field('profile_post_title', __('Post Title Profile ID', 'safecomms'), function () {
            $this->render_text_field('profile_post_title');
        }, 'safecomms_profiles_section');

        $this->register_checkbox_field('enable_post_body_scan', __('Scan Post Body', 'safecomms'), 'safecomms_profiles_section');
        $this->register_field('profile_post_body', __('Post Body Profile ID', 'safecomms'), function () {
            $this->render_text_field('profile_post_body');
        }, 'safecomms_profiles_section');

        $this->register_field('profile_comment_body', __('Comment Body Profile ID', 'safecomms'), function () {
            $this->render_text_field('profile_comment_body');
        }, 'safecomms_profiles_section');

        $this->register_checkbox_field('enable_username_scan', __('Scan Username on Registration', 'safecomms'), 'safecomms_profiles_section');
        $this->register_field('profile_username', __('Username Profile ID', 'safecomms'), function () {
            $this->render_text_field('profile_username');
        }, 'safecomms_profiles_section');

        $this->register_number_field('cache_ttl', __('Cache TTL (seconds)', 'safecomms'), 60, 86400);
        $this->register_number_field('max_retry_attempts', __('Max retry attempts', 'safecomms'), 1, 10);
    }

    public function register_menu(): void
    {
        add_options_page(
            esc_html__('SafeComms Moderation', 'safecomms'),
            esc_html__('SafeComms', 'safecomms'),
            'manage_options',
            'safecomms',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('SafeComms Moderation', 'safecomms'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('safecomms_options_group');
                do_settings_sections('safecomms');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_network_menu(): void
    {
        add_submenu_page(
            'settings.php',
            __('SafeComms Network Settings', 'safecomms'),
            __('SafeComms', 'safecomms'),
            'manage_network_options',
            'safecomms-network',
            [$this, 'render_network_page']
        );
    }

    public function render_network_page(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        $options = get_site_option('safecomms_network_options', []);
        $api_key = $options['api_key'] ?? '';
        $enforce = !empty($options['enforce_network_key']);

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'safecomms') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('SafeComms Network Settings', 'safecomms'); ?></h1>
            <form method="post" action="edit.php?action=safecomms_network_options">
                <?php wp_nonce_field('safecomms_network_options_action'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="safecomms_network_api_key"><?php echo esc_html__('Network API Key', 'safecomms'); ?></label></th>
                        <td>
                            <input type="password" name="safecomms_network_options[api_key]" id="safecomms_network_api_key" value="" class="regular-text" autocomplete="new-password" />
                            <?php if (!empty($api_key)) : ?>
                                <p class="description"><?php echo esc_html__('Key stored. Leave blank to keep existing.', 'safecomms'); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php echo esc_html__('Enter a SafeComms API key to be used across the network.', 'safecomms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enforcement', 'safecomms'); ?></th>
                        <td>
                            <label for="safecomms_enforce_network_key">
                                <input type="checkbox" name="safecomms_network_options[enforce_network_key]" id="safecomms_enforce_network_key" value="1" <?php checked($enforce); ?> />
                                <?php echo esc_html__('Force all sites to use this API key', 'safecomms'); ?>
                            </label>
                            <p class="description"><?php echo esc_html__('If checked, individual sites cannot override the API key.', 'safecomms'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function save_network_options(): void
    {
        if (!current_user_can('manage_network_options')) {
            return;
        }

        check_admin_referer('safecomms_network_options_action');

        $input = $_POST['safecomms_network_options'] ?? [];
        $existing = get_site_option('safecomms_network_options', []);
        
        $clean = [
            'enforce_network_key' => !empty($input['enforce_network_key']),
        ];

        if (!empty($input['api_key'])) {
            $clean['api_key'] = sanitize_text_field($input['api_key']);
        } elseif (!empty($existing['api_key'])) {
            $clean['api_key'] = $existing['api_key'];
        } else {
            $clean['api_key'] = '';
        }

        update_site_option('safecomms_network_options', $clean);
        
        wp_safe_redirect(add_query_arg(['page' => 'safecomms-network', 'updated' => 'true'], network_admin_url('settings.php')));
        exit;
    }

    public function sanitize_options(array $input): array
    {
        $clean = $this->defaults();

        foreach ($this->schema as $key => $field) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            switch ($field['type']) {
                case 'bool':
                    $clean[$key] = (bool) $value;
                    break;
                case 'int':
                    $clean[$key] = max(0, (int) $value);
                    break;
                case 'array':
                    if ($key === 'retry_schedule' && is_array($value)) {
                        $clean[$key] = array_values(array_filter(array_map('absint', $value), static fn($v) => $v > 0));
                        $clean[$key] = array_slice($clean[$key], 0, 10);
                        if (empty($clean[$key])) {
                            $clean[$key] = $field['default'];
                        }
                    } elseif ($key === 'custom_hooks' && is_array($value)) {
                        $clean_hooks = [];
                        // Blacklist of dangerous hooks that should never be used for content scanning
                        $blacklisted_hooks = [
                            'init', 'admin_init', 'wp_login', 'wp_logout', 'user_register',
                            'wp_authenticate', 'authenticate', 'set_current_user', 'plugins_loaded',
                            'muplugins_loaded', 'setup_theme', 'after_setup_theme', 'activated_plugin',
                            'deactivated_plugin', 'uninstall_plugin', 'wp_loaded', 'shutdown',
                            'wp_footer', 'wp_head', 'admin_footer', 'admin_head'
                        ];
                        
                        foreach ($value as $hook_config) {
                            if (!is_array($hook_config)) {
                                continue;
                            }

                            $hook_name = sanitize_text_field($hook_config['hook_name'] ?? '');
                            if ($hook_name === '' || in_array($hook_name, $blacklisted_hooks, true)) {
                                continue;
                            }

                            $hook = [
                                'hook_name' => $hook_name,
                                'type' => in_array(($hook_config['type'] ?? 'filter'), ['filter', 'action'], true) ? $hook_config['type'] : 'filter',
                                'priority' => max(1, absint($hook_config['priority'] ?? 10)),
                                'arg_position' => max(1, absint($hook_config['arg_position'] ?? 1)),
                                'array_key' => sanitize_text_field($hook_config['array_key'] ?? ''),
                                'profile_id' => sanitize_text_field($hook_config['profile_id'] ?? ''),
                                'behavior' => in_array(($hook_config['behavior'] ?? 'sanitize'), ['sanitize', 'block'], true) ? $hook_config['behavior'] : 'sanitize',
                            ];

                            $clean_hooks[] = $hook;
                        }

                        $clean[$key] = $clean_hooks;
                    } else {
                        $clean[$key] = $field['default'];
                    }
                    break;
                case 'string':
                default:
                    $clean[$key] = sanitize_text_field((string) $value);
                    break;
            }
        }

        if (!empty($input['api_key'])) {
            $clean['api_key'] = sanitize_text_field($input['api_key']);
        } else {
            $existing = get_option(self::OPTION_KEY, $this->defaults());
            if (!empty($existing['api_key'])) {
                $clean['api_key'] = $existing['api_key'];
            }
        }

        return $clean;
    }

    private function register_field(string $key, string $label, callable $render_callback, string $section = 'safecomms_main_section'): void
    {
        add_settings_field(
            $key,
            esc_html($label),
            $render_callback,
            'safecomms',
            $section
        );
    }

    private function render_text_field(string $key): void
    {
        $options = get_option(self::OPTION_KEY, $this->defaults());
        $value = isset($options[$key]) ? $options[$key] : $this->schema[$key]['default'];
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" class="regular-text" />';
    }

    private function register_checkbox_field(string $key, string $label, string $section = 'safecomms_main_section'): void
    {
        $this->register_field($key, $label, function () use ($key) {
            $options = get_option(self::OPTION_KEY, $this->defaults());
            $checked = !empty($options[$key]);
            echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . ' /> ' . esc_html__('Enabled', 'safecomms') . '</label>';
        }, $section);
    }

    private function register_number_field(string $key, string $label, int $min, int $max): void
    {
        $this->register_field($key, $label, function () use ($key, $min, $max) {
            $options = get_option(self::OPTION_KEY, $this->defaults());
            $value = isset($options[$key]) ? (int) $options[$key] : $this->schema[$key]['default'];
            echo '<input type="number" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" name="' . esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
        });
    }

    private function render_usage_bar(array $usage): void
    {
        $used = $usage['TokensUsed'] ?? 0;
        $limit = $usage['TokenLimit'] ?? 0;
        $percent = $limit > 0 ? min(100, ($used / $limit) * 100) : 0;
        $tier = $usage['Tier'] ?? 'Free';
        
        $warnings = [];
        if (in_array($tier, ['Free', 'Basic', 'Starter'], true)) {
             if ($this->get('enable_non_english')) {
                 $warnings[] = __('Your plan does not support non-English languages.', 'safecomms');
             }
        }

        echo '<div class="safecomms-usage-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 20px 0; max_width: 600px;">';
        echo '<h3>' . esc_html__('Account Usage', 'safecomms') . ' <span class="badge" style="background: #2271b1; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 12px;">' . esc_html($tier) . '</span></h3>';
        
        echo '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">';
        echo '<span>' . sprintf(esc_html__('Tokens: %s / %s', 'safecomms'), number_format($used), number_format($limit)) . '</span>';
        echo '<span>' . number_format($percent, 1) . '%</span>';
        echo '</div>';
        
        echo '<div style="background: #f0f0f1; border-radius: 4px; height: 20px; overflow: hidden;">';
        echo '<div style="background: #2271b1; height: 100%; width: ' . esc_attr($percent) . '%;"></div>';
        echo '</div>';

        if (!empty($warnings)) {
            foreach ($warnings as $warning) {
                echo '<p style="color: #d63638; margin-top: 10px;">' . esc_html($warning) . '</p>';
            }
        }
        echo '</div>';
    }
}
