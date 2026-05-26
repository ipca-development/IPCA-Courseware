import { LiveKitConnectionQualityIndicator } from "./LiveKitQualityIndicator";
import { WebRTCConnectionQualityIndicator } from "./WebRTCQualityIndicator";
import { QualityIndicatorComposite, AbstractConnectionQualityIndicator, } from "./base";
export * from "./types";
export { AbstractConnectionQualityIndicator };
export const ConnectionQualityIndicator = QualityIndicatorComposite({
    TrackerClass: LiveKitConnectionQualityIndicator,
    getParams: (room) => room,
}, {
    TrackerClass: WebRTCConnectionQualityIndicator,
    getParams: (room) => { var _a; return ((_a = room.engine.pcManager) === null || _a === void 0 ? void 0 : _a.subscriber)._pc; },
});
