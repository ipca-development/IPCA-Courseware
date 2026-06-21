import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var audio: AudioRecorderManager

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "Settings",
                        subtitle: "Server, aircraft and audio configuration",
                        systemImage: "gearshape.2"
                    )

                    serverCard
                    languageCard
                    aircraftCard
                    debugAudioCard
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Settings")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private var serverCard: some View {
        IPCACard(title: "Server", systemImage: "server.rack") {
                    TextField("Server URL", text: $settings.serverURL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .keyboardType(.URL)
                    Text(settings.serverURLHelp)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    LabeledContent("Normalized server", value: settings.normalizedServerURL?.absoluteString ?? "Invalid")
                    LabeledContent("Upload endpoint", value: settings.uploadEndpointPreview)
                    LabeledContent("Aircraft endpoint", value: settings.aircraftEndpointPreview)
                    if !settings.isServerURLConfigured {
                        Text("Server URL is not configured yet. Use your actual IPCA Courseware domain, not the example placeholder.")
                            .foregroundStyle(IPCATheme.warning)
                    }
        }
    }

    private var languageCard: some View {
        IPCACard(title: "Language", systemImage: "text.bubble") {
                    Picker("Transcript language", selection: $settings.language) {
                        ForEach(settings.supportedLanguages, id: \.code) { language in
                            Text(language.label).tag(language.code)
                        }
                    }
        }
    }

    private var aircraftCard: some View {
        IPCACard(title: "Aircraft", systemImage: "airplane.circle") {
                    Button("Refresh Aircraft") {
                        Task { await settings.refreshAircraft() }
                    }
                    .disabled(!settings.isServerURLConfigured)

                    Picker("Selected aircraft", selection: $settings.selectedAircraftID) {
                        Text("Not selected").tag(0)
                        ForEach(settings.aircraft) { aircraft in
                            Text(aircraft.label).tag(aircraft.id)
                        }
                    }

                    if let aircraft = settings.selectedAircraft {
                        LabeledContent("Registration", value: aircraft.registration)
                        LabeledContent("ADS-B hex", value: aircraft.adsbHex.isEmpty ? "Missing" : aircraft.adsbHex)
                        if !aircraft.homeAirport.isEmpty {
                            LabeledContent("Home airport", value: aircraft.homeAirport)
                        }
                    } else {
                        Text("Select an aircraft before recording to attach the ownship ADS-B hex to the upload.")
                            .foregroundStyle(.secondary)
                    }

                    if !settings.aircraftError.isEmpty {
                        Text(settings.aircraftError).foregroundStyle(IPCATheme.danger)
                    }
        }
    }

    private var debugAudioCard: some View {
        IPCACard(title: "Debug Audio Input List", systemImage: "waveform.circle") {
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
                                if input.id == audio.selectedInputID {
                                    IPCAStatusPill(text: "ACTIVE", color: IPCATheme.brightBlue)
                                }
                                if input.isUSB {
                                    IPCAStatusPill(text: "USB", color: IPCATheme.success)
                                }
                                if input.isBuiltInMic {
                                    IPCAStatusPill(text: "Built-in Mic", color: IPCATheme.warning)
                                }
                            }
                            Text(input.portType)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
        }
    }
}
