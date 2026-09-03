import type { Blueprint, BlueprintBundle } from './types';
import type { BlueprintV1Declaration } from './v1/types';
import type { BlueprintV2Declaration } from './v2/blueprint-v2-declaration';
export declare function isBlueprintBundle(input: any): input is BlueprintBundle;
export declare function getBlueprintDeclaration(blueprint: Blueprint): Promise<BlueprintV1Declaration | BlueprintV2Declaration>;
export type BlueprintType = 'bundle' | 'declaration';
export declare class BlueprintReflection {
    private readonly declaration;
    private readonly bundle;
    private readonly version;
    static create(blueprint: Blueprint): Promise<BlueprintReflection>;
    static createFromDeclaration(declaration: BlueprintV1Declaration | BlueprintV2Declaration, bundle?: BlueprintBundle | undefined): BlueprintReflection;
    protected constructor(declaration: BlueprintV1Declaration | BlueprintV2Declaration, bundle: BlueprintBundle | undefined, version: number);
    getVersion(): number;
    getDeclaration(): import("./v2/wep-1-blueprint-v2-schema/appendix-A-blueprint-v2-schema").V2Schema.BlueprintV2 | BlueprintV1Declaration;
    isBundle(): boolean;
    getBundle(): import("@wp-playground/storage").ReadableFilesystemBackend | undefined;
    getBlueprint(): Blueprint;
}
