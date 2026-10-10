<?php
/**
 * Shared Admin Header & Navigation Bar.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'avandwp-dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$health_snap  = AvandWP_Health::get_report();
$has_key      = ! empty( $health_snap['has_any_ai_key'] );

$nav_items = array(
	'avandwp-dashboard'   => array( 'label' => 'پیشخوان', 'cap' => 'edit_posts' ),
	'avandwp-studio'      => array( 'label' => 'استودیو محتوا و رسانه', 'cap' => 'edit_posts' ),
	'avandwp-woocommerce' => array( 'label' => 'دستیار ووکامرس', 'cap' => 'edit_posts' ),
	'avandwp-support'     => array( 'label' => 'چت‌بات و پشتیبانی', 'cap' => 'edit_posts' ),
	'avandwp-automation'  => array( 'label' => 'خودکارسازی و تلگرام', 'cap' => 'manage_options' ),
	'avandwp-settings'    => array( 'label' => 'تنظیمات و سلامت', 'cap' => 'manage_options' ),
);
?>
<div class="avandwp-topbar">
	<div class="avandwp-brand">
		<div class="avandwp-brand-logo" aria-hidden="true">
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none">
				<path d="M12 2L3 7v10l9 5 9-5V7l-9-5zm0 2.3l6.5 3.6L12 11.5 5.5 7.9 12 4.3zm-7 5.4l6 3.3v6.5l-6-3.3V9.7zm8 9.8V13l6-3.3v6.5l-6 3.3z" fill="#2563EB"/>
			</svg>
		</div>
		<div>
			<h1 class="avandwp-brand-title">آوند <span class="avandwp-version-badge">نسخه <?php echo esc_html( AVANDWP_VERSION ); ?></span></h1>
			<p class="avandwp-brand-subtitle">دستیار هوشمند تولید محتوا، ووکامرس و پشتیبانی وردپرس</p>
		</div>
	</div>

	<div class="avandwp-status-pills">
		<?php if ( $has_key ) : ?>
			<span class="avandwp-badge avandwp-badge-success">● کلید هوش مصنوعی فعال</span>
		<?php else : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-settings' ) ); ?>" class="avandwp-badge avandwp-badge-warning">⚠ نیازمند ثبت کلید API</a>
		<?php endif; ?>

		<?php if ( $health_snap['missing_tables_count'] > 0 ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-settings#tab-health' ) ); ?>" class="avandwp-badge avandwp-badge-danger">خطا در جداول دیتابیس</a>
		<?php endif; ?>
	</div>
</div>

<nav class="avandwp-main-nav" aria-label="ناوبری اصلی آوند">
	<?php foreach ( $nav_items as $slug => $item ) : ?>
		<?php if ( current_user_can( $item['cap'] ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
			   class="avandwp-nav-link <?php echo ( $current_page === $slug ) ? 'is-active' : ''; ?>">
				<?php echo esc_html( $item['label'] ); ?>
			</a>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>

<div id="avandwp-toast" class="avandwp-toast" role="status" aria-live="polite" hidden></div>
