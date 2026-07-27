import Foundation
import SwiftUI

struct StatusDashboardView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var system: SystemMonitor
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var coordinator: CVRUnitCoordinator
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool
    @State private var logoTapCount = 0
    @State private var lastLogoTapAt: Date?

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVRLayoutMetrics(size: proxy.size)
            ZStack {
                CVRPalette.background.ignoresSafeArea()
                VStack(spacing: metrics.spacing) {
                    CVRHeaderView(
                        aircraftRegistration: aircraftRegistration,
                        unitIdentifier: settings.cvrUnitIdentifier,
                        metrics: metrics,
                        onLogoTap: handleLogoTap
                    )
                    RecorderPrimaryStatusCard(
                        status: recorderStatus,
                        secondaryText: recorderSecondaryText,
                        elapsed: audio.elapsed,
                        metrics: metrics
                    )
                    AudioInputHealthCard(
                        sourceName: publicAudioSourceName,
                        signalState: audioSignalState,
                        needleValue: liveAudioNeedleValue,
                        averageDB: audio.averagePowerDB,
                        peakDB: audio.peakPowerDB,
                        volumePercent: Int((audio.level * 100).rounded()),
                        gainText: gainDisplayText,
                        sourceWarning: audio.isInternalMicWarning,
                        metrics: metrics
                    )
                    ThermalLoadCompactView(state: ProcessInfo.processInfo.thermalState, metrics: metrics)
                    SubsystemStatusRow(
                        cells: [
                            beaconCell,
                            gpsCell,
                            batteryCell,
                            storageCell
                        ],
                        metrics: metrics
                    )
                    RecorderHealthBanner(health: publicHealth, metrics: metrics)
                }
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(width: proxy.size.width, height: proxy.size.height, alignment: .top)
            }
        }
        .background(CVRPalette.background)
    }

    private var aircraftRegistration: String {
        settings.selectedAircraft?.registration ?? "NO AIRCRAFT"
    }

    private var recorderStatus: RecorderDisplayStatus {
        if audio.isRecording {
            return RecorderDisplayStatus(title: "RECORDING", color: CVRPalette.critical, iconName: "record.circle.fill")
        }
        if activeRecordingStatus?.uploadStatus == .uploading {
            return RecorderDisplayStatus(title: "UPLOADING", color: CVRPalette.primaryBlue, iconName: "icloud.and.arrow.up.fill")
        }
        if activeRecordingStatus?.transcriptStatus == .transcribing {
            return RecorderDisplayStatus(title: "TRANSCRIBING", color: CVRPalette.primaryBlue, iconName: "text.bubble.fill")
        }
        switch coordinator.mode {
        case .recording:
            return RecorderDisplayStatus(title: "RECORDING", color: CVRPalette.critical, iconName: "record.circle.fill")
        case .uploading:
            return RecorderDisplayStatus(title: "UPLOADING", color: CVRPalette.primaryBlue, iconName: "icloud.and.arrow.up.fill")
        case .pendingUpload:
            return RecorderDisplayStatus(title: "PENDING", color: CVRPalette.standby, iconName: "clock.fill")
        case .error:
            return RecorderDisplayStatus(title: "ERROR", color: CVRPalette.critical, iconName: "exclamationmark.triangle.fill")
        default:
            return RecorderDisplayStatus(title: "STANDBY", color: CVRPalette.standby, iconName: "circle.fill")
        }
    }

    private var recorderSecondaryText: String {
        if audio.isRecording {
            return "AUDIO BEING SAVED"
        }
        if let recording = activeRecordingStatus {
            if recording.uploadStatus == .uploading {
                return "\(Int((recording.uploadProgress * 100).rounded()))% UPLOADED TO SERVER"
            }
            if recording.transcriptStatus == .transcribing {
                return "TRANSCRIBING \(recording.transcriptProgress)%"
            }
            if recording.transcriptStatus == .ready {
                return "TRANSCRIPT READY"
            }
        }
        switch coordinator.mode {
        case .recording:
            return "AUDIO BEING SAVED"
        case .uploading:
            return "AUDIO UPLOAD IN PROGRESS"
        case .pendingUpload:
            return "UPLOAD WAITING"
        case .error:
            return "CHECK ADMIN LOG"
        default:
            return audio.recordingSignalActive ? "AUDIO INPUT MONITORING" : "WAITING FOR AUDIO SIGNAL"
        }
    }

    private var activeRecordingStatus: Recording? {
        store.recordings.first { $0.uploadStatus == .uploading }
            ?? store.recordings.first { $0.transcriptStatus == .transcribing }
            ?? store.recordings.first { $0.needsUploadRetry }
            ?? store.recordings.first
    }

    private var publicAudioSourceName: String {
        if audio.isUSBActive { return "USB-C AUDIO" }
        if audio.isAcceptedExternalInputActive { return "EXTERNAL MICROPHONE" }
        if audio.isInternalMicWarning { return "IPHONE MICROPHONE" }
        return "NO AUDIO INPUT"
    }

    private var audioSignalState: AudioSignalDisplayState {
        if audio.peakPowerDB > -1 {
            return AudioSignalDisplayState(label: "CLIPPING RISK", color: CVRPalette.critical)
        }
        if !audio.recordingSignalActive {
            return AudioSignalDisplayState(label: "NO SIGNAL", color: CVRPalette.warning)
        }
        if audio.averagePowerDB < -45 {
            return AudioSignalDisplayState(label: "TOO LOW", color: CVRPalette.warning)
        }
        if audio.averagePowerDB > -12 {
            return AudioSignalDisplayState(label: "STRONG", color: CVRPalette.success)
        }
        return AudioSignalDisplayState(label: "NORMAL", color: CVRPalette.success)
    }

    private var liveAudioNeedleValue: Double {
        min(1, max(0, Double(audio.level)))
    }

    private var gainDisplayText: String {
        if settings.postRecordingGainDB > 0 {
            return "+\(Int(settings.postRecordingGainDB)) dB"
        }
        return "FIXED"
    }

    private var beaconCell: SubsystemStatusCellModel {
        let status = operationalBeaconStatus
        let display = beaconDisplayText(status)
        return SubsystemStatusCellModel(title: "BEACON", iconName: "dot.radiowaves.left.and.right", primary: display.text, secondary: nil, color: display.color)
    }

    private var gpsCell: SubsystemStatusCellModel {
        SubsystemStatusCellModel(title: "GPS TIME", iconName: "location.fill", primary: gpsDisplayText, secondary: nil, color: gpsStatusColor)
    }

    private var batteryCell: SubsystemStatusCellModel {
        SubsystemStatusCellModel(title: "BATTERY", iconName: "battery.100", primary: batteryDisplayText, secondary: nil, color: batteryColor)
    }

    private var storageCell: SubsystemStatusCellModel {
        SubsystemStatusCellModel(title: "STORAGE", iconName: "externaldrive.fill", primary: "\(system.storageText)\navailable", secondary: nil, color: storageColor)
    }

    private var operationalBeaconStatus: AvionicsBeaconOperationalStatus {
        beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement)
    }

    private func beaconDisplayText(_ status: AvionicsBeaconOperationalStatus) -> (text: String, color: Color) {
        switch status.severity {
        case .nominal:
            return ("Connected", CVRPalette.success)
        case .warning:
            return (beacon.isScanning ? "Listening" : "Searching", CVRPalette.standby)
        case .danger:
            return ("Signal Lost", CVRPalette.critical)
        case .inactive:
            return ("Searching", CVRPalette.standby)
        }
    }

    private var gpsDisplayText: String {
        switch gps.state {
        case .ready, .recording:
            return "Ready"
        case .permissionNeeded:
            return "Acquiring"
        case .unavailable:
            return "Unavailable"
        case .denied, .failed:
            return "Unavailable"
        }
    }

    private var gpsStatusColor: Color {
        switch gps.state {
        case .ready, .recording:
            return CVRPalette.success
        case .permissionNeeded, .unavailable:
            return CVRPalette.standby
        case .denied, .failed:
            return CVRPalette.critical
        }
    }

    private var batteryDisplayText: String {
        let source = system.batteryStateText == "Charging" || system.batteryStateText == "Full" ? "Charging" : "Battery"
        return "\(source)\n\(system.batteryLevelPercent)%"
    }

    private var batteryColor: Color {
        system.batteryLevelPercent < 15 ? CVRPalette.critical : CVRPalette.success
    }

    private var storageColor: Color {
        system.availableStorageBytes < 1_000_000_000 ? CVRPalette.critical : (system.availableStorageBytes < 2_000_000_000 ? CVRPalette.warning : CVRPalette.success)
    }

    private var publicHealth: RecorderHealthDisplay {
        if coordinator.mode == .error {
            return RecorderHealthDisplay(title: "RECORDER NOT READY", message: "Recorder error. Check Admin.", color: CVRPalette.critical, iconName: "exclamationmark.octagon.fill")
        }
        if publicAudioSourceName == "NO AUDIO INPUT" {
            return RecorderHealthDisplay(title: "RECORDER NOT READY", message: "No usable audio input detected.", color: CVRPalette.critical, iconName: "mic.slash.fill")
        }
        if ProcessInfo.processInfo.thermalState == .critical {
            return RecorderHealthDisplay(title: "THERMAL CRITICAL", message: "iPhone thermal state is critical.", color: CVRPalette.critical, iconName: "thermometer.high")
        }
        if ProcessInfo.processInfo.thermalState == .serious {
            return RecorderHealthDisplay(title: "THERMAL WARNING", message: "iPhone thermal load is serious.", color: CVRPalette.warning, iconName: "thermometer.medium")
        }
        if system.availableStorageBytes < 1_000_000_000 {
            return RecorderHealthDisplay(title: "STORAGE CRITICAL", message: "Clear local recordings before flight.", color: CVRPalette.critical, iconName: "externaldrive.badge.xmark")
        }
        if system.batteryLevelPercent < 15 {
            return RecorderHealthDisplay(title: "BATTERY CRITICAL", message: "Connect power before flight.", color: CVRPalette.critical, iconName: "battery.25")
        }
        if audio.isInternalMicWarning {
            return RecorderHealthDisplay(title: "AUDIO INPUT WARNING", message: "Using iPhone microphone.", color: CVRPalette.warning, iconName: "mic.fill.badge.xmark")
        }
        if audio.isRecording && !(gps.state == .ready || gps.state == .recording) {
            return RecorderHealthDisplay(title: "GPS TIME WARNING", message: "GPS time is not ready.", color: CVRPalette.warning, iconName: "location.slash.fill")
        }
        return RecorderHealthDisplay(title: "RECORDER READY", message: "All required systems operational.", color: CVRPalette.success, iconName: "checkmark.shield.fill")
    }

    private func handleLogoTap() {
        guard !adminUnlocked else {
            logoTapCount = 0
            return
        }
        let now = Date()
        if let lastLogoTapAt, now.timeIntervalSince(lastLogoTapAt) > 3 {
            logoTapCount = 0
        }
        lastLogoTapAt = now
        logoTapCount += 1
        if logoTapCount >= 5 {
            logoTapCount = 0
            showAdminUnlock = true
        }
    }
}

