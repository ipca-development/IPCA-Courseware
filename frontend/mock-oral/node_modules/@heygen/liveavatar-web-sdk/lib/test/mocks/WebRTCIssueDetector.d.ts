export type NetworkScores = {
    inbound: number | null;
    outbound: number | null;
};
export declare class WebRTCIssueDetectorMock {
    private onNetworkScoresUpdated?;
    constructor(params: {
        onNetworkScoresUpdated?: (scores: NetworkScores) => void;
    });
    handleNewPeerConnection(_pc: globalThis.RTCPeerConnection): void;
    stopWatchingNewPeerConnections(): void;
    _triggerNetworkScoresUpdated(scores: NetworkScores): void;
}
