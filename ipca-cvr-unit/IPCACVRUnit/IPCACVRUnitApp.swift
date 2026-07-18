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
    @StateObject private var beaconManager = AvionicsBeaconManager()
    @StateObject private var gpsManager = GPSLocationManager()
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
                .environmentObject(beaconManager)
                .environmentObject(gpsManager)
                .environmentObject(remoteIPads)
                .environmentObject(coordinator)
                .preferredColorScheme(.light)
                .task {
                    await recordingStore.load()
                    await audioRecorder.refreshInputs()
                    network.start()
                    systemMonitor.start()
                    gpsManager.prepare()
                    await settings.refreshAircraft()
                    coordinator.bind(
                        audio: audioRecorder,
                        beacon: beaconManager,
                        gps: gpsManager,
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
