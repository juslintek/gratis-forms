<?php declare(strict_types=1);

namespace Gratis\Forms\CLI;

use Gratis\Forms\Storage\Submissions;

defined('ABSPATH') || exit;

/**
 * WP-CLI commands for the Gratis Forms plugin.
 */
final class FormsCommand {

    /**
     * List all forms with submission counts.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Associative args (unused).
     */
    public function list($args, $assoc_args): void {
        unset($args, $assoc_args);

        $forms = get_posts(['post_type' => 'gratis_form', 'numberposts' => -1, 'post_status' => 'any']);
        if (empty($forms)) {
            \WP_CLI::log('No forms.');
            return;
        }

        $rows = array_map(
            static fn($form): array => [
                'ID'      => $form->ID,
                'Title'   => $form->post_title,
                'Status'  => $form->post_status,
                'Entries' => Submissions::count((int) $form->ID),
            ],
            $forms
        );

        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Title', 'Status', 'Entries']);
    }

    /**
     * Export a form's submissions as JSON lines.
     *
     * ## OPTIONS
     *
     * <form_id>
     * : The form ID to export.
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args       Positional args: [form_id].
     * @param array<string,string> $assoc_args Associative args (unused).
     */
    public function export($args, $assoc_args): void {
        unset($assoc_args);

        $form_id = isset($args[0]) ? (int) $args[0] : 0;
        $rows    = Submissions::query($form_id, 10000, 0);

        if (empty($rows)) {
            \WP_CLI::log('No entries.');
            return;
        }

        foreach ($rows as $row) {
            \WP_CLI::log($row['created_at'] . "\t" . wp_json_encode($row['data']));
        }
    }
}
