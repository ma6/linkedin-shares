<?php
/**
 * Title generation for an imported share.
 *
 * Three sources, in order of precedence:
 *
 *   1. the `lsi_generate_title` filter — an explicit override for any site
 *      that wants to wire its own logic (a different model, a taxonomy lookup,
 *      a hand-maintained map). Return a non-empty string to win.
 *   2. the configured `lsi_title_source` option:
 *        - `ai`        the WordPress AI Client (Settings → Connections). Falls
 *                      back to the first-line heuristic if no provider is
 *                      connected or the call fails.
 *        - `firstline` the heuristic below — no LLM required.
 *        - `none`      leave the draft title empty ("Add title").
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derives a post title from a share.
 */
final class LSI_Title {

	const MAX_LEN = 120;

	/**
	 * Whether the WordPress AI Client is present at all.
	 *
	 * @return bool
	 */
	public static function ai_client_present(): bool {
		return function_exists( 'wp_ai_client_prompt' ) || class_exists( 'AI_Client' );
	}

	/**
	 * The configured source, one of `ai` | `firstline` | `none`.
	 *
	 * @return string
	 */
	public static function source(): string {
		$source = (string) get_option( 'lsi_title_source', 'ai' );
		return in_array( $source, array( 'ai', 'firstline', 'none' ), true ) ? $source : 'ai';
	}

	/**
	 * Generate a title for a share. Always returns a string; '' means
	 * "leave it for the editor".
	 *
	 * @param LSI_Share $share Share to title.
	 * @return string
	 */
	public static function generate( LSI_Share $share ): string {
		$body = implode( "\n\n", $share->paragraphs );

		/**
		 * Filter the generated title before any built-in source runs.
		 *
		 * @param string    $title Empty string by default.
		 * @param string    $body  The share commentary, paragraphs joined by blank lines.
		 * @param string    $url   The LinkedIn share permalink.
		 * @param LSI_Share $share The full share object.
		 */
		$pre = apply_filters( 'lsi_generate_title', '', $body, $share->share_url, $share );
		if ( is_string( $pre ) && '' !== trim( $pre ) ) {
			return self::tidy( $pre );
		}

		$source = self::source();

		if ( 'none' === $source ) {
			return '';
		}

		if ( 'ai' === $source ) {
			$ai = self::from_ai( $body );
			if ( '' !== $ai ) {
				return $ai;
			}
			// No provider connected, or the request failed — degrade to the
			// heuristic rather than shipping an untitled draft.
		}

		return self::from_first_line( $share );
	}

	/**
	 * Ask the WordPress AI Client for a title. Returns '' on any problem so the
	 * caller can fall back.
	 *
	 * @param string $body Share commentary.
	 * @return string
	 */
	private static function from_ai( string $body ): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}

		$prompt = (string) get_option( 'lsi_ai_prompt', self::default_prompt() );
		$prompt = str_replace( '{{content}}', $body, $prompt );

		try {
			$builder = wp_ai_client_prompt( $prompt );
			if (
				is_object( $builder )
				&& method_exists( $builder, 'is_supported_for_text_generation' )
				&& ! $builder->is_supported_for_text_generation()
			) {
				return '';
			}
			$result = is_object( $builder ) && method_exists( $builder, 'generate_text' )
				? $builder->generate_text()
				: '';
		} catch ( \Throwable $e ) {
			return '';
		}

		if ( is_wp_error( $result ) || ! is_string( $result ) || '' === trim( $result ) ) {
			return '';
		}

		return self::tidy( $result );
	}

	/**
	 * Heuristic title, no LLM: the first line if it already reads like a
	 * heading, otherwise the first sentence, otherwise the opening words.
	 *
	 * @param LSI_Share $share Share to title.
	 * @return string
	 */
	public static function from_first_line( LSI_Share $share ): string {
		$first = $share->paragraphs[0] ?? '';
		$first = trim( preg_replace( '/\s+/u', ' ', $first ) );
		if ( '' === $first ) {
			return '';
		}

		// Drop a leading emoji / bullet and surrounding quotes.
		$first = self::strip_edges( $first );

		// A short opening line with at most one sentence break is very often
		// already the headline the author intended.
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $first, -1, PREG_SPLIT_NO_EMPTY );
		if ( is_array( $sentences ) && count( $sentences ) >= 1 ) {
			$candidate = self::strip_edges( $sentences[0] );
			if ( mb_strlen( $candidate ) >= 12 && mb_strlen( $candidate ) <= 90 ) {
				return self::tidy( rtrim( $candidate, '.' ) );
			}
		}

		if ( mb_strlen( $first ) <= 90 ) {
			return self::tidy( rtrim( $first, '.' ) );
		}

		// Fall back to the opening words.
		$words = preg_split( '/\s+/u', $first, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_slice( (array) $words, 0, 10 );
		return self::tidy( implode( ' ', $words ) . '…' );
	}

	/**
	 * Trim quotes, dashes, bullets and leading emoji from both ends.
	 *
	 * @param string $text Text to trim.
	 * @return string
	 */
	private static function strip_edges( string $text ): string {
		$text = trim( $text );
		$text = preg_replace( '/^[\p{So}\p{Sk}\x{2022}\x{2013}\x{2014}\-\s]+/u', '', $text );
		$text = trim( $text, " \t\n\r\0\x0B\"'“”«»‚‘’" );
		return trim( $text );
	}

	/**
	 * Normalise a candidate title: strip tags, collapse whitespace, drop
	 * wrapping quotes, cap the length.
	 *
	 * @param string $text Candidate title.
	 * @return string
	 */
	private static function tidy( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = self::strip_edges( trim( $text ) );
		if ( mb_strlen( $text ) > self::MAX_LEN ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_LEN - 1 ) ) . '…';
		}
		return $text;
	}

	/**
	 * Default AI prompt. `{{content}}` is replaced with the commentary.
	 *
	 * @return string
	 */
	public static function default_prompt(): string {
		return "Write one concise, engaging blog-post title for the LinkedIn post below. "
			. "Nine words or fewer. Reply with the title only — no quotation marks, no trailing "
			. "punctuation, written in the same language as the post.\n\n{{content}}";
	}
}
