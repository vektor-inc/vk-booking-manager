/* global jQuery, vkbmServiceMenuEditor */
( function ( $ ) {
	'use strict';

	// i18n strings localized via wp_localize_script. / wp_localize_script で渡された翻訳文字列.
	var i18n = ( window.vkbmServiceMenuEditor && window.vkbmServiceMenuEditor.i18n ) ? window.vkbmServiceMenuEditor.i18n : {};
	var labelDelete = i18n.delete || 'Delete';

	var hourOptions = ( function () {
		var options = '';
		for ( var h = 0; h <= 23; h++ ) {
			var val = h < 10 ? '0' + h : '' + h;
			options += '<option value="' + val + '">' + val + '</option>';
		}
		return options;
	} )();

	var minuteOptions = ( function () {
		var options = '';
		[ '00', '10', '20', '30', '40', '50' ].forEach( function ( m ) {
			options += '<option value="' + m + '">' + m + '</option>';
		} );
		return options;
	} )();

	function buildRow() {
		return $( '<div class="vkbm-fixed-start-time-row">' +
			'<select name="vkbm_service_menu[fixed_start_times][]" class="vkbm-fixed-start-hour">' + hourOptions + '</select>' +
			'<span>:</span>' +
			'<select name="vkbm_service_menu[fixed_start_minutes][]" class="vkbm-fixed-start-minute">' + minuteOptions + '</select>' +
			'<button type="button" class="vkbm-button vkbm-button__sm vkbm-button-outline vkbm-button-outline__danger vkbm-fixed-start-time-remove">' + $( '<span>' ).text( labelDelete ).html() + '</button>' +
		'</div>' );
	}

	$( document ).on( 'click', '#vkbm-fixed-start-time-add', function () {
		$( '#vkbm-fixed-start-times-list' ).append( buildRow() );
	} );

	$( document ).on( 'click', '.vkbm-fixed-start-time-remove', function () {
		$( this ).closest( '.vkbm-fixed-start-time-row' ).remove();
	} );
} )( jQuery );
