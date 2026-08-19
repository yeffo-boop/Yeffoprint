export interface WCTracks {
	isEnabled: boolean;
	recordEvent: (
		eventName: string,
		eventProperties: Record< string, unknown >
	) => void;
}
