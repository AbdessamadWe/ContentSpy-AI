<?php
defined('ABSPATH') || exit;

class ContentSpy_REST_API {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void {
        $ns = CONTENTSPY_API_NAMESPACE;
        $perm = [ContentSpy_Security::class, 'rest_permission_callback'];

        // GET /wp-json/contentspy/v1/status
        register_rest_route($ns, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_status'],
            'permission_callback' => $perm,
        ]);

        // POST /wp-json/contentspy/v1/publish
        register_rest_route($ns, '/publish', [
            'methods'             => 'POST',
            'callback'            => [$this, 'publish_post'],
            'permission_callback' => $perm,
        ]);

        // GET /wp-json/contentspy/v1/posts
        register_rest_route($ns, '/posts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_posts'],
            'permission_callback' => $perm,
        ]);

        // POST /wp-json/contentspy/v1/sync
        register_rest_route($ns, '/sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'sync_posts'],
            'permission_callback' => $perm,
        ]);

        // DELETE /wp-json/contentspy/v1/posts/{id}
        register_rest_route($ns, '/posts/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'delete_post'],
            'permission_callback' => $perm,
            'args'                => ['id' => ['required' => true, 'type' => 'integer']],
        ]);
    }

    /** GET /status — returns site health info */
    public function get_status(\WP_REST_Request $request): \WP_REST_Response {
        ContentSpy_Logger::log('status', 'GET', 200);

        $categories = get_categories(['hide_empty' => false]);
        $users = get_users(['fields' => ['ID', 'display_name']]);

        return new \WP_REST_Response([
            'status'          => 'connected',
            'wp_version'      => get_bloginfo('version'),
            'php_version'     => PHP_VERSION,
            'plugin_version'  => CONTENTSPY_VERSION,
            'site_url'        => get_site_url(),
            'categories'      => array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug], $categories),
            'authors'         => array_map(fn($u) => ['id' => $u->ID, 'name' => $u->display_name], $users),
            'active_plugins'  => array_keys(get_plugins()),
        ], 200);
    }

    /** POST /publish — create or update a WordPress post */
    public function publish_post(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_json_params();

        $post_data = [
            'post_title'   => sanitize_text_field($params['title'] ?? ''),
            'post_content' => wp_kses_post($params['content'] ?? ''),
            'post_excerpt' => sanitize_textarea_field($params['excerpt'] ?? ''),
            'post_status'  => in_array($params['status'] ?? 'draft', ['draft', 'publish', 'pending', 'future'], true)
                              ? $params['status'] : 'draft',
            'post_name'    => sanitize_title($params['slug'] ?? ''),
            'post_author'  => absint($params['author_id'] ?? get_current_user_id()),
        ];

        // Scheduling
        if ($params['status'] === 'future' && !empty($params['scheduled_for'])) {
            $post_data['post_date']     = get_date_from_gmt($params['scheduled_for']);
            $post_data['post_date_gmt'] = $params['scheduled_for'];
        }

        // Update existing post
        if (!empty($params['wp_post_id'])) {
            $post_data['ID'] = absint($params['wp_post_id']);
            $post_id = wp_update_post($post_data, true);
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (is_wp_error($post_id)) {
            ContentSpy_Logger::log('publish', 'POST', 500, $post_id->get_error_message());
            return new \WP_REST_Response(['error' => $post_id->get_error_message()], 500);
        }

        // Categories
        if (!empty($params['categories'])) {
            wp_set_post_categories($post_id, array_map('absint', $params['categories']));
        }

        // Tags
        if (!empty($params['tags'])) {
            wp_set_post_tags($post_id, implode(',', array_map('sanitize_text_field', $params['tags'])));
        }

        // Featured image (upload from URL)
        if (!empty($params['featured_image_url'])) {
            $attachment_id = $this->upload_image_from_url($params['featured_image_url'], $post_id);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }

        // Yoast SEO meta
        if (!empty($params['yoast'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($params['yoast']['title'] ?? ''));
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($params['yoast']['metadesc'] ?? ''));
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($params['yoast']['focuskw'] ?? ''));
        }

        // Rank Math meta
        if (!empty($params['rankmath'])) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($params['rankmath']['title'] ?? ''));
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($params['rankmath']['description'] ?? ''));
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($params['rankmath']['focus_keyword'] ?? ''));
        }

        // Custom fields
        if (!empty($params['custom_fields']) && is_array($params['custom_fields'])) {
            foreach ($params['custom_fields'] as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
            }
        }

        ContentSpy_Logger::log('publish', 'POST', 201);

        return new \WP_REST_Response([
            'post_id'  => $post_id,
            'post_url' => get_permalink($post_id),
            'status'   => get_post_status($post_id),
        ], 201);
    }

    /** GET /posts — list posts */
    public function get_posts(\WP_REST_Request $request): \WP_REST_Response {
        $posts = get_posts([
            'numberposts' => absint($request->get_param('per_page') ?? 50),
            'offset'      => absint($request->get_param('offset') ?? 0),
            'post_status' => 'any',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        $data = array_map(function ($post) {
            return [
                'id'         => $post->ID,
                'title'      => get_the_title($post),
                'slug'       => $post->post_name,
                'status'     => $post->post_status,
                'date'       => $post->post_date_gmt,
                'url'        => get_permalink($post),
                'categories' => wp_get_post_categories($post->ID, ['fields' => 'names']),
                'tags'       => wp_get_post_tags($post->ID, ['fields' => 'names']),
            ];
        }, $posts);

        ContentSpy_Logger::log('posts', 'GET', 200);

        return new \WP_REST_Response(['posts' => $data, 'total' => count($data)], 200);
    }

    /** POST /sync — trigger full sync */
    public function sync_posts(\WP_REST_Request $request): \WP_REST_Response {
        // Dispatch a background sync (in production this would use WP Cron or Action Scheduler)
        do_action('contentspy_sync_posts');
        ContentSpy_Logger::log('sync', 'POST', 200);
        return new \WP_REST_Response(['message' => 'Sync initiated.'], 200);
    }

    /** DELETE /posts/{id} */
    public function delete_post(\WP_REST_Request $request): \WP_REST_Response {
        $post_id = absint($request->get_param('id'));
        $force   = (bool) $request->get_param('force');

        $result = $force ? wp_delete_post($post_id, true) : wp_trash_post($post_id);

        if (!$result) {
            return new \WP_REST_Response(['error' => 'Post not found or deletion failed.'], 404);
        }

        ContentSpy_Logger::log('delete_post', 'DELETE', 200);

        return new \WP_REST_Response(['message' => 'Post deleted.', 'post_id' => $post_id], 200);
    }

    /** Upload image from URL to media library */
    private function upload_image_from_url(string $url, int $post_id): int|\WP_Error {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($url);
        if (is_wp_error($tmp)) return $tmp;

        $file = [
            'name'     => basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg',
            'type'     => mime_content_type($tmp),
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize($tmp),
        ];

        $attachment_id = media_handle_sideload($file, $post_id);

        @unlink($tmp);
        return $attachment_id;
    }
}
