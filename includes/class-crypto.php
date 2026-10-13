<?php
/**
 * Encryption at rest for secrets stored in the options table.
 *
 * @package SendFleet
 */

namespace SendFleet;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts the API key and webhook secret before they are written to the
 * database, so a leaked SQL dump or backup does not leak a working key.
 *
 * The key is derived from the site's authentication salts (or from the
 * SENDFLEET_ENCRYPTION_KEY constant when defined). If the salts are rotated
 * the stored value can no longer be decrypted and the admin is asked to paste
 * the key again — nothing fails silently.
 */
final class Crypto {

	/**
	 * Marker for values encrypted with libsodium's secretbox.
	 */
	const PREFIX_SODIUM = 'sf1:';

	/**
	 * Marker for values encrypted with OpenSSL AES-256-GCM.
	 */
	const PREFIX_OPENSSL = 'sf2:';

	/**
	 * Secretbox nonce length in bytes.
	 */
	const SODIUM_NONCE_BYTES = 24;

	/**
	 * Derives the 32-byte symmetric key.
	 *
	 * @return string Raw binary key.
	 */
	private static function key() {
		if ( defined( 'SENDFLEET_ENCRYPTION_KEY' ) && is_string( SENDFLEET_ENCRYPTION_KEY ) && '' !== SENDFLEET_ENCRYPTION_KEY ) {
			$material = SENDFLEET_ENCRYPTION_KEY;
		} else {
			$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}

		return hash_hmac( 'sha256', 'sendfleet-secret-storage-v1', $material, true );
	}

	/**
	 * Encrypts a value for storage.
	 *
	 * @param string $plain Plain-text secret.
	 * @return string Encoded cipher text, or '' for an empty input.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce  = random_bytes( self::SODIUM_NONCE_BYTES );
				$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );

				return self::PREFIX_SODIUM . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of cipher text.
			}

			if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
				$iv     = random_bytes( 12 );
				$tag    = '';
				$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );

				if ( false !== $cipher ) {
					return self::PREFIX_OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage of cipher text.
				}
			}
		} catch ( \Exception $e ) {
			return '';
		}

		// No usable cipher at all: refuse to store the secret rather than store it in clear.
		return '';
	}

	/**
	 * Decrypts a stored value.
	 *
	 * @param string $stored Encoded cipher text.
	 * @return string Plain text, or '' when the value is empty or cannot be decrypted.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return '';
		}

		try {
			if ( 0 === strpos( $stored, self::PREFIX_SODIUM ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$raw = base64_decode( substr( $stored, strlen( self::PREFIX_SODIUM ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage of cipher text.

				if ( false === $raw || strlen( $raw ) <= self::SODIUM_NONCE_BYTES ) {
					return '';
				}

				$plain = sodium_crypto_secretbox_open(
					substr( $raw, self::SODIUM_NONCE_BYTES ),
					substr( $raw, 0, self::SODIUM_NONCE_BYTES ),
					self::key()
				);

				return false === $plain ? '' : $plain;
			}

			if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) && function_exists( 'openssl_decrypt' ) ) {
				$raw = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage of cipher text.

				if ( false === $raw || strlen( $raw ) <= 28 ) {
					return '';
				}

				$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

				return false === $plain ? '' : $plain;
			}
		} catch ( \Exception $e ) {
			return '';
		} catch ( \Error $e ) {
			return '';
		}

		return '';
	}
}
