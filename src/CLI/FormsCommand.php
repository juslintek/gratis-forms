<?php declare(strict_types=1);
namespace Gratis\Forms\CLI;

final class FormsCommand
{
    /**
     * List all forms.
     * @when after_wp_load
     */
    public function list($args, $assoc_args): void
    {
        $forms = get_posts(['post_type' => 'gratis_form', 'numberposts' => -1]);
        if (empty($forms)) { \WP_CLI::log("No forms."); return; }
        $rows = array_map(fn($f) => [
            'ID' => $f->ID, 'Title' => $f->post_title,
            'Entries' => count(get_posts(['post_type' => 'gratis_form_entry', 'post_parent' => $f->ID, 'numberposts' => -1])),
        ], $forms);
        \WP_CLI\Utils\format_items('table', $rows, ['ID', 'Title', 'Entries']);
    }

    /**
     * Export form entries as CSV.
     * <form_id>
     * : Form ID.
     * @when after_wp_load
     */
    public function export($args, $assoc_args): void
    {
        $entries = get_posts(['post_type' => 'gratis_form_entry', 'post_parent' => (int) $args[0], 'numberposts' => -1]);
        if (empty($entries)) { \WP_CLI::log("No entries."); return; }
        foreach ($entries as $e) {
            $data = get_post_meta($e->ID, '_gratis_entry_data', true);
            \WP_CLI::log($e->post_date . "\t" . json_encode($data));
        }
    }
}
