import AVFoundation
import CoreHaptics
import OSLog
import SwiftUI
import UIKit

/// Diagnosis-only haptic/audio probe. Enabled only via launch arg `-CVRHapticDiagnostics`
/// or UserDefaults key `ipca.cvrUnit.hapticDiagnostics` (never on by default for production).
enum CVRHapticDiagnostics {
    static let launchArgument = "-CVRHapticDiagnostics"
    static let userDefaultsKey = "ipca.cvrUnit.hapticDiagnostics"
    private static let logger = Logger(subsystem: "com.ipca.cvrunit", category: "HapticDiagnostics")
    private static let notificationGenerator = UINotificationFeedbackGenerator()

    static var isEnabled: Bool {
        ProcessInfo.processInfo.arguments.contains(launchArgument)
            || UserDefaults.standard.bool(forKey: userDefaultsKey)
    }

    static func snapshot(
        recordingActive: Bool,
        usbInputActive: Bool,
        phase: String
    ) -> [String: Any] {
        let session = AVAudioSession.sharedInstance()
        let options = session.categoryOptions
        return [
            "phase": phase,
            "timestamp": ISO8601DateFormatter().string(from: Date()),
            "supportsHaptics": CHHapticEngine.capabilitiesForHardware().supportsHaptics,
            "category": session.category.rawValue,
            "mode": session.mode.rawValue,
            "optionsRaw": options.rawValue,
            "options": describeCategoryOptions(options),
            "allowHapticsAndSystemSoundsDuringRecording": session.allowHapticsAndSystemSoundsDuringRecording,
            "recordingActive": recordingActive,
            "usbInputActive": usbInputActive,
            "otherAudioPlaying": session.isOtherAudioPlaying,
            "secondaryAudioShouldBeSilencedHint": session.secondaryAudioShouldBeSilencedHint
        ]
    }

    static func logSnapshot(
        recordingActive: Bool,
        usbInputActive: Bool,
        phase: String
    ) {
        guard isEnabled else { return }
        let snap = snapshot(recordingActive: recordingActive, usbInputActive: usbInputActive, phase: phase)
        logger.notice("HapticDiag snapshot \(String(describing: snap), privacy: .public)")
    }

    static func logHapticRequest(style: String, intensity: CGFloat) {
        guard isEnabled else { return }
        logger.notice("HapticDiag request style=\(style, privacy: .public) intensity=\(intensity, privacy: .public)")
    }

    static func logHapticCompletion(style: String) {
        guard isEnabled else { return }
        // UIKit does not expose a true hardware-completion callback; this marks API return.
        logger.notice("HapticDiag api_return style=\(style, privacy: .public)")
    }