private struct CVRLayoutMetrics {
    var size: CGSize

    var isCompact: Bool { size.height < 760 }
    var outerHorizontalPadding: CGFloat { isCompact ? 12 : 16 }
    var outerVerticalPadding: CGFloat { isCompact ? 8 : 12 }
    var spacing: CGFloat { isCompact ? 7 : 10 }
    var cardPadding: CGFloat { isCompact ? 9 : 12 }
    var cornerRadius: CGFloat { isCompact ? 15 : 18 }
    var logoHeight: CGFloat { isCompact ? 38 : 46 }
    var headerContentWidth: CGFloat { max(0, size.width - outerHorizontalPadding * 2) }
    var headerCenterX: CGFloat { headerContentWidth / 2 }
    var headerTextLeadingSpacing: CGFloat { isCompact ? 20 : 24 }
    var aircraftTextBlockWidth: CGFloat {
        max(132, headerContentWidth - headerCenterX - headerTextLeadingSpacing)
    }
    var aircraftRegistrationFontSize: CGFloat { isCompact ? 27 : 31 }
    var aircraftRegistrationTracking: CGFloat { isCompact ? 2.5 : 3.0 }
    var unitIdentifierFontSize: CGFloat { isCompact ? 12 : 14 }
    var unitIdentifierTracking: CGFloat { isCompact ? 3.8 : 4.6 }
    var headerTextHorizontalOffset: CGFloat { -2 }
    var headerTextVerticalOffset: CGFloat { isCompact ? -2 : -3 }
    var headerHeight: CGFloat { isCompact ? 56 : 66 }
    var primaryHeight: CGFloat { isCompact ? 118 : 144 }
    var audioHeight: CGFloat { isCompact ? 188 : 218 }
    var thermalHeight: CGFloat { isCompact ? 42 : 50 }
    var lowerRowHeight: CGFloat { isCompact ? 92 : 112 }
    var bannerHeight: CGFloat { isCompact ? 58 : 68 }
    var timerFontSize: CGFloat { isCompact ? 42 : 56 }
    var statusFontSize: CGFloat { isCompact ? 24 : 30 }
    var gaugeHeight: CGFloat { isCompact ? 86 : 108 }
    var controlHeight: CGFloat { isCompact ? 88 : 110 }
    var iconSize: CGFloat { isCompact ? 17 : 20 }
    var subsystemTopPadding: CGFloat { isCompact ? 10 : 14 }
    var subsystemIconSlotHeight: CGFloat { isCompact ? 22 : 26 }
    var subsystemTitleSlotHeight: CGFloat { isCompact ? 16 : 18 }
    var subsystemValueSlotHeight: CGFloat { isCompact ? 34 : 40 }
}

