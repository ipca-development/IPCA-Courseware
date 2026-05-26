import TypedEmitter from "typed-emitter";
import { Room } from "livekit-client";
import { VoiceChatEventCallbacks } from "./events";
import { VoiceChatConfig, SessionInteractivityMode, VoiceChatState } from "./types";
declare const VoiceChat_base: new () => TypedEmitter<VoiceChatEventCallbacks>;
export declare class VoiceChat extends VoiceChat_base {
    private readonly room;
    private _state;
    private track;
    private mode;
    private pushToTalkStarted;
    constructor(room: Room);
    private get isConnected();
    setMode(mode: SessionInteractivityMode): void;
    get state(): VoiceChatState;
    get isMuted(): boolean;
    start(config?: VoiceChatConfig): Promise<void>;
    stop(): void;
    mute(): Promise<void>;
    unmute(): Promise<void>;
    setDevice(deviceId: ConstrainDOMString): Promise<boolean>;
    startPushToTalk(): Promise<void>;
    stopPushToTalk(): Promise<void>;
    private sendPushToTalkCommand;
    private set state(value);
    private assertActive;
}
export {};
