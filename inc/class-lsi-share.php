<?php
/**
 * One parsed LinkedIn share.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object for a single row of the Shares CSV, after cleanup.
 */
final class LSI_Share {

	/**
	 * Stable id used for de-duplication: `urn:li:share:123`,
	 * `urn:li:ugcPost:123`, or `lsi:hash:<md5>` when the export row carried no
	 * recognisable URN.
	 *
	 * @var string
	 */
	public string $urn = '';

	/**
	 * The share permalink exactly as exported (percent-encoded), safe to use
	 * as an href.
	 *
	 * @var string
	 */
	public string $share_url = '';

	/**
	 * Original timestamp string from the export, e.g. `2026-07-28 06:00:29`
	 * (UTC — see LSI_CSV).
	 *
	 * @var string
	 */
	public string $date_raw = '';

	/**
	 * Unix timestamp derived from {@see $date_raw}.
	 *
	 * @var int
	 */
	public int $timestamp = 0;

	/**
	 * Audience token, e.g. `MEMBER_NETWORK`, `PUBLIC`, `CONNECTIONS`.
	 *
	 * @var string
	 */
	public string $visibility = '';

	/**
	 * The commentary split into clean paragraphs.
	 *
	 * @var string[]
	 */
	public array $paragraphs = array();

	/**
	 * `SharedUrl` column — the article/link the share pointed at, or ''.
	 *
	 * @var string
	 */
	public string $shared_url = '';

	/**
	 * `MediaUrl` column — an attached image, or ''.
	 *
	 * @var string
	 */
	public string $media_url = '';

	/**
	 * Number of clean paragraphs.
	 *
	 * @return int
	 */
	public function paragraph_count(): int {
		return count( $this->paragraphs );
	}

	/**
	 * A short, plain-text preview of the commentary.
	 *
	 * @param int $chars Maximum length.
	 * @return string
	 */
	public function excerpt( int $chars = 140 ): string {
		$text = trim( implode( ' ', $this->paragraphs ) );
		if ( mb_strlen( $text ) <= $chars ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $chars ) ) . '…';
	}

	/**
	 * Serialise for storage in a transient between the review and import steps.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'urn'        => $this->urn,
			'share_url'  => $this->share_url,
			'date_raw'   => $this->date_raw,
			'timestamp'  => $this->timestamp,
			'visibility' => $this->visibility,
			'paragraphs' => $this->paragraphs,
			'shared_url' => $this->shared_url,
			'media_url'  => $this->media_url,
		);
	}

	/**
	 * Rebuild from {@see to_array()} output.
	 *
	 * @param array<string,mixed> $data Serialised share.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$share             = new self();
		$share->urn        = (string) ( $data['urn'] ?? '' );
		$share->share_url  = (string) ( $data['share_url'] ?? '' );
		$share->date_raw   = (string) ( $data['date_raw'] ?? '' );
		$share->timestamp  = (int) ( $data['timestamp'] ?? 0 );
		$share->visibility = (string) ( $data['visibility'] ?? '' );
		$share->paragraphs = array_values( array_map( 'strval', (array) ( $data['paragraphs'] ?? array() ) ) );
		$share->shared_url = (string) ( $data['shared_url'] ?? '' );
		$share->media_url  = (string) ( $data['media_url'] ?? '' );
		return $share;
	}
}
