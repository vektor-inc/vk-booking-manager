import { __, sprintf } from '@wordpress/i18n';
import { useId } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';

const SelectField = ( {
	label,
	options,
	value,
	onChange,
	disabled,
	placeholder,
	// #429 植草レビュー指摘（低）: 絞り込みで選択肢が0件になり disabled になったとき、
	// 理由をスクリーンリーダーへ伝えるため、説明テキストの id を aria-describedby で紐づける。
	// 説明テキストが無いとき（通常時）は aria-describedby 自体を付けない。
	describedBy,
} ) => (
	<label className="vkbm-plan-summary__field">
		<span className="vkbm-plan-summary__label">{ label }</span>
		<select
			value={ value || '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			disabled={ disabled }
			aria-describedby={ describedBy || undefined }
		>
			<option value="">{ placeholder }</option>
			{ options.map( ( option ) => (
				<option key={ option.id } value={ option.id }>
					{ option.name }
				</option>
			) ) }
		</select>
	</label>
);

const ReadOnlyField = ( { label, value, placeholder } ) => (
	<div className="vkbm-plan-summary__field">
		<span className="vkbm-plan-summary__label">{ label }</span>
		<span className="vkbm-plan-summary__value">
			{ value || placeholder }
		</span>
	</div>
);

export const SelectedPlanSummary = ( {
	menuId,
	staffId,
	menus,
	staffOptions = [],
	onMenuChange,
	onStaffChange,
	allowStaffSelection,
	showStaffField = true,
	pricingRows = [],
	menuPreviewLoading = false,
	menuPreviewError = '',
	menuPreviewHtml = '',
	resourceLabelSingular = __( 'Staff', 'vk-booking-manager' ),
	lockStaffSelection = false,
	noNominationLabel = '',
	// #429 植草レビュー指摘（低）: 絞り込み検索でスタッフを選んだ結果「メニュー」の選択肢が
	// 0件になり disabled になったときの理由文言。呼び出し側（app.js）が
	// カード一覧の0件メッセージと同じ文言を渡す想定。空文字なら何も表示しない。
	menuUnavailableMessage = '',
	// #429 安藤レビュー指摘（低・再レビュー、#435 で再々レビュー修正）: 一覧側（app.js）の
	// 0件メッセージ要素が実際に描画されているかどうか（app.js の menuListEmptyMessageRendered）。
	// showMenuList（「サービスメニュー一覧」を表示する設定かどうか）とは別物で、一覧を表示する
	// 設定でも、読み込み中・取得失敗などで0件メッセージ自体が出ていなければ false になる。
	// true のときはこのコンポーネント自身のヒント表示を省略し、下記 menuListEmptyMessageId が
	// 指す一覧側の要素だけを aria-describedby で参照させる
	// （同じ文言を role="status" で二重に読み上げさせないため）。
	menuListVisible = false,
	// 一覧側（app.js 側で描画される0件メッセージ要素）の id。menuListVisible が true で
	// この値がある場合のみ、そちらを aria-describedby の参照先として使う。
	menuListEmptyMessageId = '',
	// #431: リソースタグ検索。指名機能（staffEnabled）の ON/OFF に関係なく表示・機能させる
	// 仕様のため、showStaffField（指名機能の可否）とは独立したフラグで表示可否を判定する。
	showResourceTagSearch = false,
	// チェックボックスとして表示するリソースタグ一覧（{id, name, count} の配列）。
	// リソースが1件も紐づいていないタグは呼び出し側（app.js）で既に除外済み（hide_empty）。
	resourceTagOptions = [],
	// 選択中のリソースタグのターム ID 配列（複数選択時は呼び出し側で AND 条件として扱う）。
	selectedTagIds = [],
	// チェックボックスの ON/OFF を切り替えたときのコールバック（tagId, checked）。
	onTagToggle = () => {},
	// #435: 「予約ページの表示要素」の「サービスメニュー一覧」（呼び出し側の
	// providerSettings.showMenuList）を表示する設定かどうか。一覧を表示する設定では、
	// 一覧のカードが並ぶので「メニューを選択してください。」のアラートは不要（二重案内になる）。
	// 「サービスメニュー一覧」を表示しない設定（絞り込み検索のみ）のときは従来どおり表示する。
	// 一覧の読み込み中・取得失敗のときも判定は設定値だけに揃え、表示しない。
	// menuListVisible（一覧側の0件メッセージが実際に描画されているか）とは意味が異なるため、
	// 流用せずこのフラグを別途受け取る。
	showMenuList = false,
} ) => {
	// #429 安藤レビュー指摘（低・再レビュー）: 同じページに予約ブロックが複数あっても
	// id が衝突しないよう、固定文字列ではなくコンポーネントの描画ごとに一意な id を作る
	// （一覧を表示しない構成で自前のヒントを出す場合に使う）。
	// 安藤レビュー指摘（低・2回目再レビュー）: 以前は @wordpress/compose の useInstanceId を
	// 使っていたが、公開側の予約ページに wp-compose の追加読み込みが発生していたため、
	// 既に読み込み済みの @wordpress/element（React 18 の useId をそのまま再エクスポートした
	// もの。readme.txt の Requires at least 6.8 で利用可能）へ差し替えた。
	const menuUnavailableMessageIdSuffix = useId();
	const menuUnavailableMessageId = `vkbm-plan-summary-menu-unavailable-${ menuUnavailableMessageIdSuffix }`;
	const menuSelectionMessage = ( () => {
		const message = __( 'Please select a menu.', 'vk-booking-manager' );
		if ( message.includes( '%s' ) ) {
			return sprintf( message, __( 'Menu', 'vk-booking-manager' ) );
		}
		return message;
	} )();
	const menuOptions = [ ...menus ]
		.sort( ( a, b ) => {
			const groupA = a?.vkbm_menu_group || null;
			const groupB = b?.vkbm_menu_group || null;
			const orderA = Number.isFinite( groupA?.order )
				? groupA.order
				: Number.MAX_SAFE_INTEGER;
			const orderB = Number.isFinite( groupB?.order )
				? groupB.order
				: Number.MAX_SAFE_INTEGER;

			if ( orderA !== orderB ) {
				return orderA - orderB;
			}

			const hasGroupA = Boolean( groupA );
			const hasGroupB = Boolean( groupB );
			if ( hasGroupA !== hasGroupB ) {
				return hasGroupA ? -1 : 1;
			}

			const nameA = String( groupA?.name ?? '' );
			const nameB = String( groupB?.name ?? '' );
			const groupNameCompare = nameA.localeCompare( nameB );
			if ( groupNameCompare !== 0 ) {
				return groupNameCompare;
			}

			const menuOrderA = Number.isFinite( a?.menu_order )
				? a.menu_order
				: 0;
			const menuOrderB = Number.isFinite( b?.menu_order )
				? b.menu_order
				: 0;
			if ( menuOrderA !== menuOrderB ) {
				return menuOrderA - menuOrderB;
			}

			const titleA = String( a?.title?.rendered ?? a?.title ?? '' );
			const titleB = String( b?.title?.rendered ?? b?.title ?? '' );
			return titleA.localeCompare( titleB );
		} )
		.map( ( menu ) => ( {
			id: menu.id,
			name: menu.title?.rendered ?? menu.title,
		} ) );

	const staffItems = staffOptions.map( ( staff ) => {
		const staffName = staff.title?.rendered ?? staff.name ?? staff.title;
		const tags = staff.resource_tags;
		return {
			id: staff.id,
			name:
				Array.isArray( tags ) && tags.length > 0
					? `${ staffName } ( ${ tags.join( ', ' ) } )`
					: staffName,
		};
	} );

	// #429 安藤レビュー指摘（低・再レビュー）: 「メニュー」の選択肢が0件で理由文言がある状態か。
	const hasMenuUnavailableMessage =
		! menuOptions.length && Boolean( menuUnavailableMessage );
	// 一覧も表示している構成では、一覧側の0件メッセージと重複するため、
	// このコンポーネント自身のヒント（<p>）は描画せず、一覧側の要素を aria-describedby で参照する。
	// 一覧を表示しない構成、または一覧側の id が渡っていない場合は、従来どおり自前のヒントを出す。
	const useExternalMenuListMessage =
		hasMenuUnavailableMessage &&
		menuListVisible &&
		Boolean( menuListEmptyMessageId );
	const menuSelectDescribedBy = hasMenuUnavailableMessage
		? useExternalMenuListMessage
			? menuListEmptyMessageId
			: menuUnavailableMessageId
		: undefined;

	return (
		<div className="vkbm-plan-summary">
			<div className="vkbm-plan-summary__selectors">
				<SelectField
					label={ __( 'Menu', 'vk-booking-manager' ) }
					options={ menuOptions }
					value={ menuId }
					onChange={ ( value ) =>
						onMenuChange( Number( value ) || 0 )
					}
					placeholder={ __( 'Select menu', 'vk-booking-manager' ) }
					disabled={ ! menuOptions.length }
					describedBy={ menuSelectDescribedBy }
				/>
				{ showStaffField &&
					( allowStaffSelection ? (
						<SelectField
							label={ resourceLabelSingular }
							options={ staffItems }
							value={ staffId }
							onChange={ ( value ) =>
								onStaffChange( Number( value ) || 0 )
							}
							placeholder={
								noNominationLabel ||
								__( 'No preference', 'vk-booking-manager' )
							}
							disabled={
								! staffItems.length || lockStaffSelection
							}
						/>
					) : (
						<ReadOnlyField
							label={ resourceLabelSingular }
							value={
								staffItems.find(
									( staff ) => staff.id === staffId
								)?.name
							}
							placeholder={
								noNominationLabel ||
								__( 'No preference', 'vk-booking-manager' )
							}
						/>
					) ) }
			</div>

			{ /* #431: リソースタグ検索。サービスメニュー・リソースの絞り込みプルダウンの直下に、
			    リソースが1件でも紐づいているタグだけをチェックボックスとして並べる。
			    複数選択時は呼び出し側（app.js）で AND 条件として扱われる。 */ }
			{ showResourceTagSearch && (
				<fieldset className="vkbm-plan-summary__tag-filter">
					{ /* #431: 固定文言「Resource tag」ではなく、
					    受け取り済みの resourceLabelSingular（基本設定の「リソース名称」）を使って
					    動的化する（例:「スタッフタグ」）。管理画面のタクソノミーラベル
					    （Resource_Tag_Taxonomy::register_taxonomy() の "%s Resource Tag"）と
					    同じ原文（同じ msgid）を使うことで訳語・表記を揃える。 */ }
					<legend className="vkbm-plan-summary__label">
						{ sprintf(
							/* translators: %s: resource label (singular), e.g. "Staff". */
							__( '%s Resource Tag', 'vk-booking-manager' ),
							resourceLabelSingular
						) }
					</legend>
					<div className="vkbm-plan-summary__tag-filter-options">
						{ resourceTagOptions.map( ( tag ) => (
							<label
								key={ tag.id }
								className="vkbm-plan-summary__tag-filter-option"
							>
								<input
									type="checkbox"
									checked={ selectedTagIds.includes(
										tag.id
									) }
									onChange={ ( event ) =>
										onTagToggle(
											tag.id,
											event.target.checked
										)
									}
								/>
								{ /* リソースタグ名は文字の途中で改行しない（css側で white-space: nowrap）。*/ }
								{ /* #431: REST の term.name は HTMLエンティティ
								    エンコード済みのため、そのまま表示すると「&」等が実体参照のまま
								    見えてしまう。decodeEntities() でデコードしてから表示する。 */ }
								<span className="vkbm-plan-summary__tag-filter-label">
									{ decodeEntities( tag.name ) }
								</span>
							</label>
						) ) }
					</div>
				</fieldset>
			) }

			{ hasMenuUnavailableMessage && ! useExternalMenuListMessage && (
				<p
					id={ menuUnavailableMessageId }
					className="vkbm-plan-summary__field-hint vkbm-alert vkbm-alert__warning vkbm-alert--compact"
					role="status"
				>
					{ menuUnavailableMessage }
				</p>
			) }

			{ menuId ? (
				<div className="vkbm-plan-summary__menu-preview vkbm-reservation__menu-preview">
					{ menuPreviewLoading && (
						<p className="vkbm-reservation__menu-preview-notice">
							{ __(
								'Loading menu information…',
								'vk-booking-manager'
							) }
						</p>
					) }
					{ ! menuPreviewLoading && menuPreviewError && (
						<div className="vkbm-reservation__error">
							{ menuPreviewError }
						</div>
					) }
					{ ! menuPreviewLoading &&
						! menuPreviewError &&
						menuPreviewHtml && (
							<div
								className="vkbm-reservation__menu-preview-card"
								dangerouslySetInnerHTML={ {
									__html: menuPreviewHtml,
								} }
							/>
						) }
				</div>
			) : null }

			{ menuId ? (
				pricingRows?.length > 0 ? (
					<div className="vkbm-plan-summary__pricing">
						{ pricingRows.map( ( row ) => (
							<div
								key={ row.key }
								className="vkbm-plan-summary__pricing-row"
							>
								<span className="vkbm-plan-summary__pricing-label">
									{ row.label }
								</span>
								<strong
									className={ [
										'vkbm-plan-summary__pricing-value',
										row.highlight &&
											'vkbm-plan-summary__pricing-value--accent',
									]
										.filter( Boolean )
										.join( ' ' ) }
								>
									<span className="vkbm-plan-summary__pricing-amount">
										{ row.value ?? '—' }
									</span>
									{ row.taxLabel ? (
										<span className="vkbm-plan-summary__pricing-tax">
											{ row.taxLabel }
										</span>
									) : null }
								</strong>
							</div>
						) ) }
					</div>
				) : null
			) : (
				// #435: 「サービスメニュー一覧」を表示する設定のときは、下に一覧のカードが
				// 並ぶため「メニューを選択してください。」のアラートは出さない。
				! showMenuList && (
					<div className="vkbm-plan-summary__pricing vkbm-plan-summary__pricing--alert">
						<p
							className="vkbm-alert vkbm-alert__info vkbm-alert--compact"
							role="status"
						>
							{ menuSelectionMessage }
						</p>
					</div>
				)
			) }
		</div>
	);
};
