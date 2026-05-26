export interface VoiceChatConfig {
    defaultMuted?: boolean;
    deviceId?: ConstrainDOMString;
    mode?: SessionInteractivityMode;
}
export declare enum VoiceChatState {
    INACTIVE = "INACTIVE",
    STARTING = "STARTING",
    ACTIVE = "ACTIVE"
}
export declare enum SessionInteractivityMode {
    CONVERSATIONAL = "CONVERSATIONAL",
    PUSH_TO_TALK = "PUSH_TO_TALK"
}
