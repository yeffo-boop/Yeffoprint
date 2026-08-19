import { isFunction } from 'lodash';
import { useState } from '@wordpress/element';
import {
	__experimentalHeading as Heading,
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Icon,
} from '@wordpress/components';

export const StepContainer = ( {
	title,
	renderSummary = () => null,
	children,
	renderFooter,
} ) => {
	const [ isExpanded, setIsExpanded ] = useState( true );
	const toggle = () => setIsExpanded( ! isExpanded );

	return (
		<Card clickableHeader={ true }>
			<CardHeader size="small" onClick={ toggle }>
				<Heading level={ 4 }>{ title }</Heading>
				{ renderSummary() }
				<Button onClick={ toggle }>
					<Icon
						icon={
							isExpanded ? 'arrow-up-alt2' : 'arrow-down-alt2'
						}
					></Icon>
				</Button>
			</CardHeader>
			{ isExpanded && <CardBody>{ children }</CardBody> }
			{ isExpanded && isFunction( renderFooter ) && (
				<CardFooter>{ renderFooter() }</CardFooter>
			) }
		</Card>
	);
};
