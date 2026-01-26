<?php
/**
 * Resolves customer names from user data.
 *
 * @package VKBookingManager
 */

declare( strict_types=1 );

namespace VKBookingManager\Bookings;

use WP_User;
use function get_user_meta;

/**
 * Resolves the display name used when storing booking customer names.
 */
class Customer_Name_Resolver {
	/**
	 * Resolve the customer name for a user.
	 *
	 * @param WP_User $user User instance.
	 * @return string
	 */
	public function resolve_for_user( WP_User $user ): string {
		$first_name = trim( (string) get_user_meta( $user->ID, 'first_name', true ) );
		$last_name  = trim( (string) get_user_meta( $user->ID, 'last_name', true ) );

		$full_name = trim( sprintf( '%s %s', $last_name, $first_name ) );
		if ( '' !== $full_name ) {
			return $full_name;
		}

		$kana_name = trim( (string) get_user_meta( $user->ID, 'vkbm_kana_name', true ) );
		if ( '' !== $kana_name ) {
			return $kana_name;
		}

		return $user->display_name;
	}
}
