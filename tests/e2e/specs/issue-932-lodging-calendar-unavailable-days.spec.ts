/**
 * issue #932: 宿泊カレンダーの予約できない日とチェックアウト境界を検証する。
 */
import { test, expect, Page } from '@playwright/test';
import {
	wpCliArgs,
	wpEvalPhp,
	createShiftForMonth,
	getTokyoDateParts,
} from '../utils/helpers';
import { configureProviderSettings } from '../utils/setup';

const PREFIX = 'E2E932';
const OPTION_NAME = 'vkbm_provider_settings';
let originalSettingsJson: string | null = null;
let roomId = '';
let menuId = '';

function dateAfter( offset: number ) {
	const date = new Date( Date.now() + offset * 24 * 60 * 60 * 1000 );
	const { year, month, day } = getTokyoDateParts( date );
	return {
		year: Number( year ),
		month: Number( month ),
		day: Number( day ),
		iso: `${ year }-${ month }-${ day }`,
	};
}

function insertAndGetId( php: string, label: string ): string {
	const result = wpEvalPhp( php ).trim();
	if ( ! /^\d+$/.test( result ) || Number( result ) <= 0 ) {
		throw new Error( `${ label } seeding failed: "${ result }"` );
	}
	return result;
}

async function dayButton( page: Page, offset: number ) {
	const target = dateAfter( offset );
	const calendar = page.locator( '.vkbm-stay-calendar' );
	const targetMonthIndex = target.year * 12 + target.month;
	// 表示中の年月と対象の年月の差に応じて、前月・翌月へ移動する（例: 見出しは「2026年10月」）。
	for ( let i = 0; i < 6; i++ ) {
		const heading = await calendar
			.locator( '.vkbm-calendar__current' )
			.innerText();
		const matched = heading.match( /(\d{4})\D+(\d{1,2})/ );
		if ( ! matched ) {
			throw new Error( `Unexpected calendar heading: "${ heading }"` );
		}
		const difference =
			targetMonthIndex -
			( Number( matched[ 1 ] ) * 12 + Number( matched[ 2 ] ) );
		if ( difference === 0 ) {
			break;
		}
		const nav = calendar.locator( '.vkbm-calendar__nav' );
		await ( difference < 0 ? nav.first() : nav.last() ).click();
	}
	return calendar.locator( 'button.vkbm-calendar__day' ).filter( {
		has: page.locator( '.vkbm-calendar__day-label', {
			hasText: new RegExp( `^${ target.day }$` ),
		} ),
	} );
}

function restoreSettings(): void {
	if ( originalSettingsJson ) {
		wpCliArgs( [
			'option',
			'update',
			OPTION_NAME,
			originalSettingsJson,
			'--format=json',
		] );
		return;
	}
	try {
		wpCliArgs( [ 'option', 'delete', OPTION_NAME ] );
	} catch {
		// 元から無ければ何もしない。
	}
}

function cleanup(): void {
	wpEvalPhp( `
		foreach ( get_posts( array( 'post_type' => 'vkbm_shift', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_vkbm_shift_resource_id', 'meta_value' => ${ Number(
			roomId
		) } ) ) as $id ) { wp_delete_post( $id, true ); }
		foreach ( get_posts( array( 'post_type' => array( 'vkbm_booking', 'vkbm_shift', 'vkbm_service_menu', 'vkbm_resource' ), 'post_status' => 'any', 'numberposts' => -1, 's' => '${ PREFIX }' ) ) as $post ) {
			if ( 0 === strpos( $post->post_title, '${ PREFIX }' ) ) { wp_delete_post( $post->ID, true ); }
		}
	` );
}

