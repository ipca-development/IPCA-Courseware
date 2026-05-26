import { testContext } from "../utils/testContext";
export class WebRTCIssueDetectorMock {
    constructor(params) {
        this.onNetworkScoresUpdated = params.onNetworkScoresUpdated;
        testContext.webRTCIssueDetectorInstance = this;
    }
    handleNewPeerConnection(_pc) { }
    stopWatchingNewPeerConnections() { }
    _triggerNetworkScoresUpdated(scores) {
        if (this.onNetworkScoresUpdated) {
            this.onNetworkScoresUpdated(scores);
        }
    }
}
