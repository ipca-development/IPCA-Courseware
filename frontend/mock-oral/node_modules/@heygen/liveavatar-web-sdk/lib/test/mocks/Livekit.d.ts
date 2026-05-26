import { EventEmitter } from "events";
import { ConnectionQuality, ConnectionState, TrackPublication } from "livekit-client";
export declare class LocalAudioTrackMock extends EventEmitter {
    isMuted: boolean;
    constructor();
    mute(): Promise<void>;
    unmute(): Promise<void>;
    setDeviceId: import("vitest").Mock<() => Promise<boolean>>;
    stop: import("vitest").Mock<() => Promise<boolean>>;
}
export declare class LocalParticipantMock extends EventEmitter {
    trackPublications: TrackPublication[];
    constructor();
    publishTrack(track: LocalAudioTrackMock): Promise<void>;
    getTrackPublications(): TrackPublication[];
    publishData: import("vitest").Mock<() => void>;
    _triggerConnectionQualityChanged: (quality: ConnectionQuality) => void;
}
export declare class RoomMock extends EventEmitter {
    constructor();
    name: string;
    sid: string;
    remoteParticipants: Map<any, any>;
    localParticipant: LocalParticipantMock;
    participants: Map<any, any>;
    state: string;
    connect: import("vitest").Mock<(_url?: string, token?: string) => Promise<this>>;
    _emitRequiredParticipants(_token?: string): void;
    prepareConnection: import("vitest").Mock<() => Promise<void>>;
    disconnect: import("vitest").Mock<() => Promise<void>>;
    engine: {
        pcManager: {
            subscriber: {
                _pc: {};
            };
        };
    };
    _triggerTrackSubscribed(kind: string): void;
    _triggerDataReceived(data: any): void;
    _triggerConnectionStateChanged(state: ConnectionState): void;
    _triggerConnectionQualityChanged(quality: ConnectionQuality): void;
    _triggerDisconnected(): void;
}
export declare const createLocalAudioTrack: () => Promise<LocalAudioTrackMock>;
