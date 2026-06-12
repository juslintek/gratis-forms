<?php declare(strict_types=1);

namespace Gratis\Forms;

defined('ABSPATH') || exit;

/**
 * Global form settings (reCAPTCHA v3 keys, default notification behaviour),
 * stored as a single option array and exposed via the Settings API on a
 * submenu under the Forms CPT.
 */
final class Settings {

    /** Option name holding all global settings. */
    public const OPTION = 'gratis_forms_settings';

    private const GROUP = 'gratis_forms_settings_group';
    private const PAGE  = 'gratis-forms-settings';

    /**
     * Default values for every global setting.
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array {
        return [
            'recaptcha_site_key'   => '',
            'recaptcha_secret_key' => '',
            'recaptcha_threshold'  => 0.5,
        ];
    }

    /**
     * Fetch the merged settings array (stored over defaults).
     *
     * @return array<string,mixed>
     */
    public static function all(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), $stored);
    }

    /**
     * String accessor for a single setting.
     */
    public static function get_string(string $key): string {
        $all = self::all();
        return isset($all[$key]) ? (string) $all[$key] : '';
    }

    /**
     * Whether reCAPTCHA is fully configured (both keys present).
     */
    public static function recaptcha_ready(): bool {
        return '' !== self::get_string('recaptcha_site_key')
            && '' !== self::get_string('recaptcha_secret_key');
    }

    /**
     * Register the settings submenu page.
     */
    public static function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . FormPostType::POST_TYPE,
            __('Form Settings', 'gratis-forms'),
            __('Settings', 'gratis-forms'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Register the option and its sanitisation callback.
     */
    public static function register_settings(): void {
        register_setting(
            self::GROUP,
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize'],
                'default'           => self::defaults(),
            ]
        );
    }

    /**
     * Sanitise the incoming settings array.
     *
     * @param mixed $input Raw option input.
     * @return array<string,mixed>
     */
    public static function sanitize($input): array {
        $input     = is_array($input) ? $input : [];
        $threshold = isset($input['recaptcha_threshold']) ? (float) $input['recaptcha_threshold'] : 0.5;
        $threshold = max(0.0, min(1.0, $threshold));

        return [
            'recaptcha_site_key'   => isset($input['recaptcha_site_key']) ? sanitize_text_field((string) $input['recaptcha_site_key']) : '',
            'recaptcha_secret_key' => isset($input['recaptcha_secret_key']) ? sanitize_text_field((string) $input['recaptcha_secret_key']) : '',
            'recaptcha_threshold'  => $threshold,
        ];
    }

    /**
     * Render the settings screen.
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gratis-forms'));
        }

        $settings = self::all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Form Settings', 'gratis-forms') . '</h1>';
        echo '<p>' . esc_html__('Optional reCAPTCHA v3 anti-spam protection. Leave blank to rely on the built-in honeypot only.', 'gratis-forms') . '</p>';

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gf_site_key">' . esc_html__('reCAPTCHA v3 site key', 'gratis-forms') . '</label></th><td>';
        echo '<input type="text" id="gf_site_key" class="regular-text" name="' . esc_attr(self::OPTION) . '[recaptcha_site_key]" value="' . esc_attr((string) $settings['recaptcha_site_key']) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gf_secret_key">' . esc_html__('reCAPTCHA v3 secret key', 'gratis-forms') . '</label></th><td>';
        echo '<input type="text" id="gf_secret_key" class="regular-text" name="' . esc_attr(self::OPTION) . '[recaptcha_secret_key]" value="' . esc_attr((string) $settings['recaptcha_secret_key']) . '">';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="gf_threshold">' . esc_html__('Score threshold', 'gratis-forms') . '</label></th><td>';
        echo '<input type="number" id="gf_threshold" step="0.1" min="0" max="1" class="small-text" name="' . esc_attr(self::OPTION) . '[recaptcha_threshold]" value="' . esc_attr((string) $settings['recaptcha_threshold']) . '"> ';
        echo '<span class="description">' . esc_html__('Submissions scoring below this value (0.0–1.0) are rejected as spam. Default 0.5.', 'gratis-forms') . '</span>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