    /// Runs before / monitor / recording / after probes and writes JSON under Application Support.
    @MainActor
    static func runAutomatedProbe(audio: AudioRecorderManager) async -> URL? {
        guard isEnabled else { return nil }
        var phases: [[String: Any]] = []

        func capture(_ phase: String, fireHaptic: Bool) {
            let snap = snapshot(
                recordingActive: audio.isRecording,
                usbInputActive: audio.isUSBActive,
                phase: phase
            )
            phases.append(snap)
            logSnapshot(recordingActive: audio.isRecording, usbInputActive: audio.isUSBActive, phase: phase)
            if fireHaptic {
                logHapticRequest(style: "heavy", intensity: 1)
                CVRHaptics.impact(.heavy)
                logHapticCompletion(style: "heavy")
                notificationGenerator.prepare()
                notificationGenerator.notificationOccurred(.success)
            }
        }

        // Phase 1: deactivate session if possible (true "before recording / before mic").
        audio.stopInputMonitorForDiagnostics()
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
        try? await Task.sleep(for: .milliseconds(350))
        capture("before_recording_session_inactive", fireHaptic: true)
        try? await Task.sleep(for: .milliseconds(500))

        // Phase 2: session active with passive input monitor (pre-record cockpit state).
        await audio.refreshInputs(activateSession: true)
        try? await Task.sleep(for: .milliseconds(400))
        capture("passive_monitor_active", fireHaptic: true)
        try? await Task.sleep(for: .milliseconds(500))

        // Phase 3: AVAudioRecorder active.
        let started = await audio.startRecording(language: "en")
        try? await Task.sleep(for: .milliseconds(400))
        var recordingSnap = snapshot(
            recordingActive: audio.isRecording,
            usbInputActive: audio.isUSBActive,
            phase: "recording_active"
        )
        recordingSnap["startRecordingSucceeded"] = started
        phases.append(recordingSnap)
        logSnapshot(recordingActive: audio.isRecording, usbInputActive: audio.isUSBActive, phase: "recording_active")
        if started {
            logHapticRequest(style: "heavy", intensity: 1)
            CVRHaptics.impact(.heavy)
            logHapticCompletion(style: "heavy")
            notificationGenerator.prepare()
            notificationGenerator.notificationOccurred(.success)
        }
        try? await Task.sleep(for: .milliseconds(500))

        // Phase 4: after stop (monitor typically restarts).
        _ = await audio.stopRecording(language: "en", postGainDB: 0)
        try? await Task.sleep(for: .milliseconds(500))
        capture("after_recording", fireHaptic: true)

        let report: [String: Any] = [
            "deviceProbe": "CVRHapticDiagnostics",
            "generatedAt": ISO8601DateFormatter().string(from: Date()),
            "phases": phases,
            "setAllowHapticsAndSystemSoundsDuringRecordingUsedInApp": AVAudioSession.sharedInstance().allowHapticsAndSystemSoundsDuringRecording,
            "note": "Physical feel cannot be asserted by software; allowHaptics flag + recordingActive are the measurable root-cause signals."
        ]

        do {
            let dir = try FileManager.default.url(
                for: .applicationSupportDirectory,
                in: .userDomainMask,
                appropriateFor: nil,
                create: true
            ).appendingPathComponent("IPCACVRUnit", isDirectory: true)
            try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
            let url = dir.appendingPathComponent("haptic_diagnostics_report.json")
            let data = try JSONSerialization.data(withJSONObject: report, options: [.prettyPrinted, .sortedKeys])
            try data.write(to: url, options: .atomic)
            logger.notice("HapticDiag report written \(url.path, privacy: .public)")
            return url
        } catch {
            logger.error("HapticDiag report write failed \(error.localizedDescription, privacy: .public)")
            return nil
        }
    }

    private static func describeCategoryOptions(_ options: AVAudioSession.CategoryOptions) -> [String] {
        var names: [String] = []
        if options.contains(.mixWithOthers) { names.append("mixWithOthers") }
        if options.contains(.duckOthers) { names.append("duckOthers") }
        if options.contains(.allowBluetooth) { names.append("allowBluetooth") }
        if options.contains(.allowBluetoothA2DP) { names.append("allowBluetoothA2DP") }
        if options.contains(.allowBluetoothHFP) { names.append("allowBluetoothHFP") }
        if options.contains(.defaultToSpeaker) { names.append("defaultToSpeaker") }
        if options.contains(.allowAirPlay) { names.append("allowAirPlay") }
        return names
    }
}

/// Retained feedback generators — ephemeral `UIImpactFeedbackGenerator()` instances are often
/// deallocated before the Taptic Engine plays, which feels like "haptics don't work" on iPhone.
enum CVRHaptics {
    private static let light = UIImpactFeedbackGenerator(style: .light)
    private static let medium = UIImpactFeedbackGenerator(style: .medium)
    private static let heavy = UIImpactFeedbackGenerator(style: .heavy)
    private static let soft = UIImpactFeedbackGenerator(style: .soft)
    private static let rigid = UIImpactFeedbackGenerator(style: .rigid)

    static func prepare(_ style: UIImpactFeedbackGenerator.FeedbackStyle = .medium) {
        generator(style).prepare()
    }