private enum CVRPalette {
    static let background = Color(red: 0.005, green: 0.02, blue: 0.045)
    static let cardBackground = Color(red: 0.025, green: 0.085, blue: 0.155)
    static let cardBorder = Color(red: 0.12, green: 0.34, blue: 0.56).opacity(0.55)
    static let primaryBlue = Color(red: 0.12, green: 0.47, blue: 0.92)
    static let secondaryBlue = Color(red: 0.37, green: 0.64, blue: 1.0)
    static let textPrimary = Color.white.opacity(0.92)
    static let textSecondary = Color.white.opacity(0.62)
    static let success = Color(red: 0.25, green: 0.82, blue: 0.32)
    static let standby = Color(red: 0.96, green: 0.67, blue: 0.20)
    static let warning = Color(red: 1.0, green: 0.50, blue: 0.12)
    static let critical = Color(red: 0.96, green: 0.18, blue: 0.16)
}

private struct RecorderDisplayStatus {
    var title: String
    var color: Color
    var iconName: String
}

private struct AudioSignalDisplayState {
    var label: String
    var color: Color
}

private struct SubsystemStatusCellModel: Identifiable {
    var id: String { title }
    var title: String
    var iconName: String
    var primary: String
    var secondary: String?
    var color: Color
}

private struct RecorderHealthDisplay {
    var title: String
    var message: String
    var color: Color
    var iconName: String
}

