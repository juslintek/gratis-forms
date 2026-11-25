<?php declare(strict_types=1);

namespace Gratis\Forms\Storage;

defined('ABSPATH') || exit;

/**
 * Custom database table storage for form submissions.
 *
 * Submissions are stored in a dedicated `{prefix}gratis_form_submissions`
 * table (one row per submission) rather than as posts, so large volumes of
 * entries do not bloat wp_posts/wp_postmeta. The submitted field values are
 * stored as a JSON blob in the `data` column.
 */
final class Submissions {

    /** Schema version, bumped when the table structure changes. */
    public const DB_VERSION = '1';

    /** Option key holding the installed schema version. */
    private const VERSION_OPTION = 'gratis_forms_db_version';

    /**
     * Fully-qualified table name including the site table prefix.
     */
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'gratis_form_submissions';
    }

    /**
     * Create or upgrade the submissions table via dbDelta.
     *
     * Safe to call on every activation and on admin_init (guarded by the
     * stored schema version) — dbDelta only applies the diff.
     */
    public static function install(): void {
        global $wpdb;

        if (get_option(self::VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            data LONGTEXT NOT NULL,
            ip_hash VARCHAR(64) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'unread',
            created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Insert a submission.
     *
     * @param int                  $form_id Parent form post ID.
     * @param array<string,mixed>  $data    Sanitised field values keyed by label.
     * @param array<string,string> $meta    Optional ip_hash / user_agent.
     * @return int Inserted row ID, or 0 on failure.
     */
    public static function insert(int $form_id, array $data, array $meta = []): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ok = $wpdb->insert(
            self::table(),
            [
                'form_id'    => $form_id,
                'data'       => wp_json_encode($data),
                'ip_hash'    => $meta['ip_hash'] ?? '',
                'user_agent' => $meta['user_agent'] ?? '',
                'status'     => 'unread',
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Count submissions, optionally scoped to one form.
     */
    public static function count(int $form_id = 0): int {
        global $wpdb;
        $table = self::table();

        if ($form_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE form_id = %d', $table, $form_id));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
    }

    /**
     * Fetch a paginated list of submissions.
     *
     * @param int $form_id  Optional form filter (0 = all forms).
     * @param int $per_page Rows per page.
     * @param int $offset   Row offset.
     * @return array<int,array<string,mixed>> Decoded rows (data already JSON-decoded).
     */
    public static function query(int $form_id = 0, int $per_page = 20, int $offset = 0): array {
        global $wpdb;
        $table    = self::table();
        $per_page = max(1, $per_page);
        $offset   = max(0, $offset);

        if ($form_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $form_id, $per_page, $offset), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d', $table, $per_page, $offset), ARRAY_A);
        }

        return array_map([__CLASS__, 'hydrate'], (array) $rows);
    }

    /**
     * Fetch a single submission by ID.
     *
     * @return array<string,mixed>|null
     */
    public static function get(int $id): ?array {
        global $wpdb;
        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $table, $id), ARRAY_A);

        return $row ? self::hydrate($row) : null;
    }

    /**
     * Delete a submission by ID.
     */
    public static function delete(int $id): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    /**
     * Mark a submission read/unread.
     */
    public static function set_status(int $id, string $status): bool {
        global $wpdb;
        $status = in_array($status, ['read', 'unread'], true) ? $status : 'read';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (bool) $wpdb->update(self::table(), ['status' => $status], ['id' => $id], ['%s'], ['%d']);
    }

    /**
     * Decode the stored JSON `data` column into a PHP array.
     *
     * @param array<string,mixed> $row Raw DB row.
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array {
        $decoded     = json_decode((string) ($row['data'] ?? ''), true);
        $row['data'] = is_array($decoded) ? $decoded : [];
        return $row;
    }
}