    static func impact(_ style: UIImpactFeedbackGenerator.FeedbackStyle = .medium, intensity: CGFloat = 1.0) {
        let gen = generator(style)
        gen.prepare()
        let clamped = max(0.1, min(1.0, intensity))
        CVRHapticDiagnostics.logHapticRequest(style: String(describing: style), intensity: clamped)
        gen.impactOccurred(intensity: clamped)
        CVRHapticDiagnostics.logHapticCompletion(style: String(describing: style))
    }

    private static func generator(_ style: UIImpactFeedbackGenerator.FeedbackStyle) -> UIImpactFeedbackGenerator {
        switch style {
        case .light: return light
        case .medium: return medium
        case .heavy: return heavy
        case .soft: return soft
        case .rigid: return rigid
        @unknown default: return medium
        }
    }
}

enum CVROperationalPalette {
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

struct CVROperationalMetrics {
    var size: CGSize

    var isCompact: Bool { size.height < 790 }
    var spacing: CGFloat { isCompact ? 8 : 10 }
    var outerHorizontalPadding: CGFloat { 14 }
    var outerVerticalPadding: CGFloat { isCompact ? 8 : 12 }
    var cardPadding: CGFloat { isCompact ? 11 : 13 }
    var logoHeight: CGFloat { isCompact ? 42 : 48 }
    var headerHeight: CGFloat { isCompact ? 58 : 66 }
    var headerCenterX: CGFloat { size.width * 0.50 }
    var aircraftTextBlockWidth: CGFloat { size.width * 0.34 }
    var headerTextLeadingSpacing: CGFloat { 18 }
    var headerTextHorizontalOffset: CGFloat { -2 }
    var headerTextVerticalOffset: CGFloat { 0 }
    var aircraftRegistrationFontSize: CGFloat { isCompact ? 25 : 28 }
    var aircraftRegistrationTracking: CGFloat { 3.0 }
    var unitIdentifierFontSize: CGFloat { isCompact ? 11 : 12 }
    var unitIdentifierTracking: CGFloat { 4.0 }
    var statusFontSize: CGFloat { isCompact ? 24 : 27 }
    var timerFontSize: CGFloat { isCompact ? 32 : 38 }
    var tileIconSize: CGFloat { isCompact ? 20 : 23 }
    var tileHeight: CGFloat { isCompact ? 104 : 116 }
    var primaryHeight: CGFloat { isCompact ? 112 : 128 }
}

struct CVROperationalHeaderView: View {
    var aircraftRegistration: String
    var unitIdentifier: String
    var metrics: CVROperationalMetrics
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
                .fill(CVROperationalPalette.cardBorder)
                .frame(width: 1, height: metrics.logoHeight)
                .position(x: metrics.headerCenterX, y: metrics.headerHeight / 2)

