import { expect } from "vitest";
const store = new Map();
function currentKey() {
    var _a;
    const s = expect.getState();
    if (!s.currentTestName)
        return undefined;
    return `${(_a = s.testPath) !== null && _a !== void 0 ? _a : ""}::${s.currentTestName}`;
}
export const testContext = new Proxy({}, {
    get(_target, prop) {
        const key = currentKey();
        if (!key)
            return null;
        const bag = store.get(key);
        return bag ? bag[prop] : null;
    },
    set(_target, prop, value) {
        var _a;
        const key = currentKey();
        if (!key)
            return false;
        const bag = (_a = store.get(key)) !== null && _a !== void 0 ? _a : {};
        bag[prop] = value;
        store.set(key, bag);
        return true;
    },
});
