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
    @StateObject private var workflowStore = CVRWorkflowStore()
    @StateObject private var missionCatalog = MissionCatalogStore()

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
                .environmentObject(workflowStore)
                .environmentObject(missionCatalog)
                .preferredColorScheme(.light)
                .task {
                    await recordingStore.load()
                    await workflowStore.load()
                    missionCatalog.loadBundledFallback()
                    await audioRecorder.refreshInputs()
                    network.start()
                    systemMonitor.start()
                    gpsManager.prepare()
                    await settings.refreshAircraft()
                    await settings.refreshCrewUsers()
                    await missionCatalog.refreshFromServer(settings: settings)
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflowStore, settings: settings)
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
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflowStore, settings: settings)
                    default:
                        break
                    }
                }
                .onOpenURL { url in
                    workflowStore.importGarminCSV(from: url)
                }
        }
    }
}
