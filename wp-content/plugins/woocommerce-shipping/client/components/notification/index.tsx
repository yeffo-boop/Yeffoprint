import { Button, Flex, Notice } from '@wordpress/components';
import { NoticeProps } from '@wordpress/components/build-types/notice/types';

const Notification = ( { actions, children, ...props }: NoticeProps ) => {
	return (
		<Notice { ...props }>
			<Flex direction="column" gap={ 3 }>
				{ children }
				<Flex direction="row" gap={ 3 }>
					{ actions?.map(
						( { label, className, onClick, url, variant } ) => (
							<Button
								key={ label }
								className={ className }
								onClick={ onClick }
								variant={ variant }
								{ ...( url ? { href: url } : {} ) }
							>
								{ label }
							</Button>
						)
					) }
				</Flex>
			</Flex>
		</Notice>
	);
};

export default Notification;
