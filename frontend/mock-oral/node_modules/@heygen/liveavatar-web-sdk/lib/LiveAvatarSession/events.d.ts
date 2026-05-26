import { SessionDisconnectReason, SessionState } from "./types";
import { ConnectionQuality } from "../QualityIndicator";
export declare enum SessionEvent {
    SESSION_STATE_CHANGED = "session.state_changed",
    SESSION_STREAM_READY = "session.stream_ready",
    SESSION_CONNECTION_QUALITY_CHANGED = "session.connection_quality_changed",
    SESSION_DISCONNECTED = "session.disconnected"
}
export type SessionEventCallbacks = {
    [SessionEvent.SESSION_STATE_CHANGED]: (state: SessionState) => void;
    [SessionEvent.SESSION_CONNECTION_QUALITY_CHANGED]: (quality: ConnectionQuality) => void;
    [SessionEvent.SESSION_STREAM_READY]: () => void;
    [SessionEvent.SESSION_DISCONNECTED]: (reason: SessionDisconnectReason) => void;
};
export declare enum AgentEventsEnum {
    SESSION_UPDATED = "session.updated",
    SESSION_STATE_UPDATED = "session.state_updated",
    USER_SPEAK_STARTED = "user.speak_started",
    USER_SPEAK_ENDED = "user.speak_ended",
    USER_TRANSCRIPTION = "user.transcription",
    USER_TRANSCRIPTION_CHUNK = "user.transcription.chunk",
    AVATAR_TRANSCRIPTION = "avatar.transcription",
    AVATAR_TRANSCRIPTION_CHUNK = "avatar.transcription.chunk",
    AVATAR_SPEAK_STARTED = "avatar.speak_started",
    AVATAR_SPEAK_ENDED = "avatar.speak_ended",
    ELEVENLABS_AGENT_EVENT = "elevenlabs_agent_event",
    SESSION_STOPPED = "session.stopped"
}
export type AgentEventData<T extends AgentEventsEnum, U extends object = object> = {
    event_id: string;
    event_type: T;
    source_event_id?: string;
    session_id?: string;
} & U;
export type ElevenLabsAgentEventPayload = {
    elevenlabs_event_type: string;
    data: Record<string, any>;
};
export type AgentEvent = AgentEventData<AgentEventsEnum.USER_SPEAK_STARTED> | AgentEventData<AgentEventsEnum.USER_SPEAK_ENDED> | AgentEventData<AgentEventsEnum.USER_TRANSCRIPTION, {
    text: string;
}> | AgentEventData<AgentEventsEnum.USER_TRANSCRIPTION_CHUNK, {
    text: string;
}> | AgentEventData<AgentEventsEnum.AVATAR_TRANSCRIPTION, {
    text: string;
}> | AgentEventData<AgentEventsEnum.AVATAR_TRANSCRIPTION_CHUNK, {
    text: string;
}> | AgentEventData<AgentEventsEnum.AVATAR_SPEAK_STARTED> | AgentEventData<AgentEventsEnum.AVATAR_SPEAK_ENDED> | AgentEventData<AgentEventsEnum.ELEVENLABS_AGENT_EVENT, ElevenLabsAgentEventPayload> | AgentEventData<AgentEventsEnum.SESSION_STOPPED, {
    stop_reason: string;
}>;
export type AgentEventCallbacks = {
    [AgentEventsEnum.USER_SPEAK_STARTED]: (event: AgentEventData<AgentEventsEnum.USER_SPEAK_STARTED>) => void;
    [AgentEventsEnum.USER_SPEAK_ENDED]: (event: AgentEventData<AgentEventsEnum.USER_SPEAK_ENDED>) => void;
    [AgentEventsEnum.AVATAR_SPEAK_STARTED]: (event: AgentEventData<AgentEventsEnum.AVATAR_SPEAK_STARTED>) => void;
    [AgentEventsEnum.AVATAR_SPEAK_ENDED]: (event: AgentEventData<AgentEventsEnum.AVATAR_SPEAK_ENDED>) => void;
    [AgentEventsEnum.USER_TRANSCRIPTION]: (event: AgentEventData<AgentEventsEnum.USER_TRANSCRIPTION, {
        text: string;
    }>) => void;
    [AgentEventsEnum.USER_TRANSCRIPTION_CHUNK]: (event: AgentEventData<AgentEventsEnum.USER_TRANSCRIPTION_CHUNK, {
        text: string;
    }>) => void;
    [AgentEventsEnum.AVATAR_TRANSCRIPTION]: (event: AgentEventData<AgentEventsEnum.AVATAR_TRANSCRIPTION, {
        text: string;
    }>) => void;
    [AgentEventsEnum.AVATAR_TRANSCRIPTION_CHUNK]: (event: AgentEventData<AgentEventsEnum.AVATAR_TRANSCRIPTION_CHUNK, {
        text: string;
    }>) => void;
    [AgentEventsEnum.ELEVENLABS_AGENT_EVENT]: (event: AgentEventData<AgentEventsEnum.ELEVENLABS_AGENT_EVENT, ElevenLabsAgentEventPayload>) => void;
    [AgentEventsEnum.SESSION_STOPPED]: (event: AgentEventData<AgentEventsEnum.SESSION_STOPPED, {
        stop_reason: string;
    }>) => void;
};
export declare const getAgentEventEmitArgs: (event: AgentEvent) => AgentEventEmitArgs | null;
export declare enum CommandEventsEnum {
    SESSION_UPDATE = "session.update",
    SESSION_STOP = "session.stop",
    AVATAR_INTERRUPT = "avatar.interrupt",
    AVATAR_SPEAK_TEXT = "avatar.speak_text",
    AVATAR_SPEAK_RESPONSE = "avatar.speak_response",
    AVATAR_SPEAK_AUDIO = "avatar.speak_audio",
    AVATAR_START_LISTENING = "avatar.start_listening",
    AVATAR_STOP_LISTENING = "avatar.stop_listening",
    ELEVENLABS_AGENT_COMMAND = "elevenlabs_agent_command"
}
export declare enum ElevenLabsAgentCommandType {
    USER_MESSAGE = "user_message",
    CONTEXTUAL_UPDATE = "contextual_update",
    USER_ACTIVITY = "user_activity",
    CLIENT_TOOL_RESULT = "client_tool_result"
}
export type ElevenLabsAgentCommandPayload = {
    elevenlabs_event_type: ElevenLabsAgentCommandType.USER_MESSAGE;
    data: {
        text: string;
    };
} | {
    elevenlabs_event_type: ElevenLabsAgentCommandType.CONTEXTUAL_UPDATE;
    data: {
        text: string;
    };
} | {
    elevenlabs_event_type: ElevenLabsAgentCommandType.USER_ACTIVITY;
    data?: Record<string, never>;
} | {
    elevenlabs_event_type: ElevenLabsAgentCommandType.CLIENT_TOOL_RESULT;
    data: {
        tool_call_id: string;
        result: string;
        is_error: boolean;
    };
};
export type ElevenLabsAgentCommandEvent = {
    event_type: CommandEventsEnum.ELEVENLABS_AGENT_COMMAND;
    event_id: string;
    session_id: string;
    source_event_id?: string | null;
} & ElevenLabsAgentCommandPayload;
type CommandEventData<T extends CommandEventsEnum, U extends object = object> = {
    event_id?: string;
    event_type: T;
} & U;
export type CommandEvent = CommandEventData<CommandEventsEnum.SESSION_UPDATE> | CommandEventData<CommandEventsEnum.SESSION_STOP> | CommandEventData<CommandEventsEnum.AVATAR_INTERRUPT> | CommandEventData<CommandEventsEnum.AVATAR_SPEAK_TEXT, {
    text: string;
}> | CommandEventData<CommandEventsEnum.AVATAR_SPEAK_RESPONSE, {
    text: string;
}> | CommandEventData<CommandEventsEnum.AVATAR_SPEAK_AUDIO, {
    audio: string;
}> | CommandEventData<CommandEventsEnum.AVATAR_START_LISTENING> | CommandEventData<CommandEventsEnum.AVATAR_STOP_LISTENING>;
export type AgentEventEmitArgs = {
    [K in keyof AgentEventCallbacks]: [K, ...Parameters<AgentEventCallbacks[K]>];
}[keyof AgentEventCallbacks];
export {};
