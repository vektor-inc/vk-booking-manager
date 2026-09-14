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

		// 担当スタッフ単一選択：同じ時間帯に別予約があるスタッフを候補から除外する。
		// 1予約は分割せず単一スタッフに割り当てるため、リピーターではなく単一の select を扱う。
		const resourceSelect = document.querySelector(
			'.vkbm-booking-resource'
		);
		if ( resourceSelect ) {
			// 競合スタッフIDはサーバー側が data 属性で渡す（保存時刻時点の判定）。
			let conflictIds = new Set(
				( resourceSelect.dataset.conflictStaffIds || '' )
					.split( ',' )
					.map( function ( value ) {
						return value.trim();
					} )
					.filter( Boolean )
			);

			// #446: 担当スタッフのプルダウン直下に出す「除外されている」注記。
			// 除外一覧に含まれ、かつ選択中でないリソースで、選択肢として実際に存在するものが
			// 1件以上あるときだけ表示する（PHP初期描画と同じ判定基準）。
			const resourceDescription = document.getElementById(
				'vkbm-booking-resource-description'
			);

			// #446 安藤さんレビュー対応: 選択中担当者の競合警告（vkbm-booking-resource-conflict-warning）・
			// 除外の注記（vkbm-booking-resource-description）・希望タグ警告（vkbm-booking-resource-tag-warning）は、
			// 表示中のときだけプルダウンの aria-describedby へ ID を含める（非表示中は含めない）。
			// この3つの ID は必ずこの順で並べる。push/splice で都度末尾に足し引きすると、
			// 呼び出し順（Ajax再判定・担当変更・希望タグ判定のどれが先に走るか）次第で
			// 並び順が入れ替わってしまうため、表示状態だけを state に持ち、
			// 呼ばれるたびに固定順から組み立て直す。この3つ以外の ID が将来
			// aria-describedby に含まれる場合を壊さないよう、管理対象外のトークンは
			// （相対順序を保ったまま）末尾にそのまま残す。
			const RESOURCE_DESCRIBED_BY_ORDER = [
				'vkbm-booking-resource-conflict-warning',
				'vkbm-booking-resource-description',
				'vkbm-booking-resource-tag-warning',
			];
			const resourceDescribedByState = {};
			const applyResourceDescribedBy = function () {
				const existingTokens = (
					resourceSelect.getAttribute( 'aria-describedby' ) || ''
				)
					.split( ' ' )
					.filter( Boolean );
				const otherTokens = existingTokens.filter( function ( token ) {
					return RESOURCE_DESCRIBED_BY_ORDER.indexOf( token ) === -1;
				} );
				const managedTokens = RESOURCE_DESCRIBED_BY_ORDER.filter(
					function ( id ) {
						return resourceDescribedByState[ id ];
					}
				);
				const tokens = managedTokens.concat( otherTokens );
				if ( tokens.length > 0 ) {
					resourceSelect.setAttribute(
						'aria-describedby',
						tokens.join( ' ' )
					);
				} else {
					resourceSelect.removeAttribute( 'aria-describedby' );
				}
			};
			const setResourceDescribedBy = function ( id, shouldInclude ) {
				resourceDescribedByState[ id ] = shouldInclude;
				applyResourceDescribedBy();
			};

			// #446: 隠れている選択肢数（hiddenCount）にあわせて注記の表示・非表示と
			// aria-describedby の参照先を切り替える。
			const updateResourceDescriptionNotice = function ( hiddenCount ) {
				if ( ! resourceDescription ) {
					return;
				}
				const shouldShow = hiddenCount > 0;
				resourceDescription.hidden = ! shouldShow;
				setResourceDescribedBy(
					'vkbm-booking-resource-description',
					shouldShow
				);
			};

			// #446 安藤さんレビュー対応: キャンセル・無断キャンセルの予約は担当スタッフの競合チェック
			// 対象外（PHP側 save_post() の保存時チェック・Booking_Admin::is_staff_check_target_status()
			// と同じ基準）。この基準を満たさないステータスでは、競合していても警告を出さない。
			// 除外の注記（vkbm-booking-resource-description）・選択肢の隠し方（updateStaffOptions）は
			// ステータスに関わらず今回の対応より前と同じ挙動のまま変えない（警告だけが対象）。
			const statusSelect = document.getElementById(
				'vkbm-booking-status'
			);
			const STAFF_CHECK_EXCLUDED_STATUSES = [ 'cancelled', 'no_show' ];
			const isStaffCheckTargetStatus = function ( status ) {
				return STAFF_CHECK_EXCLUDED_STATUSES.indexOf( status ) === -1;
			};

			// #446: 選択中の担当者が、この日時では担当できない（除外一覧に含まれる）ときに出す警告。
			// PHP初期描画と同じ基準（未選択(0)は対象外、除外一覧に含まれていれば警告、
			// ステータスが担当チェック対象であること）を使う。
			const conflictWarning = document.getElementById(
				'vkbm-booking-resource-conflict-warning'
			);
			const updateSelectedResourceConflictWarning = function (
				currentValue
			) {
				if ( ! conflictWarning ) {
					return;
				}
				const currentStatus = statusSelect ? statusSelect.value : '';
				const isConflicting =
					isStaffCheckTargetStatus( currentStatus ) &&
					currentValue !== '0' &&
					conflictIds.has( currentValue );
				conflictWarning.hidden = ! isConflicting;
				setResourceDescribedBy(
					'vkbm-booking-resource-conflict-warning',
					isConflicting
				);
			};

			// 同じ時間帯に別予約があるスタッフを候補から非表示・選択不可にする（選択中の値は常に表示）。
			// #446: あわせて、実際に隠れた選択肢の数を数えて除外の注記の表示・非表示を切り替え、
			// 選択中の担当者自身が除外一覧に含まれるかどうかで競合警告の表示・非表示も切り替える。
			const updateStaffOptions = function () {
				const current = resourceSelect.value;
				let hiddenCount = 0;
				Array.prototype.forEach.call(
					resourceSelect.options,
					function ( option ) {
						if ( option.value === '0' ) {
							return;
						}
						const exclude =
							option.value !== current &&
							conflictIds.has( option.value );
						option.hidden = exclude;
						option.disabled = exclude;
						if ( exclude ) {
							hiddenCount++;
						}
					}
				);
				updateResourceDescriptionNotice( hiddenCount );
				updateSelectedResourceConflictWarning( current );
			};

			// #431: 担当変更時、変更先のリソースが予約の希望タグを持っていない場合に警告を表示する
			// （保存自体は管理者判断でできるよう、フォームの送信はブロックしない）。
			const requiredTagIds = new Set(
				( resourceSelect.dataset.requiredTagIds || '' )
					.split( ',' )
					.map( function ( value ) {
						return value.trim();
					} )
					.filter( Boolean )
			);
			const tagWarning = document.getElementById(
				'vkbm-booking-resource-tag-warning'
			);
			const updateResourceTagWarning = function () {
				if ( ! tagWarning || requiredTagIds.size === 0 ) {
					return;
				}

				const selectedOption =
					resourceSelect.options[ resourceSelect.selectedIndex ];
				const optionTagIds = new Set(
					(
						( selectedOption && selectedOption.dataset.tagIds ) ||
						''
					)
						.split( ',' )
						.map( function ( value ) {
							return value.trim();
						} )
						.filter( Boolean )
				);

				// 希望タグを「すべて」持っているかを判定する（AND条件）。
				let hasAllTags = true;
				requiredTagIds.forEach( function ( tagId ) {
					if ( ! optionTagIds.has( tagId ) ) {
						hasAllTags = false;
					}
				} );

				const shouldWarn = resourceSelect.value !== '0' && ! hasAllTags;
				tagWarning.hidden = ! shouldWarn;
				// #446 安藤さんレビュー対応: 警告要素は描画時点で常に hidden（初期状態は
				// 必ず非表示）のため、aria-describedby への参照も PHP 側では持たせず、
				// hidden の切替とあわせてここで足し引きする。
				setResourceDescribedBy(
					'vkbm-booking-resource-tag-warning',
					shouldWarn
				);
			};
			resourceSelect.addEventListener(
				'change',
				updateResourceTagWarning
			);
			// #446: 担当変更で「常に表示される選択中スタッフ」が入れ替わり、除外一覧に対して
			// 実際に隠れる選択肢の数（0件⇄1件以上）が変わり得るため、注記も担当変更のたびに数え直す。
			resourceSelect.addEventListener( 'change', updateStaffOptions );

			// #446 安藤さんレビュー対応: ステータスを確定⇔キャンセル/無断キャンセルに切り替えた
			// ときも、担当スタッフの競合警告だけを再判定する（除外の注記・選択肢の隠し方は
			// ステータスで変えないため updateStaffOptions() は呼ばない）。
			if ( statusSelect ) {
				statusSelect.addEventListener( 'change', function () {
					updateSelectedResourceConflictWarning(
						resourceSelect.value
					);
				} );
			}

			// 日時を変更したら Ajax で競合スタッフを再判定し、候補を更新する。
			const ajaxConfig = window.vkbmBookingAdmin || {};
			const dateField = document.getElementById( 'vkbm-booking-date' );
			const startField = document.querySelector(
				'input[name="vkbm_booking[start_time]"]'
			);
			const endField = document.querySelector(
				'input[name="vkbm_booking[end_time]"]'
			);
			// #394: 指名OFF・定員2以上のメニューでは「残数不足」も候補除外の条件になるため、
			// 人数入力欄が表示されていればその時点の値を Ajax に含める。
			// #394 レビュー対応: 人数欄の変更自体も再判定のトリガーに含める（後述）。
			const guestsField = document.querySelector(
				'input[name="vkbm_booking[guests]"]'
			);

			// 競合スタッフ取得リクエストの世代管理。デバウンス後でも応答が前後する可能性があるため、
			// 最新リクエストの応答のみを反映して古い応答による上書きを防ぐ。
			let latestConflictRequestId = 0;
			const refreshConflicts = function () {
				if ( ! ajaxConfig.ajaxUrl || ! window.fetch ) {
					return;
				}
				const requestId = ++latestConflictRequestId;
				const body = new URLSearchParams();
				body.set(
					'action',
					ajaxConfig.action || 'vkbm_conflicting_staff'
				);
				body.set( 'nonce', ajaxConfig.nonce || '' );
				body.set( 'post_id', String( ajaxConfig.postId || 0 ) );
				body.set( 'date', dateField ? dateField.value : '' );
				body.set( 'start_time', startField ? startField.value : '' );
				body.set( 'end_time', endField ? endField.value : '' );
				body.set(
					'guests',
					guestsField && guestsField.value ? guestsField.value : '1'
				);

				window
					.fetch( ajaxConfig.ajaxUrl, {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded',
						},
						body: body.toString(),
					} )
					.then( function ( response ) {
						// HTTPエラー（4xx/5xx）を明示的に検出し、JSON解析前に失敗させる。
						if ( ! response.ok ) {
							throw new Error( 'HTTP error ' + response.status );
						}
						return response.json();
					} )
					.then( function ( json ) {
						// 最新リクエストの応答でなければ破棄する（古い応答による上書き防止）。
						if ( requestId !== latestConflictRequestId ) {
							return;
						}
						if (
							json &&
							json.success &&
							json.data &&
							Array.isArray( json.data.ids )
						) {
							conflictIds = new Set(
								json.data.ids.map( function ( value ) {
									return String( value );
								} )
							);
							updateStaffOptions();
						}
					} )
					.catch( function ( error ) {
						// 競合スタッフの再判定は補助機能のため、失敗しても処理は止めない。
						// 候補は前回値のまま据え置き、警告ログだけ残す。
						if ( window.console && window.console.warn ) {
							window.console.warn(
								'vkbm: failed to refresh conflicting staff list',
								error
							);
						}
					} );
			};

			// 日時フィールドを連続で変更した際に Ajax が多重発火しないよう、
			// refreshConflicts を 400ms デバウンスして最後の変更のみ反映する。
			let conflictDebounceTimer = null;
			const debouncedRefreshConflicts = function () {
				if ( conflictDebounceTimer ) {
					window.clearTimeout( conflictDebounceTimer );
				}
				conflictDebounceTimer = window.setTimeout(
					refreshConflicts,
					400
				);
			};

			// #394 レビュー対応: 指名OFF・定員2以上のメニューでは人数が候補判定に効くため、
			// 人数欄の変更も再判定のトリガーに含める（指名ONを前提にした当初の判断を撤回）。
			[ dateField, startField, endField, guestsField ].forEach(
				function ( field ) {
					if ( field ) {
						field.addEventListener(
							'change',
							debouncedRefreshConflicts
						);
					}
				}
			);

			updateStaffOptions();
			// #431: 初期表示時点でも、既に希望タグと不一致な担当が保存されている場合に備えて判定する。
			updateResourceTagWarning();
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
