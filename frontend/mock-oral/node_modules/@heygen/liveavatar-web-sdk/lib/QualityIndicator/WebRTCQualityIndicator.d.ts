import { AbstractConnectionQualityIndicator } from "./base";
import { ConnectionQuality } from "./types";
export declare class WebRTCConnectionQualityIndicator extends AbstractConnectionQualityIndicator<globalThis.RTCPeerConnection> {
    private issueDetector;
    private mosScores;
    protected _start(peerConnection: globalThis.RTCPeerConnection): void;
    protected _stop(): void;
    protected calculateConnectionQuality(): ConnectionQuality;
}
