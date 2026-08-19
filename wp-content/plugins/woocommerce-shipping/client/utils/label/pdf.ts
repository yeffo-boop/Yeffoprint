export const getPDFFileName = ( orderId: number, isReprint = false ) =>
	`order-#${ orderId }-label` + ( isReprint ? '-reprint' : '' ) + '.pdf';