private struct CVRHeaderView: View {
    var aircraftRegistration: String
    var unitIdentifier: String
    var metrics: CVRLayoutMetrics
    var onLogoTap: () -> Void

    var body: some View {
        ZStack(alignment: .leading) {
            Button(action: onLogoTap) {
                Image("ipca_cvr_logo_official")
                    .renderingMode(.original)
                    .resizable()
                    .scaledToFit()
                    .frame(height: metrics.logoHeight)
                    .accessibilityIdentifier("ipcaOfficialLogo")
                    .accessibilityLabel("IPCA logo")
            }
            .buttonStyle(.plain)
            .frame(height: metrics.headerHeight, alignment: .center)

            Rectangle()
                .fill(CVRPalette.cardBorder)
                .frame(width: 1, height: metrics.logoHeight)
                .position(x: metrics.headerCenterX, y: metrics.headerHeight / 2)

            VStack(alignment: .trailing, spacing: metrics.isCompact ? 0 : 1) {
                Text(aircraftRegistration)
                    .font(.system(size: metrics.aircraftRegistrationFontSize, weight: .bold, design: .rounded))
                    .tracking(metrics.aircraftRegistrationTracking)
                    .foregroundStyle(CVRPalette.textPrimary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                    .allowsTightening(true)
                    .multilineTextAlignment(.trailing)
                Text(unitIdentifier)
                    .font(.system(size: metrics.unitIdentifierFontSize, weight: .semibold, design: .rounded))
                    .tracking(metrics.unitIdentifierTracking)
                    .foregroundStyle(CVRPalette.secondaryBlue)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                    .multilineTextAlignment(.trailing)
            }
            .frame(width: metrics.aircraftTextBlockWidth, alignment: .trailing)
            .position(
                x: metrics.headerCenterX + metrics.headerTextLeadingSpacing + metrics.aircraftTextBlockWidth / 2 + metrics.headerTextHorizontalOffset,
                y: metrics.headerHeight / 2 + metrics.headerTextVerticalOffset
            )

        }
        .frame(height: metrics.headerHeight)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Aircraft \(aircraftRegistration), \(unitIdentifier)")
    }
}

private struct RecorderPrimaryStatusCard: View {
    var status: RecorderDisplayStatus
    var secondaryText: String
    var elapsed: TimeInterval
    var metrics: CVRLayoutMetrics

