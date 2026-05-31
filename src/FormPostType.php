<?php declare(strict_types=1);
namespace Gratis\Forms;

final class FormPostType
{
    public static function register(): void
    {
        register_post_type('gratis_form', [
            'labels' => ['name' => 'Forms', 'singular_name' => 'Form'],
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-feedback',
            'supports' => ['title', 'editor', 'custom-fields'],
        ]);

        register_post_type('gratis_form_entry', [
            'labels' => ['name' => 'Form Entries'],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=gratis_form',
        ]);

        // Shortcode for rendering forms
        add_shortcode('gratis-form', [__CLASS__, 'renderShortcode']);

        // Handle form submissions
        add_action('wp_ajax_gratis_form_submit', [__CLASS__, 'handleSubmission']);
        add_action('wp_ajax_nopriv_gratis_form_submit', [__CLASS__, 'handleSubmission']);
    }

    public static function renderShortcode($atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts);
        $form = get_post((int) $atts['id']);
        if (!$form || $form->post_type !== 'gratis_form') return '';

        $fields = get_post_meta($form->ID, '_gratis_form_fields', true) ?: [];
        $html = '<form class="gratis-form" data-form-id="' . $form->ID . '">';
        $html .= wp_nonce_field('gratis_form_' . $form->ID, '_gf_nonce', true, false);

        foreach ($fields as $i => $field) {
            $html .= self::renderField($field, $i);
        }

        $html .= '<button type="submit" class="gratis-form-submit">Submit</button>';
        $html .= '</form>';
        return $html;
    }

    private static function renderField(array $field, int $index): string
    {
        $type = $field['type'] ?? 'text';
        $label = esc_html($field['label'] ?? '');
        $name = 'field_' . $index;
        $required = !empty($field['required']) ? 'required' : '';
        $conditions = !empty($field['conditions']) ? ' data-conditions="' . esc_attr(json_encode($field['conditions'])) . '"' : '';

        $html = "<div class=\"gratis-form-field\" data-field-type=\"{$type}\"{$conditions}>";
        $html .= "<label>{$label}</label>";

        switch ($type) {
            case 'textarea':
                $html .= "<textarea name=\"{$name}\" {$required}></textarea>";
                break;
            case 'select':
                $html .= "<select name=\"{$name}\" {$required}>";
                foreach ($field['options'] ?? [] as $opt) {
                    $html .= "<option value=\"" . esc_attr($opt) . "\">{$opt}</option>";
                }
                $html .= "</select>";
                break;
            case 'email':
                $html .= "<input type=\"email\" name=\"{$name}\" {$required}>";
                break;
            case 'payment':
                $amount = $field['amount'] ?? '0';
                $html .= "<input type=\"hidden\" name=\"{$name}\" value=\"{$amount}\">";
                $html .= "<p class=\"gratis-form-price\">\${$amount}</p>";
                break;
            default:
                $html .= "<input type=\"text\" name=\"{$name}\" {$required}>";
        }

        $html .= "</div>";
        return $html;
    }

    public static function handleSubmission(): void
    {
        $form_id = (int) ($_POST['form_id'] ?? 0);
        if (!wp_verify_nonce($_POST['_gf_nonce'] ?? '', 'gratis_form_' . $form_id)) {
            wp_send_json_error('Invalid nonce');
        }

        $fields = get_post_meta($form_id, '_gratis_form_fields', true) ?: [];
        $data = [];
        foreach ($fields as $i => $field) {
            $data[$field['label'] ?? "field_{$i}"] = sanitize_text_field($_POST["field_{$i}"] ?? '');
        }

        // Store entry
        $entry_id = wp_insert_post([
            'post_type'   => 'gratis_form_entry',
            'post_parent' => $form_id,
            'post_status' => 'publish',
            'post_title'  => 'Entry #' . time(),
            'meta_input'  => ['_gratis_entry_data' => $data],
        ]);

        // Email notification
        $notify = get_post_meta($form_id, '_gratis_form_notify', true) ?: get_option('admin_email');
        wp_mail($notify, 'New form submission: ' . get_the_title($form_id), print_r($data, true));

        // Fire action for automation
        do_action('gratis_form_submitted', $form_id, $data, $entry_id);

        wp_send_json_success(['entry_id' => $entry_id]);
    }
}
