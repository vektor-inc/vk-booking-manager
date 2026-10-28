<?php
/**
 * 検索欄に案内を添える予約一覧。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use VKBookingManager\Attendees\Attendee_Number_Search;

/**
 * 標準一覧の検索欄だけを拡張する。
 */
class Booking_Posts_List_Table extends \WP_Posts_List_Table {

	/**
	 * 標準の検索欄と案内を、関連付けた HTML として出力する。
	 *
	 * @param string $text 検索ボタンのラベル。
	 * @param string $input_id 検索入力の ID の接頭辞。
	 */
	public function search_box( $text, $input_id ) {
		// 標準の非表示条件と並べ替え用の hidden 項目を保つ。
		ob_start();
		parent::search_box( $text, $input_id );
		$html = ob_get_clean();

		// 番号機能がオンの施設だけ、検索欄の左に番号専用の欄を置く。
		$number_box = Attendee_Number_Search::is_enabled() ? $this->get_number_box( '' === $html ) : '';

		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'INPUT' ) ) {
			if ( $input_id . '-search-input' !== $tags->get_attribute( 'id' ) ) {
				continue;
			}
			// JS が無効でも、最初の HTML から案内を参照できるようにする。
			$tags->set_attribute( 'aria-describedby', 'vkbm-booking-search-help' );
			$this->echo_search_fields( $number_box, $tags->get_updated_html() );
			( new Booking_List_Search() )->render_search_help();
			return;
		}
		$this->echo_search_fields( $number_box, $html );
		// 標準の検索欄が出ないときも、番号の欄の説明は出す。
		if ( '' !== $number_box ) {
			( new Booking_List_Search() )->render_search_help( false );
		}
	}

	/**
	 * 番号の欄と標準の検索欄を、横並び（狭い幅では縦積み）の1つのまとまりとして出力する。
	 *
	 * @param string $number_box 番号の欄の HTML。番号機能がオフなら空文字。
	 * @param string $search_box 標準の検索欄の HTML。
	 */
	private function echo_search_fields( string $number_box, string $search_box ): void {
		if ( '' === $number_box ) {
			echo $search_box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- コアがエスケープした HTML（必要な場合は固定属性だけを追加）。
			return;
		}
		echo '<div class="vkbm-booking-search-fields">';
		echo $number_box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_number_box() でエスケープ済み。
		echo $search_box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- コアがエスケープした HTML（必要な場合は固定属性だけを追加）。
		echo '</div>';
	}

	/**
	 * 番号専用の検索欄の HTML を返す。
	 *
	 * @param bool $with_submit 標準の検索欄が出ない（0件で未入力）ため、この欄に検索ボタンを付けるか。
	 * @return string 番号専用の検索欄の HTML。
	 */
	private function get_number_box( bool $with_submit ): string {
		$label = Attendee_Number_Search::get_label();
		$value = Attendee_Number_Search::get_requested_input();
		$id    = 'vkbm-attendee-number-search-input';

		$html  = '<p class="search-box vkbm-attendee-number-search">';
		$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$html .= '<input type="search" id="' . esc_attr( $id ) . '" name="' . esc_attr( Attendee_Number_Search::QUERY_VAR ) . '" value="' . esc_attr( $value ) . '" autocomplete="off" aria-describedby="vkbm-attendee-number-search-help" />';
		if ( $with_submit ) {
			$html .= get_submit_button( __( 'Search', 'vk-booking-manager' ), '', '', false, array( 'id' => 'vkbm-attendee-number-search-submit' ) );
		}
		$html .= '</p>';
		return $html;
	}
}
