import SwiftUI

@main
struct IPCARecorderApp: App {
    @Environment(\.scenePhase) private var scenePhase
    @StateObject private var settings = SettingsStore()
    @StateObject private var recordingStore = RecordingStore()
    @StateObject private var audioRecorder = AudioRecorderManager()
    @StateObject private var uploadManager = UploadManager()
    @StateObject private var ahrsBLE = AHRSBLEManager()
    @StateObject private var gps = GPSLocationManager()
    @State private var pendingNavigationRecordingID: String?

    var body: some Scene {
        WindowGroup {
            ContentView(pendingNavigationRecordingID: $pendingNavigationRecordingID)
                .environmentObject(settings)
                .environmentObject(recordingStore)
                .environmentObject(audioRecorder)
                .environmentObject(uploadManager)
                .environmentObject(ahrsBLE)
                .environmentObject(gps)
                .preferredColorScheme(.light)
                .task {
                    await recordingStore.load()
                    ahrsBLE.start()
                    gps.prepare()
                    handlePendingG3XWorkflow()
                }
                .onOpenURL { url in
                    Task { @MainActor in
                        handleIncomingURL(url)
                    }
                }
                .onChange(of: scenePhase) { _, phase in
                    switch phase {
                    case .background:
                        audioRecorder.appDidEnterBackground()
                    case .active:
                        audioRecorder.appWillEnterForeground()
                        handlePendingG3XWorkflow()
                    default:
                        break
                    }
                }
        }
    }

    @MainActor
    private func handlePendingG3XWorkflow() {
        recordingStore.syncSharedRecordingIndex()
        recordingStore.processPendingG3XImports()
        uploadManager.syncPendingG3XUploads(store: recordingStore, settings: settings)
        uploadManager.syncPendingServerG3XImports(store: recordingStore, settings: settings)
        guard settings.isServerURLConfigured else { return }
        for recording in recordingStore.recordings where recording.hasG3XData && recording.needsUploadRetry {
            uploadManager.upload(recordingID: recording.id, store: recordingStore, settings: settings)
        }
    }

    @MainActor
    private func handleIncomingURL(_ url: URL) {
        guard url.scheme?.lowercased() == "ipcarecorder" else { return }

        if url.host?.lowercased() == "import-g3x" {
            handlePendingG3XWorkflow()
            if let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
               let recordingID = components.queryItems?.first(where: { $0.name == "recording" })?.value,
               recordingStore.recording(id: recordingID) != nil {
                pendingNavigationRecordingID = recordingID
            }
            return
        }

        if url.isFileURL || url.pathExtension.lowercased() == "csv" {
            do {
                let recordingID = try G3XDocumentImporter.importFile(url, store: recordingStore)
                handlePendingG3XWorkflow()
                pendingNavigationRecordingID = recordingID
            } catch {
                print("G3X document import failed: \(error)")
            }
        }
    }
}
