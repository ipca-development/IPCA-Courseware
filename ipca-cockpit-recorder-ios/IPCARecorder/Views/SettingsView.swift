import SwiftUI

struct SettingsView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var gps: GPSLocationManager

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
                    appearanceCard
                    languageCard
                    aircraftCard
                    atmosphereCard
                    locationCard
                    debugAudioCard
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Settings")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private var appearanceCard: some View {
        IPCACard(title: "Appearance", systemImage: "app.badge") {
                    Picker("App logo style", selection: $settings.logoStyle) {
                        ForEach(settings.supportedLogoStyles, id: \.code) { style in
                            Text(style.label).tag(style.code)
                        }
                    }

                    HStack(spacing: 14) {
                        IPCALogoMark(compact: true)
                        Text("This logo style is used in the app headers.")
                            .font(.caption)
                            .foregroundStyle(IPCATheme.secondaryText)
                    }
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
                        .foregroundStyle(IPCATheme.secondaryText)
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
                            .foregroundStyle(IPCATheme.secondaryText)
                    }

                    if !settings.aircraftError.isEmpty {
                        Text(settings.aircraftError).foregroundStyle(IPCATheme.danger)
                    }
        }
    }

    private var atmosphereCard: some View {
        IPCACard(title: "ATIS / ASOS", systemImage: "thermometer.sun") {
                    Text("Enter the current field values before recording. They are saved with the recording and used to field-calibrate replay altitude.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)
                    TextField("Altimeter setting / QNH inHg, e.g. 29.92", text: $settings.altimeterSettingInHg)
                        .keyboardType(.decimalPad)
                    TextField("Airport field elevation ft, e.g. 115", text: $settings.airportElevationFt)
                        .keyboardType(.numbersAndPunctuation)
                    TextField("OAT °C, e.g. 35", text: $settings.oatC)
                        .keyboardType(.numbersAndPunctuation)
                    LabeledContent("Altimeter", value: settings.altimeterSettingValue.map { String(format: "%.2f inHg", $0) } ?? "Not logged")
                    LabeledContent("Field elevation", value: settings.airportElevationValue.map { String(format: "%.0f ft", $0) } ?? "Not logged")
                    LabeledContent("OAT", value: settings.oatValue.map { String(format: "%.1f °C", $0) } ?? "Not logged")
        }
    }

    private var locationCard: some View {
        IPCACard(title: "GPS Location", systemImage: "location.circle") {
                    LabeledContent("Permission state", value: gps.state.rawValue)

                    Button("Request GPS Permission") {
                        gps.requestPermission()
                    }

                    Text("The app appears in iPadOS Location Services after it asks for GPS permission at least once.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)

                    if !gps.lastError.isEmpty {
                        Text(gps.lastError).foregroundStyle(IPCATheme.danger)
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
                            .foregroundStyle(IPCATheme.secondaryText)
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
                                if input.isAcceptedExternalInput && !input.isUSB {
                                    IPCAStatusPill(text: "External", color: IPCATheme.success)
                                }
                                if input.isBuiltInMic {
                                    IPCAStatusPill(text: "Built-in Mic", color: IPCATheme.warning)
                                }
                            }
                            Text(input.portType)
                                .font(.caption)
                                .foregroundStyle(IPCATheme.secondaryText)
                        }
                    }
        }
    }
}
