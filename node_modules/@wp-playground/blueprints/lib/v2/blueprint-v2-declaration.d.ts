import type { BlueprintBundle } from '../types';
import type { BlueprintV1Declaration } from '../v1/types';
import type { V2Schema } from './wep-1-blueprint-v2-schema/appendix-A-blueprint-v2-schema';
type BlueprintV2Declaration = V2Schema.BlueprintV2;
export type BlueprintV2 = BlueprintV2Declaration | BlueprintBundle;
export type { BlueprintV2Declaration };
export type RawBlueprintV2Data = string | BlueprintV2Declaration | undefined;
export type ParsedBlueprintV1orV2String = {
    type: 'inline-file';
    contents: string;
} | {
    type: 'file-reference';
    reference: string;
};
export declare function parseBlueprintDeclaration(source: RawBlueprintV2Data | ParsedBlueprintV1orV2String | BlueprintV1Declaration): ParsedBlueprintV1orV2String;
