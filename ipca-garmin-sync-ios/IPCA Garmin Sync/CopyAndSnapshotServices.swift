import Darwin
import Foundation
import OSLog

struct GarminCopyService {
    private let hashService: FileHashService
    private let fileManager: FileManager
    private let logger = Logger(subsystem: "com.ipca.garmin-sync", category: "copy")

    init(hashService: FileHashService = FileHashService(), fileManager: FileManager = .default) {
        self.hashService = hashService
        self.fileManager = fileManager
    }

    func copyAndVerify(
        file: IngestionFile,
        sourceURL: URL,
        privateDirectory: URL,
        store: LocalIngestionStore
    ) async throws -> IngestionFile {
        try fileManager.createDirectory(at: privateDirectory, withIntermediateDirectories: true)
        let freshSourceHash = try hashService.sha256(url: sourceURL)
        guard freshSourceHash == file.sourceHash else {
            throw GarminSyncError.sourceChanged(file.relativePath)
        }

        let partial = privateDirectory.appendingPathComponent("\(file.id.uuidString).partial")
        let destination = privateDirectory.appendingPathComponent("\(file.id.uuidString).csv")
        try? fileManager.removeItem(at: partial)
        try await store.updateState(id: file.id, state: .copying, error: nil)

        do {
            let input = try FileHandle(forReadingFrom: sourceURL)
            defer { try? input.close() }
            fileManager.createFile(atPath: partial.path, contents: nil)
            let output = try FileHandle(forWritingTo: partial)
            do {
                while let data = try input.read(upToCount: 1024 * 1024), !data.isEmpty {
                    try output.write(contentsOf: data)
                }
                try output.synchronize()
                if fsync(output.fileDescriptor) != 0 {
                    throw POSIXError(.EIO)
                }
                try output.close()
            } catch {
                try? output.close()
                throw error
            }

            let attributes = try fileManager.attributesOfItem(atPath: partial.path)
            let copiedSize = (attributes[.size] as? NSNumber)?.int64Value ?? -1
            let destinationHash = try hashService.sha256(url: partial)
            guard copiedSize == file.sourceSize, destinationHash == file.sourceHash else {
                throw GarminSyncError.verificationFailed(file.relativePath)
            }
            try? fileManager.removeItem(at: destination)
            try fileManager.moveItem(at: partial, to: destination)
            try await store.updateState(
                id: file.id,
                state: .localVerified,
                localPath: destination.path,
                localSize: copiedSize,
                destinationHash: destinationHash,
                error: nil
            )
            logger.info("Verified local copy for file \(file.id.uuidString, privacy: .public)")
            return try await store.allFiles().first(where: { $0.id == file.id })!
        } catch {
            try? fileManager.removeItem(at: partial)
            try? await store.updateState(id: file.id, state: .failed, error: error.localizedDescription)
            logger.error("Copy failed for file \(file.id.uuidString, privacy: .public): \(error.localizedDescription, privacy: .public)")
            throw error
        }
    }
}

struct CardSnapshotService {
    private let access: ExternalStorageAccessService
    private let scanner: GarminCardScanner
    private let hashService: FileHashService
    private let copyService: GarminCopyService
    private let store: LocalIngestionStore
    private let privateDirectory: URL
    private let deviceID: String

    init(
        access: ExternalStorageAccessService,
        scanner: GarminCardScanner,
        hashService: FileHashService,
        copyService: GarminCopyService,
        store: LocalIngestionStore,
        privateDirectory: URL,
        deviceID: String = "unknown-device"
    ) {
        self.access = access
        self.scanner = scanner
        self.hashService = hashService
        self.copyService = copyService
        self.store = store
        self.privateDirectory = privateDirectory
        self.deviceID = deviceID
    }

    func capture(
        folder: URL,
        progress: @escaping @Sendable (ScanProgress) -> Void
    ) async throws -> CardSnapshotResult {
        let startedAt = Date()
        let scanned = try access.withAccess(to: folder) {
            try scanner.scan(folder: folder, progress: progress)
        }
        var members: [ScanSnapshotMember] = []
        var capturedFiles: [IngestionFile] = []
        var knownCount = 0
        var copiedCount = 0
        var errorCount = 0

        for (index, scannedFile) in scanned.enumerated() {
            do {
                let source = folder.appendingPathComponent(scannedFile.relativePath)
                progress(.init(
                    phase: .hashing, current: index + 1, total: scanned.count,
                    path: scannedFile.relativePath, filesChecked: index + 1,
                    newFiles: (index - knownCount), copiedFiles: copiedCount, errors: errorCount
                ))
                let hash = try access.withAccess(to: folder) {
                    try hashService.sha256(url: source)
                }
                let discovery = try await store.discoverResult(scannedFile, sourceHash: hash)
                if discovery.wasKnown { knownCount += 1 }
                var file = discovery.file
                if file.state == .discovered || file.state == .failed || file.state == .copying {
                    progress(.init(
                        phase: .copying, current: index + 1, total: scanned.count,
                        path: scannedFile.relativePath, filesChecked: index + 1,
                        newFiles: (index + 1 - knownCount), copiedFiles: copiedCount, errors: errorCount
                    ))
                    file = try await access.withAccess(to: folder) {
                        try await copyService.copyAndVerify(
                            file: file,
                            sourceURL: source,
                            privateDirectory: privateDirectory,
                            store: store
                        )
                    }
                    copiedCount += 1
                }
                capturedFiles.append(file)
                members.append(.init(
                    snapshotID: UUID(),
                    fileID: file.id,
                    relativePath: scannedFile.relativePath,
                    originalFilename: URL(fileURLWithPath: scannedFile.relativePath).lastPathComponent,
                    size: scannedFile.size,
                    modificationDate: scannedFile.modificationDate,
                    sourceHash: hash
                ))
            } catch {
                errorCount += 1
            }
        }

        let safe = errorCount == 0 && capturedFiles.count == scanned.count && capturedFiles.allSatisfy {
            [.localVerified, .waitingForUpload, .uploading, .serverVerified].contains($0.state)
        }
        var snapshotID: UUID?
        if safe {
            let completedID = UUID()
            members = members.map {
                .init(
                    snapshotID: completedID, fileID: $0.fileID, relativePath: $0.relativePath,
                    originalFilename: $0.originalFilename, size: $0.size,
                    modificationDate: $0.modificationDate, sourceHash: $0.sourceHash
                )
            }
            try await store.createCompletedSnapshot(
                id: completedID,
                startedAt: startedAt,
                folderDisplayName: folder.lastPathComponent,
                deviceID: deviceID,
                foundCount: scanned.count,
                previouslyKnownCount: knownCount,
                newlyCopiedCount: copiedCount,
                members: members
            )
            snapshotID = completedID
        }
        return CardSnapshotResult(
            snapshotID: snapshotID,
            files: capturedFiles,
            safeToEject: safe,
            foundCount: scanned.count,
            previouslyKnownCount: knownCount,
            newlyCopiedCount: copiedCount,
            errorCount: errorCount
        )
    }
}
