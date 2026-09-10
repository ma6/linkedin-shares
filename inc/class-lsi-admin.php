<?php
/**
 * The importer screen: Tools → LinkedIn Shares.
 *
 * Three states on one page, all POSTing back to it:
 *
 *   1. upload    — choose the CSV.
 *   2. review    — a table of every parsed share; tick the ones to import.
 *                  Shares with two or more paragraphs from the last three
 *                  years are pre-ticked. "Apply filter" recomputes that.
 *   3. results   — what got created, what was skipped and why.
 *
 * Parsed shares live in a per-user transient between step 2 and step 3, so the
 * file is uploaded and parsed only once.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles the importer screen.
 */
final class LSI_Admin {

	const PAGE        = 'lsi-import';
	const HOOK_SUFFIX = 'tools_page_lsi-import';
	const MAX_BYTES   = 67108864; // 64 MB — the LinkedIn export ZIP. PHP's own upload limit is usually the real gate.
	const TTL         = 7200;    // 2 hours.

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Register the page under Tools.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_management_page(
			__( 'Import LinkedIn Shares', 'linkedin-shares-importer' ),
			__( 'LinkedIn Shares', 'linkedin-shares-importer' ),
			'import',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enqueue the screen's small stylesheet and helper script.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function assets( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'lsi-admin',
			LSI_URL . 'assets/admin.css',
			array(),
			LSI_VERSION
		);
		wp_enqueue_script(
			'lsi-admin',
			LSI_URL . 'assets/admin.js',
			array(),
			LSI_VERSION,
			true
		);
	}

	/**
	 * Transient key for the current user.
	 *
	 * @return string
	 */
	private static function stash_key(): string {
		return 'lsi_pending_' . get_current_user_id();
	}

	/**
	 * Route the request.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'You are not allowed to import content.', 'linkedin-shares-importer' ) );
		}

		$action = isset( $_POST['lsi_action'] )
			? sanitize_key( wp_unslash( $_POST['lsi_action'] ) )
			: '';

		echo '<div class="wrap lsi-wrap">';
		echo '<h1>' . esc_html__( 'Import LinkedIn Shares', 'linkedin-shares-importer' ) . '</h1>';

		if ( 'import' === $action ) {
			self::handle_import();
		} elseif ( 'review' === $action || ! empty( $_FILES['lsi_csv']['name'] ) ) {
			self::handle_review();
		} else {
			self::render_upload();
		}

		echo '</div>';
	}

	/**
	 * Step 1 — the upload form.
	 *
	 * @param string $notice Optional error notice to show above the form.
	 * @return void
	 */
	private static function render_upload( string $notice = '' ): void {
		if ( '' !== $notice ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $notice )
			);
		}

		echo '<h2>' . esc_html__( 'Where to get the file', 'linkedin-shares-importer' ) . '</h2>';
		echo '<ol class="lsi-steps">';
		printf(
			'<li>%s</li>',
			wp_kses(
				sprintf(
					/* translators: %s: LinkedIn download-my-data URL. */
					__( 'Open <a href="%s" target="_blank" rel="noreferrer noopener">LinkedIn → Data privacy → Get a copy of your data</a>.', 'linkedin-shares-importer' ),
					'https://www.linkedin.com/mypreferences/d/download-my-data'
				),
				array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
			)
		);
		printf(
			'<li>%s</li>',
			wp_kses(
				__( 'Choose <strong>“Download larger data archive”</strong> (the complete export). <code>Shares_*.csv</code> is only in that one — the fast, file-by-file download does not include it.', 'linkedin-shares-importer' ),
				array( 'strong' => array(), 'code' => array() )
			)
		);
		echo '<li>' . esc_html__( 'LinkedIn emails you a ZIP within roughly 24 hours.', 'linkedin-shares-importer' ) . '</li>';
		echo '<li>' . esc_html__( 'Upload that ZIP below as-is, or unzip it and upload Shares_*.csv from inside.', 'linkedin-shares-importer' ) . '</li>';
		echo '</ol>';
		echo '<p>' . esc_html__( 'You then pick which shares to import; nothing is created until you confirm.', 'linkedin-shares-importer' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'lsi_upload' );
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row"><label for="lsi_csv">' . esc_html__( 'Archive or CSV', 'linkedin-shares-importer' ) . '</label></th>';
		echo '<td><input type="file" name="lsi_csv" id="lsi_csv" accept=".zip,.csv,application/zip,text/csv" required> ';
		printf(
			'<p class="description">%s</p></td>',
			esc_html(
				sprintf(
					/* translators: %s: server upload size limit, e.g. "2 MB". */
					__( 'The LinkedIn export ZIP, or Shares_*.csv extracted from it. This server accepts uploads up to %s.', 'linkedin-shares-importer' ),
					size_format( wp_max_upload_size() )
				)
			)
		);
		echo '</tr></tbody></table>';
		submit_button( __( 'Upload and review', 'linkedin-shares-importer' ) );
		echo '</form>';
	}

	/**
	 * Step 2 — parse (on upload) or reload (on "Apply filter"), then render the
	 * review table.
	 *
	 * @return void
	 */
	private static function handle_review(): void {
		$from_upload = ! empty( $_FILES['lsi_csv']['name'] );

		if ( $from_upload ) {
			check_admin_referer( 'lsi_upload' );

			$file = self::read_upload();
			if ( is_wp_error( $file ) ) {
				self::render_upload( $file->get_error_message() );
				return;
			}

			$shares = LSI_CSV::parse( $file );
			if ( empty( $shares ) ) {
				self::render_upload( __( 'No shares with text were found in that file. Is it the "Shares" export?', 'linkedin-shares-importer' ) );
				return;
			}

			set_transient(
				self::stash_key(),
				array_map( static fn( LSI_Share $s ) => $s->to_array(), $shares ),
				self::TTL
			);
		} else {
			check_admin_referer( 'lsi_process' );
			$shares = self::load_stash();
			if ( is_wp_error( $shares ) ) {
				self::render_upload( $shares->get_error_message() );
				return;
			}
		}

		self::render_review( $shares, self::filters_from_request() );
	}

	/**
	 * Render the review table.
	 *
	 * @param LSI_Share[]                                  $shares  Parsed shares.
	 * @param array{from:string,to:string,min:int}         $filters Current filter values.
	 * @return void
	 */
	private static function render_review( array $shares, array $filters ): void {
		$from_ts = (int) strtotime( $filters['from'] . ' 00:00:00 UTC' );
		$to_ts   = (int) strtotime( $filters['to'] . ' 23:59:59 UTC' );

		$preselected = 0;
		$rows        = array();

		foreach ( $shares as $share ) {
			$existing = LSI_Importer::find_existing( $share->urn );
			$in_range = ( $share->timestamp >= $from_ts && $share->timestamp <= $to_ts );
			$checked  = ( ! $existing && $in_range && $share->paragraph_count() >= $filters['min'] );
			if ( $checked ) {
				$preselected++;
			}
			$rows[] = array(
				'share'    => $share,
				'existing' => $existing,
				'checked'  => $checked,
			);
		}

		echo '<form method="post" id="lsi-review">';
		wp_nonce_field( 'lsi_process' );

		// Filters.
		echo '<div class="lsi-filters">';
		printf(
			'<label>%s <input type="date" name="lsi_from" value="%s"></label> ',
			esc_html__( 'From', 'linkedin-shares-importer' ),
			esc_attr( $filters['from'] )
		);
		printf(
			'<label>%s <input type="date" name="lsi_to" value="%s"></label> ',
			esc_html__( 'to', 'linkedin-shares-importer' ),
			esc_attr( $filters['to'] )
		);
		printf(
			'<label>%s <input type="number" min="1" step="1" name="lsi_min" value="%d" class="small-text"></label> ',
			esc_html__( 'Min. paragraphs', 'linkedin-shares-importer' ),
			(int) $filters['min']
		);
		printf(
			'<button type="submit" name="lsi_action" value="review" class="button button-secondary">%s</button>',
			esc_html__( 'Apply filter', 'linkedin-shares-importer' )
		);
		echo '<p class="description">' . esc_html__( '"Apply filter" only recomputes which rows are ticked. You can still tick or untick any row by hand before importing.', 'linkedin-shares-importer' ) . '</p>';
		echo '</div>';

		printf(
			'<p class="lsi-count">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: preselected count, 2: total count. */
					__( '%1$d of %2$d shares preselected.', 'linkedin-shares-importer' ),
					$preselected,
					count( $shares )
				)
			)
		);

		echo '<div class="lsi-tablewrap"><table class="widefat striped lsi-table"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="lsi-check-all"></td>';
		echo '<th>' . esc_html__( 'Date', 'linkedin-shares-importer' ) . '</th>';
		echo '<th>' . esc_html__( 'Audience', 'linkedin-shares-importer' ) . '</th>';
		echo '<th>' . esc_html__( '¶', 'linkedin-shares-importer' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'linkedin-shares-importer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			/** @var LSI_Share $share */
			$share    = $row['share'];
			$existing = $row['existing'];

			printf(
				'<tr class="%s">',
				$existing ? 'lsi-done' : ''
			);

			echo '<th scope="row" class="check-column">';
			printf(
				'<input type="checkbox" class="lsi-pick" name="lsi_pick[]" value="%s" %s %s>',
				esc_attr( $share->urn ),
				checked( $row['checked'], true, false ),
				disabled( (bool) $existing, true, false )
			);
			echo '</th>';

			printf(
				'<td class="lsi-date"><a href="%s" target="_blank" rel="noreferrer noopener">%s</a></td>',
				esc_url( $share->share_url ),
				esc_html( self::format_date( $share->timestamp ) )
			);

			printf( '<td>%s</td>', esc_html( self::audience_label( $share->visibility ) ) );
			printf( '<td>%d</td>', $share->paragraph_count() );

			echo '<td class="lsi-preview">';
			echo esc_html( $share->excerpt( 160 ) );
			if ( $existing ) {
				printf(
					' <span class="lsi-badge">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: post ID. */
							__( 'already imported (#%d)', 'linkedin-shares-importer' ),
							$existing
						)
					)
				);
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></div>';

		// Category + import.
		echo '<div class="lsi-actions">';
		echo '<label for="lsi_category">' . esc_html__( 'Assign category', 'linkedin-shares-importer' ) . ' </label>';
		wp_dropdown_categories(
			array(
				'show_option_none'  => __( '— none —', 'linkedin-shares-importer' ),
				'option_none_value' => 0,
				'name'              => 'lsi_category',
				'id'                => 'lsi_category',
				'hide_empty'        => false,
				'orderby'           => 'name',
				'hierarchical'      => true,
			)
		);
		echo '</div>';

		printf(
			'<p class="submit"><button type="submit" name="lsi_action" value="import" class="button button-primary">%s</button></p>',
			esc_html__( 'Import selected as drafts', 'linkedin-shares-importer' )
		);

		echo '</form>';
	}

	/**
	 * Step 3 — create the drafts.
	 *
	 * @return void
	 */
	private static function handle_import(): void {
		check_admin_referer( 'lsi_process' );

		$shares = self::load_stash();
		if ( is_wp_error( $shares ) ) {
			self::render_upload( $shares->get_error_message() );
			return;
		}

		$picks = isset( $_POST['lsi_pick'] ) && is_array( $_POST['lsi_pick'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['lsi_pick'] ) )
			: array();
		$picks = array_fill_keys( $picks, true );

		$category = isset( $_POST['lsi_category'] ) ? absint( wp_unslash( $_POST['lsi_category'] ) ) : 0;

		$selected = array_values(
			array_filter( $shares, static fn( LSI_Share $s ) => isset( $picks[ $s->urn ] ) )
		);

		if ( empty( $selected ) ) {
			self::render_review( $shares, self::filters_from_request() );
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nothing was selected, so nothing was imported.', 'linkedin-shares-importer' ) . '</p></div>';
			return;
		}

		$result = LSI_Importer::run( $selected, $category );
		delete_transient( self::stash_key() );

		$created = $result['created'];
		$skipped = $result['skipped'];

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of drafts created. */
					_n( 'Created %d draft.', 'Created %d drafts.', count( $created ), 'linkedin-shares-importer' ),
					count( $created )
				)
			)
		);

		if ( $created ) {
			echo '<ul class="lsi-result-list">';
			foreach ( $created as $post_id ) {
				$title = get_the_title( $post_id );
				if ( '' === trim( (string) $title ) ) {
					$title = __( '(untitled — add a title)', 'linkedin-shares-importer' );
				}
				printf(
					'<li><a href="%s">%s</a></li>',
					esc_url( (string) get_edit_post_link( $post_id, 'url' ) ),
					esc_html( $title )
				);
			}
			echo '</ul>';
		}

		if ( $skipped ) {
			echo '<h2>' . esc_html__( 'Skipped', 'linkedin-shares-importer' ) . '</h2><ul class="lsi-result-list">';
			foreach ( $skipped as $entry ) {
				printf(
					'<li><code>%s</code> — %s</li>',
					esc_html( $entry['urn'] ),
					esc_html( $entry['reason'] )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a> <a class="button button-primary" href="%s">%s</a></p>',
			esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ),
			esc_html__( 'Import another file', 'linkedin-shares-importer' ),
			esc_url( admin_url( 'edit.php?post_status=draft&post_type=post' ) ),
			esc_html__( 'Review the drafts', 'linkedin-shares-importer' )
		);
	}

	/**
	 * Read and lightly validate the uploaded file. Accepts the raw
	 * `Shares_*.csv` or the whole LinkedIn export ZIP, from which the shares
	 * CSV is extracted.
	 *
	 * @return string|WP_Error CSV contents, or an error.
	 */
	private static function read_upload() {
		if ( empty( $_FILES['lsi_csv'] ) || ! isset( $_FILES['lsi_csv']['tmp_name'] ) ) {
			return new WP_Error( 'lsi_no_file', __( 'No file was received.', 'linkedin-shares-importer' ) );
		}

		$upload   = $_FILES['lsi_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$err_code = isset( $upload['error'] ) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $err_code ) {
			return new WP_Error( 'lsi_upload_error', __( 'The upload failed. Try again, or a smaller file.', 'linkedin-shares-importer' ) );
		}

		$tmp  = $upload['tmp_name'];
		$name = isset( $upload['name'] ) ? sanitize_file_name( wp_unslash( $upload['name'] ) ) : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'lsi_not_uploaded', __( 'The file could not be read.', 'linkedin-shares-importer' ) );
		}
		if ( (int) ( $upload['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new WP_Error(
				'lsi_too_big',
				sprintf(
					/* translators: %s: human-readable size limit. */
					__( 'That file is larger than %s.', 'linkedin-shares-importer' ),
					size_format( self::MAX_BYTES )
				)
			);
		}
		if ( ! in_array( $ext, array( 'csv', 'zip' ), true ) ) {
			return new WP_Error( 'lsi_not_csv', __( 'Please upload the LinkedIn export ZIP or a .csv file.', 'linkedin-shares-importer' ) );
		}

		if ( 'zip' === $ext ) {
			return self::csv_from_zip( $tmp );
		}

		$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || '' === $contents ) {
			return new WP_Error( 'lsi_empty', __( 'That file is empty.', 'linkedin-shares-importer' ) );
		}
		if ( false === strpos( $contents, 'ShareCommentary' ) ) {
			return new WP_Error( 'lsi_wrong_csv', __( 'That does not look like the LinkedIn "Shares" export (no ShareCommentary column).', 'linkedin-shares-importer' ) );
		}

		return $contents;
	}

	/**
	 * Pull the shares CSV out of a LinkedIn export ZIP: `Shares_*.csv` /
	 * `Shares.csv` by name, else the first `*.csv` that carries a
	 * `ShareCommentary` column.
	 *
	 * @param string $zip_path Path to the uploaded ZIP.
	 * @return string|WP_Error CSV contents, or an error.
	 */
	private static function csv_from_zip( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'lsi_no_zip',
				__( 'This server cannot read ZIP files. Unzip the export and upload Shares_*.csv instead.', 'linkedin-shares-importer' )
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'lsi_bad_zip', __( 'That ZIP file could not be opened.', 'linkedin-shares-importer' ) );
		}

		$named    = null;
		$fallback = null;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->statIndex( $i );
			if ( ! $entry || ! isset( $entry['name'] ) ) {
				continue;
			}
			$base = strtolower( basename( $entry['name'] ) );
			if ( '.csv' !== substr( $base, -4 ) ) {
				continue;
			}
			if ( null === $named && preg_match( '/^shares(_\d+)?\.csv$/', $base ) ) {
				$named = $i;
				break;
			}
			if ( null === $fallback && (int) $entry['size'] <= self::MAX_BYTES ) {
				$fallback = $i;
			}
		}

		$index = $named ?? $fallback;
		if ( null === $index ) {
			$zip->close();
			return new WP_Error( 'lsi_no_csv_in_zip', __( 'No Shares CSV was found inside that ZIP.', 'linkedin-shares-importer' ) );
		}

		$stat = $zip->statIndex( $index );
		if ( $stat && (int) ( $stat['size'] ?? 0 ) > self::MAX_BYTES ) {
			$zip->close();
			return new WP_Error( 'lsi_zip_entry_big', __( 'The Shares CSV inside that ZIP is too large.', 'linkedin-shares-importer' ) );
		}

		$contents = $zip->getFromIndex( $index );
		$zip->close();

		if ( false === $contents || '' === $contents ) {
			return new WP_Error( 'lsi_zip_read', __( 'The Shares CSV inside that ZIP could not be read.', 'linkedin-shares-importer' ) );
		}
		if ( false === strpos( $contents, 'ShareCommentary' ) ) {
			return new WP_Error( 'lsi_wrong_csv', __( 'The CSV found inside that ZIP has no ShareCommentary column.', 'linkedin-shares-importer' ) );
		}

		return $contents;
	}

	/**
	 * Load and rebuild the stashed shares.
	 *
	 * @return LSI_Share[]|WP_Error
	 */
	private static function load_stash() {
		$data = get_transient( self::stash_key() );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return new WP_Error(
				'lsi_expired',
				__( 'That import session expired. Please upload the file again.', 'linkedin-shares-importer' )
			);
		}
		return array_map( array( 'LSI_Share', 'from_array' ), $data );
	}

	/**
	 * Read the filter values from the request, with defaults (last three
	 * years, two paragraphs).
	 *
	 * @return array{from:string,to:string,min:int}
	 */
	private static function filters_from_request(): array {
		$default_from = gmdate( 'Y-m-d', strtotime( '-3 years' ) );
		$default_to   = gmdate( 'Y-m-d' );

		$from = isset( $_POST['lsi_from'] ) ? sanitize_text_field( wp_unslash( $_POST['lsi_from'] ) ) : '';
		$to   = isset( $_POST['lsi_to'] ) ? sanitize_text_field( wp_unslash( $_POST['lsi_to'] ) ) : '';
		$min  = isset( $_POST['lsi_min'] ) ? absint( wp_unslash( $_POST['lsi_min'] ) ) : 0;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = $default_from;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$to = $default_to;
		}
		if ( $min < 1 ) {
			$min = 2;
		}

		return array(
			'from' => $from,
			'to'   => $to,
			'min'  => $min,
		);
	}

	/**
	 * Format a share timestamp in the site's date/time format and timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_date( int $timestamp ): string {
		return wp_date(
			get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ),
			$timestamp
		);
	}

	/**
	 * Human label for a LinkedIn audience token.
	 *
	 * @param string $visibility Raw token.
	 * @return string
	 */
	private static function audience_label( string $visibility ): string {
		$map = array(
			'PUBLIC'             => __( 'Public', 'linkedin-shares-importer' ),
			'MEMBER_NETWORK'     => __( 'Network', 'linkedin-shares-importer' ),
			'CONNECTIONS'        => __( 'Connections', 'linkedin-shares-importer' ),
			'LOGGED_IN'          => __( 'Logged-in', 'linkedin-shares-importer' ),
			'CONTAINER_INTERNAL' => __( 'Group', 'linkedin-shares-importer' ),
		);
		return $map[ $visibility ] ?? ( '' !== $visibility ? ucwords( strtolower( str_replace( '_', ' ', $visibility ) ) ) : '—' );
	}
}
