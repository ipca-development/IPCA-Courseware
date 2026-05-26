import { Room } from "livekit-client";
import { AbstractConnectionQualityIndicator } from "./base";
import { ConnectionQuality } from "./types";
export declare class LiveKitConnectionQualityIndicator extends AbstractConnectionQualityIndicator<Room> {
    private room;
    private liveKitConnectionQuality;
    private liveKitConnectionState;
    private handleConnectionQualityChanged;
    private handleConnectionStateChanged;
    protected _start(room: Room): void;
    protected _stop(): void;
    protected calculateConnectionQuality(): ConnectionQuality;
}
