import XCTest
@testable import IPCASyncAgent

final class IPCASyncAgentTests: XCTestCase {
    func testIdempotencyKeyKeepsGpsAndFullSourcesSeparate() {
        let one = DownloadedArtifact(
            provider: "garmin_flygarmin_logbook",
            entryID: "entry-1",
            sourceUUID: "gps-source",
            originalFilename: "gps.csv",
            contentType: "text/csv",
            contentDisposition: nil,
            localPath: "/tmp/gps.csv",
            byteSize: 10,
            sha256: String(repeating: "a", count: 64),
            downloadedAt: Date()
        )
        let two = DownloadedArtifact(
            provider: "garmin_flygarmin_logbook",
            entryID: "entry-1",
            sourceUUID: "full-source",
            originalFilename: "full.csv",
            contentType: "text/csv",
            contentDisposition: nil,
            localPath: "/tmp/full.csv",
            byteSize: 10,
            sha256: String(repeating: "a", count: 64),
            downloadedAt: Date()
        )
        XCTAssertNotEqual(one.idempotencyKey, two.idempotencyKey)
    }

    func testChromeLocatorDoesNotUsePersonalProfilePath() {
        let browser = BrowserSessionController()
        XCTAssertFalse(browser.garminProfileDirectory.path.contains("Google/Chrome/Default"))
        XCTAssertTrue(browser.garminProfileDirectory.path.contains("IPCA Sync Agent/GarminChromeProfile"))
    }
}
