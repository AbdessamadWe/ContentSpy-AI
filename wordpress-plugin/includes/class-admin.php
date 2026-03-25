<?php
defined('ABSPATH') || exit;

class ContentSpy_Admin {

    public function __construct() {
        add_action('admin_bar_menu',      [$this, 'add_admin_bar_indicator'], 100);
        add_action('wp_dashboard_setup',  [$this, 'register_dashboard_widget']);
        add_shortcode('contentspy_suggestions', [$this, 'suggestions_widget']);
    }

    /** Register the WP dashboard widget */
    public function register_dashboard_widget(): void {
        if (!current_user_can('edit_posts')) return;

        wp_add_dashboard_widget(
            'contentspy_pending_articles',
            'ContentSpy — Pending Articles',
            [$this, 'render_dashboard_widget']
        );
    }

    /** Render the dashboard widget HTML */
    public function render_dashboard_widget(): void {
        $pending = $this->get_pending_articles_from_saas();
        $count   = count($pending);
        $settings_url = admin_url('admin.php?page=contentspy-connect');

        echo '<div class="contentspy-dashboard-widget">';

        if (!ContentSpy_Settings::is_connected()) {
            echo '<p>⚠️ ContentSpy is not connected. <a href="' . esc_url($settings_url) . '">Connect your site</a> to start receiving AI-generated content.</p>';
        } elseif ($count === 0) {
            echo '<p>✅ No pending articles from ContentSpy.</p>';
            echo '<p><a href="' . esc_url(ContentSpy_Settings::get_saas_url() . '/suggestions') . '" target="_blank">View suggestions in ContentSpy →</a></p>';
        } else {
            echo '<p><strong>' . esc_html($count) . ' article' . ($count !== 1 ? 's' : '') . ' pending review</strong></p>';
            echo '<ul>';
            foreach (array_slice($pending, 0, 5) as $article) {
                $title    = esc_html($article['title'] ?? 'Untitled');
                $reviewUrl = esc_url(ContentSpy_Settings::get_saas_url() . '/articles/' . $article['id']);
                echo '<li><a href="' . $reviewUrl . '" target="_blank">' . $title . '</a></li>';
            }
            if ($count > 5) {
                echo '<li><em>...and ' . ($count - 5) . ' more</em></li>';
            }
            echo '</ul>';
            echo '<p><a href="' . esc_url(ContentSpy_Settings::get_saas_url() . '/articles') . '" target="_blank" class="button button-primary button-small">Review All →</a></p>';
        }

        echo '</div>';
    }

    /** Show pending articles count badge in the WP admin bar */
    public function add_admin_bar_indicator(\WP_Admin_Bar $bar): void {
        if (!current_user_can('manage_options')) return;

        $pending = $this->get_pending_count();

        $bar->add_node([
            'id'    => 'contentspy-pending',
            'title' => 'ContentSpy' . ($pending > 0 ? " <span class='ab-label'>{$pending}</span>" : ''),
            'href'  => admin_url('admin.php?page=contentspy-connect'),
            'meta'  => ['class' => 'contentspy-admin-bar'],
        ]);
    }

    private function get_pending_count(): int {
        return (int) get_transient('contentspy_pending_count');
    }

    /** Fetch pending articles list from SaaS API (cached 5 min) */
    private function get_pending_articles_from_saas(): array {
        $cached = get_transient('contentspy_pending_articles');
        if ($cached !== false) return $cached;

        $api_key  = ContentSpy_Settings::get_api_key();
        $saas_url = ContentSpy_Settings::get_saas_url();

        if (!$api_key || !$saas_url) return [];

        $response = wp_remote_get($saas_url . '/api/plugin/pending-articles', [
            'headers' => ['X-Plugin-API-Key' => $api_key],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data     = json_decode(wp_remote_retrieve_body($response), true);
        $articles = $data['articles'] ?? [];

        set_transient('contentspy_pending_articles', $articles, 300); // 5 min cache
        set_transient('contentspy_pending_count', count($articles), 300);

        return $articles;
    }

    /** Shortcode [contentspy_suggestions] */
    public function suggestions_widget(array $atts): string {
        if (!current_user_can('edit_posts')) return '';
        return '<div class="contentspy-widget"><p>ContentSpy suggestions widget — configure in dashboard.</p></div>';
    }
}
