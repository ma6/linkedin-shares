<?php
/**
 * Creates draft posts from selected shares.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns {@see LSI_Share} objects into `draft` posts.
 */
final class LSI_Importer {

	/**
	 * Import a batch.
	 *
	 * @param LSI_Share[] $shares      Shares to import.
	 * @param int         $category_id Optional category to assign to all of them.
	 * @return array{created:int[],skipped:array<int,array{urn:string,reason:string}>}
	 */
	public static function run( array $shares, int $category_id = 0 ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$created = array();
		$skipped = array();

		foreach ( $shares as $share ) {
			if ( $share->paragraph_count() < 1 ) {
				$skipped[] = array(
					'urn'    => $share->urn,
					'reason' => __( 'no text', 'linkedin-shares-importer' ),
				);
				continue;
			}

			$existing = self::find_existing( $share->urn );
			if ( $existing ) {
				$skipped[] = array(
					'urn'    => $share->urn,
					'reason' => sprintf(
						/* translators: %d: existing post ID. */
						__( 'already imported (post #%d)', 'linkedin-shares-importer' ),
						$existing
					),
				);
				continue;
			}

			$gmt   = gmdate( 'Y-m-d H:i:s', $share->timestamp );
			$local = get_date_from_gmt( $gmt );

			$postarr = array(
				'post_type'     => 'post',
				'post_status'   => 'draft',
				'post_title'    => LSI_Title::generate( $share ),
				'post_content'  => self::build_content( $share ),
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
				'meta_input'    => array(
					'_lsi_share_urn'  => $share->urn,
					'_lsi_share_url'  => $share->share_url,
					'_lsi_share_date' => $share->date_raw,
				),
			);

			if ( $category_id > 0 ) {
				$postarr['post_category'] = array( $category_id );
			}

			/**
			 * Filter the post array just before insertion.
			 *
			 * @param array     $postarr wp_insert_post() arguments.
			 * @param LSI_Share $share   The source share.
			 */
			$postarr = apply_filters( 'lsi_pre_insert_post', $postarr, $share );

			$post_id = wp_insert_post( wp_slash( $postarr ), true );

			if ( is_wp_error( $post_id ) ) {
				$skipped[] = array(
					'urn'    => $share->urn,
					'reason' => $post_id->get_error_message(),
				);
				continue;
			}

			/**
			 * Fires after a share has been imported.
			 *
			 * @param int       $post_id New draft post ID.
			 * @param LSI_Share $share   The source share.
			 */
			do_action( 'lsi_imported_share', $post_id, $share );

			$created[] = (int) $post_id;
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * Find a post already imported from this share.
	 *
	 * @param string $urn Share URN.
	 * @return int Post ID, or 0.
	 */
	public static function find_existing( string $urn ): int {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => '_lsi_share_urn', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $urn, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Build the post body: one `wp:paragraph` block per paragraph, then the
	 * optional attribution line.
	 *
	 * @param LSI_Share $share Source share.
	 * @return string
	 */
	private static function build_content( LSI_Share $share ): string {
		$blocks = array();

		foreach ( $share->paragraphs as $paragraph ) {
			$html     = make_clickable( esc_html( $paragraph ) );
			$blocks[] = "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
		}

		if ( get_option( 'lsi_attribution_enabled', 1 ) ) {
			$line = self::render_attribution(
				(string) get_option( 'lsi_attribution_template', LSI_Settings::default_attribution() ),
				$share->share_url
			);
			if ( '' !== $line ) {
				$blocks[] = "<!-- wp:paragraph -->\n<p>{$line}</p>\n<!-- /wp:paragraph -->";
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Render the attribution template: `{{url}}` token and a restricted
	 * `[label](target)` link syntax. Everything else is escaped as text.
	 *
	 * @param string $template Stored template.
	 * @param string $url      Share permalink.
	 * @return string Safe HTML, or '' when the template is empty.
	 */
	private static function render_attribution( string $template, string $url ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return '';
		}

		$template = str_replace( '{{url}}', $url, $template );

		$template = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '">' . esc_html( $m[1] ) . "</a>\x00";
			},
			$template
		);

		// Escape the non-link segments without touching the anchors we made.
		$parts  = preg_split( '/(<a href="[^"]*">[^<]*<\/a>)\x00/', $template, -1, PREG_SPLIT_DELIM_CAPTURE );
		$output = '';
		foreach ( (array) $parts as $part ) {
			$output .= ( 0 === strpos( $part, '<a href="' ) ) ? $part : esc_html( str_replace( "\x00", '', $part ) );
		}

		return '<em>' . $output . '</em>';
	}
}
