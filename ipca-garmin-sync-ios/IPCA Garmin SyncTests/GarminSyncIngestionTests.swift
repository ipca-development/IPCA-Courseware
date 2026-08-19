import XCTest
@testable import IPCAGarminSync

final class GarminSyncIngestionTests: XCTestCase {
    private struct Harness {
        let root: URL
        let card: URL
        let local: URL
        let store: LocalIngestionStore
        let service: CardSnapshotService
    }

    private func harness() throws -> Harness {
        let root = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        let card = root.appendingPathComponent("CARD")
        let local = root.appendingPathComponent("private")
        try FileManager.default.createDirectory(at: card, withIntermediateDirectories: true)
        let store = try LocalIngestionStore(databaseURL: root.appendingPathComponent("ledger.sqlite"))
        return Harness(
            root: root,
            card: card,
            local: local,
            store: store,
            service: CardSnapshotService(
                access: ExternalStorageAccessService(defaults: UserDefaults(suiteName: UUID().uuidString)!),
                scanner: GarminCardScanner(),
                hashService: FileHashService(),
                copyService: GarminCopyService(),
                store: store,
                privateDirectory: local
            )
        )
    }

    private func write(_ number: Int, to card: URL, content: String? = nil) throws {
        let nested = card.appendingPathComponent(number.isMultiple(of: 2) ? "GARMIN/LOGS" : "GARMIN")
        try FileManager.default.createDirectory(at: nested, withIntermediateDirectories: true)
        try Data((content ?? "fixture-\(number)\n").utf8)
            .write(to: nested.appendingPathComponent(String(format: "%03d.csv", number)))
    }

    func testFirstFiftyAndIdenticalRescanCreateImmutableSnapshotsWithoutDuplicateFiles() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        for number in 1...50 { try write(number, to: h.card) }

        let first = try await h.service.capture(folder: h.card) { _ in }
        let firstSeenFiles = try await h.store.allFiles()
        try await Task.sleep(nanoseconds: 2_000_000)
        let second = try await h.service.capture(folder: h.card) { _ in }
        let files = try await h.store.allFiles()
        let snapshots = try await h.store.snapshots()

