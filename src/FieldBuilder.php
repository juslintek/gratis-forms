<?php declare(strict_types=1);

namespace Gratis\Forms;

defined('ABSPATH') || exit;

/**
 * Drag-and-drop field builder meta box for the form edit screen.
 *
 * The builder is a small vanilla-JS UI (see assets/builder.js) that maintains
 * a JSON model of the form fields in a hidden textarea. On save the JSON is
 * decoded, every field is validated/sanitised against the supported field
 * types, and the normalised array is stored in post meta.
 */
final class FieldBuilder {

    private const NONCE_ACTION = 'gratis_forms_save_fields';
    private const NONCE_FIELD  = 'gratis_forms_fields_nonce';

    /**
     * Register the meta boxes on the form edit screen.
     */
    public static function register(): void {
        add_meta_box(
            'gratis-forms-builder',
            __('Form Builder', 'gratis-forms'),
            [__CLASS__, 'render_builder'],
            FormPostType::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'gratis-forms-settings',
            __('Form Settings', 'gratis-forms'),
            [__CLASS__, 'render_settings'],
            FormPostType::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Enqueue the builder assets only on the form edit screen.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public static function enqueue(string $hook): void {
        if ('post.php' !== $hook && 'post-new.php' !== $hook) {
            return;
        }
        if (get_post_type() !== FormPostType::POST_TYPE) {
            return;
        }

        wp_enqueue_style(
            'gratis-forms-builder',
            GRATIS_FORMS_URL . 'assets/builder.css',
            [],
            GRATIS_FORMS_VERSION
        );
        wp_enqueue_script(
            'gratis-forms-builder',
            GRATIS_FORMS_URL . 'assets/builder.js',
            [],
            GRATIS_FORMS_VERSION,
            true
        );
        wp_localize_script(
            'gratis-forms-builder',
            'GratisFormsBuilder',
            [
                'types' => FormPostType::FIELD_TYPES,
                'i18n'  => [
                    'addField'    => __('Add field', 'gratis-forms'),
                    'label'       => __('Label', 'gratis-forms'),
                    'placeholder' => __('Placeholder', 'gratis-forms'),
                    'options'     => __('Options (one per line)', 'gratis-forms'),
                    'required'    => __('Required', 'gratis-forms'),
                    'remove'      => __('Remove', 'gratis-forms'),
                    'drag'        => __('Drag to reorder', 'gratis-forms'),
                    'empty'       => __('No fields yet. Add your first field below.', 'gratis-forms'),
                ],
            ]
        );
    }

    /**
     * Render the builder meta box.
     *
     * @param \WP_Post $post Current form post.
     */
    public static function render_builder(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $fields = get_post_meta($post->ID, FormPostType::META_FIELDS, true);
        $fields = is_array($fields) ? $fields : [];
        $json   = wp_json_encode(array_values($fields));

        echo '<p class="description">' . esc_html__('Add, configure and drag to reorder your form fields. Embed the form with the shortcode shown in the sidebar.', 'gratis-forms') . '</p>';
        echo '<div id="gratis-forms-builder-root" data-types="' . esc_attr((string) wp_json_encode(FormPostType::FIELD_TYPES)) . '"></div>';

        // Hidden model consumed/updated by builder.js; decoded on save.
        echo '<textarea id="gratis-forms-fields" name="gratis_forms_fields" class="gratis-forms-fields-json" hidden>' . esc_textarea((string) $json) . '</textarea>';

        // Progressive-enhancement fallback for when JS is disabled.
        echo '<noscript><p>' . esc_html__('The visual form builder requires JavaScript. Field configuration is stored as JSON.', 'gratis-forms') . '</p></noscript>';
    }

    /**
     * Render the per-form settings meta box (notification + reCAPTCHA + shortcode).
     *
     * @param \WP_Post $post Current form post.
     */
    public static function render_settings(\WP_Post $post): void {
        $notify    = (string) get_post_meta($post->ID, FormPostType::META_NOTIFY, true);
        $recaptcha = (int) get_post_meta($post->ID, FormPostType::META_RECAPTCHA, true);
        $shortcode = '[gratis_form id="' . (int) $post->ID . '"]';

        echo '<p><label for="gratis_forms_notify"><strong>' . esc_html__('Notification email', 'gratis-forms') . '</strong></label><br>';
        echo '<input type="text" id="gratis_forms_notify" name="gratis_forms_notify" class="widefat" value="' . esc_attr($notify) . '" placeholder="' . esc_attr(get_option('admin_email')) . '">';
        echo '<span class="description">' . esc_html__('Comma-separate multiple addresses. Defaults to the site admin email.', 'gratis-forms') . '</span></p>';

        echo '<p><label><input type="checkbox" name="gratis_forms_recaptcha" value="1"' . checked($recaptcha, 1, false) . '> ';
        echo esc_html__('Protect this form with reCAPTCHA v3', 'gratis-forms') . '</label><br>';
        echo '<span class="description">' . esc_html__('Requires site/secret keys under Forms → Settings.', 'gratis-forms') . '</span></p>';

        echo '<p><strong>' . esc_html__('Shortcode', 'gratis-forms') . '</strong><br>';
        echo '<input type="text" class="widefat" readonly onfocus="this.select()" value="' . esc_attr($shortcode) . '"></p>';
    }

    /**
     * Persist the builder fields and per-form settings.
     *
     * @param int      $post_id Saved post ID.
     * @param \WP_Post $post    Saved post object.
     */
    public static function save(int $post_id, \WP_Post $post): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (FormPostType::POST_TYPE !== $post->post_type) {
            return;
        }

        // Decode and normalise the field model. Raw JSON is decoded then each
        // field is individually sanitised in sanitize_fields().
        $raw_json = isset($_POST['gratis_forms_fields'])
            ? wp_unslash($_POST['gratis_forms_fields']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : '[]';
        $decoded  = json_decode(is_string($raw_json) ? $raw_json : '[]', true);
        $fields   = self::sanitize_fields(is_array($decoded) ? $decoded : []);
        update_post_meta($post_id, FormPostType::META_FIELDS, $fields);

        // Notification recipients.
        $notify = isset($_POST['gratis_forms_notify'])
            ? sanitize_text_field(wp_unslash($_POST['gratis_forms_notify']))
            : '';
        update_post_meta($post_id, FormPostType::META_NOTIFY, $notify);

        // Per-form reCAPTCHA toggle.
        update_post_meta($post_id, FormPostType::META_RECAPTCHA, isset($_POST['gratis_forms_recaptcha']) ? 1 : 0);
    }

    /**
     * Validate and sanitise a decoded field array.
     *
     * @param array<int,mixed> $input Raw decoded field definitions.
     * @return array<int,array<string,mixed>> Clean field definitions.
     */
    public static function sanitize_fields(array $input): array {
        $clean = [];

        foreach ($input as $field) {
            if (!is_array($field)) {
                continue;
            }

            $type  = isset($field['type']) ? sanitize_key((string) $field['type']) : 'text';
            $type  = in_array($type, FormPostType::FIELD_TYPES, true) ? $type : 'text';
            $label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';

            if ('' === $label) {
                continue;
            }

            $entry = [
                'type'        => $type,
                'label'       => $label,
                'placeholder' => isset($field['placeholder']) ? sanitize_text_field((string) $field['placeholder']) : '',
                'required'    => !empty($field['required']),
            ];

            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $options = [];
                foreach ((array) ($field['options'] ?? []) as $option) {
                    $option = sanitize_text_field((string) $option);
                    if ('' !== $option) {
                        $options[] = $option;
                    }
                }
                $entry['options'] = $options;
            }

            $clean[] = $entry;
        }

        return $clean;
    }
}
