<?php
defined('ABSPATH') || exit;

class ContentSpy_Updater {

    const UPDATE_CHECK_URL = 'https://api.contentspy.ai/plugin/check-update';

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    public function check_for_update(mixed $transient): mixed {
        if (empty($transient->checked)) return $transient;

        $api_key = ContentSpy_Settings::get_api_key();
        if (empty($api_key)) return $transient;

        $response = wp_remote_get(self::UPDATE_CHECK_URL . '?version=' . CONTENTSPY_VERSION . '&key=' . urlencode($api_key), [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $transient;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['update_available'])) return $transient;

        $plugin_slug = plugin_basename(CONTENTSPY_PLUGIN_DIR . 'contentspy-connect.php');
        $transient->response[$plugin_slug] = (object) [
            'slug'        => 'contentspy-connect',
            'plugin'      => $plugin_slug,
            'new_version' => $data['version'],
            'url'         => 'https://contentspy.ai',
            'package'     => $data['download_url'],
        ];

        return $transient;
    }

    public function plugin_info(mixed $result, string $action, object $args): mixed {
        if ($action !== 'plugin_information' || $args->slug !== 'contentspy-connect') {
            return $result;
        }
        // Return minimal plugin info for the update dialog
        return (object) [
            'name'     => 'ContentSpy Connect',
            'slug'     => 'contentspy-connect',
            'version'  => CONTENTSPY_VERSION,
            'author'   => 'ContentSpy AI',
            'homepage' => 'https://contentspy.ai',
        ];
    }
}
