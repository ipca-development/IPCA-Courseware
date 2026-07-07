import UIKit
import SwiftUI
import UniformTypeIdentifiers

final class ShareViewController: UIViewController {
    private var hostingController: UIHostingController<ShareExtensionView>?

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .systemBackground
        loadSharedItem()
    }

    private func loadSharedItem() {
        guard let extensionItem = extensionContext?.inputItems.first as? NSExtensionItem else {
            fail("No shared item was received from Garmin Pilot.")
            return
        }

        let providers = extensionItem.attachments ?? []
        guard let provider = providers.first else {
            fail("Garmin Pilot did not provide a CSV attachment.")
            return
        }

        loadCSV(from: provider)
    }

    private func loadCSV(from provider: NSItemProvider) {
        let csvType = UTType.commaSeparatedText.identifier
        let plainType = UTType.plainText.identifier
        let fileType = UTType.fileURL.identifier

        if provider.hasItemConformingToTypeIdentifier(csvType) {
            provider.loadItem(forTypeIdentifier: csvType) { item, error in
                self.handleLoadedItem(item, error: error)
            }
            return
        }

        if provider.hasItemConformingToTypeIdentifier(fileType) {
            provider.loadItem(forTypeIdentifier: fileType) { item, error in
                self.handleLoadedItem(item, error: error)
            }
            return
        }

        if provider.hasItemConformingToTypeIdentifier(plainType) {
            provider.loadItem(forTypeIdentifier: plainType) { item, error in
                self.handleLoadedItem(item, error: error)
            }
            return
        }

        fail("Garmin Pilot shared an unsupported data type.")
    }

    private func handleLoadedItem(_ item: NSSecureCoding?, error: Error?) {
        DispatchQueue.main.async {
            if let error {
                self.fail(error.localizedDescription)
                return
            }

            do {
                let fileURL = try self.resolveFileURL(from: item)
                let parsed = try G3XFlightStreamParser.parse(fileURL: fileURL)
                let index = SharedRecordingIndexStore.readIndex()
                let candidates = G3XRecordingMatcher.displayCandidates(
                    metadata: parsed.metadata,
                    recordings: index
                )
                self.presentUI(
                    metadata: parsed.metadata,
                    sourceURL: fileURL,
                    candidates: candidates,
                    indexedRecordingCount: index.count
                )
            } catch {
                self.fail(error.localizedDescription)
            }
        }
    }

    private func resolveFileURL(from item: NSSecureCoding?) throws -> URL {
        if let url = item as? URL {
            return url
        }
        if let data = item as? Data, let text = String(data: data, encoding: .utf8) {
            return try writeTemporaryCSV(text)
        }
        if let text = item as? String {
            return try writeTemporaryCSV(text)
        }
        throw G3XParserError.invalidFormat("Could not read the shared Garmin CSV.")
    }

    private func writeTemporaryCSV(_ text: String) throws -> URL {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("garmin-share-\(UUID().uuidString).csv")
        try text.write(to: url, atomically: true, encoding: .utf8)
        return url
    }

    private func presentUI(
        metadata: G3XFlightStreamMetadata,
        sourceURL: URL,
        candidates: [G3XMatchCandidate],
        indexedRecordingCount: Int
    ) {
        hostingController?.willMove(toParent: nil)
        hostingController?.view.removeFromSuperview()
        hostingController?.removeFromParent()

        let view = ShareExtensionView(
            metadata: metadata,
            candidates: candidates,
            indexedRecordingCount: indexedRecordingCount,
            onAttach: { recordingID in
                self.attach(sourceURL: sourceURL, metadata: metadata, recordingID: recordingID)
            },
            onCancel: {
                self.extensionContext?.completeRequest(returningItems: nil)
            }
        )
        let host = UIHostingController(rootView: view)
        addChild(host)
        host.view.translatesAutoresizingMaskIntoConstraints = false
        self.view.addSubview(host.view)
        NSLayoutConstraint.activate([
            host.view.leadingAnchor.constraint(equalTo: self.view.leadingAnchor),
            host.view.trailingAnchor.constraint(equalTo: self.view.trailingAnchor),
            host.view.topAnchor.constraint(equalTo: self.view.topAnchor),
            host.view.bottomAnchor.constraint(equalTo: self.view.bottomAnchor),
        ])
        host.didMove(toParent: self)
        hostingController = host
    }

    private func attach(sourceURL: URL, metadata: G3XFlightStreamMetadata, recordingID: String) {
        guard let importsDir = AppGroupStorage.importsDirectoryURL else {
            fail("App Group storage is unavailable.")
            return
        }

        let importID = UUID().uuidString
        let filename = "\(importID).csv"
        let destination = importsDir.appendingPathComponent(filename)

        do {
            if FileManager.default.fileExists(atPath: destination.path) {
                try FileManager.default.removeItem(at: destination)
            }
            try FileManager.default.copyItem(at: sourceURL, to: destination)

            let pending = PendingG3XImport(
                id: importID,
                createdAt: Date(),
                sourceFilename: sourceURL.lastPathComponent,
                csvRelativePath: filename,
                aircraftIdent: metadata.aircraftIdent,
                startUtc: metadata.startUtc,
                endUtc: metadata.endUtc,
                rowCount: metadata.rowCount,
                importProfile: metadata.importProfile,
                suggestedRecordingID: recordingID,
                matchMethod: candidatesMatchMethod(for: recordingID, metadata: metadata)
            )
            SharedRecordingIndexStore.appendPendingImport(pending)

            if let url = URL(string: "ipcarecorder://import-g3x?recording=\(recordingID)") {
                extensionContext?.open(url) { _ in
                    self.extensionContext?.completeRequest(returningItems: nil)
                }
                return
            }

            extensionContext?.completeRequest(returningItems: nil)
        } catch {
            fail(error.localizedDescription)
        }
    }

    private func candidatesMatchMethod(for recordingID: String, metadata: G3XFlightStreamMetadata) -> String {
        let match = G3XRecordingMatcher.match(
            metadata: metadata,
            recordings: SharedRecordingIndexStore.readIndex()
        )
        if match?.id == recordingID {
            return "auto"
        }
        return "manual"
    }

    private func fail(_ message: String) {
        DispatchQueue.main.async {
            let alert = UIAlertController(title: "Import Failed", message: message, preferredStyle: .alert)
            alert.addAction(UIAlertAction(title: "OK", style: .default) { _ in
                self.extensionContext?.cancelRequest(withError: NSError(domain: "IPCARecorderShare", code: 1, userInfo: [
                    NSLocalizedDescriptionKey: message,
                ]))
            })
            self.present(alert, animated: true)
        }
    }
}
