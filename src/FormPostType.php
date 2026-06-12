<?php declare(strict_types=1);

namespace Gratis\Forms;

defined('ABSPATH') || exit;

/**
 * Registers the `gratis_form` custom post type. Each form stores its field
 * configuration as a JSON array in the `_gratis_form_fields` post meta key,
 * edited through the drag-and-drop {@see FieldBuilder} meta box.
 */
final class FormPostType {

    public const POST_TYPE = 'gratis_form';

    /** Post meta key holding the field-config JSON. */
    public const META_FIELDS = '_gratis_form_fields';

    /** Post meta key holding the notification recipient(s). */
    public const META_NOTIFY = '_gratis_form_notify';

    /** Post meta key toggling reCAPTCHA for the individual form. */
    public const META_RECAPTCHA = '_gratis_form_recaptcha';

    /** Supported field types. */
    public const FIELD_TYPES = ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'file'];

    /**
     * Register the custom post type.
     */
    public static function register(): void {
        register_post_type(
            self::POST_TYPE,
            [
                'labels'       => [
                    'name'          => __('Forms', 'gratis-forms'),
                    'singular_name' => __('Form', 'gratis-forms'),
                    'add_new'       => __('Add Form', 'gratis-forms'),
                    'add_new_item'  => __('Add New Form', 'gratis-forms'),
                    'edit_item'     => __('Edit Form', 'gratis-forms'),
                    'menu_name'     => __('Forms', 'gratis-forms'),
                ],
                'public'       => false,
                'show_ui'      => true,
                'show_in_rest' => false,
                'menu_icon'    => 'dashicons-feedback',
                'supports'     => ['title'],
                'capability_type' => 'post',
            ]
        );
    }
}
