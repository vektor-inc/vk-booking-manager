( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		const serviceToggle = document.getElementById(
			'vkbm-booking-allow-service-change'
		);
		const serviceSelect = document.getElementById( 'vkbm-booking-service' );

		if ( serviceToggle && serviceSelect ) {
			const updateServiceSelect = function () {
				serviceSelect.disabled = ! serviceToggle.checked;
			};
			serviceToggle.addEventListener( 'change', updateServiceSelect );
			updateServiceSelect();
		}

		const attachmentWrap = document.querySelector(
			'.vkbm-booking-attachments'
		);
		if ( attachmentWrap ) {
			const addButton = attachmentWrap.querySelector(
				'.vkbm-booking-attachments__add'
			);
			const input = attachmentWrap.querySelector(
				'input[type="hidden"]'
			);
			const list = attachmentWrap.querySelector(
				'.vkbm-booking-attachments__list'
			);
			const lightbox = attachmentWrap.querySelector(
				'.vkbm-booking-attachments__lightbox'
			);
			const lightboxImage = attachmentWrap.querySelector(
				'.vkbm-booking-attachments__lightbox-image'
			);

			if ( addButton && input && list ) {
				if ( lightbox ) {
					lightbox.hidden = true;
				}
				const buildIdList = function () {
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

				const syncInput = function ( ids ) {
					input.value = ids.join( ',' );
				};

				const appendItem = function ( attachment ) {
					const ids = buildIdList();
					if ( ids.indexOf( attachment.id ) !== -1 ) {
						return;
					}

					ids.push( attachment.id );
					syncInput( ids );

					const imageUrl =
						attachment.sizes && attachment.sizes.thumbnail
							? attachment.sizes.thumbnail.url
							: attachment.url;
					const listItem = document.createElement( 'li' );
					listItem.className = 'vkbm-booking-attachments__item';
					listItem.dataset.id = String( attachment.id );

					const image = document.createElement( 'img' );
					image.className = 'vkbm-booking-attachments__image';
					image.src = imageUrl;
					image.alt = '';
					image.dataset.fullUrl = attachment.url || '';

					const removeButton = document.createElement( 'button' );
					removeButton.type = 'button';
					removeButton.className =
						'vkbm-button vkbm-button__xs vkbm-button__danger vkbm-booking-attachments__remove';
					removeButton.textContent =
						list.dataset.removeLabel || 'delete';

					listItem.appendChild( image );
					listItem.appendChild( removeButton );
					list.appendChild( listItem );
				};

				let frame = null;
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
							frame
								.state()
								.get( 'selection' )
								.each( function ( attachment ) {
									appendItem( attachment.toJSON() );
								} );
						} );

						frame.open();
					} );
				}

				list.addEventListener( 'click', function ( event ) {
					const target = event.target;
					if (
						! target ||
						! target.classList.contains(
							'vkbm-booking-attachments__remove'
						)
					) {
						return;
					}

					event.preventDefault();

					const listItem = target.closest(
						'.vkbm-booking-attachments__item'
					);
					if ( ! listItem ) {
						return;
					}

					const removeId = parseInt( listItem.dataset.id || '', 10 );
					const ids = buildIdList().filter( function ( id ) {
						return id !== removeId;
					} );
					syncInput( ids );
					listItem.remove();
				} );

				if ( lightbox && lightboxImage ) {
					const prevButton = lightbox.querySelector(
						'[data-lightbox-prev]'
					);
					const nextButton = lightbox.querySelector(
						'[data-lightbox-next]'
					);
					let currentIndex = -1;
					let gallery = [];

					const buildGallery = function () {
						gallery = Array.prototype.slice
							.call(
								list.querySelectorAll(
									'.vkbm-booking-attachments__item'
								)
							)
							.map( function ( item ) {
								const image = item.querySelector(
									'.vkbm-booking-attachments__image'
								);
								const fullUrl = image
									? image.dataset.fullUrl ||
									  image.getAttribute( 'src' ) ||
									  ''
									: '';
								return {
									id: item.dataset.id || '',
									fullUrl,
								};
							} )
							.filter( function ( entry ) {
								return entry.fullUrl;
							} );
					};

					const updateNavState = function () {
						const hasMultiple = gallery.length > 1;
						if ( prevButton ) {
							prevButton.disabled = ! hasMultiple;
						}
						if ( nextButton ) {
							nextButton.disabled = ! hasMultiple;
						}
					};

					const openAtIndex = function ( index ) {
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

					const closeLightbox = function () {
						lightbox.hidden = true;
						lightboxImage.removeAttribute( 'src' );
					};

					list.addEventListener( 'click', function ( event ) {
						const target = event.target;
						if (
							! target ||
							! target.classList.contains(
								'vkbm-booking-attachments__image'
							)
						) {
							return;
						}

						event.preventDefault();

						buildGallery();
						const listItem = target.closest(
							'.vkbm-booking-attachments__item'
						);
						const clickedId = listItem
							? listItem.dataset.id || ''
							: '';
						const startIndex = gallery.findIndex(
							function ( entry ) {
								return entry.id === clickedId;
							}
						);
						openAtIndex( startIndex >= 0 ? startIndex : 0 );
					} );

					lightbox.addEventListener( 'click', function ( event ) {
						const target = event.target;
						const closeTarget =
							target && target.closest
								? target.closest( '[data-lightbox-close]' )
								: null;
						if ( closeTarget ) {
							event.preventDefault();
							closeLightbox();
							return;
						}

						const prevTarget =
							target && target.closest
								? target.closest( '[data-lightbox-prev]' )
								: null;
						if ( prevTarget ) {
							event.preventDefault();
							openAtIndex( currentIndex - 1 );
							return;
						}

						const nextTarget =
							target && target.closest
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
