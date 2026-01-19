( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var serviceToggle = document.getElementById( 'vkbm-booking-allow-service-change' );
		var serviceSelect = document.getElementById( 'vkbm-booking-service' );

		if ( serviceToggle && serviceSelect ) {
			var updateServiceSelect = function () {
				serviceSelect.disabled = ! serviceToggle.checked;
			};
			serviceToggle.addEventListener( 'change', updateServiceSelect );
			updateServiceSelect();
		}

		var attachmentWrap = document.querySelector( '.vkbm-booking-attachments' );
		if ( attachmentWrap ) {
			var addButton = attachmentWrap.querySelector( '.vkbm-booking-attachments__add' );
			var input = attachmentWrap.querySelector( 'input[type="hidden"]' );
			var list = attachmentWrap.querySelector( '.vkbm-booking-attachments__list' );
			var lightbox = attachmentWrap.querySelector( '.vkbm-booking-attachments__lightbox' );
			var lightboxImage = attachmentWrap.querySelector( '.vkbm-booking-attachments__lightbox-image' );

			if ( addButton && input && list ) {
				if ( lightbox ) {
					lightbox.hidden = true;
				}
				var buildIdList = function () {
					if ( ! input.value ) {
						return [];
					}
					return input.value
						.split( ',' )
						.map( function ( value ) {
							return parseInt( value, 10 );
						} )
						.filter( function ( value ) {
							return Number.isFinite( value ) && value > 0;
						} );
				};

				var syncInput = function ( ids ) {
					input.value = ids.join( ',' );
				};

				var appendItem = function ( attachment ) {
					var ids = buildIdList();
					if ( ids.indexOf( attachment.id ) !== -1 ) {
						return;
					}

					ids.push( attachment.id );
					syncInput( ids );

					var imageUrl = attachment.sizes && attachment.sizes.thumbnail
						? attachment.sizes.thumbnail.url
						: attachment.url;
					var listItem = document.createElement( 'li' );
					listItem.className = 'vkbm-booking-attachments__item';
					listItem.dataset.id = String( attachment.id );

					var image = document.createElement( 'img' );
					image.className = 'vkbm-booking-attachments__image';
					image.src = imageUrl;
					image.alt = '';
					image.dataset.fullUrl = attachment.url || '';

					var removeButton = document.createElement( 'button' );
					removeButton.type = 'button';
					removeButton.className = 'vkbm-button vkbm-button__xs vkbm-button__danger vkbm-booking-attachments__remove';
					removeButton.textContent = list.dataset.removeLabel || 'delete';

					listItem.appendChild( image );
					listItem.appendChild( removeButton );
					list.appendChild( listItem );
				};

				var frame = null;
				if ( window.wp && window.wp.media ) {
					addButton.addEventListener( 'click', function ( event ) {
						event.preventDefault();

						if ( frame ) {
							frame.open();
							return;
						}

						// Open media frame with multi-select for booking attachments.
						// 複数画像を選択できるメディアフレームを開く。
						frame = window.wp.media( {
							title: addButton.textContent || 'Add',
							button: { text: addButton.textContent || 'Add' },
							library: { type: 'image' },
							uploader: { params: { vkbm_booking_upload: '1' } },
							multiple: true,
						} );

						frame.on( 'select', function () {
							frame.state().get( 'selection' ).each( function ( attachment ) {
								appendItem( attachment.toJSON() );
							} );
						} );

						frame.open();
					} );
				}

				list.addEventListener( 'click', function ( event ) {
					var target = event.target;
					if ( ! target || ! target.classList.contains( 'vkbm-booking-attachments__remove' ) ) {
						return;
					}

					event.preventDefault();

					var listItem = target.closest( '.vkbm-booking-attachments__item' );
					if ( ! listItem ) {
						return;
					}

					var removeId = parseInt( listItem.dataset.id || '', 10 );
					var ids = buildIdList().filter( function ( id ) {
						return id !== removeId;
					} );
					syncInput( ids );
					listItem.remove();
				} );

				if ( lightbox && lightboxImage ) {
					var prevButton = lightbox.querySelector( '[data-lightbox-prev]' );
					var nextButton = lightbox.querySelector( '[data-lightbox-next]' );
					var currentIndex = -1;
					var gallery = [];

					var buildGallery = function () {
						gallery = Array.prototype.slice
							.call( list.querySelectorAll( '.vkbm-booking-attachments__item' ) )
							.map( function ( item ) {
								var image = item.querySelector( '.vkbm-booking-attachments__image' );
								var fullUrl = image ? image.dataset.fullUrl || image.getAttribute( 'src' ) || '' : '';
								return {
									id: item.dataset.id || '',
									fullUrl: fullUrl,
								};
							} )
							.filter( function ( entry ) {
								return entry.fullUrl;
							} );
					};

					var updateNavState = function () {
						var hasMultiple = gallery.length > 1;
						if ( prevButton ) {
							prevButton.disabled = ! hasMultiple;
						}
						if ( nextButton ) {
							nextButton.disabled = ! hasMultiple;
						}
					};

					var openAtIndex = function ( index ) {
						if ( ! gallery.length ) {
							return;
						}
						if ( index < 0 ) {
							index = gallery.length - 1;
						}
						if ( index >= gallery.length ) {
							index = 0;
						}
						currentIndex = index;
						lightboxImage.src = gallery[ currentIndex ].fullUrl;
						lightbox.hidden = false;
						updateNavState();
					};

					var closeLightbox = function () {
						lightbox.hidden = true;
						lightboxImage.removeAttribute( 'src' );
					};

					list.addEventListener( 'click', function ( event ) {
						var target = event.target;
						if ( ! target || ! target.classList.contains( 'vkbm-booking-attachments__image' ) ) {
							return;
						}

						event.preventDefault();

						buildGallery();
						var listItem = target.closest( '.vkbm-booking-attachments__item' );
						var clickedId = listItem ? listItem.dataset.id || '' : '';
						var startIndex = gallery.findIndex( function ( entry ) {
							return entry.id === clickedId;
						} );
						openAtIndex( startIndex >= 0 ? startIndex : 0 );
					} );

					lightbox.addEventListener( 'click', function ( event ) {
						var target = event.target;
						var closeTarget = target && target.closest
							? target.closest( '[data-lightbox-close]' )
							: null;
						if ( closeTarget ) {
							event.preventDefault();
							closeLightbox();
							return;
						}

						var prevTarget = target && target.closest
							? target.closest( '[data-lightbox-prev]' )
							: null;
						if ( prevTarget ) {
							event.preventDefault();
							openAtIndex( currentIndex - 1 );
							return;
						}

						var nextTarget = target && target.closest
							? target.closest( '[data-lightbox-next]' )
							: null;
						if ( nextTarget ) {
							event.preventDefault();
							openAtIndex( currentIndex + 1 );
						}
					} );

					document.addEventListener( 'keydown', function ( event ) {
						if ( lightbox.hidden ) {
							return;
						}
						if ( event.key === 'Escape' ) {
							closeLightbox();
						}
						if ( event.key === 'ArrowLeft' ) {
							openAtIndex( currentIndex - 1 );
						}
						if ( event.key === 'ArrowRight' ) {
							openAtIndex( currentIndex + 1 );
						}
					} );
				}
			}
		}
	} );
} )();
