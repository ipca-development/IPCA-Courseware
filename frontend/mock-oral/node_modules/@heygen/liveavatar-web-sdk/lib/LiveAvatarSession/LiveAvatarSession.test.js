var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
import { describe, it, expect, beforeEach, vi } from "vitest";
import { LiveAvatarSession } from "./LiveAvatarSession";
import { SessionDisconnectReason, SessionState, } from "./types";
import { mockFetch } from "../test/utils/mockFetch";
import { testContext } from "../test/utils/testContext";
import { AgentEventsEnum, CommandEventsEnum, SessionEvent } from "./events";
import { VoiceChatEvent, VoiceChatState } from "../VoiceChat";
import { mockWebSocket } from "../test/utils/mockWebSocket";
import { API_URL, LIVEKIT_COMMAND_CHANNEL_TOPIC } from "../const";
import { ConnectionQuality as LiveKitConnectionQuality } from "livekit-client";
import { ConnectionQuality } from "../QualityIndicator";
beforeEach(() => {
    vi.resetAllMocks();
});
const sessionInfoMock = {
    session_id: "mock-session-id",
    max_session_duration: null,
    livekit_url: "mock-livekit-url",
    livekit_client_token: "mock-livekit-client-token",
};
const setupLiveAvatarSession = ({ sessionInfo, sessionConfig, }) => {
    mockFetch({
        url: "/v1/sessions/start",
        method: "POST",
        response: {
            data: sessionInfo,
            code: 1000,
        },
    }, {
        url: "/v1/sessions/stop",
        method: "POST",
        response: {
            code: 1000,
        },
    });
    const session = new LiveAvatarSession("mock-session-token", sessionConfig);
    return session;
};
describe("LiveAvatarSession start", () => {
    it("starts the session and emits state changed events", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
        const onStateChanged = vi.fn();
        session.on(SessionEvent.SESSION_STATE_CHANGED, onStateChanged);
        yield session.start();
        expect(onStateChanged).toHaveBeenCalledTimes(2);
        expect(onStateChanged).toHaveBeenNthCalledWith(1, SessionState.CONNECTING);
        expect(onStateChanged).toHaveBeenNthCalledWith(2, SessionState.CONNECTED);
    }));
    it("does not start the session when the session is already started", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
        yield session.start();
        const onStateChanged = vi.fn();
        session.on(SessionEvent.SESSION_STATE_CHANGED, onStateChanged);
        yield session.start();
        expect(onStateChanged).not.toHaveBeenCalled();
        expect(session.state).toBe(SessionState.CONNECTED);
    }));
    it("starts voice chat when voiceChat config is provided", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({
            sessionInfo: sessionInfoMock,
            sessionConfig: { voiceChat: true },
        });
        yield session.start();
        expect(session.voiceChat.state).toBe(VoiceChatState.ACTIVE);
    }));
    it("connects to web socket when websocket url is provided", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
        });
        yield session.start();
        expect(testContext.wsInstance.readyState).toBe(WebSocket.OPEN);
    }));
});
describe("LiveAvatarSession disconnect", () => {
    it("emits session state changed and disconnect events when the session start fails", () => __awaiter(void 0, void 0, void 0, function* () {
        mockFetch({
            url: "/v1/sessions/start",
            method: "POST",
            response: {
                code: 4000,
                message: "Session start failed",
            },
        }, {
            url: "/v1/sessions/stop",
            method: "POST",
            response: {
                code: 1000,
            },
        });
        const session = new LiveAvatarSession("mock-session-token");
        const onStateChanged = vi.fn();
        session.on(SessionEvent.SESSION_STATE_CHANGED, onStateChanged);
        const onDisconnected = vi.fn();
        session.on(SessionEvent.SESSION_DISCONNECTED, onDisconnected);
        try {
            yield session.start();
        }
        catch (error) {
            expect(error).toBeDefined();
        }
        expect(onStateChanged).toHaveBeenCalledTimes(2);
        expect(onStateChanged).toHaveBeenNthCalledWith(1, SessionState.CONNECTING);
        expect(onStateChanged).toHaveBeenNthCalledWith(2, SessionState.DISCONNECTED);
        expect(onDisconnected).toHaveBeenCalledWith(SessionDisconnectReason.SESSION_START_FAILED);
    }));
    it("disconnects the session when the session is stopped", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
        const onStateChanged = vi.fn();
        session.on(SessionEvent.SESSION_STATE_CHANGED, onStateChanged);
        const onDisconnected = vi.fn();
        session.on(SessionEvent.SESSION_DISCONNECTED, onDisconnected);
        yield session.start();
        yield session.stop();
        expect(onStateChanged).toHaveBeenCalledTimes(4);
        expect(onStateChanged).toHaveBeenNthCalledWith(3, SessionState.DISCONNECTING);
        expect(onStateChanged).toHaveBeenNthCalledWith(4, SessionState.DISCONNECTED);
        expect(onDisconnected).toHaveBeenCalledWith(SessionDisconnectReason.CLIENT_INITIATED);
    }));
    it("disconnects the session when the room is disconnected", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
        const onStateChanged = vi.fn();
        session.on(SessionEvent.SESSION_STATE_CHANGED, onStateChanged);
        const onDisconnected = vi.fn();
        session.on(SessionEvent.SESSION_DISCONNECTED, onDisconnected);
        yield session.start();
        testContext.roomInstance._triggerDisconnected();
        expect(onStateChanged).toHaveBeenCalledTimes(3);
        expect(onStateChanged).toHaveBeenNthCalledWith(3, SessionState.DISCONNECTED);
        expect(onDisconnected).toHaveBeenCalledWith(SessionDisconnectReason.UNKNOWN_REASON);
    }));
    it("disconnects the session when websocket is disconnected", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
        });
        const onStateChanged = vi.fn();
        session.on(SessionEvent.SESSION_STATE_CHANGED, onStateChanged);
        const onDisconnected = vi.fn();
        session.on(SessionEvent.SESSION_DISCONNECTED, onDisconnected);
        yield session.start();
        testContext.wsInstance._triggerClose({ code: 1000, reason: "test" });
        expect(onStateChanged).toHaveBeenCalledTimes(3);
        expect(onStateChanged).toHaveBeenNthCalledWith(3, SessionState.DISCONNECTED);
        expect(onDisconnected).toHaveBeenCalledWith(SessionDisconnectReason.UNKNOWN_REASON);
    }));
});
describe("LiveAvatarSession keepAlive", () => {
    it("does not keep the session alive when the session is not started", () => __awaiter(void 0, void 0, void 0, function* () {
        mockFetch();
        const session = new LiveAvatarSession("mock-session-token");
        yield session.keepAlive();
        expect(fetch).not.toHaveBeenCalled();
    }));
    it("keeps the session alive when the session is started", () => __awaiter(void 0, void 0, void 0, function* () {
        mockFetch({
            url: "/v1/sessions/start",
            method: "POST",
            response: {
                code: 1000,
                data: sessionInfoMock,
            },
        }, {
            url: "/v1/sessions/keep-alive",
            method: "POST",
            response: {
                code: 1000,
            },
        });
        const session = new LiveAvatarSession("mock-session-token");
        yield session.start();
        yield session.keepAlive();
        expect(fetch).toHaveBeenCalledWith(`${API_URL}/v1/sessions/keep-alive`, {
            method: "POST",
            headers: {
                Authorization: "Bearer mock-session-token",
                "Content-Type": "application/json",
            },
            credentials: "include",
        });
    }));
});
describe("LiveAvatarSession command events", () => {
    const commandEvents = {
        message: {
            event_type: CommandEventsEnum.AVATAR_SPEAK_RESPONSE,
            text: "test",
        },
        repeat: {
            event_type: CommandEventsEnum.AVATAR_SPEAK_TEXT,
            text: "test",
        },
        startListening: {
            event_type: CommandEventsEnum.AVATAR_START_LISTENING,
        },
        stopListening: {
            event_type: CommandEventsEnum.AVATAR_STOP_LISTENING,
        },
        interrupt: {
            event_type: CommandEventsEnum.AVATAR_INTERRUPT,
        },
    };
    Object.entries(commandEvents).forEach(([key, event]) => {
        it(`sends ${key} command event via livekit data channel`, () => __awaiter(void 0, void 0, void 0, function* () {
            const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
            yield session.start();
            if (key === "message" || key === "repeat") {
                session[key](event.text);
            }
            else {
                session[key]();
            }
            const participant = testContext.roomInstance.localParticipant;
            expect(participant.publishData).toHaveBeenCalledWith(expect.any(Uint8Array), {
                reliable: true,
                topic: LIVEKIT_COMMAND_CHANNEL_TOPIC,
            });
            const publishedData = participant.publishData.mock.calls[0][0];
            const parsed = JSON.parse(new TextDecoder().decode(publishedData));
            expect(parsed).toMatchObject(event);
            if (key !== "interrupt") {
                expect(parsed.event_id).toEqual(expect.any(String));
            }
        }));
    });
    const commandEventsToWebSocket = {
        interrupt: {
            type: "agent.interrupt",
        },
        startListening: {
            type: "agent.start_listening",
        },
        stopListening: {
            type: "agent.stop_listening",
        },
    };
    Object.entries(commandEventsToWebSocket).forEach(([key, event]) => {
        it(`sends ${key} command event via web socket`, () => __awaiter(void 0, void 0, void 0, function* () {
            mockWebSocket();
            const session = setupLiveAvatarSession({
                sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
            });
            yield session.start();
            session[key]();
            const sendData = testContext.wsInstance.send.mock.calls[0][0];
            const parsedSendData = JSON.parse(sendData);
            expect(parsedSendData.type).toEqual(event.type);
        }));
    });
    it("sends speak audio command event via web socket", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
        });
        yield session.start();
        session.repeatAudio("test");
        const sendData = testContext.wsInstance.send.mock.calls[0][0];
        const parsedSendData = JSON.parse(sendData);
        expect(parsedSendData.type).toEqual("agent.speak");
        const lastEvent = testContext.wsInstance.send.mock.calls[testContext.wsInstance.send.mock.calls.length - 1][0];
        const parsedLastEvent = JSON.parse(lastEvent);
        expect(parsedLastEvent.type).toEqual("agent.speak_end");
    }));
    it("does not send unsopported command event via web socket", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
        });
        yield session.start();
        session.message("test");
        expect(testContext.wsInstance.send).not.toHaveBeenCalled();
    }));
    it("does not send command event when the session is not started", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
        expect(() => session.message("test")).toThrow();
        expect(() => session.repeat("test")).toThrow();
        expect(() => session.startListening()).toThrow();
        expect(() => session.stopListening()).toThrow();
        expect(() => session.interrupt()).toThrow();
        expect(testContext.roomInstance.localParticipant.publishData).not.toHaveBeenCalled();
    }));
});
describe("LiveAvatarSession server events", () => {
    it("emits avatar speak ended and speak started web socket event", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const onSpeakStarted = vi.fn();
        const onSpeakEnded = vi.fn();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
        });
        session.on(AgentEventsEnum.AVATAR_SPEAK_STARTED, onSpeakStarted);
        session.on(AgentEventsEnum.AVATAR_SPEAK_ENDED, onSpeakEnded);
        yield session.start();
        testContext.wsInstance._triggerMessage({
            type: "agent.speak_started",
            event_id: "mock-event-id",
        });
        expect(onSpeakStarted).toHaveBeenCalledWith({
            event_type: AgentEventsEnum.AVATAR_SPEAK_STARTED,
            event_id: "mock-event-id",
        });
        testContext.wsInstance._triggerMessage({
            type: "agent.speak_ended",
            event_id: "mock-event-id",
        });
        expect(onSpeakEnded).toHaveBeenCalledWith({
            event_type: AgentEventsEnum.AVATAR_SPEAK_ENDED,
            event_id: "mock-event-id",
        });
    }));
    it("does not emit unsupported socket event", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
        });
        yield session.start();
        session.emit = vi.fn();
        testContext.wsInstance._triggerMessage({
            type: "unsupported-event",
            event_id: "mock-event-id",
        });
        expect(session.emit).not.toHaveBeenCalled();
    }));
    it("subscribes to track publications and emits session stream ready event", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({
            sessionInfo: sessionInfoMock,
        });
        const onSessionStreamReady = vi.fn();
        session.on(SessionEvent.SESSION_STREAM_READY, onSessionStreamReady);
        yield session.start();
        testContext.roomInstance._triggerTrackSubscribed("video");
        testContext.roomInstance._triggerTrackSubscribed("audio");
        expect(onSessionStreamReady).toHaveBeenCalledWith();
    }));
    const livekitDataChannelEvents = {
        [AgentEventsEnum.USER_SPEAK_ENDED]: {
            event_type: AgentEventsEnum.USER_SPEAK_ENDED,
        },
        [AgentEventsEnum.USER_TRANSCRIPTION]: {
            event_type: AgentEventsEnum.USER_TRANSCRIPTION,
            text: "test",
        },
        [AgentEventsEnum.AVATAR_TRANSCRIPTION]: {
            event_type: AgentEventsEnum.AVATAR_TRANSCRIPTION,
            text: "test",
        },
        [AgentEventsEnum.AVATAR_SPEAK_STARTED]: {
            event_type: AgentEventsEnum.AVATAR_SPEAK_STARTED,
        },
        [AgentEventsEnum.AVATAR_SPEAK_ENDED]: {
            event_type: AgentEventsEnum.AVATAR_SPEAK_ENDED,
        },
    };
    for (const [key, event] of Object.entries(livekitDataChannelEvents)) {
        it(`emits ${key} livekit data channel event`, () => __awaiter(void 0, void 0, void 0, function* () {
            const session = setupLiveAvatarSession({
                sessionInfo: sessionInfoMock,
            });
            const onEvent = vi.fn();
            session.on(key, onEvent);
            yield session.start();
            testContext.roomInstance._triggerDataReceived(event);
            expect(onEvent).toHaveBeenCalled();
        }));
    }
    it("does not emit unsupported livekit data channel event", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({
            sessionInfo: sessionInfoMock,
        });
        yield session.start();
        session.emit = vi.fn();
        testContext.roomInstance._triggerDataReceived({
            event_type: "unsupported-event",
        });
        testContext.roomInstance._triggerDataReceived({});
        expect(session.emit).not.toHaveBeenCalled();
    }));
});
describe("LiveAvatarSession stop", () => {
    it("stops and cleans up the session", () => __awaiter(void 0, void 0, void 0, function* () {
        mockWebSocket();
        const session = setupLiveAvatarSession({
            sessionInfo: Object.assign(Object.assign({}, sessionInfoMock), { ws_url: "mock-websocket-url" }),
            sessionConfig: { voiceChat: true },
        });
        const onConnectionQualityChanged = vi.fn();
        session.on(SessionEvent.SESSION_CONNECTION_QUALITY_CHANGED, onConnectionQualityChanged);
        const onVoiceChatStateChanged = vi.fn();
        session.voiceChat.on(VoiceChatEvent.STATE_CHANGED, onVoiceChatStateChanged);
        yield session.start();
        yield session.stop();
        expect(session.state).toBe(SessionState.DISCONNECTED);
        expect(session.voiceChat.state).toBe(VoiceChatState.INACTIVE);
        expect(onConnectionQualityChanged).toHaveBeenCalledWith(ConnectionQuality.UNKNOWN);
        expect(onVoiceChatStateChanged).toHaveBeenCalledWith(VoiceChatState.INACTIVE);
        expect(testContext.roomInstance.disconnect).toHaveBeenCalled();
        expect(testContext.wsInstance.close).toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledWith(`${API_URL}/v1/sessions/stop`, {
            method: "POST",
            headers: {
                Authorization: "Bearer mock-session-token",
                "Content-Type": "application/json",
            },
            credentials: "include",
        });
    }));
});
describe("LiveAvatarSession connection quality", () => {
    it("emits connection quality changed event", () => __awaiter(void 0, void 0, void 0, function* () {
        const session = setupLiveAvatarSession({ sessionInfo: sessionInfoMock });
        const onConnectionQualityChanged = vi.fn();
        session.on(SessionEvent.SESSION_CONNECTION_QUALITY_CHANGED, onConnectionQualityChanged);
        yield session.start();
        testContext.roomInstance._triggerConnectionQualityChanged(LiveKitConnectionQuality.Good);
        expect(session.connectionQuality).toBe(ConnectionQuality.GOOD);
        expect(onConnectionQualityChanged).toHaveBeenCalledWith(ConnectionQuality.GOOD);
    }));
});
describe("LiveAvatarSession custom url", () => {
    it("starts the session using custom API url", () => __awaiter(void 0, void 0, void 0, function* () {
        const URL = "https://test.com";
        mockFetch({
            url: `${URL}/v1/sessions/start`,
            method: "POST",
            response: {
                code: 1000,
                data: sessionInfoMock,
            },
        }, {
            url: `${URL}/v1/sessions/stop`,
            method: "POST",
            response: {
                code: 1000,
            },
        }, {
            url: `${URL}/v1/sessions/keep-alive`,
            method: "POST",
            response: {
                code: 1000,
            },
        });
        const session = new LiveAvatarSession("mock-session-token", {
            apiUrl: URL,
        });
        yield session.start();
        expect(fetch).toHaveBeenCalledWith(`${URL}/v1/sessions/start`, expect.any(Object));
        yield session.keepAlive();
        expect(fetch).toHaveBeenCalledWith(`${URL}/v1/sessions/keep-alive`, expect.any(Object));
        yield session.stop();
        expect(fetch).toHaveBeenCalledWith(`${URL}/v1/sessions/stop`, expect.any(Object));
    }));
});
