import CoreLocation
import SwiftUI
import UIKit

struct ExternalGPSTestView: View {
    @StateObject private var viewModel = ExternalGPSTestViewModel()
    @State private var exportURLs: [URL] = []
    @State private var isShowingShareSheet = false
    @State private var exportError = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "External GPS Test",
                        subtitle: "Diagnose whether Core Location reports Garmin GPS as an external accessory",
                        systemImage: "location.north.line"
                    )

                    statusBanner
                    controls
                    liveStatus
                    transitionHistory
                    recentLog
                    helpSection
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("External GPS Test")
            .navigationBarTitleDisplayMode(.inline)
            .sheet(isPresented: $isShowingShareSheet) {
                ActivityView(activityItems: exportURLs)
            }
        }
        .onDisappear {
            viewModel.stopTest()
        }
    }

    private var statusBanner: some View {
        Text(viewModel.bannerState.bannerLabel)
            .font(.title.weight(.black))
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 20)
            .background(bannerColor, in: RoundedRectangle(cornerRadius: 22))
            .shadow(color: bannerColor.opacity(0.25), radius: 16, y: 8)
    }

    private var controls: some View {
        IPCACard(title: "Test Controls", systemImage: "switch.2") {
            VStack(alignment: .leading, spacing: 14) {
                HStack {
                    Button("Start Test") {
                        viewModel.startTest()
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(viewModel.isRunning)

                    Button("Stop Test") {
                        viewModel.stopTest()
                    }
                    .buttonStyle(.bordered)
                    .disabled(!viewModel.isRunning)

                    Button("Clear Log") {
                        viewModel.clearLog()
                    }
                    .buttonStyle(.bordered)

                    Button("Export Log") {
                        exportLog()
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(viewModel.logEntries.isEmpty)
                }

                LazyVGrid(columns: [GridItem(.adaptive(minimum: 160), spacing: 10)], spacing: 10) {
                    markerButton("Garmin Off")
                    markerButton("G3X On")
                    markerButton("GNX 375 On")
                    markerButton("Both Garmin On")
                    markerButton("Garmin Pilot Connected")
                    markerButton("Avionics Off")
                }

                LabeledContent("Active marker", value: viewModel.activeMarker)

                if !exportError.isEmpty {
                    Text(exportError)
                        .font(.caption)
                        .foregroundStyle(IPCATheme.danger)
                }
                if !viewModel.lastError.isEmpty {
                    Text(viewModel.lastError)
                        .font(.caption)
                        .foregroundStyle(IPCATheme.danger)
                }
            }
        }
    }

    private var liveStatus: some View {
        IPCACard(title: "Live Location Status", systemImage: "location.viewfinder") {
            LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], alignment: .leading, spacing: 12) {
                statusRow("Authorization", viewModel.authorizationLabel, color: authorizationColor)
                statusRow("Location services", viewModel.locationServicesEnabled ? "Enabled" : "Disabled", color: viewModel.locationServicesEnabled ? IPCATheme.success : IPCATheme.danger)
                statusRow("Test", viewModel.isRunning ? "Running" : "Stopped", color: viewModel.isRunning ? IPCATheme.success : IPCATheme.secondaryText)
                statusRow("Current source", viewModel.currentSource.label, color: bannerColor)
                statusRow("isProducedByAccessory", boolText(viewModel.isProducedByAccessory), color: boolColor(viewModel.isProducedByAccessory))
                statusRow("isSimulatedBySoftware", boolText(viewModel.isSimulatedBySoftware), color: boolColor(viewModel.isSimulatedBySoftware))
                statusRow("sourceInformation", viewModel.sourceInformationAvailable ? "Available" : "Nil / unavailable", color: viewModel.sourceInformationAvailable ? IPCATheme.success : IPCATheme.warning)
                statusRow("Last timestamp", viewModel.lastLocationTimestampText)
                statusRow("Age", viewModel.ageText, color: viewModel.bannerState == .noRecentData ? IPCATheme.danger : IPCATheme.success)
                statusRow("Latitude", coordinateText(viewModel.lastLocation?.coordinate.latitude))
                statusRow("Longitude", coordinateText(viewModel.lastLocation?.coordinate.longitude))
                statusRow("Altitude", metersText(viewModel.lastLocation?.altitude))
                statusRow("Horizontal accuracy", metersText(viewModel.lastLocation?.horizontalAccuracy))
                statusRow("Vertical accuracy", metersText(viewModel.lastLocation?.verticalAccuracy))
                statusRow("Speed", speedKnotsText(viewModel.lastLocation?.speed))
                statusRow("Course", courseText(viewModel.lastLocation?.course))
                statusRow("Total updates", "\(viewModel.totalLocationUpdates)")
                statusRow("Consecutive accessory", "\(viewModel.consecutiveAccessoryUpdates)")
                statusRow("Consecutive internal", "\(viewModel.consecutiveInternalUpdates)")
            }
        }
    }

    private var transitionHistory: some View {
        IPCACard(title: "Source Transition History", systemImage: "arrow.left.arrow.right") {
            if viewModel.sourceTransitions.isEmpty {
                Text("No source transitions yet.")
                    .foregroundStyle(IPCATheme.secondaryText)
            } else {
                VStack(alignment: .leading, spacing: 8) {
                    ForEach(viewModel.sourceTransitions.prefix(12)) { transition in
                        Text(transition.label)
                            .font(.subheadline.weight(.semibold))
                    }
                }
            }
        }
    }

    private var recentLog: some View {
        IPCACard(title: "Recent Diagnostic Log", systemImage: "doc.text.magnifyingglass") {
            if viewModel.logEntries.isEmpty {
                Text("No log entries yet.")
                    .foregroundStyle(IPCATheme.secondaryText)
            } else {
                VStack(alignment: .leading, spacing: 8) {
                    ForEach(viewModel.logEntries.suffix(12).reversed()) { entry in
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(entry.kind.rawValue.uppercased()) - \(entry.marker)")
                                .font(.caption.weight(.bold))
                            Text(logSummary(entry))
                                .font(.caption)
                                .foregroundStyle(IPCATheme.secondaryText)
                        }
                    }
                }
            }
        }
    }

    private var helpSection: some View {
        IPCACard(title: "Test Procedure", systemImage: "questionmark.circle") {
            VStack(alignment: .leading, spacing: 10) {
                Text("Test A: Avionics off. Start test, wait for at least 10 location updates, mark Garmin Off, and confirm whether source is internal.")
                Text("Test B: Turn on G3X, wait for automatic Bluetooth reconnection, mark G3X On, and observe whether isProducedByAccessory changes.")
                Text("Test C: Turn on GNX 375, mark Both Garmin On, and observe source state.")
                Text("Test D: Open Garmin Pilot, confirm Garmin connection, mark Garmin Pilot Connected, and observe whether Core Location changes source.")
                Text("Test E: Turn avionics off, mark Avionics Off, and observe whether external accessory updates stop, source returns to internal GPS, or updates become stale.")
                Text("This diagnostic preserves every raw CLLocation callback. It does not discard inaccurate, duplicate, stationary, or old-looking samples.")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(IPCATheme.secondaryText)
            }
            .font(.subheadline)
        }
    }

    private var bannerColor: Color {
        switch viewModel.bannerState {
        case .externalAccessory:
            return IPCATheme.success
        case .internalDevice:
            return IPCATheme.brightBlue
        case .unknown:
            return IPCATheme.secondaryText
        case .noRecentData:
            return IPCATheme.danger
        }
    }

    private var authorizationColor: Color {
        switch viewModel.authorizationStatus {
        case .authorizedAlways, .authorizedWhenInUse:
            return IPCATheme.success
        case .denied, .restricted:
            return IPCATheme.danger
        default:
            return IPCATheme.warning
        }
    }

    private func markerButton(_ marker: String) -> some View {
        Button("Mark \"\(marker)\"") {
            viewModel.mark(marker)
        }
        .buttonStyle(.bordered)
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

    private func boolText(_ value: Bool?) -> String {
        guard let value else { return "null" }
        return value ? "true" : "false"
    }

    private func boolColor(_ value: Bool?) -> Color {
        guard let value else { return IPCATheme.warning }
        return value ? IPCATheme.success : IPCATheme.brightBlue
    }

    private func coordinateText(_ value: Double?) -> String {
        guard let value else { return "--" }
        return String(format: "%.7f", value)
    }

    private func metersText(_ value: Double?) -> String {
        guard let value else { return "--" }
        return String(format: "%.1f m", value)
    }

    private func speedKnotsText(_ speedMetersPerSecond: Double?) -> String {
        guard let speedMetersPerSecond, speedMetersPerSecond >= 0 else { return "--" }
        return String(format: "%.1f kt", speedMetersPerSecond * 1.943844492)
    }

    private func courseText(_ value: Double?) -> String {
        guard let value, value >= 0 else { return "--" }
        return String(format: "%.0f degrees", value)
    }

    private func logSummary(_ entry: ExternalGPSLogEntry) -> String {
        if let event = entry.event {
            return event
        }
        let lat = entry.latitude.map { String(format: "%.6f", $0) } ?? "--"
        let lon = entry.longitude.map { String(format: "%.6f", $0) } ?? "--"
        return "lat \(lat), lon \(lon), accessory \(boolText(entry.isProducedByAccessory)), simulated \(boolText(entry.isSimulatedBySoftware))"
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
}

private struct ActivityView: UIViewControllerRepresentable {
    var activityItems: [Any]

    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: activityItems, applicationActivities: nil)
    }

    func updateUIViewController(_ uiViewController: UIActivityViewController, context: Context) {}
}
