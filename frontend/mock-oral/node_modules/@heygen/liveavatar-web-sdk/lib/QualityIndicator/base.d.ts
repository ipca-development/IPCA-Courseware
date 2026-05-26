import { ConnectionQuality } from "./types";
interface ChildTrackerConfig<T, U> {
    TrackerClass: new (onConnectionQualityChanged: (quality: ConnectionQuality) => void) => AbstractConnectionQualityIndicator<U>;
    getParams: (params: T) => U;
}
export declare abstract class AbstractConnectionQualityIndicator<T> {
    private _connectionQuality;
    protected readonly onConnectionQualityChanged: (quality: ConnectionQuality) => void;
    constructor(onConnectionQualityChanged: (quality: ConnectionQuality) => void);
    get connectionQuality(): ConnectionQuality;
    protected handleStatsChanged(): void;
    protected abstract calculateConnectionQuality(): ConnectionQuality;
    protected abstract _start(params: T): void;
    protected abstract _stop(): void;
    start(params: T): void;
    stop(muted?: boolean): void;
}
export declare function QualityIndicatorComposite<T>(...configs: ChildTrackerConfig<T, any>[]): {
    new (onConnectionQualityChanged: (quality: ConnectionQuality) => void): AbstractConnectionQualityIndicator<T>;
};
export {};
