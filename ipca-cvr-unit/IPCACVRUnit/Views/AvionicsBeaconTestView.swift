import SwiftUI
import UIKit

struct AvionicsBeaconTestView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var manager: AvionicsBeaconManager
    @State private var scanAllMode = false
    @State private var exportURLs: [URL] = []
    @State private var isShowingShareSheet = false
    @State private var exportError = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    header
                    statusBanner

                    controls
                    liveStatus
                    recentLog
                    helpSection
                }
                .padding(16)
            }
            .cvrAdminScreenChrome(title: "Avionics Beacon Test")
            .sheet(isPresented: $isShowingShareSheet) {
                BeaconActivityView(activityItems: exportURLs)
            }
        }
    }

    private var header: some View {
        HStack {
            VStack(alignment: .leading, spacing: 5) {
                Text("Avionics Beacon Test")
                    .font(.largeTitle.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                Text("Foreground BLE diagnostic for the avionics-power beacon")
                    .font(.headline)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
            Spacer()
            Image(systemName: "antenna.radiowaves.left.and.right")
                .font(.system(size: 30, weight: .semibold))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .padding(18)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private var statusBanner: some View {
        VStack(spacing: 6) {
            Text(bannerText)
                .font(.title.weight(.black))
            Text("Service UUID \(AvionicsBeaconManager.serviceUUID.uuidString)")
                .font(.caption.weight(.semibold))
        }
        .foregroundStyle(.white)
        .frame(maxWidth: .infinity)
        .padding(.vertical, 16)
        .background(bannerColor, in: RoundedRectangle(cornerRadius: 20))
        .shadow(color: bannerColor.opacity(0.25), radius: 12, y: 6)
    }

    private var controls: some View {
        IPCACard(title: "Test Controls", systemImage: "switch.2") {
            VStack(alignment: .leading, spacing: 12) {
                Toggle("Scan All debug mode", isOn: $scanAllMode)
                    .disabled(settings.isBeaconTriggerEnabled)
                Text("Normal mode scans only for the custom avionics beacon service. Scan All is only for troubleshooting advertisements and still does not connect to any device.")
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.textSecondary)

                LazyVGrid(columns: [GridItem(.adaptive(minimum: 118), spacing: 8)], alignment: .leading, spacing: 8) {
                    Button("Start Scan") {
                        manager.startScan(scanAll: scanAllMode)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(manager.isScanning)

                    Button("Stop Scan") {
                        manager.stopScan()
                    }
                    .buttonStyle(.bordered)
                    .disabled(!manager.isScanning || settings.isBeaconTriggerEnabled)

                    Button("Clear Log") {
                        manager.clearLog()
                    }
                    .buttonStyle(.bordered)

                    Button("Export Log") {
                        exportLog()
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(manager.logEntries.isEmpty)
                }

                Divider()

                VStack(alignment: .leading, spacing: 8) {
                    Text("Beacon Pairing")
                        .font(.headline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textPrimary)

                    LabeledContent("Paired beacon", value: emptyDash(settings.expectedBeaconIdentityHex))
                    LabeledContent("Connected beacon", value: emptyDash(manager.latestStatus?.beaconIdentityHex ?? ""))

                    if !manager.lastIgnoredBeaconIdentityHex.isEmpty {
                        Text("Ignored unpaired beacon \(manager.lastIgnoredBeaconIdentityHex).")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.warning)
                    }

                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 138), spacing: 8)], alignment: .leading, spacing: 8) {
                        Button("Pair Current Beacon") {
                            pairCurrentBeacon()
                        }
                        .buttonStyle(.borderedProminent)
                        .disabled(manager.latestStatus?.beaconIdentityHex == nil)

                        Button("Clear Pairing") {
                            settings.expectedBeaconIdentityHex = ""
                        }
                        .buttonStyle(.bordered)
                        .disabled(settings.expectedBeaconIdentityHex.isEmpty)
                    }

                    Text("When a paired identity is set, only that beacon can trigger recording. Other IPCA beacons are visible in diagnostics but ignored for recording.")
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }

                LazyVGrid(columns: [GridItem(.adaptive(minimum: 118), spacing: 8)], alignment: .leading, spacing: 8) {
                    Button("Mark \"Power On\"") {
                        manager.mark("Power On")
                    }
                    .buttonStyle(.bordered)

                    Button("Mark \"Power Off\"") {
                        manager.mark("Power Off")
                    }
                    .buttonStyle(.bordered)
                }

                LabeledContent("Active marker", value: manager.activeMarker)

                if settings.isBeaconTriggerEnabled {
                    Text("Production beacon trigger is connected. This screen is observing the active listener.")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.success)
                }

                if !exportError.isEmpty {
                    Text(exportError)
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.critical)
                }
                if !manager.lastError.isEmpty {
                    Text(manager.lastError)
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.critical)
                }
            }
        }
    }

    private var liveStatus: some View {
        IPCACard(title: "Live Beacon Status", systemImage: "dot.radiowaves.left.and.right") {
            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], alignment: .leading, spacing: 10) {
                statusRow("Bluetooth authorization", manager.bluetoothAuthorization, color: bluetoothColor)
                statusRow("Central manager state", manager.centralState, color: manager.centralState == "Powered On" ? CVROperationalPalette.success : CVROperationalPalette.critical)
                statusRow("Scan", manager.isScanning ? "Active" : "Inactive", color: manager.isScanning ? CVROperationalPalette.success : CVROperationalPalette.textSecondary)
                statusRow("Scan mode", manager.scanAllMode ? "Scan All" : "Service UUID only", color: manager.scanAllMode ? CVROperationalPalette.warning : CVROperationalPalette.success)
                statusRow("Beacon detected", manager.beaconDetected ? "true" : "false", color: manager.beaconDetected ? CVROperationalPalette.success : CVROperationalPalette.textSecondary)
                statusRow("Interpreted state", manager.currentState.label, color: bannerColor)
                statusRow("First seen", timestamp(manager.firstSeenAt))
                statusRow("Last seen", timestamp(manager.lastSeenAt))
                statusRow("Seconds since last ad", seconds(manager.secondsSinceLastAdvertisement), color: ageColor)
                statusRow("Current RSSI", rssi(manager.currentRSSI))
                statusRow("Strongest RSSI", rssi(manager.strongestRSSI))
                statusRow("Weakest RSSI", rssi(manager.weakestRSSI))
                statusRow("Advertisements", "\(manager.advertisementCount)")
                statusRow("Advertised local name", emptyDash(manager.advertisedLocalName))
                statusRow("Service UUIDs", manager.advertisedServiceUUIDs.isEmpty ? "--" : manager.advertisedServiceUUIDs.joined(separator: ", "))
                statusRow("Manufacturer data", emptyDash(manager.manufacturerDataHex))
                statusRow("Paired beacon identity", emptyDash(settings.expectedBeaconIdentityHex), color: settings.expectedBeaconIdentityHex.isEmpty ? CVROperationalPalette.warning : CVROperationalPalette.success)
                statusRow("Connected beacon identity", emptyDash(manager.latestStatus?.beaconIdentityHex ?? ""), color: connectedBeaconColor)
                statusRow("Last ignored beacon", emptyDash(manager.lastIgnoredBeaconIdentityHex), color: manager.lastIgnoredBeaconIdentityHex.isEmpty ? CVROperationalPalette.textSecondary : CVROperationalPalette.warning)
            }
        }
    }

    private var recentLog: some View {
        IPCACard(title: "Recent Beacon Log", systemImage: "doc.text.magnifyingglass") {
            if manager.logEntries.isEmpty {
                Text("No log entries yet.")
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            } else {
                VStack(alignment: .leading, spacing: 8) {
                    ForEach(manager.logEntries.suffix(10).reversed()) { entry in
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(entry.kind.rawValue.uppercased()) - \(entry.marker)")
                                .font(.caption.weight(.bold))
                            Text(logSummary(entry))
                                .font(.caption)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                                .textSelection(.enabled)
                        }
                    }
                }
            }
        }
    }

    private var helpSection: some View {
        IPCACard(title: "Test Procedure", systemImage: "questionmark.circle") {
            VStack(alignment: .leading, spacing: 8) {
                Text("Test A: Aircraft USB power off. Start scan and confirm state remains UNKNOWN or AVIONICS OFF after the timeout.")
                Text("Test B: Turn aircraft USB power on. Confirm the assigned beacon boots, at least one matching advertisement arrives, and state changes to AVIONICS ON.")
                Text("Test C: Leave power on for at least 5 minutes. Confirm advertisements continue arriving and there are no false OFF transitions.")
                Text("Test D: Turn aircraft USB power off. Confirm temporary missing after 5 seconds and AVIONICS OFF after 15 seconds.")
                Text("Test E: Rapidly cycle power off/on. Confirm the app recognizes the same beacon identity after reboot and does not rely on the iOS peripheral identifier.")
                Text("When Admin > Connect Beacon is enabled, this beacon state starts and stops the CVR recording.")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
            .font(.subheadline)
        }
    }

    private var bannerText: String {
        switch manager.currentState {
        case .avionicsOn:
            return "AVIONICS ON"
        case .avionicsOff:
            return "AVIONICS OFF"
        case .bluetoothUnavailable:
            return "UNKNOWN / BLUETOOTH UNAVAILABLE"
        case .temporarilyMissing:
            return "BEACON TEMPORARILY MISSING"
        case .candidateOn:
            return "BEACON CANDIDATE"
        case .scanning:
            return "SCANNING"
        case .unknown:
            return "UNKNOWN"
        }
    }

    private var bannerColor: Color {
        switch manager.currentState {
        case .avionicsOn:
            return CVROperationalPalette.success
        case .avionicsOff:
            return CVROperationalPalette.critical
        case .temporarilyMissing, .candidateOn, .scanning:
            return CVROperationalPalette.warning
        case .bluetoothUnavailable, .unknown:
            return CVROperationalPalette.textSecondary
        }
    }

    private var bluetoothColor: Color {
        manager.bluetoothAuthorization == "Allowed Always" ? CVROperationalPalette.success : CVROperationalPalette.warning
    }

    private var connectedBeaconColor: Color {
        guard let identity = manager.latestStatus?.beaconIdentityHex, !identity.isEmpty else {
            return CVROperationalPalette.textSecondary
        }
        if settings.expectedBeaconIdentityHex.isEmpty || settings.expectedBeaconIdentityHex == identity {
            return CVROperationalPalette.success
        }
        return CVROperationalPalette.warning
    }

    private var ageColor: Color {
        guard let seconds = manager.secondsSinceLastAdvertisement else {
            return CVROperationalPalette.textSecondary
        }
        if seconds > AvionicsBeaconManager.offConfirmationAfter {
            return CVROperationalPalette.critical
        }
        if seconds > AvionicsBeaconManager.temporarilyMissingAfter {
            return CVROperationalPalette.warning
        }
        return CVROperationalPalette.success
    }

    private func statusRow(_ label: String, _ value: String, color: Color = CVROperationalPalette.textPrimary) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(label)
                .font(.caption)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(color)
                .textSelection(.enabled)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func timestamp(_ date: Date?) -> String {
        guard let date else { return "--" }
        return Self.timeFormatter.string(from: date)
    }

    private func seconds(_ value: TimeInterval?) -> String {
        guard let value else { return "--" }
        return String(format: "%.1f s", value)
    }

    private func rssi(_ value: Int?) -> String {
        guard let value else { return "--" }
        return "\(value) dBm"
    }

    private func emptyDash(_ value: String) -> String {
        value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? "--" : value
    }

    private func pairCurrentBeacon() {
        guard let identity = manager.latestStatus?.beaconIdentityHex else { return }
        settings.expectedBeaconIdentityHex = identity
    }

    private func logSummary(_ entry: AvionicsBeaconLogEntry) -> String {
        if let event = entry.event {
            return event
        }
        let name = entry.advertisedLocalName ?? entry.peripheralName ?? "unnamed"
        let rssiText = entry.rssi.map { "\($0) dBm" } ?? "--"
        let matched = entry.matchedCustomService == true ? "target service" : "non-target"
        return "\(name), \(rssiText), \(matched), services: \(entry.advertisedServiceUUIDs.joined(separator: " "))"
    }

    private func exportLog() {
        do {
            exportURLs = try AvionicsBeaconExportService.exportFiles(entries: manager.logEntries)
            exportError = ""
            isShowingShareSheet = true
        } catch {
            exportError = error.localizedDescription
        }
    }

    private static let timeFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss"
        return formatter
    }()
}

private struct BeaconActivityView: UIViewControllerRepresentable {
    var activityItems: [Any]

    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }

    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
