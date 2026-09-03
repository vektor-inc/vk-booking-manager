export declare class ProcessIdAllocator {
    private initialId;
    private maxId;
    private nextId;
    private claimed;
    constructor(initialId?: number, maxId?: number);
    claim(): number;
    release(id: number): boolean;
}