            VStack(alignment: .trailing, spacing: metrics.isCompact ? 0 : 1) {
                Text(aircraftRegistration)
                    .font(.system(size: metrics.aircraftRegistrationFontSize, weight: .bold, design: .rounded))
                    .tracking(metrics.aircraftRegistrationTracking)
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                    .allowsTightening(true)
                    .multilineTextAlignment(.trailing)
                Text(unitIdentifier)
                    .font(.system(size: metrics.unitIdentifierFontSize, weight: .semibold, design: .rounded))
                    .tracking(metrics.unitIdentifierTracking)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
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

struct CVROperationalStatusCard: View {
    var title: String
    var subtitle: String
    var iconName: String
    var color: Color
    var value: String?
    var caption: String?
    var metrics: CVROperationalMetrics

    var body: some View {
        VStack(spacing: metrics.isCompact ? 5 : 7) {
            HStack(spacing: 8) {
                Image(systemName: iconName)
                    .foregroundStyle(color)
                Text(title)
                    .font(.system(size: metrics.statusFontSize, weight: .bold, design: .rounded))
                    .foregroundStyle(color)
                    .lineLimit(1)
                    .minimumScaleFactor(0.82)
                    .allowsTightening(true)
            }
            Text(subtitle)
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .lineLimit(1)
                .allowsTightening(true)
            if let value {
                Text(value)
                    .font(.system(size: metrics.timerFontSize, weight: .bold, design: .monospaced))
                    .monospacedDigit()
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
            }
            if let caption {
                Text(caption)
                    .font(.caption2.weight(.bold))
                    .tracking(1.4)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .lineLimit(1)
            }
        }
        .padding(metrics.cardPadding)
        .frame(maxWidth: .infinity, minHeight: metrics.primaryHeight)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

struct CVROperationalTile: View {
    var title: String
    var iconName: String
    var value: String
    var color: Color
    var metrics: CVROperationalMetrics
    var caption: String? = nil
    var action: (() -> Void)? = nil

    var body: some View {
        let content = VStack(spacing: 4) {
            Image(systemName: iconName)
                .font(.system(size: metrics.tileIconSize, weight: .semibold))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                .frame(height: metrics.tileIconSize + 2)
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .lineLimit(1)
                .frame(height: 13)
            Text(value)
                .font(.caption.weight(.bold))
                .foregroundStyle(color)
                .lineLimit(3)
                .multilineTextAlignment(.center)
                .minimumScaleFactor(0.75)
                .frame(minHeight: caption == nil ? 32 : 28, alignment: .top)
            if let caption, !caption.isEmpty {
                Text(caption)
                    .font(.system(size: 9, weight: .semibold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .lineLimit(2)
                    .multilineTextAlignment(.center)
                    .minimumScaleFactor(0.85)
            }
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, minHeight: metrics.tileHeight, maxHeight: .infinity)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(
            action == nil ? CVROperationalPalette.cardBorder : CVROperationalPalette.secondaryBlue.opacity(0.55),
            lineWidth: 1
        ))
        .contentShape(RoundedRectangle(cornerRadius: 16))

        if let action {
            Button(action: action) {
                content
            }
            .buttonStyle(.plain)
            .accessibilityHint(caption ?? "Opens editor")
        } else {
            content
        }
    }
}

struct CVROperationalHoldTile: View {
    var title: String
    var iconName: String
    var value: String
    var subtitle: String
    var color: Color
    var metrics: CVROperationalMetrics
    var minimumDuration: TimeInterval = 2
    var isEnabled: Bool = true
    let action: () -> Void

    @State private var isPressing = false
    @State private var holdProgress = 0.0
    @State private var confirmedFlash = false

    var body: some View {
        VStack(spacing: 5) {
            Image(systemName: iconName)
                .font(.system(size: metrics.tileIconSize, weight: .semibold))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                .frame(height: metrics.tileIconSize + 2)
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .lineLimit(1)
                .frame(height: 13)
            Text(value)
                .font(.caption.weight(.bold))
                .foregroundStyle(confirmedFlash ? Color.white : color)
                .lineLimit(1)
                .minimumScaleFactor(0.82)
                .frame(height: 18, alignment: .top)
            Text(subtitle)
                .font(.system(size: 9, weight: .semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .lineLimit(1)
                .frame(height: 11)
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, minHeight: metrics.tileHeight, maxHeight: metrics.tileHeight)
        .background {
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    CVROperationalPalette.cardBackground
                    color.opacity(confirmedFlash ? 0.95 : 0.22)
                        .frame(width: proxy.size.width * holdProgress)
                }
                .clipShape(RoundedRectangle(cornerRadius: 16))
            }
        }
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(color.opacity(isEnabled ? 0.85 : 0.25), lineWidth: 1))
        .opacity(isEnabled ? 1 : 0.55)
        .scaleEffect(isPressing ? 0.985 : 1.0)
        .contentShape(RoundedRectangle(cornerRadius: 16))
        .onLongPressGesture(
            minimumDuration: minimumDuration,
            maximumDistance: 45,
            pressing: { pressing in
                guard isEnabled else { return }
                isPressing = pressing
                if pressing {
                    confirmedFlash = false
                    holdProgress = 0
                    CVRHaptics.prepare(.medium)
                    withAnimation(.linear(duration: minimumDuration)) {
                        holdProgress = 1
                    }
                } else if !confirmedFlash {
                    withAnimation(.easeOut(duration: 0.15)) {
                        holdProgress = 0
                    }
                }
            },
            perform: {
                guard isEnabled else { return }
                confirmedFlash = true
                holdProgress = 1
                CVRHaptics.impact(.medium)
                action()
                Task {
                    try? await Task.sleep(for: .milliseconds(450))
                    confirmedFlash = false
                    withAnimation(.easeOut(duration: 0.18)) {
                        holdProgress = 0
                    }
                }
            }
        )
        .animation(.easeInOut(duration: 0.1), value: confirmedFlash)
    }
}

struct CVROperationalWarningCard: View {
    var title: String
    var message: String
    var iconName: String
    var color: Color
    var progress: Double? = nil

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: iconName)
                .font(.title3.weight(.bold))
                .foregroundStyle(color)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(color)
                    .lineLimit(1)
                Text(message)
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .lineLimit(2)
                    .minimumScaleFactor(0.86)
                if let progress {
                    ProgressView(value: progress)
                        .tint(color)
                        .animation(.linear(duration: 0.15), value: progress)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(color.opacity(0.75), lineWidth: 1))
    }
}

struct CVROperationalActionButton: View {
    var title: String
    var subtitle: String?
    var color: Color
    var isConfirmed: Bool = false
    var hapticStyle: UIImpactFeedbackGenerator.FeedbackStyle? = .medium
    var action: () -> Void

    var body: some View {
        Button {
            if let hapticStyle {
                CVRHaptics.impact(hapticStyle)
            }
            action()
        } label: {
            VStack(spacing: 2) {
                Text(title)
                    .font(.subheadline.weight(.bold))
                    .tracking(0.8)
                    .lineLimit(1)
                if let subtitle {
                    Text(subtitle)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(isConfirmed ? Color.white.opacity(0.9) : CVROperationalPalette.textSecondary)
                        .lineLimit(1)
                }
            }
            .foregroundStyle(isConfirmed ? Color.white : color)
            .frame(maxWidth: .infinity, minHeight: 50)
            .background(isConfirmed ? CVROperationalPalette.success : CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 17))
            .overlay(RoundedRectangle(cornerRadius: 17).stroke(isConfirmed ? CVROperationalPalette.success : color.opacity(0.75), lineWidth: 1))
            .animation(.easeInOut(duration: 0.12), value: isConfirmed)
        }
        .buttonStyle(.plain)
        .contentShape(RoundedRectangle(cornerRadius: 17))
    }
}

/// Dark section card matching operational workflow panels (Admin / diagnostics).
struct CVROperationalSectionCard<Content: View>: View {
    var title: String
    var systemImage: String? = nil
    @ViewBuilder var content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                if let systemImage {
                    Image(systemName: systemImage)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                }
                Text(title.uppercased())
                    .font(.caption.weight(.bold))
                    .tracking(1.2)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                Spacer(minLength: 0)
            }
            content
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .foregroundStyle(CVROperationalPalette.textPrimary)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

extension View {
    /// Navigation + page chrome for Admin screens (matches operational dark UI).
    func cvrAdminScreenChrome(title: String) -> some View {
        self
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbarBackground(CVROperationalPalette.background, for: .navigationBar)
            .toolbarBackground(.visible, for: .navigationBar)
            .toolbarColorScheme(.dark, for: .navigationBar)
            .tint(CVROperationalPalette.secondaryBlue)
            .preferredColorScheme(.dark)
    }

    /// List / Form rows on Admin screens: dark card cells, not system white panels.
    func cvrAdminListRowStyle() -> some View {
        self
            .listRowBackground(CVROperationalPalette.cardBackground)
            .listRowSeparatorTint(CVROperationalPalette.cardBorder)
            .foregroundStyle(CVROperationalPalette.textPrimary)
    }

    func cvrAdminListChrome() -> some View {
        self
            .listStyle(.insetGrouped)
            .scrollContentBackground(.hidden)
            .background(CVROperationalPalette.background)
            .tint(CVROperationalPalette.secondaryBlue)
            .preferredColorScheme(.dark)
    }
}
