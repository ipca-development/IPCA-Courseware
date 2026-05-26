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
import { RoomMock, LocalParticipantMock, createLocalAudioTrack, } from "./mocks/Livekit";
import { WebRTCIssueDetectorMock } from "./mocks/WebRTCIssueDetector";
vi.doMock("livekit-client", (orig) => __awaiter(void 0, void 0, void 0, function* () {
    const mod = yield orig();
    return Object.assign(Object.assign({}, mod), { Room: RoomMock, LocalParticipant: LocalParticipantMock, supportsAdaptiveStream: () => false, supportsDynacast: () => false, createLocalAudioTrack: createLocalAudioTrack });
}));
vi.doMock("webrtc-issue-detector", (orig) => __awaiter(void 0, void 0, void 0, function* () {
    const mod = yield orig();
    return Object.assign(Object.assign({}, mod), { default: WebRTCIssueDetectorMock });
}));
Object.defineProperty(globalThis, "MediaStream", {
    value: class MediaStream {
        constructor() {
            this.tracks = [];
            this.tracks = [];
        }
        addTrack(track) {
            this.tracks.push(track);
        }
        removeTrack(track) {
            this.tracks = this.tracks.filter((t) => t !== track);
        }
        getTracks() {
            return this.tracks;
        }
        getAudioTracks() {
            return this.tracks.filter((t) => t.kind === "audio");
        }
        getVideoTracks() {
            return this.tracks.filter((t) => t.kind === "video");
        }
    },
});
// Object.defineProperty(globalThis, 'navigator', {
//   value: {
//     mediaDevices: {
//       getUserMedia: vi.fn(async (constraints: MediaStreamConstraints) => {
//         return new Promise((resolve, reject) => {
//           resolve(new MediaStream());
//         });
//       }),
//     },
//   },
// });
