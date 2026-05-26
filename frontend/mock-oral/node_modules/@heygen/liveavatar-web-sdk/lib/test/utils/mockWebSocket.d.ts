interface WebSocketConfig {
    onSend?: (ws: globalThis.WebSocket, data: any) => void;
    onclose?: (ws: globalThis.WebSocket) => void;
}
export declare const mockWebSocket: (config?: WebSocketConfig) => void;
export {};
