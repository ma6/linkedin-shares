<?php
/**
 * Parser for LinkedIn's `Shares_*.csv` export.
 *
 * The export is not clean CSV: the commentary field is wrapped in quotes, then
 * every paragraph inside it is *also* wrapped in quotes and split across
 * physical lines, and the whole file is frequently mojibake (UTF-8 bytes that
 * were once decoded as Windows-1252 and re-saved). A strict RFC 4180 reader
 * chokes on it, so this parser is deliberately lenient: it re-assembles records
 * by their leading timestamp, peels the trailing columns off the right, and
 * then repairs and re-paragraphs whatever is left.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns raw CSV bytes into {@see LSI_Share} objects.
 */
final class LSI_CSV {

	/**
	 * Parse the export. De-duplicates by URN, keeping the first occurrence.
	 * Rows whose commentary is empty are dropped.
	 *
	 * @param string $raw File contents.
	 * @return LSI_Share[] Shares, newest first.
	 */
	public static function parse( string $raw ): array {
		$raw = self::strip_bom( $raw );
		$raw = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		$lines = explode( "\n", $raw );

		// Drop everything up to and including the header row.
		foreach ( $lines as $i => $line ) {
			if ( false !== strpos( $line, 'ShareCommentary' ) && 0 === strpos( $line, 'Date,' ) ) {
				$lines = array_slice( $lines, $i + 1 );
				break;
			}
		}

		$records    = self::reassemble_records( $lines );
		$shares     = array();
		$seen_urns  = array();

		foreach ( $records as $record ) {
			$share = self::parse_record( $record );
			if ( null === $share ) {
				continue;
			}
			if ( isset( $seen_urns[ $share->urn ] ) ) {
				continue;
			}
			$seen_urns[ $share->urn ] = true;
			$shares[]                 = $share;
		}

		usort(
			$shares,
			static fn( LSI_Share $a, LSI_Share $b ) => $b->timestamp <=> $a->timestamp
		);

		return $shares;
	}