    var body: some View {
        VStack(spacing: metrics.isCompact ? 4 : 7) {
            HStack(spacing: 8) {
                Image(systemName: status.iconName)
                    .foregroundStyle(status.color)
                Text(status.title)
                    .font(.system(size: metrics.statusFontSize, weight: .bold, design: .rounded))
                    .foregroundStyle(status.color)
                    .lineLimit(1)
            }
            Text(secondaryText)
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVRPalette.textSecondary)
                .lineLimit(1)
                .allowsTightening(true)
            Text(format(duration: elapsed))
                .font(.system(size: metrics.timerFontSize, weight: .bold, design: .monospaced))
                .monospacedDigit()
                .foregroundStyle(CVRPalette.textPrimary)
                .lineLimit(1)
                .minimumScaleFactor(0.85)
                .layoutPriority(2)
            Text("ELAPSED RECORDING TIME")
                .font(.caption2.weight(.bold))
                .tracking(1.4)
                .foregroundStyle(CVRPalette.secondaryBlue)
                .lineLimit(1)
        }
        .padding(metrics.cardPadding)
        .frame(maxWidth: .infinity, minHeight: metrics.primaryHeight, maxHeight: metrics.primaryHeight)
        .cvrCard(metrics)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Recorder status \(status.title). Elapsed recording time \(formatAccessibility(duration: elapsed))")
    }

    private func format(duration: TimeInterval) -> String {
        let total = Int(duration.rounded())
        return String(format: "%02d:%02d:%02d", total / 3600, (total % 3600) / 60, total % 60)
    }

    private func formatAccessibility(duration: TimeInterval) -> String {
        let total = Int(duration.rounded())
        return "\(total / 3600) hours \((total % 3600) / 60) minutes \(total % 60) seconds"
    }
}

private struct AudioInputHealthCard: View {
    var sourceName: String
    var signalState: AudioSignalDisplayState
    var needleValue: Double
    var averageDB: Float
    var peakDB: Float
    var volumePercent: Int
    var gainText: String
    var sourceWarning: Bool
    var metrics: CVRLayoutMetrics

    var body: some View {
        HStack(spacing: metrics.spacing) {
            VStack(alignment: .leading, spacing: metrics.isCompact ? 4 : 7) {
                HStack {
                    Text("AUDIO INPUT")
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(CVRPalette.textPrimary)
                    Spacer()
                    Text(signalState.label)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(signalState.color)
                        .lineLimit(1)
                }
                Text(sourceName)
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(sourceWarning ? CVRPalette.warning : CVRPalette.secondaryBlue)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                    .allowsTightening(true)

                AudioHealthGauge(value: needleValue, metrics: metrics)
                    .frame(height: metrics.gaugeHeight)
            }
            .frame(maxWidth: .infinity)

            VStack(spacing: metrics.isCompact ? 8 : 12) {
                CompactControlValue(
                    title: "VOLUME",
                    value: "\(volumePercent)%",
                    systemImage: "speaker.wave.2.fill",
                    metrics: metrics,
                    active: true,
                    meterValue: Double(volumePercent) / 100,
                    controlValue: nil
                )
                CompactControlValue(
                    title: "GAIN",
                    value: gainText,
                    systemImage: "lock.fill",
                    metrics: metrics,
                    active: false,
                    meterValue: 0,
                    controlValue: nil
                )
            }
            .frame(width: metrics.isCompact ? 78 : 90)
        }
        .padding(metrics.cardPadding)
        .frame(maxWidth: .infinity, minHeight: metrics.audioHeight, maxHeight: metrics.audioHeight)
        .cvrCard(metrics)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Audio input \(sourceName), \(signalState.label)")
    }
}

