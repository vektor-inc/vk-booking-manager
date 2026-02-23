export const resolveLoginState = ( {
	userBootstrap = null,
	isEditor = false,
} = {} ) => {
	if (
		typeof document !== 'undefined' &&
		document.body &&
		document.body.classList.contains( 'logged-in' )
	) {
		return true;
	}

	if (
		userBootstrap &&
		typeof userBootstrap === 'object' &&
		( Boolean( userBootstrap.canManageReservations ) ||
			( typeof userBootstrap.logoutUrl === 'string' &&
				userBootstrap.logoutUrl.trim() !== '' ) )
	) {
		return true;
	}

	if ( isEditor && typeof window !== 'undefined' ) {
		return Boolean( window.wpApiSettings?.nonce );
	}

	return false;
};
