import SwiftUI
import UIKit

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
    @StateObject private var scheduledSessions = ScheduledSessionsStore()
    @StateObject private var flightLogs = CVRFlightLogStore()
    @StateObject private var garminVault = GarminCsvVaultStore()
    @StateObject private var sdRecovery = GarminSDCardRecoveryService()
    @StateObject private var garminSync = GarminCsvSyncManager()

    init() {
        let background = UIColor.black
        let selected = UIColor(red: 0.12, green: 0.47, blue: 0.92, alpha: 1)
        let unselected = UIColor.white.withAlphaComponent(0.55)
        let appearance = UITabBarAppearance()
        appearance.configureWithOpaqueBackground()
        appearance.backgroundColor = background
        appearance.shadowColor = UIColor.white.withAlphaComponent(0.08)

        for itemAppearance in [
            appearance.stackedLayoutAppearance,
            appearance.inlineLayoutAppearance,
            appearance.compactInlineLayoutAppearance
        ] {
            itemAppearance.normal.iconColor = unselected
            itemAppearance.normal.titleTextAttributes = [.foregroundColor: unselected]
            itemAppearance.selected.iconColor = selected
            itemAppearance.selected.titleTextAttributes = [.foregroundColor: selected]
        }

        let tabBar = UITabBar.appearance()
        tabBar.standardAppearance = appearance
        tabBar.scrollEdgeAppearance = appearance
        tabBar.isTranslucent = false
        tabBar.tintColor = selected
        tabBar.unselectedItemTintColor = unselected
    }

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
                .environmentObject(scheduledSessions)
                .environmentObject(flightLogs)
                .environmentObject(garminVault)
                .environmentObject(sdRecovery)
                .environmentObject(garminSync)
                .preferredColorScheme(.light)
                .task {
                    await recordingStore.load()
                    await workflowStore.load()
                    recordingStore.repairFlightSessionLinks(workflowStore.recordingSessionFlightRecordLinks())
                    recordingStore.requeueConnectivityFailedUploads()
                    workflowStore.requeueConnectivityFailedUploads()
                    await scheduledSessions.load()
                    scheduledSessions.filterToAircraft(
                        id: settings.selectedAircraft?.id,
                        registration: settings.selectedAircraft?.registration
                    )
                    await garminVault.load()
                    missionCatalog.loadBundledFallback()
                    await audioRecorder.refreshInputs()
                    network.start()
                    uploadManager.configureNetworkMonitor(network)
                    systemMonitor.start()
                    gpsManager.prepare()
                    await settings.refreshAircraft()
                    await settings.refreshCrewUsers()
                    await scheduledSessions.refresh(settings: settings)
                    await flightLogs.refresh(settings: settings)
                    await missionCatalog.refreshFromServer(settings: settings)
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflowStore, settings: settings)
                    sdRecovery.refreshBookmarkState(settings: settings)
                    _ = await sdRecovery.scanAndImportIfNeeded(
                        settings: settings,
                        vault: garminVault,
                        workflow: workflowStore
                    )
                    await garminSync.syncPending(
                        settings: settings,
                        vault: garminVault,
                        workflow: workflowStore,
                        network: network,
                        uploadManager: uploadManager
                    )
                    coordinator.bind(
                        audio: audioRecorder,
                        beacon: beaconManager,
                        gps: gpsManager,
                        network: network,
                        remoteIPads: remoteIPads,
                        store: recordingStore,
                        settings: settings,
                        uploadManager: uploadManager,
                        workflow: workflowStore
                    )
                    coordinator.appBecameActive()
                }
                .onChange(of: scenePhase) {
                    switch scenePhase {
                    case .background:
                        coordinator.appEnteredBackground()
                    case .active:
                        coordinator.appWillEnterForeground()
                        workflowStore.recoverOrphanedUploads(
                            activeComponentIDs: uploadManager.activeWorkflowUploadIDs
                        )
                        if network.canUpload(allowCellular: settings.allowCellularUpload) {
                            recordingStore.repairFlightSessionLinks(workflowStore.recordingSessionFlightRecordLinks())
                            recordingStore.requeueConnectivityFailedUploads()
                            workflowStore.requeueConnectivityFailedUploads()
                            uploadManager.uploadPending(store: recordingStore, settings: settings, network: network)
                        }
                        uploadManager.uploadQueuedWorkflowComponents(
                            workflow: workflowStore,
                            settings: settings,
                            trigger: .appForeground
                        )
                        Task {
                            await scheduledSessions.refresh(settings: settings)
                            await flightLogs.refresh(settings: settings)
                            sdRecovery.refreshBookmarkState(settings: settings)
                            _ = await sdRecovery.scanAndImportIfNeeded(
                                settings: settings,
                                vault: garminVault,
                                workflow: workflowStore
                            )
                            await garminSync.syncPending(
                                settings: settings,
                                vault: garminVault,
                                workflow: workflowStore,
                                network: network,
                                uploadManager: uploadManager
                            )
                        }
                    default:
                        break
                    }
                }
                .onOpenURL { url in
                    if flightLogs.stageGarminCSV(from: url) {
                        workflowStore.selectTab(.log)
                    }
                }
        }
    }
}
