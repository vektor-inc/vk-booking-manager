<?php
/**
 * このプラグインのブロックのアセットのバージョンを、プラグインのバージョンに揃える。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ブロックの CSS の `?ver=` を、WordPress 本体のバージョンではなくプラグインのバージョンにする。
 *
 * `block.json` に `version` が無いと、WordPress は style / editorStyle の `?ver=` に本体のバージョンを使う。
 * その場合はプラグインを更新しても URL が変わらず、CDN やブラウザのキャッシュが古いファイルを配り続ける（issue #854）。
 * 各 `block.json` に書くとリリースのたびに書き換え忘れが起きるため、登録時に `VKBM_VERSION` を設定する。
 */
class Block_Asset_Version {
	/**
	 * このプラグインのブロック名の接頭辞。他プラグイン・コアのブロックを対象外にするために使う。
	 */
	public const BLOCK_NAME_PREFIX = 'vk-booking-manager/';

	/**
	 * フックを登録する。
	 */
	public function register(): void {
		add_filter( 'block_type_metadata', array( $this, 'filter_block_type_metadata' ) );
	}

	/**
	 * このプラグインのブロックのメタデータに、プラグインのバージョンを設定する。
	 *
	 * @param array<string, mixed> $metadata block.json から読み込んだメタデータ。
	 * @return array<string, mixed>
	 */
	public function filter_block_type_metadata( $metadata ) {
		if ( ! is_array( $metadata ) || ! defined( 'VKBM_VERSION' ) ) {
			return $metadata;
		}

		// このプラグインのブロック以外（コア・他プラグイン）は変更しない。
		$name = isset( $metadata['name'] ) && is_string( $metadata['name'] ) ? $metadata['name'] : '';
		if ( 0 !== strpos( $name, self::BLOCK_NAME_PREFIX ) ) {
			return $metadata;
		}

		$metadata['version'] = VKBM_VERSION;

		return $metadata;
	}
}
