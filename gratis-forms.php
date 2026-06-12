<?php
/**
 * Plugin Name: Gratis Forms
 * Plugin URI: https://github.com/juslintek/gratis-forms
 * Description: Drag-and-drop form builder with conditional fields, anti-spam (honeypot + reCAPTCHA v3) and submission storage. Replaces WPForms ($49/yr) and Gravity Forms ($259/yr).
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * Author URI: https://github.com/juslintek
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gratis-forms
 */

defined('ABSPATH') || exit;

define('GRATIS_FORMS_VERSION', '1.0.0');
define('GRATIS_FORMS_FILE', __FILE__);
define('GRATIS_FORMS_DIR', plugin_dir_path(__FILE__));
define('GRATIS_FORMS_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/autoload.php';

// Activation: create the submissions table.
register_activation_hook(__FILE__, [\Gratis\Forms\Storage\Submissions::class, 'install']);

// Post type + builder.
add_action('init', [\Gratis\Forms\FormPostType::class, 'register']);
add_action('init', [\Gratis\Forms\Renderer::class, 'register']);
add_action('add_meta_boxes', [\Gratis\Forms\FieldBuilder::class, 'register']);
add_action('save_post_gratis_form', [\Gratis\Forms\FieldBuilder::class, 'save'], 10, 2);
add_action('admin_enqueue_scripts', [\Gratis\Forms\FieldBuilder::class, 'enqueue']);

// Submission handling (AJAX) + REST.
add_action('init', [\Gratis\Forms\Submission::class, 'register']);
add_action('rest_api_init', [\Gratis\Forms\API::class, 'register']);

// Admin: settings + submissions list.
add_action('admin_menu', [\Gratis\Forms\Settings::class, 'register_menu']);
add_action('admin_init', [\Gratis\Forms\Settings::class, 'register_settings']);
add_action('admin_menu', [\Gratis\Forms\Admin\SubmissionsPage::class, 'register_menu']);

// Upgrade the schema if the plugin was updated without re-activation.
add_action('admin_init', [\Gratis\Forms\Storage\Submissions::class, 'install']);

// WP-CLI.
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-forms', \Gratis\Forms\CLI\FormsCommand::class);
    }
});
