import SwiftUI

@main
struct IPCARecorderApp: App {
    @StateObject private var settings = SettingsStore()
    @StateObject private var recordingStore = RecordingStore()
    @StateObject private var audioRecorder = AudioRecorderManager()
    @StateObject private var uploadManager = UploadManager()
    @StateObject private var ahrsBLE = AHRSBLEManager()
    @StateObject private var gps = GPSLocationManager()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(settings)
                .environmentObject(recordingStore)
                .environmentObject(audioRecorder)
                .environmentObject(uploadManager)
                .environmentObject(ahrsBLE)
                .environmentObject(gps)
                .task {
                    await recordingStore.load()
                    ahrsBLE.start()
                    gps.prepare()
                }
        }
    }
}
