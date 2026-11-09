<?php

namespace CorinthianPro\Ai;

use WP_Error;

/**
 * Thin wrapper around the WordPress AI client (WordPress 7.0+).
 *
 * The site admin picks a provider under Settings > Connectors; core ships
 * none, so every call can come back unavailable.
 */
final class AiClient {

	public function available(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}
		try {
			return true === wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<mixed>|WP_Error
	 */
	public function json( string $system, string $user, array $schema, float $temperature = 0.2 ): array|WP_Error {
		$raw = $this->generate( $system, $user, $schema, $temperature );
		if ( is_wp_error( $raw ) || is_array( $raw ) ) {
			return $raw;
		}
		$text = trim( $raw );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
			$text = $m[1];
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'corinthian_ai_bad_response', __( 'The AI provider returned a response that is not valid JSON.', 'corinthian-pro' ), array( 'status' => 502 ) );
		}
		return $data;
	}

	public function text( string $system, string $user, float $temperature = 0.2 ): string|WP_Error {
		$raw = $this->generate( $system, $user, null, $temperature );
		if ( is_array( $raw ) ) {
			return new WP_Error( 'corinthian_ai_bad_response', __( 'The AI provider returned an unexpected response.', 'corinthian-pro' ), array( 'status' => 502 ) );
		}
		return $raw;
	}

	/**
	 * @param array<string,mixed>|null $schema
	 * @return string|array<mixed>|WP_Error
	 */
	private function generate( string $system, string $user, ?array $schema, float $temperature ): string|array|WP_Error {
		/**
		 * Short-circuit the AI call. Return a string (raw model output), an
		 * array (decoded JSON) or a WP_Error to skip the provider entirely.
		 *
		 * @param mixed                    $response null to call the provider.
		 * @param string                   $system   System instruction.
		 * @param string                   $user     User message.
		 * @param array<string,mixed>|null $schema   JSON schema, or null for plain text.
		 */
		$override = apply_filters( 'corinthian_ai_response', null, $system, $user, $schema );
		if ( null !== $override ) {
			if ( is_string( $override ) || is_array( $override ) || is_wp_error( $override ) ) {
				return $override;
			}
			return new WP_Error( 'corinthian_ai_bad_response', __( 'The AI provider returned an unexpected response.', 'corinthian-pro' ), array( 'status' => 502 ) );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! $this->available() ) {
			return self::unavailable();
		}

		try {
			$builder = wp_ai_client_prompt( $user )
				->using_system_instruction( $system )
				->using_temperature( $temperature );
			if ( null !== $schema ) {
				$builder = $builder->as_json_response( $schema );
			}
			$result = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'corinthian_ai_failed', __( 'The AI request failed.', 'corinthian-pro' ), array( 'status' => 502 ) );
		}

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 502 ) ) );
			}
			return $result;
		}
		return is_string( $result ) ? $result : new WP_Error( 'corinthian_ai_bad_response', __( 'The AI provider returned an unexpected response.', 'corinthian-pro' ), array( 'status' => 502 ) );
	}

	public static function unavailable(): WP_Error {
		return new WP_Error(
			'corinthian_ai_unavailable',
			__( 'AI features are unavailable. An administrator needs to connect an AI provider under Settings > Connectors.', 'corinthian-pro' ),
			array( 'status' => 503 )
		);
	}
}
