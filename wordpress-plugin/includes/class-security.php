<?php
defined('ABSPATH') || exit;

class ContentSpy_Security {

    /**
     * Verify HMAC signature on incoming SaaS requests.
     * Uses timing-safe comparison (hash_equals) — NEVER strcmp.
     *
     * Header: X-ContentSpy-Signature: sha256=<hex_digest>
     */
    public static function verify_request(\WP_REST_Request $request): bool {
        $secret = ContentSpy_Settings::get_plugin_secret();
        if (empty($secret)) return false;

        $signature_header = $request->get_header('X-ContentSpy-Signature');
        if (empty($signature_header)) return false;

        // Format: sha256=<hex>
        if (!str_starts_with($signature_header, 'sha256=')) return false;
        $received_sig = substr($signature_header, 7);

        $body = $request->get_body();
        $expected_sig = hash_hmac('sha256', $body, $secret);

        // Timing-safe comparison — critical for HMAC verification
        return hash_equals($expected_sig, $received_sig);
    }

    /**
     * Check if the requesting IP is whitelisted (optional feature).
     */
    public static function is_ip_allowed(): bool {
        $whitelist = ContentSpy_Settings::get_option('ip_whitelist', '');
        if (empty($whitelist)) return true; // no whitelist = allow all

        $allowed_ips = array_map('trim', explode("\n", $whitelist));
        $client_ip   = self::get_client_ip();

        return in_array($client_ip, $allowed_ips, true);
    }

    private static function get_client_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '';
    }

    /** Rate limiting: max 60 requests/minute per endpoint using transients */
    public static function check_rate_limit(string $endpoint): bool {
        $key   = 'contentspy_rl_' . md5($endpoint . self::get_client_ip());
        $count = (int) get_transient($key);
        if ($count >= 60) return false;
        set_transient($key, $count + 1, 60);
        return true;
    }

    /** Permission callback used by all custom REST endpoints */
    public static function rest_permission_callback(\WP_REST_Request $request): bool {
        // Rate limiting check
        if (!self::check_rate_limit($request->get_route())) {
            return false;
        }

        // IP whitelist check
        if (!self::is_ip_allowed()) {
            return false;
        }

        // HMAC signature verification
        return self::verify_request($request);
    }
}
