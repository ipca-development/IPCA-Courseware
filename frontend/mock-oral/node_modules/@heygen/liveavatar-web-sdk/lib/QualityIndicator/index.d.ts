import { AbstractConnectionQualityIndicator } from "./base";
import { Room } from "livekit-client";
export * from "./types";
export { AbstractConnectionQualityIndicator };
export declare const ConnectionQualityIndicator: new (onConnectionQualityChanged: (quality: import("./types").ConnectionQuality) => void) => AbstractConnectionQualityIndicator<Room>;
