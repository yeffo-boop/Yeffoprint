/**
 * Supported paper-size keys. Kept as a union (rather than `string`) so the
 * compiler catches typos and any new key has to be added here intentionally,
 * which in turn forces `getPaperSizes` to grow with it.
 */
export type PaperSizeKey = 'a4' | 'label' | 'letter';

export interface PaperSize {
	key: PaperSizeKey;
	name: string;
	size: string;
}
