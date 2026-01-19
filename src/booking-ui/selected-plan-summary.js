import { __ } from '@wordpress/i18n';

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
	const menuOptions = menus.map((menu) => ({
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
									{row.value ?? '—'}
								</strong>
							</div>
						))}
					</div>
				) : null
				) : (
					<div className="vkbm-plan-summary__pricing vkbm-plan-summary__pricing--alert">
						<p className="vkbm-alert vkbm-alert__info vkbm-alert--compact" role="status">
							{__('Please select a menu.', 'vk-booking-manager')}
						</p>
					</div>
				)}
			</div>
	);
};