test.describe.serial( 'issue #932: 宿泊カレンダーの予約不可日', () => {
	test.beforeAll( async () => {
		try {
			originalSettingsJson = wpCliArgs( [
				'option',
				'get',
				OPTION_NAME,
				'--format=json',
			] );
		} catch {
			originalSettingsJson = null;
		}
		roomId = insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_resource', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Room' ) );
			echo $id;
		`,
			'room'
		);
		for ( const offset of [ 1, 2, 3, 4 ] ) {
			const { year, month } = dateAfter( offset );
			createShiftForMonth( roomId, year, month );
		}
		menuId = insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_service_menu', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Plan' ) );
			update_post_meta( $id, '_vkbm_staff_ids', array( ${ Number( roomId ) } ) );
			update_post_meta( $id, '_vkbm_base_price', 10000 );
			update_post_meta( $id, '_vkbm_booking_unit', 'night' );
			echo $id;
		`,
			'menu'
		);
		const booked = dateAfter( 2 );
		insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Booking' ) );
			update_post_meta( $id, '_vkbm_booking_service_start', '${
				booked.iso
			} 15:00:00' );
			update_post_meta( $id, '_vkbm_booking_service_end', '${
				dateAfter( 3 ).iso
			} 10:00:00' );
			update_post_meta( $id, '_vkbm_booking_total_end', '${
				dateAfter( 3 ).iso
			} 10:00:00' );
			update_post_meta( $id, '_vkbm_booking_resource_id', ${ Number( roomId ) } );
			update_post_meta( $id, '_vkbm_booking_service_id', ${ Number( menuId ) } );
			update_post_meta( $id, '_vkbm_booking_status', 'confirmed' );
			echo $id;
		`,
			'booking'
		);
		const laterBooked = dateAfter( 3 );
		insertAndGetId(
			`
			$id = wp_insert_post( array( 'post_type' => 'vkbm_booking', 'post_status' => 'publish', 'post_title' => '${ PREFIX } Later Booking' ) );
			update_post_meta( $id, '_vkbm_booking_service_start', '${
				laterBooked.iso
			} 15:00:00' );
			update_post_meta( $id, '_vkbm_booking_service_end', '${
				dateAfter( 4 ).iso
			} 10:00:00' );
			update_post_meta( $id, '_vkbm_booking_total_end', '${
				dateAfter( 4 ).iso
			} 10:00:00' );
			update_post_meta( $id, '_vkbm_booking_resource_id', ${ Number( roomId ) } );
			update_post_meta( $id, '_vkbm_booking_service_id', ${ Number( menuId ) } );
			update_post_meta( $id, '_vkbm_booking_status', 'confirmed' );
			echo $id;
			`,
			'later booking'
		);
		await configureProviderSettings( { industry_preset: 'lodging' } );
		wpCliArgs( [ 'transient', 'delete', '--all' ] );
	} );

	test.afterAll( () => {
		try {
			cleanup();
		} finally {
			restoreSettings();
		}
	} );

	test( '満室日は × と読み上げ状態が出て選べず、最初の満室日はチェックアウトに選べる', async ( {
		page,
	} ) => {
		await page.goto( `/booking/?menu_id=${ menuId }` );
		await expect( page.locator( '.vkbm-stay-calendar' ) ).toBeVisible();

		const fullDay = await dayButton( page, 2 );
		await expect(
			fullDay.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveText( '×' );
		await expect( fullDay ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( fullDay ).toHaveAttribute(
			'aria-label',
			/選べません（満室）/
		);
		// aria-disabled の要素は Playwright が通常クリックを止めるため、実際のポインターイベントを強制して防御処理を確かめる。
		await fullDay.click( { force: true } );
		await expect( await dayButton( page, 2 ) ).toHaveAttribute(
			'aria-pressed',
			'false'
		);
		await expect( page.locator( '.vkbm-lodging__guide' ) ).toContainText(
			'予約できません'
		);

		const checkIn = await dayButton( page, 1 );
		const checkoutMetaResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/lodging-calendar-meta' ) &&
				response.url().includes( 'check_in_date=' ) &&
				response.ok()
		);
		await checkIn.click();
		await checkoutMetaResponse;
		await expect( await dayButton( page, 1 ) ).toHaveAttribute(
			'aria-pressed',
			'true'
		);
		const checkoutBoundary = await dayButton( page, 2 );
		await expect( checkoutBoundary ).toHaveAttribute(
			'aria-disabled',
			'false'
		);
		await expect( checkoutBoundary ).toHaveAttribute(
			'aria-label',
			/チェックアウト日に選べます/
		);
		await expect(
			checkoutBoundary.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveText( '×' );

		const blocked = await dayButton( page, 3 );
		await expect( blocked ).toHaveAttribute( 'aria-disabled', 'true' );
		await expect( blocked ).toHaveAttribute(
			'aria-label',
			/途中に予約できない日があります/
		);
		await expect(
			blocked.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveText( '' );

		// 日程を選び直して満室日より後をチェックインにしても、前の満室日の × と選択不可状態は残る。
		await page.getByRole( 'button', { name: '日程を選び直す' } ).click();
		const laterCheckIn = await dayButton( page, 4 );
		const laterCheckoutMetaResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( '/lodging-calendar-meta' ) &&
				response.url().includes( 'check_in_date=' ) &&
				response.ok()
		);
		await laterCheckIn.click();
		await laterCheckoutMetaResponse;
		const fullDayAfterReselect = await dayButton( page, 2 );
		await expect(
			fullDayAfterReselect.locator(
				'.vkbm-calendar__day-unavailable-mark'
			)
		).toHaveText( '×' );
		await expect( fullDayAfterReselect ).toHaveAttribute(
			'aria-disabled',
			'true'
		);

		// 最大30泊を超えるチェックアウト日は、月をまたいでも選べない。
		const beyondMaximum = await dayButton( page, 35 );
		await expect( beyondMaximum ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		await expect( beyondMaximum ).toHaveAttribute(
			'aria-label',
			/泊数の上限を超えています/
		);
		await expect(
			beyondMaximum.locator( '.vkbm-calendar__day-unavailable-mark' )
		).toHaveText( '' );
	} );

	test( '受付上限外の × の日もチェックイン後はチェックアウトに選べる', async ( {
		page,
	} ) => {
		try {
			// 翌日までをチェックイン可能にし、翌々日を受付上限外の × にする。
			wpEvalPhp( `
				update_post_meta( ${ Number( menuId ) }, '_vkbm_max_advance_booking_days', 1 );
			` );
			wpCliArgs( [ 'transient', 'delete', '--all' ] );

			await page.goto( `/booking/?menu_id=${ menuId }` );
			await expect( page.locator( '.vkbm-stay-calendar' ) ).toBeVisible();
			const checkout = await dayButton( page, 2 );
			await expect(
				checkout.locator( '.vkbm-calendar__day-unavailable-mark' )
			).toHaveText( '×' );
			await expect( checkout ).toHaveAttribute( 'aria-disabled', 'true' );

			const checkoutMetaResponse = page.waitForResponse(
				( response ) =>
					response.url().includes( '/lodging-calendar-meta' ) &&
					response.url().includes( 'check_in_date=' ) &&
					response.ok()
			);
			await ( await dayButton( page, 1 ) ).click();
			await checkoutMetaResponse;
			const selectableCheckout = await dayButton( page, 2 );
			await expect( selectableCheckout ).toHaveAttribute(
				'aria-disabled',
				'false'
			);
			await expect( selectableCheckout ).toHaveAttribute(
				'aria-label',
				/チェックアウト日に選べます/
			);
			await expect(
				selectableCheckout.locator(
					'.vkbm-calendar__day-unavailable-mark'
				)
			).toHaveText( '×' );
			await selectableCheckout.click();
			await expect( await dayButton( page, 2 ) ).toHaveAttribute(
				'aria-pressed',
				'true'
			);
		} finally {
			wpEvalPhp( `
				delete_post_meta( ${ Number( menuId ) }, '_vkbm_max_advance_booking_days' );
			` );
			wpCliArgs( [ 'transient', 'delete', '--all' ] );
		}
	} );
} );
