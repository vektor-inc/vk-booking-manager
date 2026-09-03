export declare function declareFileLockManagerTests({ name, testWorkerUrl, shouldSkip, workerType, }: {
    name: string;
    testWorkerUrl: URL;
    shouldSkip?: boolean;
    workerType: 'childProcess' | 'workerThread';
}): import("vitest").SuiteCollector<object>;
