import SwiftUI

struct ContentView: View {
    var body: some View {
        TabView {
            RecorderView()
                .tabItem { Label("Recorder", systemImage: "waveform") }

            RecordingsView()
                .tabItem { Label("Recordings", systemImage: "list.bullet.rectangle") }

            SettingsView()
                .tabItem { Label("Settings", systemImage: "gearshape") }
        }
        .tint(IPCATheme.brightBlue)
    }
}
