import { describe, it, expect, vi } from "vitest";
import { AbstractConnectionQualityIndicator, QualityIndicatorComposite, } from "./base";
import { ConnectionQuality } from "./types";
class ChildIndicator extends AbstractConnectionQualityIndicator {
    constructor() {
        super(...arguments);
        this._quality = ConnectionQuality.UNKNOWN;
    }
    calculateConnectionQuality() {
        return this._quality;
    }
    _start() { }
    _stop() { }
    _triggerConnectionQualityChanged(quality) {
        this._quality = quality;
        this.handleStatsChanged();
    }
}
const setupIndicators = (onConnectionQualityChanged) => {
    class ChildIndicator1 extends ChildIndicator {
        constructor(onConnectionQualityChanged) {
            if (ChildIndicator1.instance) {
                return ChildIndicator1.instance;
            }
            super(onConnectionQualityChanged);
            ChildIndicator1.instance = this;
        }
    }
    ChildIndicator1.instance = null;
    class ChildIndicator2 extends ChildIndicator {
        constructor(onConnectionQualityChanged) {
            if (ChildIndicator2.instance) {
                return ChildIndicator2.instance;
            }
            super(onConnectionQualityChanged);
            ChildIndicator2.instance = this;
        }
    }
    ChildIndicator2.instance = null;
    const IndicatorCompositeClass = QualityIndicatorComposite({
        TrackerClass: ChildIndicator1,
        getParams: () => ({}),
    }, {
        TrackerClass: ChildIndicator2,
        getParams: () => ({}),
    });
    const indicator = new IndicatorCompositeClass(onConnectionQualityChanged);
    return { indicator, ChildIndicator1, ChildIndicator2 };
};
describe("QualityIndicatorComposite", () => {
    it("resolves to BAD when any child indicator resolves to BAD", () => {
        var _a, _b;
        const onConnectionQualityChanged = vi.fn();
        const { indicator, ChildIndicator1, ChildIndicator2 } = setupIndicators(onConnectionQualityChanged);
        indicator.start({});
        (_a = ChildIndicator1.instance) === null || _a === void 0 ? void 0 : _a._triggerConnectionQualityChanged(ConnectionQuality.BAD);
        (_b = ChildIndicator2.instance) === null || _b === void 0 ? void 0 : _b._triggerConnectionQualityChanged(ConnectionQuality.GOOD);
        expect(onConnectionQualityChanged).toHaveBeenCalledWith(ConnectionQuality.BAD);
    });
    it("resolves to UNKNOWN when all child indicators resolve to UNKNOWN", () => {
        var _a, _b;
        const onConnectionQualityChanged = vi.fn();
        const { indicator, ChildIndicator1 } = setupIndicators(onConnectionQualityChanged);
        indicator.start({});
        (_a = ChildIndicator1.instance) === null || _a === void 0 ? void 0 : _a._triggerConnectionQualityChanged(ConnectionQuality.BAD);
        (_b = ChildIndicator1.instance) === null || _b === void 0 ? void 0 : _b._triggerConnectionQualityChanged(ConnectionQuality.UNKNOWN);
        expect(onConnectionQualityChanged).toHaveBeenCalledWith(ConnectionQuality.UNKNOWN);
    });
    it("resolves to GOOD when at least one child indicator resolves to GOOD and no child indicator resolves to BAD", () => {
        var _a;
        const onConnectionQualityChanged = vi.fn();
        const { indicator, ChildIndicator1 } = setupIndicators(onConnectionQualityChanged);
        indicator.start({});
        (_a = ChildIndicator1.instance) === null || _a === void 0 ? void 0 : _a._triggerConnectionQualityChanged(ConnectionQuality.GOOD);
        expect(onConnectionQualityChanged).toHaveBeenCalledWith(ConnectionQuality.GOOD);
    });
});