private struct AudioHealthGauge: View {
    var value: Double
    var metrics: CVRLayoutMetrics

    var body: some View {
        ZStack {
            ForEach(0..<4, id: \.self) { index in
                ArcSegment(
                    start: 180 + Double(index) * 180 / 4,
                    end: 180 + Double(index + 1) * 180 / 4
                )
                .stroke(zoneColor(index), style: StrokeStyle(lineWidth: metrics.isCompact ? 8 : 10, lineCap: .round))
            }
            AudioNeedleShape(value: min(1, max(0, value)))
                .fill(CVRPalette.textPrimary)
                .shadow(color: .black.opacity(0.45), radius: 2, x: 0, y: 1)
            GaugePivotShape()
                .fill(CVRPalette.textPrimary)
        }
        .padding(.horizontal, 10)
        .animation(.easeOut(duration: 0.08), value: value)
    }

    private func zoneColor(_ index: Int) -> Color {
        switch index {
        case 0: return CVRPalette.warning
        case 1: return CVRPalette.success
        case 2: return CVRPalette.success
        default: return CVRPalette.critical
        }
    }
}

private struct ArcSegment: Shape {
    var start: Double
    var end: Double

    func path(in rect: CGRect) -> Path {
        var path = Path()
        let geometry = AudioGaugeGeometry(rect: rect)
        path.addArc(
            center: geometry.center,
            radius: geometry.radius,
            startAngle: .degrees(start),
            endAngle: .degrees(end),
            clockwise: false
        )
        return path
    }
}

private struct AudioNeedleShape: Shape {
    var value: Double

    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    func path(in rect: CGRect) -> Path {
        let geometry = AudioGaugeGeometry(rect: rect)
        let angle = geometry.angle(for: value)
        let direction = CGVector(dx: cos(angle), dy: sin(angle))
        let perpendicular = CGVector(dx: -direction.dy, dy: direction.dx)
        let pivot = geometry.center
        let baseCenter = CGPoint(
            x: pivot.x + direction.dx * 8,
            y: pivot.y + direction.dy * 8
        )
        let tip = CGPoint(
            x: pivot.x + direction.dx * geometry.radius * 0.94,
            y: pivot.y + direction.dy * geometry.radius * 0.94
        )
        let baseHalfWidth: CGFloat = 4.5
        let tipHalfWidth: CGFloat = 1.1

        var path = Path()
        path.move(to: CGPoint(x: baseCenter.x + perpendicular.dx * baseHalfWidth, y: baseCenter.y + perpendicular.dy * baseHalfWidth))
        path.addLine(to: CGPoint(x: tip.x + perpendicular.dx * tipHalfWidth, y: tip.y + perpendicular.dy * tipHalfWidth))
        path.addLine(to: CGPoint(x: tip.x - perpendicular.dx * tipHalfWidth, y: tip.y - perpendicular.dy * tipHalfWidth))
        path.addLine(to: CGPoint(x: baseCenter.x - perpendicular.dx * baseHalfWidth, y: baseCenter.y - perpendicular.dy * baseHalfWidth))
        path.closeSubpath()
        return path
    }
}

private struct GaugePivotShape: Shape {
    func path(in rect: CGRect) -> Path {
        let geometry = AudioGaugeGeometry(rect: rect)
        let radius: CGFloat = 6.5
        let pivotRect = CGRect(
            x: geometry.center.x - radius,
            y: geometry.center.y - radius,
            width: radius * 2,
            height: radius * 2
        )
        var path = Path()
        path.addEllipse(in: pivotRect)
        return path
    }
}

