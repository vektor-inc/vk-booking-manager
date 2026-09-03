import { type StreamedPHPResponse, type UniversalPHP } from '@php-wasm/universal';
import { type RawBlueprintV2Data, type ParsedBlueprintV1orV2String } from './blueprint-v2-declaration';
import type { BlueprintV1Declaration } from '../v1/types';
export type PHPExceptionDetails = {
    exception: string;
    message: string;
    file: string;
    line: number;
    trace: string;
};
export type BlueprintMessage = {
    type: 'blueprint.target_resolved';
} | {
    type: 'blueprint.progress';
    progress: number;
    caption: string;
} | {
    type: 'blueprint.error';
    message: string;
    details?: PHPExceptionDetails;
} | {
    type: 'blueprint.completion';
    message: string;
};
interface RunV2Options {
    php: UniversalPHP;
    cliArgs?: string[];
    blueprint: RawBlueprintV2Data | ParsedBlueprintV1orV2String | BlueprintV1Declaration;
    blueprintOverrides?: {
        wordpressVersion?: string;
        additionalSteps?: any[];
    };
    onMessage?: (message: BlueprintMessage) => void | Promise<void>;
}
export declare function runBlueprintV2(options: RunV2Options): Promise<StreamedPHPResponse>;
export {};
