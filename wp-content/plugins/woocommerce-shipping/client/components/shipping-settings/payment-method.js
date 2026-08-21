import React from 'react';
import PaymentMethodIcon from 'wcshipping/components/payment-method-icon';
import './style.scss';

const PaymentMethod = ( props ) => {
	return (
		<div className="payment-method-card__container">
			<div className="payment-method-icon">
				<PaymentMethodIcon type={ props.type } />
			</div>
			<div className="payment-method-card__text">
				<p className="payment-method-card__text-title">
					{ props.type } ****{ props.cardDigits }
				</p>
				<p>{ props.cardName }</p>
			</div>
			<div className="payment-method-card__expiry">
				Expires: { props.expiry }
			</div>
		</div>
	);
};

export default PaymentMethod;
