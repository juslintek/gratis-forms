<?php declare(strict_types=1);

namespace Gratis\Forms;

use Gratis\Forms\Storage\Submissions;

defined('ABSPATH') || exit;

/**
 * Read-only REST API for retrieving submissions of a form (admin only).
 */
final class API {

    /**
     * Register REST routes.
     */
    public static function register(): void {
        register_rest_route(
            'gratis-forms/v1',
            '/entries/(?P<form_id>\d+)',
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_entries'],
                'permission_callback' => static fn() => current_user_can('manage_options'),
                'args'                => [
                    'form_id' => [
                        'validate_callback' => static fn($value) => is_numeric($value),
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * Return submissions for the requested form.
     */
    public static function get_entries(\WP_REST_Request $request): \WP_REST_Response {
        $form_id = (int) $request['form_id'];
        $rows    = Submissions::query($form_id, 100, 0);

        $data = array_map(
            static fn(array $row): array => [
                'id'   => (int) $row['id'],
                'date' => (string) $row['created_at'],
                'data' => $row['data'],
            ],
            $rows
        );

        return new \WP_REST_Response($data);
    }
}
