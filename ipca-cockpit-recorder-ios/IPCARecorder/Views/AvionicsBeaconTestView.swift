import SwiftUI
import UIKit

struct AvionicsBeaconTestView: View {
    @StateObject private var viewModel = AvionicsBeaconTestViewModel()
    @State private var scanAllMode = false
    @State private var exportURLs: [URL] = []
    @State private var isShowingShareSheet = false
    @State private var exportError = ""

    private var manager: AvionicsBeaconManager {
        viewModel.manager
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "Avionics Beacon Test",
                        subtitle: "Foreground BLE diagnostic for the XIAO ESP32-C3 avionics-power beacon",
                        systemImage: "antenna.radiowaves.left.and.right"
                    )

                    statusBanner
                    controls
                    liveStatus
                    recentLog
                    helpSection
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Avionics Beacon Test")
            .navigationBarTitleDisplayMode(.inline)
            .sheet(isPresented: $isShowingShareSheet) {
                BeaconActivityView(activityItems: exportURLs)
            }
        }
        .onDisappear {
            viewModel.stopScan()
        }
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
        .padding(.vertical, 20)
        .background(bannerColor, in: RoundedRectangle(cornerRadius: 22))
        .shadow(color: bannerColor.opacity(0.25), radius: 16, y: 8)
    }

    private var controls: some View {
        IPCACard(title: "Test Controls", systemImage: "switch.2") {
            VStack(alignment: .leading, spacing: 14) {
                Toggle("Scan All debug mode", isOn: $scanAllMode)
                Text("Normal mode scans only for the custom avionics beacon service. Scan All is only for troubleshooting advertisements and still does not connect to any device.")
                    .font(.caption)
                    .foregroundStyle(IPCATheme.secondaryText)

                HStack {
                    Button("Start Scan") {
                        viewModel.startScan(scanAll: scanAllMode)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(manager.isScanning)

                    Button("Stop Scan") {
                        viewModel.stopScan()
                    }
                    .buttonStyle(.bordered)
                    .disabled(!manager.isScanning)

                    Button("Clear Log") {
                        viewModel.clearLog()
                    }
                    .buttonStyle(.bordered)

                    Button("Export Log") {
                        exportLog()
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(manager.logEntries.isEmpty)
                }

                HStack {
                    Button("Mark \"Power On\"") {
                        viewModel.mark("Power On")
                    }
                    .buttonStyle(.bordered)

                    Button("Mark \"Power Off\"") {
                        viewModel.mark("Power Off")
                    }
                    .buttonStyle(.bordered)
                }

                LabeledContent("Active marker", value: manager.activeMarker)

                if !exportError.isEmpty {
                    Text(exportError)
                        .font(.caption)
                        .foregroundStyle(IPCATheme.danger)
                }
                if !manager.lastError.isEmpty {
                    Text(manager.lastError)
                        .font(.caption)
                        .foregroundStyle(IPCATheme.danger)
                }
            }
        }
    }

    private var liveStatus: some View {
        IPCACard(title: "Live Beacon Status", systemImage: "dot.radiowaves.left.and.right") {
            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], alignment: .leading, spacing: 12) {
                statusRow("Bluetooth authorization", manager.bluetoothAuthorization, color: bluetoothColor)
                statusRow("Central manager state", manager.centralState, color: manager.centralState == "Powered On" ? IPCATheme.success : IPCATheme.danger)
                statusRow("Scan", manager.isScanning ? "Active" : "Inactive", color: manager.isScanning ? IPCATheme.success : IPCATheme.secondaryText)
                statusRow("Scan mode", manager.scanAllMode ? "Scan All" : "Service UUID only", color: manager.scanAllMode ? IPCATheme.warning : IPCATheme.success)
                statusRow("Beacon detected", manager.beaconDetected ? "true" : "false", color: manager.beaconDetected ? IPCATheme.success : IPCATheme.secondaryText)
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
            }
        }
    }

    private var recentLog: some View {
        IPCACard(title: "Recent Beacon Log", systemImage: "doc.text.magnifyingglass") {
            if manager.logEntries.isEmpty {
                Text("No log entries yet.")
                    .foregroundStyle(IPCATheme.secondaryText)
            } else {
                VStack(alignment: .leading, spacing: 9) {
                    ForEach(manager.logEntries.suffix(14).reversed()) { entry in
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(entry.kind.rawValue.uppercased()) - \(entry.marker)")
                                .font(.caption.weight(.bold))
                            Text(logSummary(entry))
                                .font(.caption)
                                .foregroundStyle(IPCATheme.secondaryText)
                                .textSelection(.enabled)
                        }
                    }
                }
            }
        }
    }

    private var helpSection: some View {
        IPCACard(title: "Test Procedure", systemImage: "questionmark.circle") {
            VStack(alignment: .leading, spacing: 10) {
                Text("Test A: Aircraft USB power off. Start scan and confirm state remains UNKNOWN or AVIONICS OFF after the timeout.")
                Text("Test B: Turn aircraft USB power on. Confirm the XIAO boots, at least two advertisements arrive within 5 seconds, and state changes to AVIONICS ON.")
                Text("Test C: Leave power on for at least 5 minutes. Confirm advertisements continue arriving and there are no false OFF transitions.")
                Text("Test D: Turn aircraft USB power off. Confirm temporary missing after 5 seconds and AVIONICS OFF after 15 seconds.")
                Text("Test E: Rapidly cycle power off/on. Confirm the app does not create duplicate devices and recognizes the beacon after reboot without depending on the iOS peripheral identifier.")
                Text("Foreground scanning only: iOS background BLE scanning behaves differently and must be tested separately before recorder auto-start/auto-stop is connected.")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(IPCATheme.secondaryText)
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
            return IPCATheme.success
        case .avionicsOff:
            return IPCATheme.danger
        case .temporarilyMissing, .candidateOn, .scanning:
            return IPCATheme.warning
        case .bluetoothUnavailable, .unknown:
            return IPCATheme.secondaryText
        }
    }

    private var bluetoothColor: Color {
        manager.bluetoothAuthorization == "Allowed Always" ? IPCATheme.success : IPCATheme.warning
    }

    private var ageColor: Color {
        guard let seconds = manager.secondsSinceLastAdvertisement else {
            return IPCATheme.secondaryText
        }
        if seconds > AvionicsBeaconManager.offConfirmationAfter {
            return IPCATheme.danger
        }
        if seconds > AvionicsBeaconManager.temporarilyMissingAfter {
            return IPCATheme.warning
        }
        return IPCATheme.success
    }

    private func statusRow(_ label: String, _ value: String, color: Color = IPCATheme.navy) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)
            Text(value)
                .font(.headline)
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
            exportURLs = try viewModel.exportFiles()
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
