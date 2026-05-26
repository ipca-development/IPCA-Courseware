import { RoomEvent, ParticipantEvent, ConnectionQuality as LiveKitConnectionQuality, ConnectionState as LiveKitConnectionState, } from "livekit-client";
import { AbstractConnectionQualityIndicator } from "./base";
import { ConnectionQuality } from "./types";
export class LiveKitConnectionQualityIndicator extends AbstractConnectionQualityIndicator {
    constructor() {
        super(...arguments);
        this.room = null;
        this.liveKitConnectionQuality = LiveKitConnectionQuality.Unknown;
        this.liveKitConnectionState = null;
        this.handleConnectionQualityChanged = (quality) => {
            this.liveKitConnectionQuality = quality;
            this.handleStatsChanged();
        };
        this.handleConnectionStateChanged = (state) => {
            this.liveKitConnectionState = state;
            this.handleStatsChanged();
        };
    }
    _start(room) {
        this.room = room;
        this.room.localParticipant.on(ParticipantEvent.ConnectionQualityChanged, this.handleConnectionQualityChanged);
        this.room.on(RoomEvent.ConnectionStateChanged, this.handleConnectionStateChanged);
    }
    _stop() {
        if (this.room) {
            this.room.localParticipant.off(ParticipantEvent.ConnectionQualityChanged, this.handleConnectionQualityChanged);
            this.room.off(RoomEvent.ConnectionStateChanged, this.handleConnectionStateChanged);
        }
    }
    calculateConnectionQuality() {
        if ([LiveKitConnectionQuality.Lost, LiveKitConnectionQuality.Poor].includes(this.liveKitConnectionQuality)) {
            return ConnectionQuality.BAD;
        }
        if (this.liveKitConnectionState &&
            [
                LiveKitConnectionState.Disconnected,
                LiveKitConnectionState.Reconnecting,
                LiveKitConnectionState.SignalReconnecting,
            ].includes(this.liveKitConnectionState)) {
            return ConnectionQuality.BAD;
        }
        return ConnectionQuality.GOOD;
    }
}
