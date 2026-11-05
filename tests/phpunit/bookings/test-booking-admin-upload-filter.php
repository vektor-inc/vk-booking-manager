<?php
/**
 * 予約の編集画面からのアップロードに対する処理のテスト。
 *
 * 画像の縮小・JPEG 変換（issue #871）、添付の非公開での作成、ファイル名のランダム化、
 * 更新前にアップロードした添付の非公開への切り替えを検証する。
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Tests\Bookings;

use VKBookingManager\Bookings\Booking_Admin;
use VKBookingManager\PostTypes\Booking_Post_Type;
use WP_UnitTestCase;

/**
 * Booking_Admin のアップロード時のフィルターを検証する。
 *
 * @group bookings
 */
class Booking_Admin_Upload_Filter_Test extends WP_UnitTestCase {

	/**
	 * リクエストの後始末。
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_REQUEST['vkbm_booking_upload'], $_REQUEST['post_id'] );
		delete_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE );
		parent::tear_down();
	}

	/**
	 * 目印の無い通常のアップロードとして、リクエストを組み立てる。
	 *
	 * @return void
	 */
	private function clear_upload_request(): void {
		unset( $_REQUEST['vkbm_booking_upload'], $_REQUEST['post_id'] );
	}

	/**
	 * 予約の編集画面からのアップロードとして、リクエストを組み立てる。
	 *
	 * @param int $post_id アップロード先の投稿ID。
	 * @return void
	 */
	private function set_upload_request( int $post_id ): void {
		$_REQUEST['vkbm_booking_upload'] = '1';
		$_REQUEST['post_id']             = (string) $post_id;
	}

	/**
	 * 目印が付くかどうかを、品質フィルターの結果で調べる（予約画面からのアップロードなら 50 になる）。
	 *
	 * @return bool
	 */
	private function is_marked_as_booking_upload(): bool {
		return 50 === ( new Booking_Admin() )->filter_booking_upload_quality( 82, 'image/jpeg' );
	}

	/**
	 * 予約の投稿への、権限のある利用者によるアップロードは予約画面からのアップロードとして扱う。
	 *
	 * @return void
	 */
	public function test_booking_post_with_capability_is_marked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$this->set_upload_request( $booking_id );

