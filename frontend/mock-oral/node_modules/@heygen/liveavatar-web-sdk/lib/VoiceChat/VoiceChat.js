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
import { createLocalAudioTrack, TrackEvent, Track, ConnectionState, } from "livekit-client";
import { PushToTalkCommandEvent, PushToTalkServerEvent, VoiceChatEvent, } from "./events";
import { SessionInteractivityMode, VoiceChatState, } from "./types";
import { initEventPromise } from "../utils/initEventPromise";
import { LIVEKIT_COMMAND_CHANNEL_TOPIC } from "../const";
export class VoiceChat extends EventEmitter {
    constructor(room) {
        super();
        this._state = VoiceChatState.INACTIVE;
        this.track = null;
        this.mode = null;
        this.pushToTalkStarted = false;
        this.room = room;
    }
    get isConnected() {
        return (this.room.state !== ConnectionState.Disconnected &&
            this.room.state !== ConnectionState.Connecting);
    }
    setMode(mode) {
        if (this.mode) {
            console.warn("Voice chat mode can only be set once");
            return;
        }
        this.mode = mode;
    }
    get state() {
        return this._state;
    }
    get isMuted() {
        var _a, _b;
        return (_b = (_a = this.track) === null || _a === void 0 ? void 0 : _a.isMuted) !== null && _b !== void 0 ? _b : true;
    }
    start() {
        return __awaiter(this, arguments, void 0, function* (config = {}) {
            if (!this.isConnected) {
                console.warn("Voice chat can only be started when session is active");
                return;
            }
            if (this._state !== VoiceChatState.INACTIVE) {
                console.warn("Voice chat is already started");
                return;
            }
            this.state = VoiceChatState.STARTING;
            const { defaultMuted, deviceId, mode } = config;
            if (mode) {
                this.setMode(mode);
            }
            try {
                this.track = yield createLocalAudioTrack({
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                    deviceId,
                });
            }
            catch (error) {
                this.state = VoiceChatState.INACTIVE;
                throw error;
            }
            if (defaultMuted) {
                yield this.track.mute();
                this.emit(VoiceChatEvent.MUTED);
            }
            else {
                this.emit(VoiceChatEvent.UNMUTED);
            }
            yield this.room.localParticipant.publishTrack(this.track);
            this.track.on(TrackEvent.Muted, () => {
                this.emit(VoiceChatEvent.MUTED);
            });
            this.track.on(TrackEvent.Unmuted, () => {
                this.emit(VoiceChatEvent.UNMUTED);
            });
            this.state = VoiceChatState.ACTIVE;
        });
    }
    stop() {
        this.room.localParticipant.getTrackPublications().forEach((publication) => {
            if (publication.track && publication.track.kind === Track.Kind.Audio) {
                publication.track.stop();
            }
        });
        if (this.track) {
            this.track.removeAllListeners();
            this.track.stop();
            this.track = null;
        }
        this.state = VoiceChatState.INACTIVE;
    }
    mute() {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.assertActive("Voice chat can only be muted when active")) {
                return;
            }
            if (this.track) {
                yield this.track.mute();
            }
        });
    }
    unmute() {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.assertActive("Voice chat can only be unmuted when active")) {
                return;
            }
            if (this.track) {
                yield this.track.unmute();
            }
        });
    }
    setDevice(deviceId) {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.assertActive("Voice chat device can only be set when active")) {
                return false;
            }
            if (this.track) {
                return this.track.setDeviceId(deviceId);
            }
            return false;
        });
    }
    startPushToTalk() {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.assertActive("Push to talk can only be started when voice chat is active")) {
                return;
            }
            console.error("Session interactivity mode", this.mode);
            if (this.mode !== SessionInteractivityMode.PUSH_TO_TALK) {
                console.warn("Push to talk can only be started in push to talk mode");
                return;
            }
            if (this.pushToTalkStarted) {
                console.warn("Push to talk has already been started");
                return;
            }
            this.pushToTalkStarted = true;
            const promise = initEventPromise(this.room, PushToTalkServerEvent.START_SUCCESS, PushToTalkServerEvent.START_FAILED);
            this.sendPushToTalkCommand(PushToTalkCommandEvent.START);
            try {
                yield promise;
                yield this.unmute();
            }
            catch (e) {
                console.error("Failed to start push to talk", e);
                this.pushToTalkStarted = false;
                throw e;
            }
        });
    }
    stopPushToTalk() {
        return __awaiter(this, void 0, void 0, function* () {
            if (!this.pushToTalkStarted) {
                console.warn("Push to talk has not been started");
                return;
            }
            const promise = initEventPromise(this.room, PushToTalkServerEvent.STOP_SUCCESS, PushToTalkServerEvent.STOP_FAILED);
            this.sendPushToTalkCommand(PushToTalkCommandEvent.STOP);
            try {
                yield promise;
                this.pushToTalkStarted = false;
            }
            catch (e) {
                console.error("Failed to stop push to talk", e);
                throw e;
            }
        });
    }
    sendPushToTalkCommand(command) {
        const data = new TextEncoder().encode(JSON.stringify({ event_type: command }));
        this.room.localParticipant.publishData(data, {
            reliable: true,
            topic: LIVEKIT_COMMAND_CHANNEL_TOPIC,
        });
    }
    set state(state) {
        if (this._state !== state) {
            this._state = state;
            this.emit(VoiceChatEvent.STATE_CHANGED, state);
        }
    }
    assertActive(warnMessage) {
        if (this.state !== VoiceChatState.ACTIVE) {
            console.warn(warnMessage !== null && warnMessage !== void 0 ? warnMessage : "Voice chat is not active");
            return false;
        }
        return true;
    }
}