private struct AudioGaugeGeometry {
    var rect: CGRect

    var center: CGPoint {
        CGPoint(x: rect.midX, y: rect.maxY - 8)
    }

    var radius: CGFloat {
        min(rect.width * 0.44, rect.height - 16)
    }

    func angle(for value: Double) -> CGFloat {
        CGFloat((180 + min(1, max(0, value)) * 180) * .pi / 180)
    }
}

private struct CompactControlValue: View {
    var title: String
    var value: String
    var systemImage: String
    var metrics: CVRLayoutMetrics
    var active: Bool
    var meterValue: Double
    var controlValue: Binding<Double>?

    var body: some View {
        VStack(spacing: 4) {
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVRPalette.textSecondary)
                .lineLimit(1)
            Text(value)
                .font(.caption.weight(.bold))
                .foregroundStyle(active ? CVRPalette.secondaryBlue : CVRPalette.textSecondary)
                .lineLimit(1)
                .minimumScaleFactor(0.8)
            controlBody
            Image(systemName: systemImage)
                .font(.caption)
                .foregroundStyle(CVRPalette.textSecondary)
                .accessibilityHidden(true)
        }
        .frame(minWidth: 44)
    }

    @ViewBuilder
    private var controlBody: some View {
        if let controlValue {
            VerticalMiniSlider(value: controlValue, height: metrics.controlHeight * 0.42)
        } else {
            ZStack(alignment: .bottom) {
                Capsule()
                    .fill(CVRPalette.cardBorder.opacity(0.75))
                    .frame(width: 9, height: metrics.controlHeight * 0.42)
                Capsule()
                    .fill(active ? CVRPalette.primaryBlue : CVRPalette.cardBorder)
                    .frame(width: 9, height: metrics.controlHeight * 0.42 * min(1, max(0, meterValue)))
            }
            .frame(height: metrics.controlHeight * 0.42)
        }
    }
}

private struct VerticalMiniSlider: View {
    @Binding var value: Double
    var height: CGFloat

    var body: some View {
        ZStack(alignment: .bottom) {
            Capsule()
                .fill(CVRPalette.cardBorder.opacity(0.75))
                .frame(width: 9, height: height)
            Capsule()
                .fill(CVRPalette.primaryBlue)
                .frame(width: 9, height: height * min(1, max(0, value)))
            Capsule()
                .fill(CVRPalette.textPrimary.opacity(0.88))
                .frame(width: 18, height: 7)
                .offset(y: thumbOffset)
        }
        .frame(width: 44, height: height)
        .contentShape(Rectangle())
        .gesture(
            DragGesture(minimumDistance: 0)
                .onChanged { gesture in
                    let invertedY = height - min(height, max(0, gesture.location.y))
                    value = invertedY / height
                }
        )
        .accessibilityElement()
        .accessibilityLabel("Input gain")
        .accessibilityValue("\(Int((value * 100).rounded())) percent")
        .accessibilityAdjustableAction { direction in
            switch direction {
            case .increment:
                value = min(1, value + 0.05)
            case .decrement:
                value = max(0, value - 0.05)
            @unknown default:
                break
            }
        }
    }

    private var thumbOffset: CGFloat {
        -min(1, max(0, value)) * (height - 7)
    }
}

