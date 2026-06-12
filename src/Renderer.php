<?php declare(strict_types=1);

namespace Gratis\Forms;

defined('ABSPATH') || exit;

/**
 * Renders forms on the front end via the `[gratis_form id="X"]` shortcode and
 * enqueues the front-end stylesheet/script (incl. the AJAX endpoint config and
 * optional reCAPTCHA v3 library).
 */
final class Renderer {

    public const SHORTCODE = 'gratis_form';

    /** Tracks whether assets have been enqueued this request. */
    private static bool $enqueued = false;

    /**
     * Register the shortcode (plus a hyphenated alias for back-compat).
     */
    public static function register(): void {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'render']);
        add_shortcode('gratis-form', [__CLASS__, 'render']);
    }

    /**
     * Enqueue (once) the front-end assets and localise the AJAX config.
     */
    public static function enqueue_assets(): void {
        if (self::$enqueued) {
            return;
        }
        self::$enqueued = true;

        wp_enqueue_style(
            'gratis-forms',
            GRATIS_FORMS_URL . 'assets/forms.css',
            [],
            GRATIS_FORMS_VERSION
        );
        wp_enqueue_script(
            'gratis-forms',
            GRATIS_FORMS_URL . 'assets/forms.js',
            [],
            GRATIS_FORMS_VERSION,
            true
        );

        $site_key = Settings::get_string('recaptcha_site_key');
        if ('' !== $site_key && Settings::recaptcha_ready()) {
            wp_enqueue_script(
                'gratis-forms-recaptcha',
                'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($site_key),
                [],
                null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google API URL is unversioned.
                true
            );
        }

        wp_localize_script(
            'gratis-forms',
            'GratisForms',
            [
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'action'           => 'gratis_form_submit',
                'recaptchaSiteKey' => Settings::recaptcha_ready() ? $site_key : '',
                'i18n'             => [
                    'sending' => __('Sending…', 'gratis-forms'),
                    'error'   => __('Something went wrong. Please try again.', 'gratis-forms'),
                ],
            ]
        );
    }

    /**
     * Render a form by ID.
     *
     * @param array<string,mixed>|string $atts Shortcode attributes.
     * @return string Form HTML, or empty string when the form does not exist.
     */
    public static function render($atts): string {
        $atts    = shortcode_atts(['id' => 0], (array) $atts, self::SHORTCODE);
        $form_id = (int) $atts['id'];
        $form    = $form_id > 0 ? get_post($form_id) : null;

        if (!$form || FormPostType::POST_TYPE !== $form->post_type || 'publish' !== $form->post_status) {
            return '';
        }

        $fields = get_post_meta($form_id, FormPostType::META_FIELDS, true);
        $fields = is_array($fields) ? $fields : [];
        if (empty($fields)) {
            return '';
        }

        self::enqueue_assets();

        $use_recaptcha = (int) get_post_meta($form_id, FormPostType::META_RECAPTCHA, true) === 1
            && Settings::recaptcha_ready();

        $out  = '<form class="gratis-form" data-form-id="' . esc_attr((string) $form_id) . '"';
        $out .= $use_recaptcha ? ' data-recaptcha="1"' : '';
        $out .= ' method="post" novalidate>';

        $out .= wp_nonce_field('gratis_form_' . $form_id, '_gf_nonce', true, false);

        // Honeypot: hidden from humans, tempting to bots. Submissions with a
        // value here are silently dropped server-side.
        $out .= '<div class="gratis-form-hp" aria-hidden="true" style="position:absolute;left:-5000px;" tabindex="-1">';
        $out .= '<label>' . esc_html__('Leave this field empty', 'gratis-forms') . '<input type="text" name="gf_hp" tabindex="-1" autocomplete="off" value=""></label>';
        $out .= '</div>';

        foreach ($fields as $index => $field) {
            $out .= self::render_field((array) $field, (int) $index);
        }

        $out .= '<input type="hidden" name="gf_recaptcha_token" value="">';
        $out .= '<div class="gratis-form-feedback" role="status" aria-live="polite"></div>';
        $out .= '<button type="submit" class="gratis-form-submit">' . esc_html__('Submit', 'gratis-forms') . '</button>';
        $out .= '</form>';

        return $out;
    }

    /**
     * Render a single field.
     *
     * @param array<string,mixed> $field Field definition.
     * @param int                 $index Field index (used for the input name).
     */
    private static function render_field(array $field, int $index): string {
        $type     = isset($field['type']) ? (string) $field['type'] : 'text';
        $type     = in_array($type, FormPostType::FIELD_TYPES, true) ? $type : 'text';
        $label    = isset($field['label']) ? (string) $field['label'] : '';
        $name     = 'field_' . $index;
        $id        = 'gf-' . $index;
        $required = !empty($field['required']);
        $req_attr = $required ? ' required' : '';
        $req_mark = $required ? ' <span class="gratis-form-required" aria-hidden="true">*</span>' : '';
        $place    = isset($field['placeholder']) ? (string) $field['placeholder'] : '';
        $options  = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];

        $out  = '<div class="gratis-form-field gratis-form-field--' . esc_attr($type) . '">';

        if (in_array($type, ['checkbox', 'radio'], true) && !empty($options)) {
            $out .= '<fieldset><legend>' . esc_html($label) . wp_kses_post($req_mark) . '</legend>';
            $input_type = 'checkbox' === $type ? 'checkbox' : 'radio';
            $input_name = 'checkbox' === $type ? $name . '[]' : $name;
            foreach ($options as $opt_index => $option) {
                $opt_id = $id . '-' . (int) $opt_index;
                $out   .= '<label class="gratis-form-choice" for="' . esc_attr($opt_id) . '">';
                $out   .= '<input type="' . esc_attr($input_type) . '" id="' . esc_attr($opt_id) . '" name="' . esc_attr($input_name) . '" value="' . esc_attr((string) $option) . '"' . $req_attr . '> ';
                $out   .= esc_html((string) $option) . '</label>';
            }
            $out .= '</fieldset>';
            $out .= '</div>';
            return $out;
        }

        $out .= '<label for="' . esc_attr($id) . '">' . esc_html($label) . wp_kses_post($req_mark) . '</label>';

        switch ($type) {
            case 'textarea':
                $out .= '<textarea id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" placeholder="' . esc_attr($place) . '"' . $req_attr . '></textarea>';
                break;
            case 'select':
                $out .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $req_attr . '>';
                $out .= '<option value="">' . esc_html__('— Select —', 'gratis-forms') . '</option>';
                foreach ($options as $option) {
                    $out .= '<option value="' . esc_attr((string) $option) . '">' . esc_html((string) $option) . '</option>';
                }
                $out .= '</select>';
                break;
            case 'email':
                $out .= '<input type="email" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" placeholder="' . esc_attr($place) . '"' . $req_attr . '>';
                break;
            case 'file':
                $out .= '<input type="file" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $req_attr . '>';
                break;
            default:
                $out .= '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" placeholder="' . esc_attr($place) . '"' . $req_attr . '>';
        }

        $out .= '</div>';
        return $out;
    }
}
