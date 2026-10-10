<?php
/**
 * Plugin Name:       آوند (AvandWP) — دستیار هوشمند محتوا، ووکامرس و پشتیبانی
 * Plugin URI:        https://github.com/Masoumiofficial/etehadyar-plu
 * Description:       بسته عملیاتی هوش مصنوعی برای وردپرس: استودیو تولید محتوا و رسانه، بهینه‌ساز محصولات ووکامرس، چت‌بات پاسخ‌گو متصل به پایگاه دانش و صندوق تیکت، و انتشار خودکار در تلگرام با پشتیبانی از سرویس‌های بین‌المللی و ریالی.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            تیم توسعه آوند (AvandWP)
 * Text Domain:       avandwp
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AVANDWP_VERSION', '1.0.0' );
define( 'AVANDWP_DB_VERSION', '1000' );
define( 'AVANDWP_FILE', __FILE__ );
define( 'AVANDWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'AVANDWP_URL', plugin_dir_url( __FILE__ ) );
define( 'AVANDWP_BASENAME', plugin_basename( __FILE__ ) );

// Core & Security
require_once AVANDWP_PATH . 'includes/Core/class-text-helper.php';
require_once AVANDWP_PATH . 'includes/Security/class-vault.php';
require_once AVANDWP_PATH . 'includes/Security/class-rate-limiter.php';
require_once AVANDWP_PATH . 'includes/Core/class-logger.php';
require_once AVANDWP_PATH . 'includes/Core/class-activator.php';
require_once AVANDWP_PATH . 'includes/Core/class-deactivator.php';
require_once AVANDWP_PATH . 'includes/Core/class-job-queue.php';
require_once AVANDWP_PATH . 'includes/Core/class-health.php';

// Providers
require_once AVANDWP_PATH . 'includes/Providers/class-ai-client.php';
require_once AVANDWP_PATH . 'includes/Providers/class-image-client.php';

// Domain Modules
require_once AVANDWP_PATH . 'includes/Knowledge/class-knowledge-base.php';
require_once AVANDWP_PATH . 'includes/Content/class-content-studio.php';
require_once AVANDWP_PATH . 'includes/WooCommerce/class-woo-assistant.php';
require_once AVANDWP_PATH . 'includes/Support/class-support-desk.php';
require_once AVANDWP_PATH . 'includes/Support/class-chat-assistant.php';
require_once AVANDWP_PATH . 'includes/Automation/class-telegram.php';
require_once AVANDWP_PATH . 'includes/Automation/class-content-auditor.php';
require_once AVANDWP_PATH . 'includes/Reports/class-reports.php';

// Admin & Main Controller
require_once AVANDWP_PATH . 'includes/Admin/class-admin-menu.php';
require_once AVANDWP_PATH . 'includes/Core/class-plugin.php';

register_activation_hook( __FILE__, array( 'AvandWP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AvandWP_Deactivator', 'deactivate' ) );

/**
 * Bootstrap the plugin instance on plugins_loaded.
 *
 * @return AvandWP_Plugin
 */
function avandwp() {
	return AvandWP_Plugin::instance();
}
add_action( 'plugins_loaded', 'avandwp' );
