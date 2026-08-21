import { useState } from '@wordpress/element';
import {
	__experimentalSpacer as Spacer,
	__experimentalText as Text,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	Button,
	Flex,
	Icon,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Destination, OriginAddress } from 'types';
import { addressToString } from 'utils';
import { recordEvent } from 'utils/tracks';
import { withBoundary } from 'components/HOC';
import { DataForm } from '@wordpress/dataviews/wp';
import { close, caution as warning } from '@wordpress/icons';

interface AddressSuggestionProps {
	warnings: Record< string, string >[];
	originalAddress: OriginAddress | Destination;
	normalizedAddress: OriginAddress | Destination;
	editAddress: () => void;
	confirmAddress: ( arg: boolean ) => void;
	errors: Record< string, string >;
	nextDesign?: boolean;
	isUpdating?: boolean;
	surfaceArea?: string;
}

export const AddressSuggestion = withBoundary(
	( {
		warnings,
		originalAddress,
		normalizedAddress,
		editAddress,
		confirmAddress,
		errors,
		nextDesign = false,
		isUpdating = false,
		surfaceArea,
	}: AddressSuggestionProps ) => {
		const [ selectedAddress, setSelectedAddress ] = useState(
			normalizedAddress ? 'normalized' : 'original'
		);
		const notice = normalizedAddress
			? __(
					'We have slightly modified the address entered. If correct, please use the suggested address to ensure accurate delivery.',
					'woocommerce-shipping'
			  )
			: __(
					'We were unable to verify the address entered. It may still be a valid address, but we cannot ensure accurate delivery to this address as entered. Please confirm if you would like to continue with the address as entered or return to edit the address.',
					'woocommerce-shipping'
			  );
		const confirmButtonMessage = normalizedAddress
			? __( 'Use selected address', 'woocommerce-shipping' )
			: __( 'Confirm unverified address', 'woocommerce-shipping' );
		return nextDesign ? (
			<div>
				<Spacer marginBottom={ 4 }>
					<Flex justify="space-between" align="flex-start">
						<Text as="h2" size={ 20 } weight={ 500 }>
							{ __( 'Confirm Address', 'woocommerce-shipping' ) }
						</Text>
						<Button
							icon={ close }
							onClick={ () => editAddress() }
							title={ __(
								'Close modal',
								'woocommerce-shipping'
							) }
							style={ {
								padding: 0,
								minWidth: '24px',
								height: '24px',
							} }
						/>
					</Flex>
				</Spacer>
				<Spacer style={ { minHeight: '448px' } } paddingY={ 6 }>
					{ errors.general && (
						<Notice status="error" isDismissible={ false }>
							{ errors.general }
						</Notice>
					) }
					<Spacer marginBottom={ 8 }>
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'We found a similar address, Please review the suggestion below, or proceed with your original address.',
								'woocommerce-shipping'
							) }
						</Notice>
						{ !! warnings &&
							warnings.length > 0 &&
							warnings.map( ( { code, message } ) => (
								<div key={ code }>
									<Spacer marginBottom={ 2 } />
									<Notice
										status="warning"
										isDismissible={ false }
									>
										<Flex align="center" justify="left">
											<Text>{ message }</Text>
										</Flex>
									</Notice>
								</div>
							) ) }
					</Spacer>
					<DataForm< {
						selectedAddress: 'original' | 'normalized';
					} >
						data={ {
							selectedAddress: selectedAddress as
								| 'original'
								| 'normalized',
						} }
						onChange={ ( value ) =>
							setSelectedAddress( value.selectedAddress )
						}
						form={ {
							layout: { type: 'regular', labelPosition: 'top' },
							fields: [ 'selectedAddress' ],
						} }
						fields={ [
							{
								id: 'selectedAddress',
								type: 'text',
								Edit: 'radio',
								label: __(
									'Choose an address',
									'woocommerce-shipping'
								),
								elements: [
									{
										label: __(
											'Keep my address',
											'woocommerce-shipping'
										),
										value: 'original',
										description:
											addressToString( originalAddress ),
									},
									{
										label: __(
											'Suggested address',
											'woocommerce-shipping'
										),
										value: 'normalized',
										description: normalizedAddress
											? addressToString(
													normalizedAddress
											  )
											: undefined,
									},
								],
							},
						] }
					/>
				</Spacer>
				<Spacer marginBottom={ 4 } />
				<Flex justify="flex-end" align={ 'center' } as="footer">
					<Button
						onClick={ () => {
							if ( surfaceArea ) {
								recordEvent(
									`${ surfaceArea }_modal_button_click`,
									{
										modal_title: 'confirm_address',
										button_label: 'edit_address',
									}
								);
							}
							editAddress();
						} }
						variant="tertiary"
						disabled={ isUpdating }
					>
						{ __( 'Edit address', 'woocommerce-shipping' ) }
					</Button>
					<Button
						onClick={ () => {
							if ( surfaceArea ) {
								const label =
									selectedAddress === 'normalized'
										? 'use_selected_address'
										: 'confirm_unverified_address';
								recordEvent(
									`${ surfaceArea }_modal_button_click`,
									{
										modal_title: 'confirm_address',
										button_label: label,
									}
								);
							}
							confirmAddress( selectedAddress === 'normalized' );
						} }
						variant="primary"
						isBusy={ isUpdating }
						disabled={ isUpdating }
					>
						{ confirmButtonMessage }
					</Button>
				</Flex>
			</div>
		) : (
			<div>
				{ errors.general && (
					<Notice status="error" isDismissible={ false }>
						{ errors.general }
					</Notice>
				) }
				<p>{ notice }</p>
				{ !! warnings &&
					warnings.length > 0 &&
					warnings.map( ( { code, message } ) => (
						<Notice
							status="warning"
							isDismissible={ false }
							key={ code }
						>
							<Flex align="center" justify="left">
								<Icon
									icon={ warning }
									fill="#f0b849"
									style={ {
										minWidth: '20px',
										alignSelf: 'center',
										justifyContent: 'left',
									} }
								/>
								<Text>{ message }</Text>
							</Flex>
						</Notice>
					) ) }
				<ToggleGroupControl
					// @ts-ignore
					onChange={ setSelectedAddress }
					value={ selectedAddress }
					justify="space-between"
					gap={ 4 }
				>
					<Flex direction="column" gap={ 2 }>
						<strong>
							{ __( 'What you entered', 'woocommerce-shipping' ) }
						</strong>
						<ToggleGroupControlOption
							value="original"
							label={ addressToString( originalAddress ) }
						></ToggleGroupControlOption>
					</Flex>
					<Flex direction="column" gap={ 2 }>
						{ normalizedAddress && (
							<>
								<strong>
									{ __(
										'Suggested',
										'woocommerce-shipping'
									) }
								</strong>
								<ToggleGroupControlOption
									value="normalized"
									label={ addressToString(
										normalizedAddress
									) }
								></ToggleGroupControlOption>
							</>
						) }
					</Flex>
				</ToggleGroupControl>
				<Flex justify="flex-end" as="footer">
					<Button
						onClick={ editAddress }
						variant="tertiary"
						disabled={ isUpdating }
					>
						{ __( 'Edit address', 'woocommerce-shipping' ) }
					</Button>
					<Button
						onClick={ () =>
							confirmAddress( selectedAddress === 'normalized' )
						}
						variant="primary"
						isBusy={ isUpdating }
						disabled={ isUpdating }
					>
						{ confirmButtonMessage }
					</Button>
				</Flex>
			</div>
		);
	}
)( 'AddressSuggestion' );
