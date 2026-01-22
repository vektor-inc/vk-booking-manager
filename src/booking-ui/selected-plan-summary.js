import { __, sprintf } from '@wordpress/i18n';

const SelectField = ({ label, options, value, onChange, disabled, placeholder }) => (
	<label className="vkbm-plan-summary__field">
		<span className="vkbm-plan-summary__label">{label}</span>
		<select
			value={value || ''}
			onChange={(event) => onChange(event.target.value)}
			disabled={disabled}
		>
			<option value="">{placeholder}</option>
			{options.map((option) => (
				<option key={option.id} value={option.id}>
					{option.name}
				</option>
			))}
		</select>
	</label>
);

const ReadOnlyField = ({ label, value, placeholder }) => (
	<div className="vkbm-plan-summary__field">
		<span className="vkbm-plan-summary__label">{label}</span>
		<span className="vkbm-plan-summary__value">
			{value || placeholder}
		</span>
	</div>
);

export const SelectedPlanSummary = ({
	menuId,
	staffId,
	menus,
	staffOptions,
	onMenuChange,
	onStaffChange,
	allowMenuSelection,
	allowStaffSelection,
	showStaffField = true,
	pricingRows = [],
	menuPreviewLoading = false,
	menuPreviewError = '',
	menuPreviewHtml = '',
	resourceLabelSingular = __('Staff', 'vk-booking-manager'),
	lockStaffSelection = false,
}) => {
	const menuSelectionMessage = (() => {
		const message = __('Please select a menu.', 'vk-booking-manager');
		if (message.includes('%s')) {
			return sprintf(message, __('Menu', 'vk-booking-manager'));
		}
		return message;
	})();
	const menuOptions = [...menus]
		.sort((a, b) => {
			const groupA = a?.vkbm_menu_group || null;
			const groupB = b?.vkbm_menu_group || null;
			const orderA = Number.isFinite(groupA?.order)
				? groupA.order
				: Number.MAX_SAFE_INTEGER;
			const orderB = Number.isFinite(groupB?.order)
				? groupB.order
				: Number.MAX_SAFE_INTEGER;

			if (orderA !== orderB) {
				return orderA - orderB;
			}

			const hasGroupA = Boolean(groupA);
			const hasGroupB = Boolean(groupB);
			if (hasGroupA !== hasGroupB) {
				return hasGroupA ? -1 : 1;
			}

			const nameA = String(groupA?.name ?? '');
			const nameB = String(groupB?.name ?? '');
			const groupNameCompare = nameA.localeCompare(nameB);
			if (groupNameCompare !== 0) {
				return groupNameCompare;
			}

			const menuOrderA = Number.isFinite(a?.menu_order) ? a.menu_order : 0;
			const menuOrderB = Number.isFinite(b?.menu_order) ? b.menu_order : 0;
			if (menuOrderA !== menuOrderB) {
				return menuOrderA - menuOrderB;
			}

			const titleA = String(a?.title?.rendered ?? a?.title ?? '');
			const titleB = String(b?.title?.rendered ?? b?.title ?? '');
			return titleA.localeCompare(titleB);
		})
		.map((menu) => ({
			id: menu.id,
			name: menu.title?.rendered ?? menu.title,
		}));

	const staffItems = staffOptions.map((staff) => ({
		id: staff.id,
		name: staff.title?.rendered ?? staff.name ?? staff.title,
	}));

	return (
		<div className="vkbm-plan-summary">
			<div className="vkbm-plan-summary__selectors">
				{allowMenuSelection ? (
					<SelectField
						label={__('Menu', 'vk-booking-manager')}
						options={menuOptions}
						value={menuId}
						onChange={(value) => onMenuChange(Number(value) || 0)}
						placeholder={__('Select menu', 'vk-booking-manager')}
						disabled={!menuOptions.length}
					/>
				) : (
					<ReadOnlyField
						label={__('Menu', 'vk-booking-manager')}
						value={menuOptions.find((menu) => menu.id === menuId)?.name}
						placeholder={__('Not set', 'vk-booking-manager')}
					/>
				)}
				{showStaffField &&
					(allowStaffSelection ? (
						<SelectField
							label={resourceLabelSingular}
							options={staffItems}
							value={staffId}
							onChange={(value) => onStaffChange(Number(value) || 0)}
							placeholder={__('No preference', 'vk-booking-manager')}
							disabled={!staffItems.length || lockStaffSelection}
						/>
					) : (
						<ReadOnlyField
							label={resourceLabelSingular}
							value={staffItems.find((staff) => staff.id === staffId)?.name}
							placeholder={__('No preference', 'vk-booking-manager')}
						/>
					))}
			</div>

			{menuId ? (
				<div className="vkbm-plan-summary__menu-preview vkbm-reservation__menu-preview">
					{menuPreviewLoading && (
						<p className="vkbm-reservation__menu-preview-notice">
							{__('Loading menu information...', 'vk-booking-manager')}
						</p>
					)}
					{!menuPreviewLoading && menuPreviewError && (
						<div className="vkbm-reservation__error">{menuPreviewError}</div>
					)}
					{!menuPreviewLoading && !menuPreviewError && menuPreviewHtml && (
						<div
							className="vkbm-reservation__menu-preview-card"
							dangerouslySetInnerHTML={{ __html: menuPreviewHtml }}
						/>
					)}
				</div>
			) : null}

			{menuId ? (
				pricingRows?.length > 0 ? (
					<div className="vkbm-plan-summary__pricing">
						{pricingRows.map((row) => (
							<div key={row.key} className="vkbm-plan-summary__pricing-row">
								<span className="vkbm-plan-summary__pricing-label">
									{row.label}
								</span>
								<strong
									className={[
										'vkbm-plan-summary__pricing-value',
										row.highlight && 'vkbm-plan-summary__pricing-value--accent',
									]
										.filter(Boolean)
										.join(' ')}
								>
									<span className="vkbm-plan-summary__pricing-amount">
										{row.value ?? '—'}
									</span>
									{row.taxLabel ? (
										<span className="vkbm-plan-summary__pricing-tax">
											{row.taxLabel}
										</span>
									) : null}
								</strong>
							</div>
						))}
					</div>
				) : null
				) : (
					<div className="vkbm-plan-summary__pricing vkbm-plan-summary__pricing--alert">
						<p className="vkbm-alert vkbm-alert__info vkbm-alert--compact" role="status">
							{menuSelectionMessage}
						</p>
					</div>
				)}
			</div>
	);
};
