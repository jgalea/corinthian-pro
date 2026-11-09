<?php
// Test-only mu-plugin: answers Corinthian's AI calls with canned data. Never calls a provider.

add_filter(
	'corinthian_ai_response',
	function ( $response, $system, $user, $schema ) {
		$block = function ( string $tag ) use ( $user ) {
			if ( ! preg_match( '#<' . $tag . '>\s*(.*?)\s*</' . $tag . '>#s', $user, $m ) ) {
				return null;
			}
			return json_decode( $m[1], true );
		};

		if ( $block( 'request' ) !== null ) {
			$text = (string) $block( 'request' );
			if ( false !== strpos( $text, 'FAIL' ) ) {
				return new WP_Error( 'corinthian_ai_unavailable', 'AI features are unavailable. An administrator needs to connect an AI provider under Settings > Connectors.', array( 'status' => 503 ) );
			}
			$value = trim( (string) preg_replace( '/^.*exactly\s+/i', '', $text ) );
			$rules = array();
			foreach ( (array) $block( 'columns' ) as $column ) {
				if ( '64882268049ba4' !== $column['id'] ) {
					continue;
				}
				foreach ( $column['operators'] as $operator ) {
					if ( 'is' === $operator['label'] ) {
						$rules[] = array(
							'column' => $column['id'],
							'op'     => $operator['op'],
							'value'  => $value,
						);
					}
				}
			}
			$rules[] = array(
				'column' => 'no-such-column',
				'op'     => 'is',
				'value'  => 'x',
			);
			return array(
				'match' => 'all',
				'rules' => $rules,
			);
		}

		if ( $block( 'instruction' ) !== null ) {
			$tag         = (string) $block( 'instruction' );
			$suggestions = array();
			foreach ( (array) $block( 'items' ) as $item ) {
				$suggestions[] = array(
					'id'        => $item['id'],
					'suggested' => $tag . ' ' . $item['id'],
				);
			}
			return array( 'suggestions' => $suggestions );
		}
		return $response;
	},
	10,
	4
);

// Core has no filter for "a provider is connected", so tell the table script directly.
add_action(
	'admin_enqueue_scripts',
	function () {
		if ( wp_script_is( 'corinthian-table', 'enqueued' ) ) {
			wp_add_inline_script( 'corinthian-table', 'if (window.corinthianTable && window.corinthianTable.ai) { window.corinthianTable.ai.available = true; }', 'before' );
		}
	},
	99
);
