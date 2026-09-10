<?php
/**
 * Settings screen: Settings → LinkedIn Import.
 *
 * @package LinkedInSharesImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the plugin's options.
 */
final class LSI_Settings {

	const PAGE  = 'lsi-settings';
	const GROUP = 'lsi_settings';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( LSI_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Default "Originally posted" template. `{{url}}` is the share permalink;
	 * `[label](target)` becomes a link.
	 *
	 * @return string
	 */
	public static function default_attribution(): string {
		return 'Originally posted [on LinkedIn]({{url}}).';
	}

	/**
	 * Add the options page under Settings.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'LinkedIn Import', 'linkedin-shares-importer' ),
			__( 'LinkedIn Import', 'linkedin-shares-importer' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register settings, sections and fields.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			'lsi_title_source',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_title_source' ),
				'default'           => 'ai',
			)
		);
		register_setting(
			self::GROUP,
			'lsi_ai_prompt',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_prompt' ),
				'default'           => LSI_Title::default_prompt(),
			)
		);
		register_setting(
			self::GROUP,
			'lsi_attribution_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ) => (int) (bool) $v,
				'default'           => 1,
			)
		);
		register_setting(
			self::GROUP,
			'lsi_attribution_template',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_template' ),
				'default'           => self::default_attribution(),
			)
		);

		add_settings_section(
			'lsi_titles',
			__( 'Titles', 'linkedin-shares-importer' ),
			array( __CLASS__, 'section_titles' ),
			self::PAGE
		);
		add_settings_field(
			'lsi_title_source',
			__( 'Title source', 'linkedin-shares-importer' ),
			array( __CLASS__, 'field_title_source' ),
			self::PAGE,
			'lsi_titles'
		);
		add_settings_field(
			'lsi_ai_prompt',
			__( 'AI prompt', 'linkedin-shares-importer' ),
			array( __CLASS__, 'field_ai_prompt' ),
			self::PAGE,
			'lsi_titles'
		);

		add_settings_section(
			'lsi_attribution',
			__( 'Attribution', 'linkedin-shares-importer' ),
			array( __CLASS__, 'section_attribution' ),
			self::PAGE
		);
		add_settings_field(
			'lsi_attribution_enabled',
			__( 'Append a source line', 'linkedin-shares-importer' ),
			array( __CLASS__, 'field_attribution_enabled' ),
			self::PAGE,
			'lsi_attribution'
		);
		add_settings_field(
			'lsi_attribution_template',
			__( 'Source line', 'linkedin-shares-importer' ),
			array( __CLASS__, 'field_attribution_template' ),
			self::PAGE,
			'lsi_attribution'
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'LinkedIn Import', 'linkedin-shares-importer' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';
		echo '<p>' . sprintf(
			/* translators: %s: link to the importer screen. */
			wp_kses_post( __( 'Ready to import? Open <a href="%s">Tools → LinkedIn Shares</a>.', 'linkedin-shares-importer' ) ),
			esc_url( admin_url( 'tools.php?page=' . LSI_Admin::PAGE ) )
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Titles section intro.
	 *
	 * @return void
	 */
	public static function section_titles(): void {
		if ( LSI_Title::ai_client_present() ) {
			echo '<p>' . esc_html__( 'The WordPress AI Client is available on this site. Connect a provider under Settings → Connections to use it.', 'linkedin-shares-importer' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'No AI provider is available on this site, so the first-line heuristic is used. It reads the first line when that already looks like a headline, otherwise the first sentence.', 'linkedin-shares-importer' ) . '</p>';
		}
	}

	/**
	 * Attribution section intro.
	 *
	 * @return void
	 */
	public static function section_attribution(): void {
		echo '<p>' . esc_html__( 'Appended as the last paragraph of every imported draft.', 'linkedin-shares-importer' ) . '</p>';
	}

	/**
	 * Title-source radios.
	 *
	 * @return void
	 */
	public static function field_title_source(): void {
		$current = LSI_Title::source();
		$options = array(
			'ai'        => __( 'AI Client — fall back to the first line if no provider is connected', 'linkedin-shares-importer' ),
			'firstline' => __( 'First line / first sentence (no AI)', 'linkedin-shares-importer' ),
			'none'      => __( 'Leave the title empty', 'linkedin-shares-importer' ),
		);
		echo '<fieldset>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label style="display:block;margin:.2em 0"><input type="radio" name="lsi_title_source" value="%s" %s> %s</label>',
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * AI prompt textarea.
	 *
	 * @return void
	 */
	public static function field_ai_prompt(): void {
		$value = (string) get_option( 'lsi_ai_prompt', LSI_Title::default_prompt() );
		printf(
			'<textarea name="lsi_ai_prompt" rows="4" class="large-text code">%s</textarea>',
			esc_textarea( $value )
		);
		echo '<p class="description">' . esc_html__( 'Sent to the AI Client for each share. {{content}} is replaced with the post text.', 'linkedin-shares-importer' ) . '</p>';
	}

	/**
	 * Attribution on/off.
	 *
	 * @return void
	 */
	public static function field_attribution_enabled(): void {
		printf(
			'<label><input type="checkbox" name="lsi_attribution_enabled" value="1" %s> %s</label>',
			checked( (bool) get_option( 'lsi_attribution_enabled', 1 ), true, false ),
			esc_html__( 'Add “Originally posted on LinkedIn” to each draft', 'linkedin-shares-importer' )
		);
	}

	/**
	 * Attribution template text field.
	 *
	 * @return void
	 */
	public static function field_attribution_template(): void {
		$value = (string) get_option( 'lsi_attribution_template', self::default_attribution() );
		printf(
			'<input type="text" name="lsi_attribution_template" value="%s" class="large-text code">',
			esc_attr( $value )
		);
		echo '<p class="description">' . wp_kses(
			__( '<code>{{url}}</code> is the share link. <code>[label](target)</code> becomes a link. HTML is not allowed.', 'linkedin-shares-importer' ),
			array( 'code' => array() )
		) . '</p>';
	}

	/**
	 * Sanitise the title source.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_title_source( $value ): string {
		$value = is_string( $value ) ? $value : 'ai';
		return in_array( $value, array( 'ai', 'firstline', 'none' ), true ) ? $value : 'ai';
	}

	/**
	 * Sanitise the AI prompt.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_prompt( $value ): string {
		$value = is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
		return '' === $value ? LSI_Title::default_prompt() : $value;
	}

	/**
	 * Sanitise the attribution template — plain text with a restricted
	 * markdown-link syntax, no HTML.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_template( $value ): string {
		$value = is_string( $value ) ? $value : '';
		$value = wp_strip_all_tags( $value );
		$value = trim( $value );
		return '' === $value ? self::default_attribution() : $value;
	}

	/**
	 * Add a "Settings" / "Import" link on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		$own = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=' . LSI_Admin::PAGE ) ),
				esc_html__( 'Import', 'linkedin-shares-importer' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
				esc_html__( 'Settings', 'linkedin-shares-importer' )
			),
		);
		return array_merge( $own, $links );
	}
}