		$this->assertTrue( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 予約以外の投稿を指定したアップロードは、予約画面からのアップロードとして扱わない。
	 *
	 * @return void
	 */
	public function test_non_booking_post_is_not_marked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->set_upload_request( $page_id );

		$this->assertFalse( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 投稿IDが無い、または存在しない場合は予約画面からのアップロードとして扱わない。
	 *
	 * @return void
	 */
	public function test_missing_post_id_is_not_marked(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_upload_request( 0 );
		$this->assertFalse( $this->is_marked_as_booking_upload() );

		$this->set_upload_request( 99999999 );
		$this->assertFalse( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 予約を管理する権限の無い利用者では、予約の投稿を指定しても扱わない。
	 *
	 * @return void
	 */
	public function test_user_without_capability_is_not_marked(): void {
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->set_upload_request( $booking_id );

		$this->assertFalse( $this->is_marked_as_booking_upload() );
	}

	/**
	 * 変換後の .jpg と同名のファイルが同じフォルダーにあっても、そのファイルを残して別名で保存する。
	 *
	 * @return void
	 */
	public function test_converted_file_does_not_overwrite_existing_jpg(): void {
		if ( ! function_exists( 'imagepng' ) ) {
			$this->markTestSkipped( 'GD is required.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$this->set_upload_request( $booking_id );

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['path'];
		wp_mkdir_p( $dir );

		$png_file = $dir . '/vkbm-871-sample.png';
		$image    = imagecreatetruecolor( 40, 30 );
		imagepng( $image, $png_file );
		imagedestroy( $image );

		$existing_jpg = $dir . '/vkbm-871-sample.jpg';
		file_put_contents( $existing_jpg, 'existing-file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$attachment_id = self::factory()->attachment->create_object(
			$png_file,
			0,
			array( 'post_mime_type' => 'image/png' )
		);

		( new Booking_Admin() )->filter_booking_attachment_metadata( array( 'width' => 40, 'height' => 30 ), $attachment_id );

		$this->assertSame( 'existing-file', file_get_contents( $existing_jpg ), '既存のファイルが残っていること' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$new_file = get_attached_file( $attachment_id );
		$this->assertNotSame( $existing_jpg, $new_file );
		$this->assertStringEndsWith( '.jpg', $new_file );
		$this->assertFileExists( $new_file );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $attachment_id ) );

		wp_delete_file( $existing_jpg );
		wp_delete_file( $new_file );
	}

	/**
	 * 予約の編集画面からのアップロードでは、ファイル名をランダムな英数字に置き換え、拡張子は残す。
	 *
	 * @return void
	 */
	public function test_filter_booking_upload_filename(): void {
		$admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$page_id    = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '予約画面からのアップロードで拡張子が png => ランダムな名前で拡張子は png のまま',
				'conditions'          => array(
					'post_id' => $booking_id,
					'name'    => 'IMG_0001.png',
				),
				'expected_renamed'    => true,
				'expected_extension'  => 'png',
			),
			array(
				'test_condition_name' => '予約画面からのアップロードで拡張子が大文字の JPG => 拡張子はそのまま',
				'conditions'          => array(
					'post_id' => $booking_id,
					'name'    => 'DSC_0002.JPG',
				),
				'expected_renamed'    => true,
				'expected_extension'  => 'JPG',
			),
			array(
				'test_condition_name' => '予約以外の投稿へのアップロード => 名前を変えない',
				'conditions'          => array(
					'post_id' => $page_id,
					'name'    => 'IMG_0001.png',
				),
				'expected_renamed'    => false,
				'expected_extension'  => 'png',
			),
			array(
				'test_condition_name' => '目印の無い通常のアップロード => 名前を変えない',
				'conditions'          => array(
					'post_id' => null,
					'name'    => 'IMG_0001.png',
				),
				'expected_renamed'    => false,
				'expected_extension'  => 'png',
			),
			array(
				'test_condition_name' => '拡張子の無いファイル => 名前を変えない',
				'conditions'          => array(
					'post_id' => $booking_id,
					'name'    => 'IMG_0001',
				),
				'expected_renamed'    => false,
				'expected_extension'  => '',
			),
		);

		wp_set_current_user( $admin_id );

		foreach ( $test_cases as $case ) {
			if ( null === $case['conditions']['post_id'] ) {
				$this->clear_upload_request();
			} else {
				$this->set_upload_request( $case['conditions']['post_id'] );
			}

			$file = array(
				'name'     => $case['conditions']['name'],
				'type'     => 'image/png',
				'tmp_name' => '/tmp/vkbm-upload-sample',
				'error'    => 0,
				'size'     => 100,
			);

			$actual = ( new Booking_Admin() )->filter_booking_upload_filename( $file );

			$this->assertSame( $case['expected_extension'], pathinfo( $actual['name'], PATHINFO_EXTENSION ), $case['test_condition_name'] . '（拡張子）' );
			if ( $case['expected_renamed'] ) {
				$this->assertNotSame( $file['name'], $actual['name'], $case['test_condition_name'] );
				$this->assertMatchesRegularExpression( '/^[a-f0-9]{24}$/', pathinfo( $actual['name'], PATHINFO_FILENAME ), $case['test_condition_name'] . '（名前の本体）' );
			} else {
				$this->assertSame( $file['name'], $actual['name'], $case['test_condition_name'] );
			}

			// 名前以外の情報は変えない。
			unset( $file['name'], $actual['name'] );
			$this->assertSame( $file, $actual, $case['test_condition_name'] . '（名前以外）' );
		}

		// 同じ名前のファイルを続けてアップロードしても、呼ぶたびに別の名前になる。
		$this->set_upload_request( $booking_id );
		$first  = ( new Booking_Admin() )->filter_booking_upload_filename( array( 'name' => 'IMG_0001.png' ) );
		$second = ( new Booking_Admin() )->filter_booking_upload_filename( array( 'name' => 'IMG_0001.png' ) );
		$this->assertNotSame( $first['name'], $second['name'], '呼ぶたびに別の名前になる' );
	}

	/**
	 * 予約の編集画面からの新規アップロードだけ、添付を非公開（private）で作る。
	 *
	 * @return void
	 */
	public function test_filter_booking_attachment_insert_data(): void {
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$booking_id    = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$page_id       = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '管理者が予約画面から新規アップロード => private',
				'conditions'          => array(
					'user_id' => $admin_id,
					'post_id' => $booking_id,
					'update'  => false,
				),
				'expected'            => 'private',
			),
			array(
				'test_condition_name' => '管理者が予約画面からアップロードした添付の更新 => 状態を変えない',
				'conditions'          => array(
					'user_id' => $admin_id,
					'post_id' => $booking_id,
					'update'  => true,
				),
				'expected'            => 'inherit',
			),
			array(
				'test_condition_name' => '予約以外の投稿への新規アップロード => 状態を変えない',
				'conditions'          => array(
					'user_id' => $admin_id,
					'post_id' => $page_id,
					'update'  => false,
				),
				'expected'            => 'inherit',
			),
			array(
				'test_condition_name' => '目印の無い通常の新規アップロード => 状態を変えない',
				'conditions'          => array(
					'user_id' => $admin_id,
					'post_id' => null,
					'update'  => false,
				),
				'expected'            => 'inherit',
			),
			array(
				'test_condition_name' => '予約を管理する権限の無い利用者による新規アップロード => 状態を変えない',
				'conditions'          => array(
					'user_id' => $subscriber_id,
					'post_id' => $booking_id,
					'update'  => false,
				),
				'expected'            => 'inherit',
			),
		);

		foreach ( $test_cases as $case ) {
			wp_set_current_user( $case['conditions']['user_id'] );
			if ( null === $case['conditions']['post_id'] ) {
				$this->clear_upload_request();
			} else {
				$this->set_upload_request( $case['conditions']['post_id'] );
			}

			$data = array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_parent' => (int) $case['conditions']['post_id'],
				'post_title'  => 'sample',
			);

			$actual = ( new Booking_Admin() )->filter_booking_attachment_insert_data( $data, $data, $data, $case['conditions']['update'] );

			$this->assertSame( $case['expected'], $actual['post_status'], $case['test_condition_name'] );

			// 状態以外の値は変えない。
			unset( $data['post_status'], $actual['post_status'] );
			$this->assertSame( $data, $actual, $case['test_condition_name'] . '（状態以外）' );
		}

		// 実際に添付を作る経路（フックの登録を含む）でも、予約画面からの新規アップロードだけ private になる。
		wp_set_current_user( $admin_id );
		$this->set_upload_request( $booking_id );
		$booking_attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'booking-sample',
				'post_status'    => 'inherit',
			),
			'booking-sample.png',
			$booking_id
		);
		$this->assertSame( 'private', get_post( $booking_attachment_id )->post_status, '予約画面からの新規アップロードで作った添付は private' );

		$this->clear_upload_request();
		$page_attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'page-sample',
				'post_status'    => 'inherit',
			),
			'page-sample.png',
			$page_id
		);
		$this->assertSame( 'inherit', get_post( $page_attachment_id )->post_status, '通常のアップロードで作った添付は inherit のまま' );
	}

	/**
	 * 更新前にアップロードした履歴画像の添付を一度だけ非公開へ切り替え、対象外の添付は変えない。
	 *
	 * @return void
	 */
	public function test_maybe_make_existing_booking_attachments_private(): void {
		$booking_id = self::factory()->post->create( array( 'post_type' => Booking_Post_Type::POST_TYPE ) );
		$post_id    = self::factory()->post->create( array( 'post_type' => 'post' ) );

		$test_cases = array(
			array(
				'test_condition_name' => '親なし・目印あり・inherit => private',
				'conditions'          => array(
					'parent' => 0,
					'flag'   => true,
					'status' => 'inherit',
				),
				'expected'            => 'private',
			),
			array(
				'test_condition_name' => '親が予約・目印なし・inherit => private',
				'conditions'          => array(
					'parent' => $booking_id,
					'flag'   => false,
					'status' => 'inherit',
				),
				'expected'            => 'private',
			),
			array(
				'test_condition_name' => '親が予約・目印あり・inherit => private',
				'conditions'          => array(
					'parent' => $booking_id,
					'flag'   => true,
					'status' => 'inherit',
				),
				'expected'            => 'private',
			),
			array(
				'test_condition_name' => '親が公開投稿・目印なし・inherit => 変えない',
				'conditions'          => array(
					'parent' => $post_id,
					'flag'   => false,
					'status' => 'inherit',
				),
				'expected'            => 'inherit',
			),
			array(
				'test_condition_name' => '親が公開投稿・目印あり・inherit => private',
				'conditions'          => array(
					'parent' => $post_id,
					'flag'   => true,
					'status' => 'inherit',
				),
				'expected'            => 'private',
			),
			array(
				'test_condition_name' => '親が予約・目印あり・すでに private => private のまま',
				'conditions'          => array(
					'parent' => $booking_id,
					'flag'   => true,
					'status' => 'private',
				),
				'expected'            => 'private',
			),
			array(
				'test_condition_name' => '親が予約・目印あり・ゴミ箱 => 変えない',
				'conditions'          => array(
					'parent' => $booking_id,
					'flag'   => true,
					'status' => 'trash',
				),
				'expected'            => 'trash',
			),
			array(
				'test_condition_name' => '親が存在しない投稿ID・目印なし・inherit => 変えない',
				'conditions'          => array(
					'parent' => 99999999,
					'flag'   => false,
					'status' => 'inherit',
				),
				'expected'            => 'inherit',
			),
		);

		$attachment_ids = array();
		foreach ( $test_cases as $index => $case ) {
			$attachment_id = self::factory()->post->create(
				array(
					'post_type'      => 'attachment',
					'post_status'    => $case['conditions']['status'],
					'post_parent'    => $case['conditions']['parent'],
					'post_mime_type' => 'image/png',
				)
			);
			if ( $case['conditions']['flag'] ) {
				update_post_meta( $attachment_id, '_vkbm_booking_uploaded_attachment', '1' );
			}
			$this->assertSame( $case['conditions']['status'], get_post( $attachment_id )->post_status, $case['test_condition_name'] . '（作成直後）' );
			$attachment_ids[ $index ] = $attachment_id;
		}

		$booking_admin = new Booking_Admin();

		// 対象は 4 件。2 件ずつ取り出して 2 回に分けて切り替える。
		$booking_admin->maybe_make_existing_booking_attachments_private( 2 );

		foreach ( $test_cases as $index => $case ) {
			$this->assertSame( $case['expected'], get_post( $attachment_ids[ $index ] )->post_status, $case['test_condition_name'] );
		}
		$this->assertSame( '1', get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), '切り替えが終わったことを option に記録する' );

		// 記録済みなら、対象が残っていても切り替えない。
		wp_update_post(
			array(
				'ID'          => $attachment_ids[0],
				'post_status' => 'inherit',
			)
		);
		$booking_admin->maybe_make_existing_booking_attachments_private( 2 );
		$this->assertSame( 'inherit', get_post( $attachment_ids[0] )->post_status, '記録済みなら切り替えない' );

		// 記録を消すと、残りを切り替えて改めて記録する。
		delete_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE );
		$booking_admin->maybe_make_existing_booking_attachments_private( 2 );
		$this->assertSame( 'private', get_post( $attachment_ids[0] )->post_status, '記録を消すと残りを切り替える' );
		$this->assertSame( '1', get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), '切り替え後に改めて記録する' );

		// 切り替えに失敗した添付があれば記録せず、他の添付は切り替える。次の実行で残りを切り替えて記録する。
		delete_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE );
		foreach ( array( $attachment_ids[0], $attachment_ids[1] ) as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_status' => 'inherit',
				)
			);
		}
		$failing_id = $attachment_ids[0];
		/**
		 * 指定した添付の保存だけを「内容が空」と判定させて失敗させる。
		 *
		 * @param bool                $maybe_empty 内容が空と判定されたか。
		 * @param array<string,mixed> $postarr     保存する投稿のデータ。
		 * @return bool 指定した添付なら true。それ以外は元の判定。
		 */
		$fail_filter = static function ( $maybe_empty, $postarr ) use ( $failing_id ) {
			return ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === $failing_id ) ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail_filter, 10, 2 );
		$booking_admin->maybe_make_existing_booking_attachments_private( 1 );
		remove_filter( 'wp_insert_post_empty_content', $fail_filter, 10 );

		$this->assertSame( 'inherit', get_post( $attachment_ids[0] )->post_status, '失敗した添付は inherit のまま' );
		$this->assertSame( 'private', get_post( $attachment_ids[1] )->post_status, '失敗した添付があっても他の添付は切り替える' );
		$this->assertFalse( get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), '失敗があれば記録しない' );

		$booking_admin->maybe_make_existing_booking_attachments_private( 1 );
		$this->assertSame( 'private', get_post( $attachment_ids[0] )->post_status, '次の実行で残りを切り替える' );
		$this->assertSame( '1', get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), '残りを切り替えたら記録する' );

		// Ajax のリクエストでは切り替えず、記録もしない。
		delete_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE );
		wp_update_post(
			array(
				'ID'          => $attachment_ids[0],
				'post_status' => 'inherit',
			)
		);
		add_filter( 'wp_doing_ajax', '__return_true' );
		$booking_admin->maybe_make_existing_booking_attachments_private( 2 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( 'inherit', get_post( $attachment_ids[0] )->post_status, 'Ajax のリクエストでは切り替えない' );
		$this->assertFalse( get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), 'Ajax のリクエストでは記録しない' );

		// 1回の実行で繰り返す問い合わせの上限（5回）に達したら記録せず、次の実行で残りを切り替えて記録する。
		// 対象を 6 件にして 1 件ずつ取り出し、5 件で打ち切られることを確かめる。
		$target_ids = array( $attachment_ids[0], $attachment_ids[1], $attachment_ids[2], $attachment_ids[4], $attachment_ids[5] );
		foreach ( $target_ids as $attachment_id ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_status' => 'inherit',
				)
			);
		}
		$sixth_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $booking_id,
				'post_mime_type' => 'image/png',
			)
		);
		$booking_admin->maybe_make_existing_booking_attachments_private( 1 );
		foreach ( $target_ids as $attachment_id ) {
			$this->assertSame( 'private', get_post( $attachment_id )->post_status, '上限までの 5 件は切り替える' );
		}
		$this->assertSame( 'inherit', get_post( $sixth_id )->post_status, '上限に達したら 6 件目は残る' );
		$this->assertFalse( get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), '上限に達したら記録しない' );

		$booking_admin->maybe_make_existing_booking_attachments_private( 1 );
		$this->assertSame( 'private', get_post( $sixth_id )->post_status, '次の実行で残りを切り替える' );
		$this->assertSame( '1', get_option( Booking_Admin::OPTION_ATTACHMENTS_PRIVATE_DONE ), '残りを切り替えたら記録する' );
	}
}
