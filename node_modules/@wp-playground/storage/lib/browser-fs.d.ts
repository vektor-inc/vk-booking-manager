export type MountDevice = {
    type: 'opfs';
    path: string;
} | {
    type: 'local-fs';
    handle: FileSystemDirectoryHandle;
};
export declare function directoryHandleFromMountDevice(device: MountDevice): Promise<FileSystemDirectoryHandle>;
export declare function opfsPathToDirectoryHandle(opfsPath: string): Promise<FileSystemDirectoryHandle>;
export declare function directoryHandleToOpfsPath(directoryHandle: FileSystemDirectoryHandle): Promise<string>;
export declare function clearContentsFromMountDevice(mountDevice: MountDevice): Promise<void>;
