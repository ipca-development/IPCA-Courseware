import { LiveAvatarSession } from "./LiveAvatarSession";
import { SessionConfig } from "./types";
import { ElevenLabsAgentCommandPayload } from "./events";
export declare class ElevenLabsAgentSessionError extends Error {
    constructor(message: string);
}
/**
 * Session class for LiveAvatar sessions backed by an ElevenLabs Agent.
 *
 * Inherits the connection lifecycle, voice chat, and inbound event surface
 * from LiveAvatarSession. Adds outbound commands that wrap ElevenLabs
 * client-event types (user_message, contextual_update, user_activity,
 * client_tool_result) and ship them on the LiveKit `agent-control` topic.
 *
 * Refuses to send if the JWT does not indicate an ELEVENLABS agent.
 *
 * NOTE: The non-ElevenLabs LiveAvatar command methods (`message`, `repeat`,
 * `repeatAudio`) are not supported on this path and will throw. In a future
 * iteration we may map these onto ElevenLabs equivalents (e.g. `message` ->
 * `sendUserMessage`) so a developer can swap LiveAvatarSession for
 * ElevenLabsAgentSession with no call-site changes.
 */
export declare class ElevenLabsAgentSession extends LiveAvatarSession {
    constructor(sessionAccessToken: string, config?: SessionConfig);
    /**
     * Low-level: send any whitelisted ElevenLabs agent command. Prefer the
     * named convenience methods below.
     */
    sendElevenLabsAgentCommand(payload: ElevenLabsAgentCommandPayload, options?: {
        sourceEventId?: string;
    }): string;
    /** Send a user message — equivalent to typing in the ElevenLabs widget. */
    sendUserMessage(text: string): string;
    /** Send a contextual update — silent context the agent can use. */
    sendContextualUpdate(text: string): string;
    /** Keepalive / typing-indicator ping. */
    sendUserActivity(): string;
    /** Reply to a client_tool_call inbound event with the tool result. */
    sendClientToolResult(args: {
        toolCallId: string;
        result: string;
        isError?: boolean;
        sourceEventId?: string;
    }): string;
    message(_message: string): string;
    repeat(_message: string): string;
    repeatAudio(_audio: string): string;
}
