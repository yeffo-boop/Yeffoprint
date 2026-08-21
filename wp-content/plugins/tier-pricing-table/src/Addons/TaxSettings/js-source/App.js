import {useState, useEffect} from '@wordpress/element';
import {
	SelectControl,
	RadioControl,
	TextControl,
	ToggleControl,
	Button,
	Spinner,
	Notice,
	Card,
	CardHeader,
	CardBody,
	Placeholder,
	Flex,
	FlexItem,
	FlexBlock,
	Panel,
	PanelBody,
	PanelRow,
	Icon
} from '@wordpress/components';
import {trash, plus} from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import {__} from '@wordpress/i18n';

const ROLES = window.tptTaxSettings?.roles || [
	{label: 'Administrator', value: 'administrator'},
	{label: 'Editor', value: 'editor'},
	{label: 'Author', value: 'author'},
	{label: 'Contributor', value: 'contributor'},
	{label: 'Subscriber', value: 'subscriber'},
	{label: 'Customer', value: 'customer'},
	{label: 'Shop manager', value: 'shop_manager'}
];

export default function App() {
	const [settings, setSettings] = useState(null);
	const [loading, setLoading] = useState(true);
	const [activeRoles, setActiveRoles] = useState([]);
	const [newRole, setNewRole] = useState('');
	const [newlyAddedRoles, setNewlyAddedRoles] = useState([]);

	useEffect(() => {
		apiFetch({path: '/tier-pricing-table/v1/tax-settings'}).then((response) => {
			setSettings(response || {});
			setActiveRoles(Object.keys(response || {}));
			setLoading(false);
		}).catch(() => {
			setSettings({});
			setLoading(false);
		});
	}, []);


	const addRole = () => {
		if (!newRole || activeRoles.includes(newRole)) return;

		setActiveRoles([...activeRoles, newRole]);
		setNewlyAddedRoles([...newlyAddedRoles, newRole]);
		setSettings({
			...settings,
			[newRole]: {
				tax_exempt: false,
				tax_class: 'default',
				display_shop: 'default',
				display_cart: 'default',
				prices_include_tax: 'default',
				price_suffix: ''
			}
		});
		setNewRole('');
	};

	const removeRole = (role) => {
		const newRoles = activeRoles.filter(r => r !== role);
		const newSettings = {...settings};
		delete newSettings[role];

		setActiveRoles(newRoles);
		setSettings(newSettings);
	};

	const updateRoleSetting = (role, key, value) => {
		setSettings({
			...settings,
			[role]: {
				...settings[role],
				[key]: value
			}
		});
	};

	if (loading) {
		return <Spinner/>;
	}

	const availableRolesToAdd = ROLES.filter(r => !activeRoles.includes(r.value));

	return (
		<div className="tpt-tax-settings-app">
			<style>
				{`
				.tpt-tax-settings-app {
					margin-top: 20px;
					max-width: 650px;
				}
				.tpt-role-settings-panel {
					border: 1px solid var(--wp-components-color-border, #c3c4c7);
					background: var(--wp-components-color-background, #fff);
					margin-bottom: 20px;
					border-radius: 4px;
					box-shadow: 0 1px 1px rgba(0,0,0,.04);
				}
				.tpt-role-settings-panel > .components-button {
					background: var(--wp-admin-theme-color-darker-20, #f6f7f7);
					border-bottom: 1px solid var(--wp-components-color-border, #c3c4c7);
					font-weight: 600;
					color: var(--wp-components-color-foreground, #1d2327);
				}
				.tpt-role-settings-panel > .components-button:hover {
					background: var(--wp-admin-theme-color-darker-10, #f0f0f1);
				}
				.tpt-premium-banner {
					background: #f0f6fc;
					padding: 16px 16px;
					margin: 20px 0;
					display: flex;
					justify-content: space-between;
					border-radius: 5px;
					align-items: center;
					border: 1px solid #ccc;
				}
				.tpt-add-role-card {
					position: sticky;
					top: 40px;
				}
				`}
			</style>

			<Flex align="center" justify="space-between" style={{marginBottom: '20px'}}>
				<FlexItem>
					<h2 style={{margin: 0}}>{__('Role-Based Tax Options', 'tier-pricing-table')}</h2>
					<p style={{margin: '5px 0 0 0', color: '#646970'}}>
						{__('Override default tax options for specific user roles.', 'tier-pricing-table')}
					</p>
				</FlexItem>
			</Flex>

			<input type="hidden" name="tpt_role_tax_settings" value={JSON.stringify(settings || {})}/>


			{activeRoles.length === 0 ? (
				<div style={{
					padding: '40px',
					textAlign: 'center',
					background: '#fff',
					border: '1px solid #c3c4c7',
					borderRadius: '8px',
					marginBottom: '24px',
					color: '#50575e'
				}}>
					<div style={{display: 'flex', justifyContent: 'center', marginBottom: '12px', color: '#a7aaad'}}>
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
						     strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
							<path d="M18 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
							<circle cx="10" cy="7" r="4"/>
							<circle cx="18" cy="17" r="5" fill="#fff"/>
							<line x1="16" y1="19" x2="20" y2="15"/>
							<circle cx="16.5" cy="15.5" r="0.5" fill="currentColor"/>
							<circle cx="19.5" cy="18.5" r="0.5" fill="currentColor"/>
						</svg>
					</div>
					<h3 style={{margin: '0 0 8px', fontSize: '16px', color: '#1d2327', fontWeight: 600}}>
						{__('No Custom Tax Configurations', 'tier-pricing-table')}
					</h3>
					<p style={{margin: '0 0 8px', fontSize: '14px'}}>
						{__('Configure custom tax rules for specific user roles.', 'tier-pricing-table')}
					</p>
					<p style={{
						margin: '0 0 20px',
						fontSize: '13px',
						color: '#646970',
						maxWidth: '500px',
						marginLeft: 'auto',
						marginRight: 'auto'
					}}>
						{__('Select a role below to create your first configuration.', 'tier-pricing-table')}
					</p>

					<div style={{display: 'flex', gap: '10px', alignItems: 'center', justifyContent: 'center'}}>
						<SelectControl
							options={[
								{label: __('Select a role...', 'tier-pricing-table'), value: ''},
								...availableRolesToAdd
							]}
							value={newRole}
							onChange={(val) => setNewRole(val)}
							style={{marginBottom: 0}}
						/>
						<Button variant="primary" onClick={addRole} disabled={!newRole}>
							{__('Create Configuration', 'tier-pricing-table')}
						</Button>
					</div>
				</div>
			) : (
				<div className="tpt-accordion-container" style={{maxWidth: '650px'}}>
					{activeRoles.map(role => {
						const roleName = ROLES.find(r => r.value === role)?.label || role;
						return (
							<PanelBody
								key={role}
								className="tpt-role-settings-panel"
								title={
									<div style={{display: 'flex', alignItems: 'center', gap: '8px'}}>
										<span
											style={{fontWeight: 600}}>{__('Custom Tax Configuration', 'tier-pricing-table')}</span>
										<span style={{
											background: 'var(--wp-admin-theme-color, #2271b1)',
											padding: '4px 10px',
											borderRadius: '4px',
											fontSize: '12px',
											fontWeight: '600',
											color: '#fff'
										}}>
											{roleName}
										</span>
									</div>
								}
								initialOpen={newlyAddedRoles.includes(role)}
							>
								{!window.tptTaxSettings?.isPremium && (
									<div className="tpt-premium-banner">
										<p style={{margin: '0', fontSize: '13px'}}>
											<strong>{__('Premium Feature', 'tier-pricing-table')}</strong>: {__('Upgrading to premium to unlock role-based tax options.', 'tier-pricing-table')}
										</p>
										<a href={window.tptTaxSettings?.upgradeUrl || '#'} target="_blank"
										   rel="noopener noreferrer" className="button button-primary">
											{__('Upgrade to Premium', 'tier-pricing-table')}
										</a>
									</div>
								)}

								<PanelRow>
									<div style={{
										width: '100%',
										paddingTop: '20px',
										maxWidth: '600px',
										display: 'flex',
										flexDirection: 'column',
										gap: '20px'
									}}>
										<ToggleControl
											label={__('Tax Exempt', 'tier-pricing-table')}
											help={__('Disable tax calculation entirely for this role.', 'tier-pricing-table')}
											checked={!!settings[role]?.tax_exempt}
											onChange={(val) => updateRoleSetting(role, 'tax_exempt', val)}
										/>

										{!settings[role]?.tax_exempt && (
											<>
												<RadioControl
													label={__('Tax Class', 'tier-pricing-table')}
													selected={settings[role]?.tax_class || 'default'}
													options={[
														{
															label: __('Default (Inherit)', 'tier-pricing-table'),
															value: 'default'
														},
														{
															label: __('Standard', 'tier-pricing-table'),
															value: 'standard'
														},
														{
															label: __('Reduced Rate', 'tier-pricing-table'),
															value: 'reduced-rate'
														},
														{
															label: __('Zero Rate', 'tier-pricing-table'),
															value: 'zero-rate'
														}
													]}
													onChange={(val) => updateRoleSetting(role, 'tax_class', val)}
												/>

												<RadioControl
													label={__('Prices Entered With Tax', 'tier-pricing-table')}
													selected={settings[role]?.prices_include_tax || 'default'}
													options={[
														{
															label: __('Default (Inherit)', 'tier-pricing-table'),
															value: 'default'
														},
														{
															label: __('Yes, I will enter prices inclusive of tax', 'tier-pricing-table'),
															value: 'yes'
														},
														{
															label: __('No, I will enter prices exclusive of tax', 'tier-pricing-table'),
															value: 'no'
														}
													]}
													onChange={(val) => updateRoleSetting(role, 'prices_include_tax', val)}
												/>

												<RadioControl
													label={__('Display Prices in Shop', 'tier-pricing-table')}
													selected={settings[role]?.display_shop || 'default'}
													options={[
														{
															label: __('Default (Inherit)', 'tier-pricing-table'),
															value: 'default'
														},
														{
															label: __('Including Tax', 'tier-pricing-table'),
															value: 'incl'
														},
														{
															label: __('Excluding Tax', 'tier-pricing-table'),
															value: 'excl'
														}
													]}
													onChange={(val) => updateRoleSetting(role, 'display_shop', val)}
												/>

												<RadioControl
													label={__('Display Prices in Cart/Checkout', 'tier-pricing-table')}
													selected={settings[role]?.display_cart || 'default'}
													options={[
														{
															label: __('Default (Inherit)', 'tier-pricing-table'),
															value: 'default'
														},
														{
															label: __('Including Tax', 'tier-pricing-table'),
															value: 'incl'
														},
														{
															label: __('Excluding Tax', 'tier-pricing-table'),
															value: 'excl'
														}
													]}
													onChange={(val) => updateRoleSetting(role, 'display_cart', val)}
												/>

												<TextControl
													label={__('Price Display Suffix', 'tier-pricing-table')}
													help={__('Leave blank to inherit default settings.', 'tier-pricing-table')}
													value={settings[role]?.price_suffix !== undefined ? settings[role]?.price_suffix : ''}
													onChange={(val) => updateRoleSetting(role, 'price_suffix', val)}
													placeholder={__('N/A', 'tier-pricing-table')}
												/>
											</>
										)}

										<div style={{
											marginTop: '10px',
											paddingTop: '20px',
											borderTop: '1px solid #eee'
										}}>
											<Button
												isDestructive
												isTertiary
												icon={trash}
												onClick={() => removeRole(role)}
												style={{padding: 0}}
											>
												{__('Remove Configuration', 'tier-pricing-table')}
											</Button>
										</div>
									</div>
								</PanelRow>
							</PanelBody>
						);
					})}
				</div>
			)}

			<Flex gap="4" style={{marginTop: '20px', alignItems: 'center'}}>
				{activeRoles.length > 0 && availableRolesToAdd.length > 0 && (
					<div style={{display: 'flex', gap: '10px', alignItems: 'center'}}>
						<SelectControl
							options={[
								{label: __('Select a role...', 'tier-pricing-table'), value: ''},
								...availableRolesToAdd
							]}
							value={newRole}
							onChange={(val) => setNewRole(val)}
							style={{marginBottom: 0, minWidth: '200px'}}
						/>
						<Button variant="secondary" icon={plus} onClick={addRole} disabled={!newRole}>
							{__('Add Configuration', 'tier-pricing-table')}
						</Button>
					</div>
				)}
			</Flex>
		</div>
	);
}

function sprintf(format, ...args) {
	let i = 0;
	return format.replace(/%s/g, () => args[i++]);
}
