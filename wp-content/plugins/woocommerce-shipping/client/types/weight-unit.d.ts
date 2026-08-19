import { WEIGHT_UNITS } from '../utils';

export type WeightUnit = ( typeof WEIGHT_UNITS )[ keyof typeof WEIGHT_UNITS ];
