<?php declare(strict_types=1);

namespace Gratis\Forms;

use Gratis\Forms\Storage\Submissions;

defined('ABSPATH') || exit;

/**
 * Handles AJAX form submissions: nonce + honeypot + optional reCAPTCHA v3
 * checks, per-field server-side validation/sanitisation, persistence to the
 * custom submissions table, admin notification, and a `gratis_form_submitted`
 * action for automations.
 */
final class Submission {

    /**
     * Register the AJAX endpoints (logged-in and anonymous).
     */
    public static function register(): void {
        add_action('wp_ajax_gratis_form_submit', [__CLASS__, 'handle']);
        add_action('wp_ajax_nopriv_gratis_form_submit', [__CLASS__, 'handle']);
    }

    /**
     * Process a submission and respond with JSON.
     */
    public static function handle(): void {
        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;

        $nonce = isset($_POST['_gf_nonce']) ? sanitize_text_field(wp_unslash($_POST['_gf_nonce'])) : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, 'gratis_form_' . $form_id)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload and try again.', 'gratis-forms')], 403);
        }

        $form = $form_id > 0 ? get_post($form_id) : null;
        if (!$form || FormPostType::POST_TYPE !== $form->post_type || 'publish' !== $form->post_status) {
            wp_send_json_error(['message' => __('Form not found.', 'gratis-forms')], 404);
        }

        // Honeypot: a filled value means a bot. Pretend success to avoid hints.
        $honeypot = isset($_POST['gf_hp']) ? sanitize_text_field(wp_unslash($_POST['gf_hp'])) : '';
        if ('' !== $honeypot) {
            wp_send_json_success(['message' => __('Thank you for your submission.', 'gratis-forms')]);
        }

        // Optional reCAPTCHA v3.
        if ((int) get_post_meta($form_id, FormPostType::META_RECAPTCHA, true) === 1 && Settings::recaptcha_ready()) {
            $token = isset($_POST['gf_recaptcha_token']) ? sanitize_text_field(wp_unslash($_POST['gf_recaptcha_token'])) : '';
            if (!self::verify_recaptcha($token)) {
                wp_send_json_error(['message' => __('Spam check failed. Please try again.', 'gratis-forms')], 422);
            }
        }

        $fields = get_post_meta($form_id, FormPostType::META_FIELDS, true);
        $fields = is_array($fields) ? $fields : [];

        $data   = [];
        $errors = [];

        foreach ($fields as $index => $field) {
            $field = (array) $field;
            $type  = isset($field['type']) ? (string) $field['type'] : 'text';
            $label = isset($field['label']) ? (string) $field['label'] : ('field_' . $index);
            $key   = 'field_' . $index;
            [$value, $error] = self::read_field($type, $key, $field, $label);

            if (null !== $error) {
                $errors[$key] = $error;
                continue;
            }
            $data[$label] = $value;
        }

        if (!empty($errors)) {
            wp_send_json_error(
                [
                    'message' => __('Please correct the highlighted fields.', 'gratis-forms'),
                    'errors'  => $errors,
                ],
                422
            );
        }

        $submission_id = Submissions::insert(
            $form_id,
            $data,
            [
                'ip_hash'    => self::ip_hash(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
            ]
        );

        self::notify($form_id, $data);

        /**
         * Fires after a form submission has been stored.
         *
         * @param int                 $form_id       Form post ID.
         * @param array<string,mixed> $data          Sanitised submitted values keyed by label.
         * @param int                 $submission_id New submission row ID.
         */
        do_action('gratis_form_submitted', $form_id, $data, $submission_id);

        wp_send_json_success(
            [
                'message'       => __('Thank you for your submission.', 'gratis-forms'),
                'submission_id' => $submission_id,
            ]
        );
    }

    /**
     * Read, validate and sanitise a single posted field.
     *
     * @param string              $type  Field type.
     * @param string              $key   POST key (field_N).
     * @param array<string,mixed> $field Field definition.
     * @param string              $label Field label (for error messages).
     * @return array{0:mixed,1:?string} Tuple of [value, errorMessageOrNull].
     */
    private static function read_field(string $type, string $key, array $field, string $label) {
        $required = !empty($field['required']);

        if ('file' === $type) {
            return self::read_file($key, $required, $label);
        }

        if ('checkbox' === $type) {
            // Nonce is verified by handle() before this helper is reached.
            $raw = isset($_POST[$key]) ? (array) wp_unslash($_POST[$key]) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $values = array_values(array_filter(array_map('sanitize_text_field', $raw), static fn($v) => '' !== $v));
            if ($required && empty($values)) {
                /* translators: %s: field label. */
                return [null, sprintf(__('%s is required.', 'gratis-forms'), $label)];
            }
            return [implode(', ', $values), null];
        }

        // Nonce is verified by handle(); value is sanitised immediately below.
        $raw   = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $value = is_array($raw) ? '' : (string) $raw;

        if ('textarea' === $type) {
            $value = sanitize_textarea_field($value);
        } elseif ('email' === $type) {
            $value = sanitize_email($value);
        } else {
            $value = sanitize_text_field($value);
        }

        if ($required && '' === $value) {
            /* translators: %s: field label. */
            return [null, sprintf(__('%s is required.', 'gratis-forms'), $label)];
        }

        if ('email' === $type && '' !== $value && !is_email($value)) {
            /* translators: %s: field label. */
            return [null, sprintf(__('%s must be a valid email address.', 'gratis-forms'), $label)];
        }

        return [$value, null];
    }

    /**
     * Handle a single uploaded file field.
     *
     * @param string $key      POST/FILES key.
     * @param bool   $required Whether the field is required.
     * @param string $label    Field label.
     * @return array{0:string,1:?string}
     */
    private static function read_file(string $key, bool $required, string $label) {
        // Nonce is verified by handle() before this helper is reached.
        if (empty($_FILES[$key]) || !isset($_FILES[$key]['name']) || '' === $_FILES[$key]['name']) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            if ($required) {
                /* translators: %s: field label. */
                return ['', sprintf(__('%s is required.', 'gratis-forms'), $label)];
            }
            return ['', null];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = ['test_form' => false];
        // Nonce verified by handle(); wp_handle_upload performs MIME/type validation.
        $uploaded = wp_handle_upload($_FILES[$key], $overrides); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (isset($uploaded['error'])) {
            return ['', sanitize_text_field((string) $uploaded['error'])];
        }
        if (empty($uploaded['url'])) {
            /* translators: %s: field label. */
            return ['', sprintf(__('%s could not be uploaded.', 'gratis-forms'), $label)];
        }

        return [esc_url_raw((string) $uploaded['url']), null];
    }

    /**
     * Verify a reCAPTCHA v3 token against Google's siteverify endpoint.
     */
    private static function verify_recaptcha(string $token): bool {
        if ('' === $token) {
            return false;
        }

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
                'timeout' => 5,
                'body'    => [
                    'secret'   => Settings::get_string('recaptcha_secret_key'),
                    'response' => $token,
                    'remoteip' => self::client_ip(),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            return false;
        }

        $threshold = (float) Settings::all()['recaptcha_threshold'];
        $score     = isset($body['score']) ? (float) $body['score'] : 0.0;

        return $score >= $threshold;
    }

    /**
     * Send the admin notification email.
     *
     * @param int                 $form_id Form post ID.
     * @param array<string,mixed> $data    Submitted values keyed by label.
     */
    private static function notify(int $form_id, array $data): void {
        $notify = (string) get_post_meta($form_id, FormPostType::META_NOTIFY, true);
        if ('' === $notify) {
            $notify = (string) get_option('admin_email');
        }

        $recipients = array_filter(array_map('sanitize_email', array_map('trim', explode(',', $notify))));
        if (empty($recipients)) {
            return;
        }

        $lines = [];
        foreach ($data as $label => $value) {
            $lines[] = $label . ': ' . (is_scalar($value) ? (string) $value : wp_json_encode($value));
        }

        /* translators: %s: form title. */
        $subject = sprintf(__('New submission: %s', 'gratis-forms'), get_the_title($form_id));
        wp_mail($recipients, $subject, implode("\n", $lines));
    }

    /**
     * A privacy-preserving one-way hash of the client IP (salted with auth key).
     */
    private static function ip_hash(): string {
        $ip = self::client_ip();
        return '' === $ip ? '' : hash('sha256', $ip . wp_salt('auth'));
    }

    /**
     * Best-effort client IP from the request.
     */
    private static function client_ip(): string {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return '';
        }
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        return (string) filter_var($ip, FILTER_VALIDATE_IP);
    }
}
