import { CustomsItem } from './customs-item';

export interface CustomsState {
	items: CustomsItem[];
	contentsType: string;
	contentsExplanation?: string;
	restrictionType: string;
	restrictionComments?: string;
	itn: string;
	isReturnToSender: boolean;
}