	/**
	 * Group physical lines into logical records. A record starts at a line
	 * beginning with a `YYYY-MM-DD HH:MM:SS,` timestamp; every following line
	 * that does not is a continuation of the current record's commentary.
	 *
	 * @param string[] $lines Physical lines, header already removed.
	 * @return string[] Record strings.
	 */
	private static function reassemble_records( array $lines ): array {
		$records = array();
		$current = null;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2},/', $line ) ) {
				if ( null !== $current ) {
					$records[] = $current;
				}
				$current = $line;
			} elseif ( null !== $current ) {
				$current .= "\n" . $line;
			}
		}

		if ( null !== $current ) {
			$records[] = $current;
		}

		return $records;
	}

	/**
	 * Parse a single reassembled record.
	 *
	 * @param string $record Record string.
	 * @return LSI_Share|null Null when the row has no usable commentary.
	 */
	private static function parse_record( string $record ): ?LSI_Share {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),([^,\n]*),(.*)$/s', $record, $m ) ) {
			return null;
		}

		$date_raw   = $m[1];
		$share_link = self::unwrap( $m[2] );
		$rest       = $m[3];

		$commentary_raw = $rest;
		$shared_url     = '';
		$media_url      = '';
		$visibility     = '';

		// Peel `…,<SharedUrl>,<MediaUrl>,<VISIBILITY>` off the right. Each of
		// the two URL columns is either a quoted string or a bare, comma-free
		// token; the visibility column is an ALL_CAPS token.
		if ( preg_match(
			'/^(.*),("(?:[^"]|"")*"|[^,\n]*),("(?:[^"]|"")*"|[^,\n]*),([A-Z_]{4,})\s*$/s',
			$rest,
			$tail
		) ) {
			$commentary_raw = $tail[1];
			$shared_url     = self::unwrap( $tail[2] );
			$media_url      = self::unwrap( $tail[3] );
			$visibility     = $tail[4];
		} elseif ( preg_match( '/^(.*),([A-Z_]{4,})\s*$/s', $rest, $tail ) ) {
			$commentary_raw = $tail[1];
			$visibility     = $tail[2];
		}

		$paragraphs = self::to_paragraphs( $commentary_raw );
		if ( empty( $paragraphs ) ) {
			return null;
		}

		$share             = new LSI_Share();
		$share->share_url  = $share_link;
		$share->urn        = self::extract_urn( $share_link );
		$share->date_raw   = $date_raw;
		$share->timestamp  = self::to_timestamp( $date_raw );
		$share->visibility = $visibility;
		$share->paragraphs = $paragraphs;
		$share->shared_url = self::fix_mojibake( $shared_url );
		$share->media_url  = self::fix_mojibake( $media_url );

		return $share;
	}

	/**
	 * Turn one raw commentary blob into clean paragraphs.
	 *
	 * @param string $commentary Raw commentary field (still quote-wrapped).
	 * @return string[] Paragraphs, no empties.
	 */
	public static function to_paragraphs( string $commentary ): array {
		$text = self::fix_mojibake( $commentary );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // Non-breaking space.
		$text = preg_replace( '/\x{2028}|\x{2029}/u', "\n", $text );
		$text = trim( $text );

		// Peel one layer of wrapping quotes off the whole blob.
		if ( strlen( $text ) >= 2 && '"' === $text[0] && '"' === substr( $text, -1 ) ) {
			$text = substr( $text, 1, -1 );
		}

		// RFC-style: a doubled quote is a literal quote.
		$text = str_replace( '""', '"', $text );

		$lines   = explode( "\n", $text );
		$paras   = array();
		$buffer  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Strip the per-paragraph wrapping quotes LinkedIn adds; a line
			// that is only quotes is a paragraph separator.
			$line = trim( $line, '"' );
			$line = trim( $line );

			if ( '' === $line ) {
				if ( $buffer ) {
					$paras[]  = implode( ' ', $buffer );
					$buffer   = array();
				}
				continue;
			}
			$buffer[] = $line;
		}
		if ( $buffer ) {
			$paras[] = implode( ' ', $buffer );
		}

		$paras = array_map(
			static fn( $p ) => trim( preg_replace( '/[ \t]+/u', ' ', $p ) ),
			$paras
		);

		return array_values( array_filter( $paras, static fn( $p ) => '' !== $p ) );
	}

	/**
	 * Repair the classic "UTF-8 read as Windows-1252 (or Latin-1), re-saved as
	 * UTF-8" mojibake, including the rare double application.
	 *
	 * A candidate reversal is accepted only when it strictly reduces the number
	 * of tell-tale sequences, its recovered bytes are mostly valid UTF-8, and
	 * it does not shrink the text wildly. That keeps genuine typographic runs
	 * (German quotes, an em dash next to an ellipsis) from being "repaired"
	 * into nothing.
	 *
	 * @param string $text Possibly mangled text.
	 * @return string Repaired text, or the original if no reversal qualified.
	 */
	public static function fix_mojibake( string $text ): string {
		if ( '' === $text || ! function_exists( 'mb_convert_encoding' ) ) {
			return $text;
		}

		$best         = $text;
		$best_markers = self::mojibake_markers( $text );
		if ( 0 === $best_markers ) {
			return $text;
		}

		foreach ( array( 'Windows-1252', 'ISO-8859-1' ) as $encoding ) {
			$candidate = $text;

			for ( $pass = 0; $pass < 3; $pass++ ) {
				$bytes = self::to_single_byte( $candidate, $encoding );
				if ( null === $bytes || '' === $bytes ) {
					break;
				}

				$recovered = self::drop_invalid_utf8( $bytes );

				// The reversal has to actually produce UTF-8, not shred the
				// string.
				if ( '' === $recovered ) {
					break;
				}
				if ( strlen( $recovered ) < strlen( $bytes ) * 0.6 ) {
					break;
				}
				if ( mb_strlen( $recovered ) < mb_strlen( $candidate ) * 0.4 ) {
					break;
				}

				$markers = self::mojibake_markers( $recovered );
				if ( $markers < $best_markers ) {
					$best         = $recovered;
					$best_markers = $markers;
				}
				if ( $recovered === $candidate || 0 === $markers ) {
					break;
				}
				$candidate = $recovered;
			}
		}

		return $best;
	}

	/**
	 * Count runs of two or more consecutive characters in the byte-value range
	 * a single-byte charset can hold. Genuine text keeps such characters apart
	 * with ASCII; double-encoding packs two to four of them together per
	 * original non-ASCII character.
	 *
	 * @param string $text Text to inspect.
	 * @return int
	 */
	private static function mojibake_markers( string $text ): int {
		return (int) preg_match_all(
			'/[\x{0080}-\x{024F}\x{2000}-\x{27BF}\x{2C60}-\x{2C7F}]{2,}/u',
			$text
		);
	}

	/**
	 * Re-encode a UTF-8 string to a single-byte charset, recovering the
	 * original bytes of a double-encoded string. Unmappable characters are
	 * dropped.
	 *
	 * @param string $text     UTF-8 text.
	 * @param string $encoding Target single-byte charset.
	 * @return string|null Byte string, or null on failure.
	 */
	private static function to_single_byte( string $text, string $encoding ): ?string {
		if ( function_exists( 'iconv' ) ) {
			$result = @iconv( 'UTF-8', $encoding . '//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $result ) {
				return $result;
			}
		}
		$result = @mb_convert_encoding( $text, $encoding, 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return ( false === $result ) ? null : $result;
	}

	/**
	 * Decode a byte string as UTF-8, silently dropping any invalid sequences.
	 *
	 * @param string $bytes Byte string.
	 * @return string Clean UTF-8.
	 */
	private static function drop_invalid_utf8( string $bytes ): string {
		$previous = mb_substitute_character();
		mb_substitute_character( 'none' );
		$result = mb_convert_encoding( $bytes, 'UTF-8', 'UTF-8' );
		mb_substitute_character( $previous );
		return is_string( $result ) ? $result : '';
	}

	/**
	 * Extract `urn:li:share:123` / `urn:li:ugcPost:123` from the share
	 * permalink; hash the URL as a last resort so de-duplication still works.
	 *
	 * @param string $share_link The share URL.
	 * @return string
	 */
	private static function extract_urn( string $share_link ): string {
		$decoded = rawurldecode( $share_link );
		if ( preg_match( '#urn:li:(share|ugcPost|activity):(\d+)#i', $decoded, $m ) ) {
			return 'urn:li:' . lcfirst( $m[1] ) . ':' . $m[2];
		}
		return 'lsi:hash:' . md5( $share_link );
	}

	/**
	 * Convert the export timestamp (UTC) to a Unix timestamp.
	 *
	 * @param string $date_raw `YYYY-MM-DD HH:MM:SS`.
	 * @return int
	 */
	private static function to_timestamp( string $date_raw ): int {
		/**
		 * Filter the timezone the export timestamps are read in. LinkedIn
		 * exports in UTC; override if yours differ.
		 *
		 * @param string $timezone A PHP timezone name.
		 * @param string $date_raw The raw timestamp string.
		 */
		$timezone = apply_filters( 'lsi_share_timezone', 'UTC', $date_raw );

		try {
			$dt = new DateTimeImmutable( $date_raw, new DateTimeZone( $timezone ) );
			return $dt->getTimestamp();
		} catch ( \Exception $e ) {
			return (int) strtotime( $date_raw . ' UTC' );
		}
	}

	/**
	 * Strip surrounding quotes and unescape doubled quotes in a simple
	 * (single-line) CSV value. `""` alone becomes an empty string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function unwrap( string $value ): string {
		$value = trim( $value );
		if ( '""' === $value || '' === $value ) {
			return '';
		}
		if ( strlen( $value ) >= 2 && '"' === $value[0] && '"' === substr( $value, -1 ) ) {
			$value = substr( $value, 1, -1 );
		}
		return str_replace( '""', '"', $value );
	}

	/**
	 * Remove a leading UTF-8 byte-order mark.
	 *
	 * @param string $raw File contents.
	 * @return string
	 */
	private static function strip_bom( string $raw ): string {
		if ( 0 === strncmp( $raw, "\xEF\xBB\xBF", 3 ) ) {
			return substr( $raw, 3 );
		}
		return $raw;
	}
}
