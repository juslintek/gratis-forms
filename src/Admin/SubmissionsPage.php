<?php declare(strict_types=1);

namespace Gratis\Forms\Admin;

use Gratis\Forms\FormPostType;
use Gratis\Forms\Storage\Submissions;

defined('ABSPATH') || exit;

/**
 * Admin submenu page that lists submissions, shows single submissions, handles
 * delete/bulk-delete actions and CSV export.
 */
final class SubmissionsPage {

    private const SLUG = 'gratis-forms-submissions';

    /**
     * Register the submenu under the Forms CPT.
     */
    public static function register_menu(): void {
        $hook = add_submenu_page(
            'edit.php?post_type=' . FormPostType::POST_TYPE,
            __('Submissions', 'gratis-forms'),
            __('Submissions', 'gratis-forms'),
            'manage_options',
            self::SLUG,
            [__CLASS__, 'render']
        );

        if ($hook) {
            add_action('load-' . $hook, [__CLASS__, 'handle_actions']);
        }
    }

    /**
     * Process delete / bulk-delete / CSV-export requests before output.
     */
    public static function handle_actions(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $action  = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        $form_id = isset($_REQUEST['form_id']) ? absint(wp_unslash($_REQUEST['form_id'])) : 0;

        // Single delete.
        if ('delete' === $action && isset($_REQUEST['submission']) && !is_array($_REQUEST['submission'])) {
            $id    = absint(wp_unslash($_REQUEST['submission']));
            $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
            if ($id > 0 && wp_verify_nonce($nonce, 'gratis_forms_delete_' . $id)) {
                Submissions::delete($id);
                self::redirect(['deleted' => 1]);
            }
        }

        // Bulk delete.
        $bulk = self::current_bulk_action();
        if ('delete' === $bulk && isset($_REQUEST['submission']) && is_array($_REQUEST['submission'])) {
            check_admin_referer('bulk-submissions');
            $ids = array_map('absint', wp_unslash($_REQUEST['submission']));
            foreach ($ids as $id) {
                if ($id > 0) {
                    Submissions::delete($id);
                }
            }
            self::redirect(['deleted' => count($ids)]);
        }

        // CSV export.
        if ('export' === $action) {
            $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
            if (wp_verify_nonce($nonce, 'gratis_forms_export')) {
                self::export_csv($form_id);
            }
        }
    }

    /**
     * Resolve the requested bulk action from either bulk dropdown.
     */
    private static function current_bulk_action(): string {
        foreach (['action', 'action2'] as $field) {
            if (isset($_REQUEST[$field]) && '-1' !== $_REQUEST[$field]) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $value = sanitize_key(wp_unslash($_REQUEST[$field])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                if ('delete' === $value) {
                    return 'delete';
                }
            }
        }
        return '';
    }

    /**
     * Redirect back to the list with a status flag.
     *
     * @param array<string,int|string> $args Extra query args.
     */
    private static function redirect(array $args): void {
        $base = add_query_arg(
            array_merge(
                ['post_type' => FormPostType::POST_TYPE, 'page' => self::SLUG],
                $args
            ),
            admin_url('edit.php')
        );
        wp_safe_redirect($base);
        exit;
    }

    /**
     * Stream submissions for a form as a CSV download.
     */
    private static function export_csv(int $form_id): void {
        $rows = Submissions::query($form_id, 10000, 0);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="submissions-' . absint($form_id) . '.csv"');

        // Streaming a CSV download to php://output requires direct stream
        // handles; WP_Filesystem cannot write to the output buffer.
        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        // Build a union of all data keys for the header row.
        $columns = ['id', 'created_at'];
        foreach ($rows as $row) {
            foreach (array_keys((array) $row['data']) as $key) {
                if (!in_array($key, $columns, true)) {
                    $columns[] = $key;
                }
            }
        }
        fputcsv($out, $columns); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv

        foreach ($rows as $row) {
            $line = ['id' => (int) $row['id'], 'created_at' => (string) $row['created_at']];
            foreach ($columns as $col) {
                if (in_array($col, ['id', 'created_at'], true)) {
                    continue;
                }
                $value      = $row['data'][$col] ?? '';
                $line[$col] = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
            }
            fputcsv($out, $line); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
        }

        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * Render the page: either the single-submission view or the list table.
     */
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gratis-forms'));
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ('view' === $action && isset($_GET['submission'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            self::render_single();
            return;
        }

        $form_id = isset($_GET['form_id']) ? absint(wp_unslash($_GET['form_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $table = new SubmissionsListTable($form_id);
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Form Submissions', 'gratis-forms') . '</h1>';

        $export_url = wp_nonce_url(
            add_query_arg(
                ['post_type' => FormPostType::POST_TYPE, 'page' => self::SLUG, 'action' => 'export', 'form_id' => $form_id],
                admin_url('edit.php')
            ),
            'gratis_forms_export'
        );
        echo ' <a href="' . esc_url($export_url) . '" class="page-title-action">' . esc_html__('Export CSV', 'gratis-forms') . '</a>';
        echo '<hr class="wp-header-end">';

        if (isset($_GET['deleted'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $deleted = absint(wp_unslash($_GET['deleted'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>';
            /* translators: %d: number of deleted submissions. */
            echo esc_html(sprintf(_n('%d submission deleted.', '%d submissions deleted.', $deleted, 'gratis-forms'), $deleted));
            echo '</p></div>';
        }

        echo '<form method="post">';
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render a single submission's full detail.
     */
    private static function render_single(): void {
        $id    = isset($_GET['submission']) ? absint(wp_unslash($_GET['submission'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($id < 1 || !wp_verify_nonce($nonce, 'gratis_forms_view_' . $id)) {
            wp_die(esc_html__('Invalid request.', 'gratis-forms'));
        }

        $row = Submissions::get($id);
        if (!$row) {
            wp_die(esc_html__('Submission not found.', 'gratis-forms'));
        }

        if ('unread' === ($row['status'] ?? '')) {
            Submissions::set_status($id, 'read');
        }

        $back = add_query_arg(
            ['post_type' => FormPostType::POST_TYPE, 'page' => self::SLUG],
            admin_url('edit.php')
        );

        echo '<div class="wrap">';
        /* translators: %d: submission ID. */
        echo '<h1>' . esc_html(sprintf(__('Submission #%d', 'gratis-forms'), $id)) . '</h1>';
        echo '<p><a href="' . esc_url($back) . '">&larr; ' . esc_html__('Back to submissions', 'gratis-forms') . '</a></p>';

        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>' . esc_html__('Form', 'gratis-forms') . '</th><td>' . esc_html(get_the_title((int) $row['form_id'])) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Date', 'gratis-forms') . '</th><td>' . esc_html((string) $row['created_at']) . '</td></tr>';

        foreach ((array) $row['data'] as $label => $value) {
            $display = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
            echo '<tr><th>' . esc_html((string) $label) . '</th><td>' . esc_html($display) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
