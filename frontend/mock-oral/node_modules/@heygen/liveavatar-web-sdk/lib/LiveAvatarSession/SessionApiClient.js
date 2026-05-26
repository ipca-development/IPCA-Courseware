var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
import { API_URL } from "../const";
const DEFAULT_ERROR_CODE = 500;
const SUCCESS_CODE = 1000;
export class SessionApiError extends Error {
    constructor(message, errorCode, status) {
        super(message);
        this.status = null;
        this.errorCode = errorCode !== null && errorCode !== void 0 ? errorCode : DEFAULT_ERROR_CODE;
        this.status = status !== null && status !== void 0 ? status : null;
    }
}
export class SessionAPIClient {
    constructor(sessionToken, apiUrl = API_URL) {
        this.sessionToken = sessionToken;
        this.apiUrl = apiUrl !== null && apiUrl !== void 0 ? apiUrl : API_URL;
    }
    request(path, params) {
        return __awaiter(this, void 0, void 0, function* () {
            try {
                const response = yield fetch(`${this.apiUrl}${path}`, Object.assign(Object.assign({}, params), { credentials: "include", headers: Object.assign({ Authorization: `Bearer ${this.sessionToken}`, "Content-Type": "application/json" }, params.headers) }));
                if (!response.ok) {
                    const data = yield response.json();
                    throw new SessionApiError(data.message || `API request failed with status ${response.status}`, data.code, response.status);
                }
                const data = yield response.json();
                if (data.code !== SUCCESS_CODE) {
                    throw new SessionApiError(data.message || "API request failed");
                }
                return data.data;
            }
            catch (err) {
                if (err instanceof SessionApiError) {
                    throw err;
                }
                throw new SessionApiError("API request failed");
            }
        });
    }
    startSession() {
        return __awaiter(this, void 0, void 0, function* () {
            return yield this.request(`/v1/sessions/start`, { method: "POST" });
        });
    }
    stopSession() {
        return __awaiter(this, void 0, void 0, function* () {
            return yield this.request(`/v1/sessions/stop`, { method: "POST" });
        });
    }
    keepAlive() {
        return __awaiter(this, void 0, void 0, function* () {
            return yield this.request(`/v1/sessions/keep-alive`, { method: "POST" });
        });
    }
}
