<?php declare(strict_types=1);

namespace Gratis\Forms\Admin;

use Gratis\Forms\Storage\Submissions;

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin list table of form submissions backed by the custom submissions table.
 */
final class SubmissionsListTable extends \WP_List_Table {

    private int $form_id = 0;

    /**
     * @param int $form_id Optional form filter.
     */
    public function __construct(int $form_id = 0) {
        $this->form_id = $form_id;
        parent::__construct(
            [
                'singular' => 'submission',
                'plural'   => 'submissions',
                'ajax'     => false,
            ]
        );
    }

    /**
     * Define the table columns.
     *
     * @return array<string,string>
     */
    public function get_columns(): array {
        return [
            'cb'         => '<input type="checkbox" />',
            'id'         => __('ID', 'gratis-forms'),
            'form'       => __('Form', 'gratis-forms'),
            'summary'    => __('Submission', 'gratis-forms'),
            'created_at' => __('Date', 'gratis-forms'),
        ];
    }

    /**
     * Checkbox column.
     *
     * @param array<string,mixed> $item Row data.
     */
    public function column_cb($item): string {
        return sprintf('<input type="checkbox" name="submission[]" value="%d" />', (int) $item['id']);
    }

    /**
     * Render the ID column with row actions.
     *
     * @param array<string,mixed> $item Row data.
     */
    public function column_id($item): string {
        $id   = (int) $item['id'];
        $base = admin_url('edit.php?post_type=gratis_form&page=gratis-forms-submissions');

        $view_url = wp_nonce_url(add_query_arg(['action' => 'view', 'submission' => $id], $base), 'gratis_forms_view_' . $id);
        $del_url  = wp_nonce_url(add_query_arg(['action' => 'delete', 'submission' => $id], $base), 'gratis_forms_delete_' . $id);

        $actions = [
            'view'   => sprintf('<a href="%s">%s</a>', esc_url($view_url), esc_html__('View', 'gratis-forms')),
            'delete' => sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(%s);">%s</a>',
                esc_url($del_url),
                esc_js("'" . __('Delete this submission?', 'gratis-forms') . "'"),
                esc_html__('Delete', 'gratis-forms')
            ),
        ];

        $status = ('unread' === ($item['status'] ?? '')) ? ' <strong>•</strong>' : '';

        return sprintf('%1$s%2$s%3$s', absint($id), $status, $this->row_actions($actions));
    }

    /**
     * Render the Form column.
     *
     * @param array<string,mixed> $item Row data.
     */
    public function column_form($item): string {
        $title = get_the_title((int) $item['form_id']);
        return $title ? esc_html($title) : esc_html__('(deleted form)', 'gratis-forms');
    }

    /**
     * Render a short summary of the submission data.
     *
     * @param array<string,mixed> $item Row data.
     */
    public function column_summary($item): string {
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];
        $bits = [];
        foreach ($data as $label => $value) {
            $value  = is_scalar($value) ? (string) $value : wp_json_encode($value);
            $bits[] = esc_html($label . ': ' . wp_trim_words((string) $value, 8));
            if (count($bits) >= 3) {
                break;
            }
        }
        return implode('<br>', $bits);
    }

    /**
     * Render the Date column.
     *
     * @param array<string,mixed> $item Row data.
     */
    public function column_created_at($item): string {
        $ts = strtotime((string) ($item['created_at'] ?? ''));
        return $ts ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts)) : '';
    }

    /**
     * Fallback column renderer.
     *
     * @param array<string,mixed> $item        Row data.
     * @param string              $column_name Column key.
     */
    public function column_default($item, $column_name): string {
        return isset($item[$column_name]) && is_scalar($item[$column_name])
            ? esc_html((string) $item[$column_name])
            : '';
    }

    /**
     * Bulk action definitions.
     *
     * @return array<string,string>
     */
    public function get_bulk_actions(): array {
        return ['delete' => __('Delete', 'gratis-forms')];
    }

    /**
     * Load and paginate the items.
     */
    public function prepare_items(): void {
        $per_page = 20;
        $current  = $this->get_pagenum();
        $offset   = ($current - 1) * $per_page;

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items           = Submissions::query($this->form_id, $per_page, $offset);

        $total = Submissions::count($this->form_id);
        $this->set_pagination_args(
            [
                'total_items' => $total,
                'per_page'    => $per_page,
                'total_pages' => (int) ceil($total / $per_page),
            ]
        );
    }
}
