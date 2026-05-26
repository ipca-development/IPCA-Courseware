var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
import { vi } from "vitest";
import { API_URL } from "../../const";
function matchUrl(target, pattern) {
    if (pattern.startsWith("http")) {
        return target === pattern;
    }
    return target === `${API_URL}${pattern}`;
}
export function mockFetch(...configs) {
    const spy = vi
        .spyOn(globalThis, "fetch")
        .mockImplementation((input, init) => __awaiter(this, void 0, void 0, function* () {
        var _a;
        const url = typeof input === "string" ? input : input.toString();
        const method = ((init === null || init === void 0 ? void 0 : init.method) || "GET").toUpperCase();
        const cfg = configs.find((c) => matchUrl(url, c.url) &&
            (c.method ? c.method.toUpperCase() === method : method === "GET"));
        if (!cfg) {
            return Promise.reject(new Error(`Unhandled fetch: ${method} ${url}`));
        }
        const body = cfg.response === undefined ? null : JSON.stringify(cfg.response);
        const res = new Response(body, {
            status: (_a = cfg.status) !== null && _a !== void 0 ? _a : 200,
            headers: Object.assign({ "content-type": "application/json" }, (cfg.headers || {})),
        });
        return res;
    }));
    return () => spy.mockRestore();
}
