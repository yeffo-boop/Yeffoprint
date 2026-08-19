export interface SiteSettingsRecord extends Record< string, unknown > {
	plan: Record< string, unknown > & {
		stored_details_id: number;
		subscription_id?: number;
	};
}
