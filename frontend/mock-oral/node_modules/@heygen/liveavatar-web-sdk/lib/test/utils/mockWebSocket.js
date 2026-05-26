import { testContext } from "./testContext";
import { vi } from "vitest";
class WebSocketMock extends EventTarget {
    constructor(url, protocols, config = {}) {
        super();
        this.send = vi.fn((data) => {
            if (this.config.onSend) {
                this.config.onSend(this, data);
            }
        });
        this.close = vi.fn(() => {
            this.readyState = global.WebSocket.CLOSED;
            if (this.config.onclose) {
                this.config.onclose(this);
            }
        });
        this.url = url;
        this.protocols = protocols;
        this.readyState = global.WebSocket.CONNECTING;
        this.config = config;
        setTimeout(() => {
            this._triggerOpen();
        }, 100);
    }
    _triggerOpen() {
        this.readyState = global.WebSocket.OPEN;
        this.dispatchEvent(new Event("open"));
    }
    _triggerMessage(data) {
        this.dispatchEvent(new MessageEvent("message", { data: JSON.stringify(data) }));
    }
    _triggerError(error) {
        this.dispatchEvent(new Event("error", { error }));
    }
    _triggerClose(event) {
        this.readyState = global.WebSocket.CLOSED;
        this.dispatchEvent(new Event("close", {
            code: event.code,
            reason: event.reason,
        }));
    }
}
WebSocketMock.CONNECTING = 0;
WebSocketMock.OPEN = 1;
WebSocketMock.CLOSING = 2;
WebSocketMock.CLOSED = 3;
export const mockWebSocket = (config) => {
    var _a, _b, _c, _d;
    class MockWS extends WebSocketMock {
        constructor(url, protocols) {
            super(url, protocols, config);
            testContext.wsInstance = this;
        }
    }
    // copy WebSocket readyState constants
    MockWS.CONNECTING = (_a = WebSocketMock.CONNECTING) !== null && _a !== void 0 ? _a : 0;
    MockWS.OPEN = (_b = WebSocketMock.OPEN) !== null && _b !== void 0 ? _b : 1;
    MockWS.CLOSING = (_c = WebSocketMock.CLOSING) !== null && _c !== void 0 ? _c : 2;
    MockWS.CLOSED = (_d = WebSocketMock.CLOSED) !== null && _d !== void 0 ? _d : 3;
    Object.defineProperty(globalThis, "WebSocket", {
        configurable: true,
        writable: true,
        value: MockWS,
    });
};
