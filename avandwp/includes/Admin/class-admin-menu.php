<?php
/**
 * Admin Navigation Menu and Asset Loader (`AvandWP_Admin_Menu`).
 *
 * Replaces the cluttered 20-submenu legacy layout with 6 focused, task-oriented
 * pages and enforces strict capability separation (`edit_posts` vs `manage_options`).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Admin_Menu {

	/**
	 * Register WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register top-level menu and 6 submenus.
	 *
	 * @return void
	 */
	public function register_menus() {
		$icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none">'
			. '<path d="M12 2L3 7v10l9 5 9-5V7l-9-5zm0 2.3l6.5 3.6L12 11.5 5.5 7.9 12 4.3zm-7 5.4l6 3.3v6.5l-6-3.3V9.7zm8 9.8V13l6-3.3v6.5l-6 3.3z" fill="#a7aaad"/>'
			. '</svg>'
		);

		add_menu_page(
			'آوند (AvandWP) — پیشخوان هوشمند',
			'آوند (AvandWP)',
			'edit_posts',
			'avandwp-dashboard',
			array( $this, 'render_dashboard' ),
			$icon_svg,
			56
		);

		add_submenu_page(
			'avandwp-dashboard',
			'پیشخوان — آوند',
			'پیشخوان',
			'edit_posts',
			'avandwp-dashboard',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'avandwp-dashboard',
			'استودیو محتوا و رسانه — آوند',
			'استودیو محتوا و رسانه',
			'edit_posts',
			'avandwp-studio',
			array( $this, 'render_studio' )
		);

		add_submenu_page(
			'avandwp-dashboard',
			'دستیار ووکامرس — آوند',
			'دستیار ووکامرس',
			'edit_posts',
			'avandwp-woocommerce',
			array( $this, 'render_woocommerce' )
		);

		add_submenu_page(
			'avandwp-dashboard',
			'چت‌بات و پشتیبانی — آوند',
			'چت‌بات و پشتیبانی',
			'edit_posts',
			'avandwp-support',
			array( $this, 'render_support' )
		);

		// Restricted to administrators (manage_options)
		add_submenu_page(
			'avandwp-dashboard',
			'خودکارسازی و تلگرام — آوند',
			'خودکارسازی و تلگرام',
			'manage_options',
			'avandwp-automation',
			array( $this, 'render_automation' )
		);

		add_submenu_page(
			'avandwp-dashboard',
			'تنظیمات و سلامت — آوند',
			'تنظیمات و سلامت',
			'manage_options',
			'avandwp-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue CSS and JS strictly on AvandWP admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'avandwp' ) ) {
			return;
		}

		wp_enqueue_style(
			'avandwp-admin',
			AVANDWP_URL . 'assets/css/admin.css',
			array(),
			AVANDWP_VERSION
		);

		wp_enqueue_script(
			'avandwp-admin',
			AVANDWP_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			AVANDWP_VERSION,
			true
		);

		wp_localize_script(
			'avandwp-admin',
			'AvandWPAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'avandwp_admin_nonce' ),
				'version' => AVANDWP_VERSION,
			)
		);
	}

	/**
	 * Render Page 1: Dashboard.
	 */
	public function render_dashboard() {
		include AVANDWP_PATH . 'templates/admin/dashboard.php';
	}

	/**
	 * Render Page 2: Content & Media Studio.
	 */
	public function render_studio() {
		include AVANDWP_PATH . 'templates/admin/studio.php';
	}

	/**
	 * Render Page 3: WooCommerce AI Assistant.
	 */
	public function render_woocommerce() {
		include AVANDWP_PATH . 'templates/admin/woocommerce.php';
	}

	/**
	 * Render Page 4: Chat Assistant & Support Desk.
	 */
	public function render_support() {
		include AVANDWP_PATH . 'templates/admin/support.php';
	}

	/**
	 * Render Page 5: Automation & Telegram.
	 */
	public function render_automation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'شما دسترسی لازم برای مشاهده این بخش را ندارید.' );
		}
		include AVANDWP_PATH . 'templates/admin/automation.php';
	}

	/**
	 * Render Page 6: Settings & System Health.
	 */
	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'شما دسترسی لازم برای مشاهده این بخش را ندارید.' );
		}
		include AVANDWP_PATH . 'templates/admin/settings.php';
	}
}
