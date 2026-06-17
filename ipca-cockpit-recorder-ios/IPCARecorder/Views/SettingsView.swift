import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var audio: AudioRecorderManager

    var body: some View {
        NavigationStack {
            Form {
                Section("Server") {
                    TextField("Server URL", text: $settings.serverURL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                    Text("Example: https://courseware.example.com")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                Section("Language") {
                    Picker("Transcript language", selection: $settings.language) {
                        ForEach(settings.supportedLanguages, id: \.code) { language in
                            Text(language.label).tag(language.code)
                        }
                    }
                }

                Section("Debug Audio Input List") {
                    Button("Refresh Inputs") {
                        Task { await audio.refreshInputs() }
                    }

                    if audio.availableInputs.isEmpty {
                        Text("No inputs reported by AVAudioSession.")
                            .foregroundStyle(.secondary)
                    }

                    ForEach(audio.availableInputs) { input in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(input.name).font(.headline)
                                if input.isUSB {
                                    Text("USB").font(.caption).foregroundStyle(.green)
                                }
                                if input.isBuiltInMic {
                                    Text("Built-in Mic").font(.caption).foregroundStyle(.orange)
                                }
                            }
                            Text(input.portType)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
            .navigationTitle("Settings")
        }
    }
}
