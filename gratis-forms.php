<?php
/**
 * Plugin Name: Gratis Forms
 * Description: Free form builder with conditional logic + Stripe payments. Replaces WPForms ($49/yr) and Gravity Forms ($259/yr).
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Linas Jusys
 * License: GPL-2.0-or-later
 * Text Domain: gratis-forms
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/autoload.php';

add_action('init', [\Gratis\Forms\FormPostType::class, 'register']);
add_action('rest_api_init', [\Gratis\Forms\API::class, 'register']);
add_action('plugins_loaded', function () {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('gratis-forms', \Gratis\Forms\CLI\FormsCommand::class);
    }
});
