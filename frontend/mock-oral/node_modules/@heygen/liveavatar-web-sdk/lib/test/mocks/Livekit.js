var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
import { EventEmitter } from "events";
import { vi } from "vitest";
import { ConnectionState, ParticipantEvent, RoomEvent, TrackEvent, } from "livekit-client";
import { testContext } from "../utils/testContext";
import { LIVEKIT_SERVER_RESPONSE_CHANNEL_TOPIC } from "../../const";
export class LocalAudioTrackMock extends EventEmitter {
    constructor() {
        super();
        this.isMuted = false;
        this.setDeviceId = vi.fn(() => __awaiter(this, void 0, void 0, function* () {
            return true;
        }));
        this.stop = vi.fn(() => __awaiter(this, void 0, void 0, function* () {
            return true;
        }));
    }
    mute() {
        return __awaiter(this, void 0, void 0, function* () {
            this.isMuted = true;
            this.emit(TrackEvent.Muted);
        });
    }
    unmute() {
        return __awaiter(this, void 0, void 0, function* () {
            this.isMuted = false;
            this.emit(TrackEvent.Unmuted);
        });
    }
}
export class LocalParticipantMock extends EventEmitter {
    constructor() {
        super();
        this.trackPublications = [];
        this.publishData = vi.fn(() => { });
        this._triggerConnectionQualityChanged = (quality) => {
            this.emit(ParticipantEvent.ConnectionQualityChanged, quality);
        };
    }
    publishTrack(track) {
        return __awaiter(this, void 0, void 0, function* () {
            this.trackPublications.push({
                track: track,
            });
        });
    }
    getTrackPublications() {
        return this.trackPublications;
    }
}
export class RoomMock extends EventEmitter {
    constructor() {
        super();
        this.name = "mock-room";
        this.sid = "mock-room-sid";
        this.remoteParticipants = new Map();
        this.localParticipant = new LocalParticipantMock();
        this.participants = new Map();
        this.state = "disconnected";
        this.connect = vi.fn(() => __awaiter(this, void 0, void 0, function* () {
            this.state = "connecting";
            yield new Promise((resolve) => setTimeout(resolve, 10));
            this.state = "connected";
            this.emit(RoomEvent.Connected);
            this.emit(RoomEvent.ActiveSpeakersChanged, [this.localParticipant]);
            this.emit(RoomEvent.ConnectionStateChanged, ConnectionState.Connected);
            return this;
        }));
        this.prepareConnection = vi.fn(() => __awaiter(this, void 0, void 0, function* () {
            return Promise.resolve();
        }));
        this.disconnect = vi.fn(() => __awaiter(this, void 0, void 0, function* () {
            this.state = "disconnected";
            this.emit(RoomEvent.Disconnected);
            return Promise.resolve();
        }));
        this.engine = {
            pcManager: { subscriber: { _pc: {} } },
        };
        testContext.roomInstance = this;
    }
    _triggerTrackSubscribed(kind) {
        this.emit(RoomEvent.TrackSubscribed, { kind, mediaStreamTrack: { kind } }, null, {
            identity: "heygen",
        });
    }
    _triggerDataReceived(data) {
        const message = new TextEncoder().encode(JSON.stringify(data));
        this.emit(RoomEvent.DataReceived, message, null, null, LIVEKIT_SERVER_RESPONSE_CHANNEL_TOPIC);
    }
    _triggerConnectionStateChanged(state) {
        this.emit(RoomEvent.ConnectionStateChanged, state);
    }
    _triggerConnectionQualityChanged(quality) {
        this.localParticipant._triggerConnectionQualityChanged(quality);
    }
    _triggerDisconnected() {
        this.emit(RoomEvent.Disconnected);
    }
}
export const createLocalAudioTrack = () => __awaiter(void 0, void 0, void 0, function* () {
    return new LocalAudioTrackMock();
});
// export const Room = vi.fn(RoomMock);
