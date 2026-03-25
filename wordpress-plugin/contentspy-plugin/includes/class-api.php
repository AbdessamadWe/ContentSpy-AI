<?php
class ContentSpy_API {
  private $hmac_secret;

  public function __construct() {
    $this->hmac_secret = get_option('contentspy_hmac_secret', '');
  }

  public function verify_signature(string $payload, string $signature): bool {
    $expected = hash_hmac('sha256', $payload, $this->hmac_secret);
    return hash_equals($expected, $signature); // timing-attack safe
  }
}