private struct ThermalLoadCompactView: View {
    var state: ProcessInfo.ThermalState
    var metrics: CVRLayoutMetrics

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text("THERMAL LOAD")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVRPalette.textPrimary)
                Spacer()
                Text(label)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(color)
                    .lineLimit(1)
            }
            HStack(spacing: 3) {
                ForEach(0..<4, id: \.self) { index in
                    Capsule()
                        .fill(index <= activeIndex ? segmentColor(index) : CVRPalette.cardBorder.opacity(0.45))
                        .frame(height: 8)
                }
            }
        }
        .padding(.horizontal, metrics.cardPadding)
        .padding(.vertical, metrics.isCompact ? 7 : 9)
        .frame(maxWidth: .infinity, minHeight: metrics.thermalHeight, maxHeight: metrics.thermalHeight)
        .cvrCard(metrics)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Thermal load \(label)")
    }

    private var activeIndex: Int {
        switch state {
        case .nominal: return 0
        case .fair: return 1
        case .serious: return 2
        case .critical: return 3
        @unknown default: return 1
        }
    }

    private var label: String {
        switch state {
        case .nominal: return "NOMINAL"
        case .fair: return "FAIR"
        case .serious: return "SERIOUS"
        case .critical: return "CRITICAL"
        @unknown default: return "UNKNOWN"
        }
    }

    private var color: Color {
        segmentColor(activeIndex)
    }

    private func segmentColor(_ index: Int) -> Color {
        switch index {
        case 0: return CVRPalette.success
        case 1: return CVRPalette.standby
        case 2: return CVRPalette.warning
        default: return CVRPalette.critical
        }
    }
}

private struct SubsystemStatusRow: View {
    var cells: [SubsystemStatusCellModel]
    var metrics: CVRLayoutMetrics

    var body: some View {
        HStack(spacing: metrics.spacing) {
            ForEach(cells) { cell in
                SubsystemStatusCell(cell: cell, metrics: metrics)
            }
        }
        .frame(maxWidth: .infinity, minHeight: metrics.lowerRowHeight, maxHeight: metrics.lowerRowHeight)
    }
}

private struct SubsystemStatusCell: View {
    var cell: SubsystemStatusCellModel
    var metrics: CVRLayoutMetrics

    var body: some View {
        VStack(spacing: 5) {
            Image(systemName: cell.iconName)
                .font(.system(size: metrics.iconSize, weight: .semibold))
                .foregroundStyle(CVRPalette.secondaryBlue)
                .frame(height: metrics.subsystemIconSlotHeight)
                .accessibilityHidden(true)
            Text(cell.title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVRPalette.textSecondary)
                .lineLimit(1)
                .minimumScaleFactor(0.8)
                .frame(height: metrics.subsystemTitleSlotHeight)
            Text(cell.primary)
                .font(.caption.weight(.bold))
                .foregroundStyle(cell.color)
                .lineLimit(2)
                .multilineTextAlignment(.center)
                .minimumScaleFactor(0.82)
                .allowsTightening(true)
                .frame(height: metrics.subsystemValueSlotHeight, alignment: .top)
            if let secondary = cell.secondary {
                Text(secondary)
                    .font(.caption2)
                    .foregroundStyle(CVRPalette.textSecondary)
                    .lineLimit(1)
            }
        }
        .padding(.horizontal, 5)
        .padding(.top, metrics.subsystemTopPadding)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .cvrCard(metrics)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(cell.title), \(cell.primary)")
    }
}

private struct RecorderHealthBanner: View {
    var health: RecorderHealthDisplay
    var metrics: CVRLayoutMetrics

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: health.iconName)
                .font(.system(size: metrics.iconSize + 2, weight: .bold))
                .foregroundStyle(health.color)
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(health.title)
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(health.color)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                Text(health.message)
                    .font(.caption.weight(.medium))
                    .foregroundStyle(CVRPalette.textSecondary)
                    .lineLimit(2)
                    .fixedSize(horizontal: false, vertical: true)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, metrics.cardPadding)
        .frame(maxWidth: .infinity, minHeight: metrics.bannerHeight, maxHeight: metrics.bannerHeight)
        .cvrCard(metrics, borderColor: health.color.opacity(0.75))
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(health.title), \(health.message)")
    }
}

private struct CVRCardModifier: ViewModifier {
    var metrics: CVRLayoutMetrics
    var borderColor: Color

    func body(content: Content) -> some View {
        content
            .background(CVRPalette.cardBackground, in: RoundedRectangle(cornerRadius: metrics.cornerRadius))
            .overlay(RoundedRectangle(cornerRadius: metrics.cornerRadius).stroke(borderColor, lineWidth: 1))
    }
}

private extension View {
    func cvrCard(_ metrics: CVRLayoutMetrics, borderColor: Color = CVRPalette.cardBorder) -> some View {
        modifier(CVRCardModifier(metrics: metrics, borderColor: borderColor))
    }
}
