export var SessionEvent;
(function (SessionEvent) {
    SessionEvent["SESSION_STATE_CHANGED"] = "session.state_changed";
    SessionEvent["SESSION_STREAM_READY"] = "session.stream_ready";
    SessionEvent["SESSION_CONNECTION_QUALITY_CHANGED"] = "session.connection_quality_changed";
    SessionEvent["SESSION_DISCONNECTED"] = "session.disconnected";
})(SessionEvent || (SessionEvent = {}));
export var AgentEventsEnum;
(function (AgentEventsEnum) {
    AgentEventsEnum["SESSION_UPDATED"] = "session.updated";
    AgentEventsEnum["SESSION_STATE_UPDATED"] = "session.state_updated";
    AgentEventsEnum["USER_SPEAK_STARTED"] = "user.speak_started";
    AgentEventsEnum["USER_SPEAK_ENDED"] = "user.speak_ended";
    AgentEventsEnum["USER_TRANSCRIPTION"] = "user.transcription";
    AgentEventsEnum["USER_TRANSCRIPTION_CHUNK"] = "user.transcription.chunk";
    AgentEventsEnum["AVATAR_TRANSCRIPTION"] = "avatar.transcription";
    AgentEventsEnum["AVATAR_TRANSCRIPTION_CHUNK"] = "avatar.transcription.chunk";
    AgentEventsEnum["AVATAR_SPEAK_STARTED"] = "avatar.speak_started";
    AgentEventsEnum["AVATAR_SPEAK_ENDED"] = "avatar.speak_ended";
})(AgentEventsEnum || (AgentEventsEnum = {}));
export const getAgentEventEmitArgs = (event) => {
    if ("event_type" in event) {
        switch (event.event_type) {
            case AgentEventsEnum.USER_SPEAK_STARTED: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                };
                return [AgentEventsEnum.USER_SPEAK_STARTED, payload];
            }
            case AgentEventsEnum.USER_SPEAK_ENDED: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                };
                return [AgentEventsEnum.USER_SPEAK_ENDED, payload];
            }
            case AgentEventsEnum.USER_TRANSCRIPTION: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                    text: event.text,
                };
                return [AgentEventsEnum.USER_TRANSCRIPTION, payload];
            }
            case AgentEventsEnum.USER_TRANSCRIPTION_CHUNK: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                    text: event.text,
                };
                return [AgentEventsEnum.USER_TRANSCRIPTION_CHUNK, payload];
            }
            case AgentEventsEnum.AVATAR_SPEAK_STARTED: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                };
                return [AgentEventsEnum.AVATAR_SPEAK_STARTED, payload];
            }
            case AgentEventsEnum.AVATAR_SPEAK_ENDED: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                };
                return [AgentEventsEnum.AVATAR_SPEAK_ENDED, payload];
            }
            case AgentEventsEnum.AVATAR_TRANSCRIPTION: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                    text: event.text,
                };
                return [AgentEventsEnum.AVATAR_TRANSCRIPTION, payload];
            }
            case AgentEventsEnum.AVATAR_TRANSCRIPTION_CHUNK: {
                const payload = {
                    event_id: event.event_id,
                    event_type: event.event_type,
                    text: event.text,
                };
                return [AgentEventsEnum.AVATAR_TRANSCRIPTION_CHUNK, payload];
            }
            default:
                console.warn("Received event type:", event === null || event === void 0 ? void 0 : event.event_type);
                console.warn("New unsupported event type");
                return null;
        }
    }
    return null;
};
export var CommandEventsEnum;
(function (CommandEventsEnum) {
    CommandEventsEnum["SESSION_UPDATE"] = "session.update";
    CommandEventsEnum["SESSION_STOP"] = "session.stop";
    CommandEventsEnum["AVATAR_INTERRUPT"] = "avatar.interrupt";
    // AVATAR_INTERRUPT_VIDEO = "avatar.interrupt_video",
    CommandEventsEnum["AVATAR_SPEAK_TEXT"] = "avatar.speak_text";
    CommandEventsEnum["AVATAR_SPEAK_RESPONSE"] = "avatar.speak_response";
    CommandEventsEnum["AVATAR_SPEAK_AUDIO"] = "avatar.speak_audio";
    CommandEventsEnum["AVATAR_START_LISTENING"] = "avatar.start_listening";
    CommandEventsEnum["AVATAR_STOP_LISTENING"] = "avatar.stop_listening";
})(CommandEventsEnum || (CommandEventsEnum = {}));
