<?php
defined('ABSPATH') || exit;

class ContentSpy_Settings {

    const OPTION_KEY        = 'contentspy_settings';
    const API_KEY_OPTION    = 'contentspy_api_key';
    const SECRET_OPTION     = 'contentspy_plugin_secret';
    const STATUS_OPTION     = 'contentspy_connection_status';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu_page(): void {
        add_menu_page(
            'ContentSpy Connect',
            'ContentSpy',
            'manage_options',
            'contentspy-connect',
            [$this, 'render_settings_page'],
            'dashicons-analytics',
            80
        );
    }

    public function register_settings(): void {
        register_setting('contentspy_settings_group', self::API_KEY_OPTION, [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
    }

    public function render_settings_page(): void {
        $status = get_option(self::STATUS_OPTION, 'disconnected');
        $api_key = self::get_api_key();
        ?>
        <div class="wrap">
            <h1>ContentSpy Connect</h1>
            <div class="notice notice-<?= $status === 'connected' ? 'success' : 'warning' ?>">
                <p>Status: <strong><?= esc_html(ucfirst($status)) ?></strong></p>
            </div>
            <form method="post" action="options.php">
                <?php settings_fields('contentspy_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>SaaS API Key</th>
                        <td>
                            <input type="password" name="<?= esc_attr(self::API_KEY_OPTION) ?>"
                                value="<?= esc_attr($api_key) ?>" class="regular-text" />
                            <p class="description">Paste your API key from the ContentSpy dashboard.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save & Verify Connection'); ?>
            </form>
        </div>
        <?php
    }

    public static function get_api_key(): string {
        return (string) get_option(self::API_KEY_OPTION, '');
    }

    public static function get_plugin_secret(): string {
        return (string) get_option(self::SECRET_OPTION, '');
    }

    public static function get_option(string $key, mixed $default = null): mixed {
        $settings = get_option(self::OPTION_KEY, []);
        return $settings[$key] ?? $default;
    }

    public static function update_option(string $key, mixed $value): void {
        $settings = get_option(self::OPTION_KEY, []);
        $settings[$key] = $value;
        update_option(self::OPTION_KEY, $settings);
    }
}
