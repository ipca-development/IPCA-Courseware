import SwiftUI

@main
struct IPCACVRUnitApp: App {
    @Environment(\.scenePhase) private var scenePhase
    @StateObject private var settings = SettingsStore()
    @StateObject private var recordingStore = RecordingStore()
    @StateObject private var audioRecorder = AudioRecorderManager()
    @StateObject private var uploadManager = UploadManager()
    @StateObject private var network = NetworkMonitor()
    @StateObject private var systemMonitor = SystemMonitor()
    @StateObject private var bluetooth = GarminBluetoothMonitor()
    @StateObject private var remoteIPads = RemoteIPadLinkManager()
    @StateObject private var coordinator = CVRUnitCoordinator()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(settings)
                .environmentObject(recordingStore)
                .environmentObject(audioRecorder)
                .environmentObject(uploadManager)
                .environmentObject(network)
                .environmentObject(systemMonitor)
                .environmentObject(bluetooth)
                .environmentObject(remoteIPads)
                .environmentObject(coordinator)
                .preferredColorScheme(.light)
                .task {
                    await recordingStore.load()
                    await audioRecorder.refreshInputs()
                    network.start()
                    systemMonitor.start()
                    bluetooth.start(settings: settings)
                    await settings.refreshAircraft()
                    coordinator.bind(
                        audio: audioRecorder,
                        bluetooth: bluetooth,
                        network: network,
                        remoteIPads: remoteIPads,
                        store: recordingStore,
                        settings: settings,
                        uploadManager: uploadManager
                    )
                    coordinator.appBecameActive()
                }
                .onChange(of: scenePhase) { _, phase in
                    switch phase {
                    case .background:
                        coordinator.appEnteredBackground()
                    case .active:
                        coordinator.appWillEnterForeground()
                    default:
                        break
                    }
                }
        }
    }
}
