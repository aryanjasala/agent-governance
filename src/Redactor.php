<?php
/**
 * Keeps secrets out of the log and big payloads small.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the values of secret-looking keys with "[redacted]" and cuts JSON
 * that is over the size limit down to a marked preview.
 */
final class Redactor {

	public const MASK = '[redacted]';

	/**
	 * Default secret key names. A key matches when it is the name, or has it as
	 * one of its words: "user_pass" and "client_secret" match, "passport" does not.
	 */
	private const KEYS = array( 'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'api_key', 'apikey', 'auth', 'authorization', 'cookie', 'nonce', 'private_key', 'card_number', 'cvv', 'cvc' );

	/**
	 * Key names whose values are hidden.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		/**
		 * Filters the input keys whose values never reach the log.
		 *
		 * A key matches when it is one of these, or has one as a word: with the
		 * default "pass", "user_pass" and "pass-1" match but "passport" does not.
		 * Case, dashes and camelCase are ignored.
		 *
		 * @param string[] $keys Key names.
		 */
		$keys = (array) apply_filters( 'agent_governance_redact_keys', self::KEYS );

		return array_values( array_filter( array_map( array( self::class, 'normalize' ), $keys ) ) );
	}

	/**
	 * Replaces secret values, at any depth.
	 *
	 * @param mixed         $value Input.
	 * @param string[]|null $keys  Key names, or null for keys().
	 * @return mixed
	 */
	public static function redact( $value, ?array $keys = null ) {
		$keys = null === $keys ? self::keys() : $keys;

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::matches( $key, $keys ) && null !== $item && '' !== $item && array() !== $item ) {
				$value[ $key ] = self::MASK;
			} else {
				$value[ $key ] = self::redact( $item, $keys );
			}
		}

		return $value;
	}

	/**
	 * Whether a key is secret.
	 *
	 * @param string   $key  Key.
	 * @param string[] $keys Normalised secret names.
	 * @return bool
	 */
	public static function matches( string $key, array $keys ): bool {
		$key = self::normalize( $key );
		if ( '' === $key ) {
			return false;
		}

		foreach ( $keys as $name ) {
			if ( $key === $name || false !== strpos( '_' . $key . '_', '_' . $name . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * JSON for storage: redacted, and cut to a preview when over the limit.
	 *
	 * @param mixed  $value   Value.
	 * @param string $context input, result or dry_run, for the size limit.
	 * @param bool   $redact  Whether to hide secrets.
	 * @return string
	 */
	public static function encode( $value, string $context, bool $redact = true ): string {
		$json  = (string) wp_json_encode( $redact ? self::redact( $value ) : $value );
		$limit = Settings::payload_limit( $context );

		if ( strlen( $json ) <= $limit ) {
			return $json;
		}

		return (string) wp_json_encode(
			array(
				'_truncated' => true,
				'_bytes'     => strlen( $json ),
				'_preview'   => function_exists( 'mb_strcut' ) ? mb_strcut( $json, 0, $limit - 200 ) : substr( $json, 0, $limit - 200 ),
			)
		);
	}

	/**
	 * Lower case, words joined by underscores.
	 *
	 * @param mixed $key Key.
	 * @return string
	 */
	private static function normalize( $key ): string {
		$key = preg_replace( '/([a-z])([A-Z])/', '$1_$2', (string) $key );

		return trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $key ) ), '_' );
	}
}
