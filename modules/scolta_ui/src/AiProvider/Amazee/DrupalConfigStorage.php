<?php

declare(strict_types=1);

namespace Drupal\scolta_ui\AiProvider\Amazee;

use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface;

/**
 * Stores Amazee.ai credentials in Drupal State, encrypted at rest.
 *
 * State is used rather than Config Management (CMI) because LiteLLM tokens
 * are secrets that must not be exported to config sync or version control.
 * The token is encrypted with AES-256-CBC using a key derived from hash_salt.
 *
 * @since 1.0.0-rc1
 * @stability experimental
 */
final class DrupalConfigStorage implements ProvenanceAwareConfigStorageInterface {

  private const STATE_KEY = 'scolta.amazee.credentials';
  private const SOURCE_STATE_KEY = 'scolta.amazee.connection_source';
  private const CIPHER = 'AES-256-CBC';
  private const IV_LENGTH = 16;

  public function __construct(private readonly StateInterface $state) {}

  /**
   * {@inheritdoc}
   */
  public function store(string $litellmToken, string $litellmApiUrl, string $region): void {
    $this->state->set(self::STATE_KEY, [
      'litellm_token' => $this->encrypt($litellmToken),
      'litellm_api_url' => $litellmApiUrl,
      'region' => $region,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function load(): ?array {
    $data = $this->state->get(self::STATE_KEY);
    if (!is_array($data) || empty($data['litellm_token'])) {
      return NULL;
    }

    $token = $this->decrypt($data['litellm_token']);
    if ($token === NULL) {
      return NULL;
    }

    return array_merge($data, ['litellm_token' => $token]);
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
    // The provenance goes with the credentials it describes. Left behind, it
    // would be paired with whatever connection comes next, which is a guess
    // wearing a recorded fact's clothes.
    $this->state->delete(self::SOURCE_STATE_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function storeConnectionSource(AmazeeConnectionSource $source): void {
    $this->state->set(self::SOURCE_STATE_KEY, $source->value);
  }

  /**
   * {@inheritdoc}
   */
  public function loadConnectionSource(): ?AmazeeConnectionSource {
    $stored = $this->state->get(self::SOURCE_STATE_KEY);

    // NULL is the right answer for a connection made before provenance was
    // recorded. It must read as "not recorded", never as a default.
    return is_string($stored) ? AmazeeConnectionSource::tryFrom($stored) : NULL;
  }

  /**
   * Encrypt the token using AES-256-CBC with a hash_salt-derived key.
   *
   * Output format: base64(iv . ciphertext)
   */
  private function encrypt(string $plaintext): string {
    $key = $this->deriveKey();
    $iv = random_bytes(self::IV_LENGTH);
    $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ciphertext);
  }

  /**
   * Decrypt a token previously encrypted with encrypt().
   *
   * Returns NULL if decryption fails. For unencrypted legacy tokens (which
   * cannot be base64-decoded into 16+ bytes of IV), returns the raw value so
   * existing installations continue to work after upgrading.
   */
  private function decrypt(string $stored): ?string {
    $raw = base64_decode($stored, strict: TRUE);
    if ($raw === FALSE || strlen($raw) <= self::IV_LENGTH) {
      // Legacy plain-text token — return as-is so existing installations work.
      return $stored;
    }

    $key = $this->deriveKey();
    $iv = substr($raw, 0, self::IV_LENGTH);
    $ciphertext = substr($raw, self::IV_LENGTH);
    $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

    if ($plaintext === FALSE) {
      // Decryption failed — could be a plain-text token that happens to be
      // valid base64. Return the stored value as-is (legacy path).
      return $stored;
    }

    return $plaintext;
  }

  /**
   * Derive a 32-byte AES key from Drupal's hash_salt.
   */
  private function deriveKey(): string {
    return hash('sha256', Settings::getHashSalt(), binary: TRUE);
  }

}
