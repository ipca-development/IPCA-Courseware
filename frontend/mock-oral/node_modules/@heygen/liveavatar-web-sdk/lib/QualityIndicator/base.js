import { ConnectionQuality } from "./types";
export class AbstractConnectionQualityIndicator {
    constructor(onConnectionQualityChanged) {
        this._connectionQuality = ConnectionQuality.UNKNOWN;
        this.onConnectionQualityChanged = onConnectionQualityChanged;
    }
    get connectionQuality() {
        return this._connectionQuality;
    }
    handleStatsChanged() {
        const newConnectionQuality = this.calculateConnectionQuality();
        if (newConnectionQuality !== this._connectionQuality) {
            this._connectionQuality = newConnectionQuality;
            this.onConnectionQualityChanged(newConnectionQuality);
        }
    }
    start(params) {
        this.stop(true);
        this._start(params);
    }
    stop(muted = false) {
        this._stop();
        this._connectionQuality = ConnectionQuality.UNKNOWN;
        if (!muted) {
            this.onConnectionQualityChanged(ConnectionQuality.UNKNOWN);
        }
    }
}
export function QualityIndicatorComposite(...configs) {
    class CombinedQualityIndicator extends AbstractConnectionQualityIndicator {
        constructor(onConnectionQualityChanged) {
            super(onConnectionQualityChanged);
            this.childTrackers = configs.map(({ getParams, TrackerClass }) => ({
                tracker: new TrackerClass(() => this.handleStatsChanged()),
                getParams,
            }));
        }
        calculateConnectionQuality() {
            const connectionQualities = this.childTrackers.map(({ tracker }) => tracker.connectionQuality);
            if (connectionQualities.some((quality) => quality === ConnectionQuality.BAD)) {
                return ConnectionQuality.BAD;
            }
            if (connectionQualities.every((quality) => quality === ConnectionQuality.UNKNOWN)) {
                return ConnectionQuality.UNKNOWN;
            }
            return ConnectionQuality.GOOD;
        }
        _start(params) {
            this.childTrackers.forEach(({ tracker, getParams }) => tracker.start(getParams(params)));
        }
        _stop() {
            this.childTrackers.forEach(({ tracker }) => tracker.stop(true));
        }
    }
    return CombinedQualityIndicator;
}
