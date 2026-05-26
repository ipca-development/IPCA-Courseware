import { SessionInfo } from "./types";
export declare class SessionApiError extends Error {
    errorCode: number;
    status: number | null;
    constructor(message: string, errorCode?: number, status?: number);
}
export declare class SessionAPIClient {
    private readonly sessionToken;
    private readonly apiUrl;
    constructor(sessionToken: string, apiUrl?: string);
    private request;
    startSession(): Promise<SessionInfo>;
    stopSession(): Promise<void>;
    keepAlive(): Promise<void>;
}
