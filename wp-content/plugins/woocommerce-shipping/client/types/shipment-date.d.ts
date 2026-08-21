// If D is a string, it is in ISO 8601 format
export interface ShipmentDate< D = Date | string > {
	shippingDate?: D;
	estimatedDeliveryDate?: D;
}
