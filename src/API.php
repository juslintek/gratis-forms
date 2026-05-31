<?php declare(strict_types=1);
namespace Gratis\Forms;

final class API
{
    public static function register(): void
    {
        register_rest_route('gratis-forms/v1', '/entries/(?P<form_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'getEntries'],
            'permission_callback' => fn() => current_user_can('manage_options'),
        ]);
    }

    public static function getEntries(\WP_REST_Request $request): \WP_REST_Response
    {
        $entries = get_posts([
            'post_type'   => 'gratis_form_entry',
            'post_parent' => (int) $request['form_id'],
            'numberposts' => 100,
        ]);

        $data = array_map(fn($e) => [
            'id'   => $e->ID,
            'date' => $e->post_date,
            'data' => get_post_meta($e->ID, '_gratis_entry_data', true),
        ], $entries);

        return new \WP_REST_Response($data);
    }
}
