<?php
/**
 * プラグインを削除（アンインストール）したときに WordPress が実行するファイル。
 *
 * 片付けの内容は src/uninstall/class-uninstaller.php と docs/specification-uninstall.md を参照。
 * このファイルは無料版・Pro 版の両方の配布物に含まれる。
 *
 * @package VKBookingManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 削除するプラグインとは別のフォルダの本体が読み込み済み（有効）なら、そのエディションが残っているため何もしない。
// このまま進むと、同名のクラスを別のフォルダから読み込み直して Fatal エラーになる。
// 削除するプラグイン自身が読み込み済み（WP-CLI の `wp plugin uninstall --deactivate` など）のときは、
// フォルダが同じため止めずに片付けへ進む（読み込み済みのクラスは下の class_exists で読み込み直さない）。
if (
	defined( 'VKBM_PLUGIN_DIR_PATH' )
	&& untrailingslashit( wp_normalize_path( VKBM_PLUGIN_DIR_PATH ) ) !== untrailingslashit( wp_normalize_path( __DIR__ ) )
) {
	return;
}

// 同名のクラスが読み込み済みのとき（無料版と Pro 版の一括削除の 2 つ目）は、読み込み直さない。
// 読み込み済みのクラスは、先に削除された方のフォルダのものだが、同じ内容のため使える。
if ( ! class_exists( 'VKBookingManager\Common\Scheduled_Events', false ) ) {
	require_once __DIR__ . '/src/common/class-scheduled-events.php';
}
if ( ! class_exists( 'VKBookingManager\Uninstall\Uninstaller', false ) ) {
	require_once __DIR__ . '/src/uninstall/class-uninstaller.php';
}

// 無料版と Pro 版を一括で削除しても削除する側を取り違えないよう、定数ではなくこのファイルの場所で判別する。
\VKBookingManager\Uninstall\Uninstaller::run( plugin_basename( __DIR__ . '/vk-booking-manager.php' ), __DIR__ );
