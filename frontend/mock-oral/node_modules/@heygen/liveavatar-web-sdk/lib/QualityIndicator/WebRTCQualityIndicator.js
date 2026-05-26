import WebRTCIssueDetector from "webrtc-issue-detector";
import { AbstractConnectionQualityIndicator } from "./base";
import { ConnectionQuality } from "./types";
export class WebRTCConnectionQualityIndicator extends AbstractConnectionQualityIndicator {
    constructor() {
        super(...arguments);
        this.issueDetector = null;
        this.mosScores = null;
    }
    _start(peerConnection) {
        this.issueDetector = new WebRTCIssueDetector({
            autoAddPeerConnections: false,
            getStatsInterval: 3000,
            onNetworkScoresUpdated: (scores) => {
                this.mosScores = scores;
                this.handleStatsChanged();
            },
        });
        this.issueDetector.handleNewPeerConnection(peerConnection);
    }
    _stop() {
        if (this.issueDetector) {
            this.issueDetector.stopWatchingNewPeerConnections();
            this.issueDetector = null;
        }
        this.mosScores = null;
    }
    calculateConnectionQuality() {
        if (!this.mosScores ||
            (!this.mosScores.inbound && !this.mosScores.outbound)) {
            return ConnectionQuality.UNKNOWN;
        }
        if ((this.mosScores.inbound && this.mosScores.inbound < 3) ||
            (this.mosScores.outbound && this.mosScores.outbound < 3)) {
            return ConnectionQuality.BAD;
        }
        return ConnectionQuality.GOOD;
    }
}