        XCTAssertTrue(first.safeToEject)
        XCTAssertTrue(second.safeToEject)
        XCTAssertEqual(first.foundCount, 50)
        XCTAssertEqual(first.previouslyKnownCount, 0)
        XCTAssertEqual(first.newlyCopiedCount, 50)
        XCTAssertEqual(first.errorCount, 0)
        XCTAssertEqual(second.foundCount, 50)
        XCTAssertEqual(second.previouslyKnownCount, 50)
        XCTAssertEqual(second.newlyCopiedCount, 0)
        XCTAssertEqual(second.errorCount, 0)
        XCTAssertEqual(files.count, 50)
        XCTAssertEqual(snapshots.map(\.memberCount), [50, 50])
        XCTAssertEqual(snapshots.map(\.completionStatus), ["COMPLETED", "COMPLETED"])
        XCTAssertTrue(files.allSatisfy { $0.state == .localVerified })
        for file in files {
            let original = firstSeenFiles.first(where: { $0.id == file.id })!
            XCTAssertGreaterThan(file.lastSeen, original.lastSeen)
            XCTAssertEqual(file.firstSeen, original.firstSeen)
        }
    }

    func testRolloverFromOneThroughFiftyToTwoThroughFiftyOne() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        for number in 1...50 { try write(number, to: h.card) }
        _ = try await h.service.capture(folder: h.card) { _ in }
        try FileManager.default.removeItem(at: h.card.appendingPathComponent("GARMIN/001.csv"))
        try write(51, to: h.card)

        let rollover = try await h.service.capture(folder: h.card) { _ in }
        let files = try await h.store.allFiles()
        let snapshots = try await h.store.snapshots()

        XCTAssertEqual(files.count, 51)
        XCTAssertEqual(snapshots.first?.memberCount, 50)
        XCTAssertEqual(rollover.foundCount, 50)
        XCTAssertEqual(rollover.previouslyKnownCount, 49)
        XCTAssertEqual(rollover.newlyCopiedCount, 1)
        XCTAssertEqual(rollover.errorCount, 0)
    }

    func testFilenameReuseWithChangedContentCreatesNewLedgerIdentity() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        try write(7, to: h.card, content: "old-content")
        _ = try await h.service.capture(folder: h.card) { _ in }
        try write(7, to: h.card, content: "new-content")

        _ = try await h.service.capture(folder: h.card) { _ in }

        let files = try await h.store.allFiles()
        XCTAssertEqual(files.count, 2)
        XCTAssertEqual(Set(files.map(\.relativePath)).count, 1)
        XCTAssertEqual(Set(files.map(\.sourceHash)).count, 2)
    }

    func testSameContentUnderDifferentNamesUsesOneHashIdentityButSnapshotsBothPaths() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        let first = h.card.appendingPathComponent("GARMIN/first.csv")
        let second = h.card.appendingPathComponent("GARMIN/LOGS/renamed.csv")
        try FileManager.default.createDirectory(at: first.deletingLastPathComponent(), withIntermediateDirectories: true)
        try FileManager.default.createDirectory(at: second.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Data("identical-content".utf8).write(to: first)
        try Data("identical-content".utf8).write(to: second)

        let result = try await h.service.capture(folder: h.card) { _ in }
        let files = try await h.store.allFiles()
        let snapshots = try await h.store.snapshots()

        XCTAssertTrue(result.safeToEject)
        XCTAssertEqual(result.foundCount, 2)
        XCTAssertEqual(result.previouslyKnownCount, 1)
        XCTAssertEqual(result.newlyCopiedCount, 1)
        XCTAssertEqual(files.count, 1)
        XCTAssertEqual(snapshots.first?.memberCount, 2)
    }

    func testZeroByteCSVNeverProducesSafeToEjectSnapshot() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        let empty = h.card.appendingPathComponent("GARMIN/empty.csv")
        try FileManager.default.createDirectory(at: empty.deletingLastPathComponent(), withIntermediateDirectories: true)
        FileManager.default.createFile(atPath: empty.path, contents: Data())

        do {
            _ = try await h.service.capture(folder: h.card) { _ in }
            XCTFail("Expected zero-byte CSV rejection")
        } catch {
            XCTAssertTrue(error.localizedDescription.contains("verification"))
        }
        let snapshots = try await h.store.snapshots()
        XCTAssertTrue(snapshots.isEmpty)
    }

    func testInterruptedCopyRemovesPartialAndReturnsToDiscovered() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        try write(1, to: h.card)
        let scanned = try GarminCardScanner().scan(folder: h.card)
        let source = h.card.appendingPathComponent(scanned[0].relativePath)
        let file = try await h.store.discover(scanned[0], sourceHash: try FileHashService().sha256(url: source))
        try await h.store.updateState(id: file.id, state: .copying)
        try FileManager.default.createDirectory(at: h.local, withIntermediateDirectories: true)
        let partial = h.local.appendingPathComponent("\(file.id).partial")
        try Data("partial".utf8).write(to: partial)

        try await h.store.recoverInterruptedWork(partialDirectory: h.local)
        let recovered = try await h.store.allFiles().first

        XCTAssertFalse(FileManager.default.fileExists(atPath: partial.path))
        XCTAssertEqual(recovered?.state, .discovered)
    }

    func testOpeningExistingLedgerAndRecoveryPreserveCapturedDataAndPendingUploadIdentity() async throws {
        let h = try harness()
        defer { try? FileManager.default.removeItem(at: h.root) }
        try write(42, to: h.card, content: "irreplaceable-captured-flight-data")
        _ = try await h.service.capture(folder: h.card) { _ in }

        let originalFiles = try await h.store.allFiles()
        let original = try XCTUnwrap(originalFiles.first)
        let localPath = try XCTUnwrap(original.localPath)
        let capturedURL = URL(fileURLWithPath: localPath)
        let capturedBytes = try Data(contentsOf: capturedURL)
        try await h.store.updateState(id: original.id, state: .waitingForUpload)
        let staleContainerPath = h.root
            .appendingPathComponent("Previous-App-Container/Files")
            .appendingPathComponent(capturedURL.lastPathComponent)
            .path
        try await h.store.updateLocalPath(id: original.id, path: staleContainerPath)
        let pendingFiles = try await h.store.allFiles()
        let pending = try XCTUnwrap(pendingFiles.first)
        let snapshotsBefore = try await h.store.snapshots()

        let reopened = try LocalIngestionStore(
            databaseURL: h.root.appendingPathComponent("ledger.sqlite")
        )
        try await reopened.recoverInterruptedWork(partialDirectory: h.local)
        try await reopened.reconcileLocalFilePaths(privateDirectory: h.local)

        let recoveredFiles = try await reopened.allFiles()
        let recovered = try XCTUnwrap(recoveredFiles.first)
        XCTAssertEqual(try Data(contentsOf: capturedURL), capturedBytes)
        XCTAssertEqual(recovered.id, pending.id)
        XCTAssertEqual(recovered.uploadID, pending.uploadID)
        XCTAssertEqual(recovered.state, .waitingForUpload)
        XCTAssertEqual(recovered.uploadedBytes, pending.uploadedBytes)
        XCTAssertEqual(recovered.localPath, localPath)
        let snapshotsAfter = try await reopened.snapshots()
        XCTAssertEqual(snapshotsAfter, snapshotsBefore)
    }
}
