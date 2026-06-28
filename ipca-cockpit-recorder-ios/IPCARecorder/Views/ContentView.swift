import SwiftUI
import Foundation

struct ContentView: View {
    @Binding var pendingNavigationRecordingID: String?

    var body: some View {
        TabView {
            RecorderView()
                .tabItem { Label("Recorder", systemImage: "waveform") }

            RecordingsView(pendingNavigationRecordingID: $pendingNavigationRecordingID)
                .tabItem { Label("Recordings", systemImage: "list.bullet.rectangle") }

            RealisticCockpitSetupView()
                .tabItem { Label("Cockpit Setup", systemImage: "gyroscope") }

            SettingsView()
                .tabItem { Label("Settings", systemImage: "gearshape") }
        }
        .tint(IPCATheme.brightBlue)
    }
}

struct CockpitSetupView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var ahrsBLE: AHRSBLEManager
    @EnvironmentObject private var gps: GPSLocationManager
    @State private var logoTapCount = 0
    @State private var showTestTools = false
    @State private var testDurationHours = 3
    @State private var isGeneratingTest = false
    @State private var testMessage = ""

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "Cockpit Setup",
                        subtitle: "Preflight AHRS, audio, GPS and replay inputs",
                        systemImage: "airplane.circle"
                    )
                    .onTapGesture {
                        logoTapCount += 1
                        if logoTapCount >= 5 {
                            showTestTools = true
                            logoTapCount = 0
                        }
                    }

                    cockpitPreview
                    setupParameters
                    sourceStatus
                    if showTestTools {
                        hiddenTestTools
                    }
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Cockpit Setup")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private var cockpitPreview: some View {
        IPCACard(title: "Sample Cockpit", systemImage: "display") {
            let sample = ahrsBLE.latestSample
            VStack(spacing: 16) {
                ZStack {
                    RoundedRectangle(cornerRadius: 18)
                        .fill(LinearGradient(colors: [Color.blue.opacity(0.35), Color.white], startPoint: .top, endPoint: .bottom))
                        .frame(height: 220)
                    horizon(sample)
                    VStack {
                        HStack {
                            metric("GS", gps.latestSample.map { String(format: "%.0f kt", $0.speedKnots) } ?? "--")
                            Spacer()
                            metric("ALT SET", settings.altimeterSettingValue.map { String(format: "%.2f", $0) } ?? "--")
                        }
                        Spacer()
                        HStack {
                            metric("PITCH", sample.map { String(format: "%.1f", $0.calibratedPitch ?? $0.aviationPitch ?? -$0.pitch) } ?? "--")
                            Spacer()
                            metric("ROLL", sample.map { String(format: "%.1f", $0.calibratedRoll ?? $0.aviationRoll ?? $0.roll) } ?? "--")
                        }
                    }
                    .padding()
                }

                headingIndicator(sample)
                slipBall(sample)
            }
        }
    }

    private func horizon(_ sample: AHRSSample?) -> some View {
        let roll = sample?.calibratedRoll ?? sample?.aviationRoll ?? sample?.roll ?? 0
        let pitch = sample?.calibratedPitch ?? sample?.aviationPitch ?? -(sample?.pitch ?? 0)
        return ZStack {
            Rectangle()
                .fill(Color.brown.opacity(0.45))
                .frame(width: 420, height: 180)
                .offset(y: 85 + pitch * 3)
            Rectangle()
                .fill(Color.blue.opacity(0.28))
                .frame(width: 420, height: 180)
                .offset(y: -95 + pitch * 3)
            Rectangle()
                .fill(Color.yellow)
                .frame(width: 110, height: 5)
            Rectangle()
                .fill(Color.yellow)
                .frame(width: 5, height: 26)
        }
        .rotationEffect(.degrees(-roll))
        .clipped()
    }

    private func headingIndicator(_ sample: AHRSSample?) -> some View {
        let heading = sample?.correctedMagneticHeading ?? sample?.fusedHeading ?? sample?.magneticHeading ?? 0
        return ZStack {
            Circle().stroke(Color.white.opacity(0.75), lineWidth: 3)
            ForEach(0..<12) { tick in
                Text(tickLabel(tick * 30))
                    .font(.caption.weight(.bold))
                    .foregroundStyle(IPCATheme.navy)
                    .offset(y: -52)
                    .rotationEffect(.degrees(Double(tick * 30)))
            }
            Image(systemName: "airplane")
                .font(.title2)
                .foregroundStyle(IPCATheme.brightBlue)
            Text(String(format: "HDG %.0f°", heading))
                .font(.caption.weight(.bold))
                .offset(y: 42)
        }
        .frame(width: 140, height: 140)
        .rotationEffect(.degrees(-heading))
    }

    private func slipBall(_ sample: AHRSSample?) -> some View {
        let slip = slipValue(from: sample)
        return VStack(alignment: .leading, spacing: 6) {
            Text(String(format: "Slip/Skid %.3f g", slip))
                .font(.caption.weight(.semibold))
            ZStack {
                Capsule().fill(Color.black.opacity(0.12)).frame(height: 22)
                Capsule().stroke(Color.black.opacity(0.25), lineWidth: 1).frame(height: 22)
                Circle().fill(IPCATheme.navy).frame(width: 20, height: 20).offset(x: slip * 220)
            }
        }
    }

    private func slipValue(from sample: AHRSSample?) -> Double {
        guard let sample else { return 0 }

        if let slip = sample.slipSkid {
            return clampSlip(applySlipDeadband(slip))
        }

        // Fallback for older packets: fused roll gives a stable body-frame
        // lateral gravity proxy. Prefer this over the raw GRAVY event because
        // some BNO085 gravity packets recenter after a static roll.
        let roll = sample.calibratedRoll ?? sample.aviationRoll ?? sample.roll
        if abs(roll) > 0.5 {
            return clampSlip(applySlipDeadband(sin(roll * .pi / 180.0)))
        }

        if let gravityY = sample.gravityY {
            let gravityMagnitude = sqrt(
                pow(sample.gravityX ?? 0, 2) +
                pow(gravityY, 2) +
                pow(sample.gravityZ ?? 0, 2)
            )
            if gravityMagnitude > 7.0 && gravityMagnitude < 12.0 {
                return clampSlip(applySlipDeadband(gravityY / gravityMagnitude))
            }
        }

        // Last resort for very old firmware packets without SLIP/GRAV*.
        return clampSlip(applySlipDeadband(sin(roll * .pi / 180.0)))
    }

    private func applySlipDeadband(_ value: Double) -> Double {
        abs(value) < 0.015 ? 0 : value
    }

    private func clampSlip(_ value: Double) -> Double {
        max(-0.35, min(0.35, value))
    }

    private var setupParameters: some View {
        IPCACard(title: "Setup Parameters", systemImage: "slider.horizontal.3") {
            TextField("Altimeter setting / QNH inHg", text: $settings.altimeterSettingInHg)
                .keyboardType(.decimalPad)
                .textFieldStyle(.roundedBorder)
            TextField("Airport field elevation ft", text: $settings.airportElevationFt)
                .keyboardType(.numbersAndPunctuation)
                .textFieldStyle(.roundedBorder)
            TextField("OAT °C", text: $settings.oatC)
                .keyboardType(.numbersAndPunctuation)
                .textFieldStyle(.roundedBorder)
            LabeledContent("Compass deviation d", value: String(format: "%.1f°", settings.ahrsCalibration.compassDeviation))
            LabeledContent("Magnetic variation V", value: String(format: "%.1f°", settings.ahrsCalibration.magneticVariation))
        }
    }

    private var sourceStatus: some View {
        IPCACard(title: "Source Status", systemImage: "checklist") {
            LabeledContent("Audio", value: audio.sourceSummary)
            LabeledContent("AHRS", value: "\(ahrsBLE.connectionState.rawValue) / \(String(format: "%.1f Hz", ahrsBLE.receiveRateHz))")
            LabeledContent("GPS", value: gps.state.rawValue)
            LabeledContent("Aircraft", value: settings.selectedAircraft?.label ?? "Not selected")
            if audio.isInternalMicWarning {
                Text("Audio is not using an accepted external cockpit input.")
                    .foregroundStyle(IPCATheme.warning)
            }
            if !ahrsBLE.lastError.isEmpty {
                Text(ahrsBLE.lastError).foregroundStyle(IPCATheme.danger)
            }
            if !gps.lastError.isEmpty {
                Text(gps.lastError).foregroundStyle(IPCATheme.danger)
            }
        }
    }

    private var hiddenTestTools: some View {
        IPCACard(title: "Hidden Test Tools", systemImage: "testtube.2") {
            Text("Creates a real long WAV file plus synthetic GPS/AHRS, then uploads using the normal chunked path.")
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)
            Picker("Duration", selection: $testDurationHours) {
                Text("1 hour").tag(1)
                Text("2 hours").tag(2)
                Text("3 hours").tag(3)
                Text("4 hours").tag(4)
            }
            .pickerStyle(.segmented)
            Button(isGeneratingTest ? "Generating..." : "Generate and Upload Test Recording") {
                Task { await generateAndUploadTest() }
            }
            .buttonStyle(.borderedProminent)
            .tint(IPCATheme.warning)
            .disabled(isGeneratingTest || !settings.isServerURLConfigured)
            if !testMessage.isEmpty {
                Text(testMessage)
                    .font(.caption)
                    .foregroundStyle(testMessage.hasPrefix("Failed") ? IPCATheme.danger : IPCATheme.secondaryText)
            }
        }
    }

    private func metric(_ label: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label).font(.caption2.weight(.bold))
            Text(value).font(.headline.monospacedDigit())
        }
        .padding(8)
        .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 8))
    }

    private func tickLabel(_ degrees: Int) -> String {
        switch degrees {
        case 0: "N"
        case 90: "E"
        case 180: "S"
        case 270: "W"
        default: "\(degrees / 10)"
        }
    }

    private func generateAndUploadTest() async {
        isGeneratingTest = true
        testMessage = "Generating \(testDurationHours)h test recording..."
        defer { isGeneratingTest = false }
        do {
            let recording = try store.createLongTestRecording(durationHours: testDurationHours, settings: settings)
            testMessage = "Created \(Formatters.bytes(recording.fileSize)). Uploading..."
            uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
        } catch {
            testMessage = "Failed: \(error.localizedDescription)"
        }
    }
}

private enum RealisticAltitudeMode: String {
    case calculatedBaro = "Baro Calc"
    case gps = "GPS Alt"
}

private enum RealisticVerticalSpeedMode: String {
    case calculatedBaro = "Baro Trend"
    case gps = "GPS Trend"
}

private enum RealisticHeadingMode: String {
    case magnetic = "Mag HDG"
    case trueHeading = "True HDG"
}

struct RealisticCockpitSetupView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var ahrsBLE: AHRSBLEManager
    @EnvironmentObject private var gps: GPSLocationManager

    @State private var logoTapCount = 0
    @State private var showTestTools = false
    @State private var testDurationHours = 3
    @State private var isGeneratingTest = false
    @State private var testMessage = ""
    @State private var altitudeMode: RealisticAltitudeMode = .calculatedBaro
    @State private var verticalSpeedMode: RealisticVerticalSpeedMode = .calculatedBaro
    @State private var headingMode: RealisticHeadingMode = .magnetic
    @State private var datumPitchText = "0.0"
    @State private var datumRollText = "0.0"
    @State private var compassReferenceText = ""
    @State private var smoothedPitch = 0.0
    @State private var smoothedRoll = 0.0
    @State private var smoothedHeading = 0.0
    @State private var smoothedSpeed = 0.0
    @State private var smoothedAltitude = 0.0
    @State private var smoothedVerticalSpeed = 0.0
    @State private var smoothedSlip = 0.0
    @State private var altitudeHistory: [RealisticAltitudeSnapshot] = []

    private let displayTimer = Timer.publish(every: 1.0 / 30.0, on: .main, in: .common).autoconnect()

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "Cockpit Setup",
                        subtitle: "Smooth AHRS alignment, source checks and long-recording tests",
                        systemImage: "airplane.circle"
                    )
                    .onTapGesture {
                        logoTapCount += 1
                        if logoTapCount >= 5 {
                            showTestTools = true
                            logoTapCount = 0
                        }
                    }

                    cockpitInstrument
                    setupParameters
                    sourceStatus
                    if showTestTools {
                        hiddenTestTools
                    }
                }
                .padding()
            }
            .background(IPCATheme.pageBackground.ignoresSafeArea())
            .navigationTitle("Cockpit Setup")
            .navigationBarTitleDisplayMode(.inline)
            .onReceive(displayTimer) { _ in
                updateSmoothedInstrumentValues()
            }
        }
    }

    private var cockpitInstrument: some View {
        IPCACard(title: "Cockpit Instrument", systemImage: "display") {
            VStack(spacing: 12) {
                GeometryReader { proxy in
                    let width = proxy.size.width
                    let height = max(730.0, width * 0.80)
                    ZStack {
                        Canvas { context, size in
                            drawCockpitInstrument(context: &context, size: size)
                        }
                        Color.clear
                            .contentShape(Rectangle())
                            .frame(width: 150, height: min(380.0, height * 0.58))
                            .position(x: width - 190, y: height * 0.42)
                            .onTapGesture {
                                altitudeMode = altitudeMode == .calculatedBaro ? .gps : .calculatedBaro
                            }
                        Color.clear
                            .contentShape(Rectangle())
                            .frame(width: 85, height: min(350.0, height * 0.54))
                            .position(x: width - 62, y: height * 0.42)
                            .onTapGesture {
                                verticalSpeedMode = verticalSpeedMode == .calculatedBaro ? .gps : .calculatedBaro
                            }
                        Color.clear
                            .contentShape(Circle())
                            .frame(width: 270, height: 270)
                            .position(x: width / 2, y: height - 178)
                            .onTapGesture {
                                headingMode = headingMode == .magnetic ? .trueHeading : .magnetic
                            }
                    }
                    .frame(width: width, height: height)
                    .background(Color.black.opacity(0.08), in: RoundedRectangle(cornerRadius: 18))
                    .clipShape(RoundedRectangle(cornerRadius: 18))
                }
                .frame(minHeight: 750)
                .padding(.bottom, 54)

                HStack {
                    IPCAStatusPill(text: altitudeMode.rawValue, color: IPCATheme.brightBlue)
                    IPCAStatusPill(text: verticalSpeedMode.rawValue, color: IPCATheme.brightBlue)
                    IPCAStatusPill(text: headingMode.rawValue, color: IPCATheme.brightBlue)
                    Spacer()
                    Text("Tap altitude, VSI, or compass to switch source.")
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)
                }
            }
        }
    }

    private func drawCockpitInstrument(context: inout GraphicsContext, size: CGSize) {
        let width = size.width
        let height = size.height
        let horizonY = height * 0.42
        let tapeHeight = min(380.0, height * 0.58)
        let tapeTop = horizonY - tapeHeight / 2

        drawAttitude(context: &context, width: width, height: height, horizonY: horizonY)
        drawBankArc(context: &context, center: CGPoint(x: width / 2, y: horizonY - 70), radius: min(width * 0.22, 176))
        drawAircraftSymbol(context: &context, center: CGPoint(x: width / 2, y: horizonY))
        drawTape(
            context: &context,
            rect: CGRect(x: 42, y: tapeTop, width: 112, height: tapeHeight),
            title: "GS",
            value: smoothedSpeed,
            unit: "KT",
            majorStep: 10,
            minorStep: 5,
            visibleRange: 50,
            pointerSide: .right
        )
        drawTape(
            context: &context,
            rect: CGRect(x: width - 260, y: tapeTop, width: 126, height: tapeHeight),
            title: altitudeMode.rawValue,
            value: smoothedAltitude,
            unit: "FT",
            majorStep: 100,
            minorStep: 20,
            visibleRange: 700,
            pointerSide: .left
        )
        drawAltimeterSetting(context: &context, center: CGPoint(x: width - 197, y: tapeTop + tapeHeight + 28))
        drawVSI(context: &context, rect: CGRect(x: width - 92, y: horizonY - min(350.0, height * 0.54) / 2, width: 62, height: min(350.0, height * 0.54)))
        drawCompass(context: &context, center: CGPoint(x: width / 2, y: height - 260), radius: min(126, max(116, width * 0.12)))
        drawSlipBall(context: &context, center: CGPoint(x: width / 2, y: height - 104))
    }

    private func drawAttitude(context: inout GraphicsContext, width: Double, height: Double, horizonY: Double) {
        var attitude = context
        attitude.translateBy(x: width / 2, y: horizonY)
        attitude.rotate(by: .degrees(-smoothedRoll))
        let pitchOffset = smoothedPitch * 4.8
        attitude.fill(
            Path(CGRect(x: -width, y: -height * 1.35 + pitchOffset, width: width * 2, height: height * 1.35)),
            with: .color(Color(red: 0.02, green: 0.47, blue: 0.96))
        )
        attitude.fill(
            Path(CGRect(x: -width, y: pitchOffset, width: width * 2, height: height * 1.35)),
            with: .color(Color(red: 0.54, green: 0.30, blue: 0.18))
        )
        var horizon = Path()
        horizon.move(to: CGPoint(x: -width, y: pitchOffset))
        horizon.addLine(to: CGPoint(x: width, y: pitchOffset))
        attitude.stroke(horizon, with: .color(.white), lineWidth: 2)

        for pitch in stride(from: -90, through: 90, by: 5) where pitch != 0 {
            let y = -Double(pitch) * 4.8 + pitchOffset
            let isMajor = abs(pitch) % 10 == 0
            let lineWidth = isMajor ? 130.0 : 72.0
            let opacity = max(0.10, 1.0 - min(1.0, abs(y) / 360.0))
            var line = Path()
            line.move(to: CGPoint(x: -lineWidth / 2, y: y))
            line.addLine(to: CGPoint(x: lineWidth / 2, y: y))
            attitude.stroke(line, with: .color(.white.opacity(opacity)), lineWidth: 2)
            if isMajor {
                attitude.draw(Text("\(abs(pitch))").font(.caption.weight(.semibold)).foregroundColor(.white.opacity(opacity)), at: CGPoint(x: -lineWidth / 2 - 28, y: y))
                attitude.draw(Text("\(abs(pitch))").font(.caption.weight(.semibold)).foregroundColor(.white.opacity(opacity)), at: CGPoint(x: lineWidth / 2 + 28, y: y))
            }
        }
    }

    private func drawBankArc(context: inout GraphicsContext, center: CGPoint, radius: Double) {
        var arc = Path()
        arc.addArc(center: center, radius: radius, startAngle: .degrees(220), endAngle: .degrees(320), clockwise: false)
        context.stroke(arc, with: .color(.white), lineWidth: 3)
        for degree in [-60, -45, -30, -20, -10, 0, 10, 20, 30, 45, 60] {
            let angle = Double(degree) * .pi / 180
            let major = abs(degree) == 30 || abs(degree) == 60 || degree == 0
            let outer = CGPoint(x: center.x + sin(angle) * radius, y: center.y - cos(angle) * radius)
            let innerRadius = radius - (major ? 26 : 16)
            let inner = CGPoint(x: center.x + sin(angle) * innerRadius, y: center.y - cos(angle) * innerRadius)
            var tick = Path()
            tick.move(to: outer)
            tick.addLine(to: inner)
            context.stroke(tick, with: .color(.white), lineWidth: 3)
        }
        drawTriangle(context: &context, center: CGPoint(x: center.x, y: center.y - radius - 12), width: 24, height: 20, color: .white, filled: true, rotation: 180)
    }

    private func drawAircraftSymbol(context: inout GraphicsContext, center: CGPoint) {
        var symbol = Path()
        symbol.move(to: CGPoint(x: center.x - 92, y: center.y))
        symbol.addLine(to: CGPoint(x: center.x - 30, y: center.y))
        symbol.addLine(to: CGPoint(x: center.x, y: center.y - 16))
        symbol.addLine(to: CGPoint(x: center.x + 30, y: center.y))
        symbol.addLine(to: CGPoint(x: center.x + 92, y: center.y))
        context.stroke(symbol, with: .color(.white), lineWidth: 5)
        context.stroke(Path(ellipseIn: CGRect(x: center.x - 17, y: center.y - 17, width: 34, height: 34)), with: .color(.white), lineWidth: 4)
    }

    private func drawTape(context: inout GraphicsContext, rect: CGRect, title: String, value: Double, unit: String, majorStep: Double, minorStep: Double, visibleRange: Double, pointerSide: RealisticTapePointerSide) {
        let rounded = Path(roundedRect: rect, cornerRadius: 12)
        context.fill(rounded, with: .color(Color.black.opacity(0.38)))
        context.stroke(rounded, with: .color(Color.white.opacity(0.85)), lineWidth: 2)

        let start = floor((value - visibleRange / 2) / minorStep) * minorStep
        let end = ceil((value + visibleRange / 2) / minorStep) * minorStep
        var tick = start
        while tick <= end {
            let major = abs(tick.truncatingRemainder(dividingBy: majorStep)) < 0.001
            let y = rect.midY - ((tick - value) / visibleRange) * rect.height
            if y >= rect.minY - 10 && y <= rect.maxY + 10 {
                var line = Path()
                line.move(to: CGPoint(x: rect.minX + 8, y: y))
                line.addLine(to: CGPoint(x: rect.minX + (major ? 32 : 20), y: y))
                context.stroke(line, with: .color(.white), lineWidth: 2)
                if major {
                    context.draw(Text(String(format: "%.0f", tick)).font(.caption.weight(.bold)).foregroundColor(.white), at: CGPoint(x: rect.minX + 58, y: y))
                }
            }
            tick += minorStep
        }

        context.draw(Text(title).font(.caption2.weight(.bold)).foregroundColor(.white), at: CGPoint(x: rect.midX, y: rect.minY + 14))
        let box = CGRect(x: rect.midX - 38, y: rect.midY - 20, width: 76, height: 40)
        context.fill(Path(roundedRect: box, cornerRadius: 7), with: .color(.black))
        context.draw(Text(String(format: "%.0f", value)).font(.title3.monospacedDigit().weight(.black)).foregroundColor(.white), at: CGPoint(x: box.midX - 5, y: box.midY))
        context.draw(Text(unit).font(.caption2.weight(.bold)).foregroundColor(.white), at: CGPoint(x: box.maxX - 12, y: box.midY))
        let pointerX = pointerSide == .right ? box.maxX + 9 : box.minX - 9
        drawTriangle(context: &context, center: CGPoint(x: pointerX, y: box.midY), width: 13, height: 10, color: .black, filled: true, rotation: pointerSide == .right ? 90 : -90)
    }

    private func drawAltimeterSetting(context: inout GraphicsContext, center: CGPoint) {
        let text = settings.altimeterSettingValue.map { String(format: "%.2f IN", $0) } ?? "-- IN"
        let box = CGRect(x: center.x - 36, y: center.y - 12, width: 72, height: 24)
        context.fill(Path(roundedRect: box, cornerRadius: 7), with: .color(Color.black.opacity(0.78)))
        context.draw(Text(text).font(.caption2.weight(.bold)).foregroundColor(.white), at: center)
    }

    private func drawVSI(context: inout GraphicsContext, rect: CGRect) {
        let rounded = Path(roundedRect: rect, cornerRadius: 12)
        context.fill(rounded, with: .color(Color.black.opacity(0.34)))
        context.stroke(rounded, with: .color(Color.white.opacity(0.85)), lineWidth: 2)
        for mark in -2...2 {
            let y = rect.midY - Double(mark) * rect.height * 0.18
            var line = Path()
            line.move(to: CGPoint(x: rect.minX + 12, y: y))
            line.addLine(to: CGPoint(x: rect.minX + (mark == 0 ? 38 : 28), y: y))
            context.stroke(line, with: .color(.white), lineWidth: 2)
            if mark != 0 {
                context.draw(Text("\(abs(mark))").font(.caption2.weight(.bold)).foregroundColor(.white), at: CGPoint(x: rect.maxX - 16, y: y))
            }
        }
        let clamped = max(-2000, min(2000, smoothedVerticalSpeed))
        let y = rect.midY - clamped / 2000 * rect.height * 0.36
        var needle = Path()
        needle.move(to: CGPoint(x: rect.minX + 8, y: y))
        needle.addLine(to: CGPoint(x: rect.minX + 44, y: y))
        context.stroke(needle, with: .color(.white), lineWidth: 3)
        let box = CGRect(x: rect.midX - 24, y: rect.midY - 13, width: 48, height: 26)
        context.fill(Path(roundedRect: box, cornerRadius: 6), with: .color(.black))
        context.draw(Text(String(format: "%.0f", smoothedVerticalSpeed)).font(.caption2.monospacedDigit().weight(.bold)).foregroundColor(.white), at: CGPoint(x: box.midX, y: box.midY))
    }

    private func drawCompass(context: inout GraphicsContext, center: CGPoint, radius: Double) {
        context.fill(Path(ellipseIn: CGRect(x: center.x - radius, y: center.y - radius, width: radius * 2, height: radius * 2)), with: .color(Color(red: 0.18, green: 0.10, blue: 0.02).opacity(0.96)))
        context.stroke(Path(ellipseIn: CGRect(x: center.x - radius, y: center.y - radius, width: radius * 2, height: radius * 2)), with: .color(Color.white.opacity(0.86)), lineWidth: 2)

        for degree in stride(from: 0, to: 360, by: 5) {
            let screenAngle = (Double(degree) - smoothedHeading - 90) * .pi / 180
            let major = degree % 10 == 0
            let cardinal = degree % 90 == 0
            let outer = CGPoint(x: center.x + cos(screenAngle) * (radius - 4), y: center.y + sin(screenAngle) * (radius - 4))
            let inner = CGPoint(x: center.x + cos(screenAngle) * (radius - (major ? 20 : 12)), y: center.y + sin(screenAngle) * (radius - (major ? 20 : 12)))
            var tick = Path()
            tick.move(to: outer)
            tick.addLine(to: inner)
            context.stroke(tick, with: .color(.white), lineWidth: major ? 2.4 : 1.2)
            if let label = compassRoseLabel(degree) {
                let labelPoint = CGPoint(x: center.x + cos(screenAngle) * (radius - 38), y: center.y + sin(screenAngle) * (radius - 38))
                var labelContext = context
                labelContext.translateBy(x: labelPoint.x, y: labelPoint.y)
                labelContext.rotate(by: .degrees(Double(degree) - smoothedHeading))
                labelContext.draw(Text(label).font((cardinal ? Font.title3 : Font.caption).weight(.bold)).foregroundColor(.white), at: .zero)
            }
        }

        drawStaticCompassReference(context: &context, center: center, radius: radius)
        context.draw(Text(String(format: "%03.0f", smoothedHeading)).font(.headline.monospacedDigit().weight(.bold)).foregroundColor(.white), at: CGPoint(x: center.x, y: center.y - radius - 28))
        let headingBox = CGRect(x: center.x - 42, y: center.y - radius - 52, width: 84, height: 36)
        context.fill(Path(roundedRect: headingBox, cornerRadius: 8), with: .color(.black))
        context.draw(Text(String(format: "%03.0f", smoothedHeading)).font(.title3.monospacedDigit().weight(.black)).foregroundColor(.white), at: CGPoint(x: headingBox.midX, y: headingBox.midY))
    }

    private func drawStaticCompassReference(context: inout GraphicsContext, center: CGPoint, radius: Double) {
        var airplaneContext = context
        airplaneContext.translateBy(x: center.x, y: center.y)
        airplaneContext.rotate(by: .degrees(-90))
        airplaneContext.draw(Text(Image(systemName: "airplane")).font(.title2.weight(.bold)).foregroundColor(.white), at: .zero)
        for line in [
            (CGPoint(x: center.x, y: center.y + radius - 1), CGPoint(x: center.x, y: center.y + radius + 46)),
            (CGPoint(x: center.x, y: center.y - radius + 1), CGPoint(x: center.x, y: center.y - radius - 22)),
            (CGPoint(x: center.x - radius + 1, y: center.y), CGPoint(x: center.x - radius - 44, y: center.y)),
            (CGPoint(x: center.x + radius - 1, y: center.y), CGPoint(x: center.x + radius + 44, y: center.y)),
        ] {
            var path = Path()
            path.move(to: line.0)
            path.addLine(to: line.1)
            context.stroke(path, with: .color(.white), lineWidth: 2)
        }
    }

    private func drawSlipBall(context: inout GraphicsContext, center: CGPoint) {
        let rect = CGRect(x: center.x - 62, y: center.y - 16, width: 124, height: 32)
        context.fill(Path(roundedRect: rect, cornerRadius: 16), with: .color(.black))
        for xOffset in [-18.0, 18.0] {
            var line = Path()
            line.move(to: CGPoint(x: center.x + xOffset, y: rect.minY + 2))
            line.addLine(to: CGPoint(x: center.x + xOffset, y: rect.maxY - 2))
            context.stroke(line, with: .color(.white), lineWidth: 2)
        }
        let ballX = center.x + max(-42, min(42, smoothedSlip * 190))
        context.fill(Path(ellipseIn: CGRect(x: ballX - 12, y: center.y - 12, width: 24, height: 24)), with: .color(.white))
    }

    private func drawTriangle(context: inout GraphicsContext, center: CGPoint, width: Double, height: Double, color: Color, filled: Bool, rotation: Double) {
        var triangleContext = context
        triangleContext.translateBy(x: center.x, y: center.y)
        triangleContext.rotate(by: .degrees(rotation))
        var path = Path()
        path.move(to: CGPoint(x: 0, y: -height / 2))
        path.addLine(to: CGPoint(x: width / 2, y: height / 2))
        path.addLine(to: CGPoint(x: -width / 2, y: height / 2))
        path.closeSubpath()
        if filled {
            triangleContext.fill(path, with: .color(color))
        } else {
            triangleContext.stroke(path, with: .color(color), lineWidth: 3)
        }
    }

    private func attitudeBackground(width: Double, height: Double, horizonY: Double) -> some View {
        let pitchOffset = smoothedPitch * 4.8
        let centerOffset = horizonY - height / 2
        return ZStack {
            Rectangle()
                .fill(Color(red: 0.02, green: 0.47, blue: 0.96))
                .frame(width: width * 1.8, height: height * 1.25)
                .offset(y: centerOffset - height * 0.625 + pitchOffset)
            Rectangle()
                .fill(Color(red: 0.54, green: 0.30, blue: 0.18))
                .frame(width: width * 1.8, height: height * 1.25)
                .offset(y: centerOffset + height * 0.625 + pitchOffset)
            Rectangle()
                .fill(Color.white)
                .frame(width: width * 1.8, height: 2)
                .offset(y: centerOffset + pitchOffset)
        }
        .rotationEffect(.degrees(-smoothedRoll))
    }

    private func pitchLadder(horizonOffset: Double) -> some View {
        ZStack {
            ForEach([-30, -20, -10, -5, 5, 10, 20, 30], id: \.self) { pitch in
                let y = -Double(pitch) * 4.8 + smoothedPitch * 4.8
                HStack(spacing: 14) {
                    Text("\(abs(pitch))")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white)
                    Rectangle().fill(Color.white).frame(width: abs(pitch) % 10 == 0 ? 130 : 70, height: 2)
                    Text("\(abs(pitch))")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white)
                }
                .offset(y: horizonOffset + y)
            }
        }
        .rotationEffect(.degrees(-smoothedRoll))
    }

    private func bankAngleArc(width: Double) -> some View {
        ZStack {
            RealisticArcShape(startAngle: .degrees(215), endAngle: .degrees(325))
                .stroke(Color.white, lineWidth: 3)
                .frame(width: min(width * 0.52, 390), height: min(width * 0.52, 390))
            ForEach([-60, -45, -30, -20, -10, 0, 10, 20, 30, 45, 60], id: \.self) { degree in
                let major = abs(degree) == 30 || abs(degree) == 60 || degree == 0
                Rectangle()
                    .fill(Color.white)
                    .frame(width: 3, height: major ? 26 : 16)
                    .offset(y: -132)
                    .rotationEffect(.degrees(Double(degree)))
            }
            RealisticTriangle()
                .fill(Color.white)
                .frame(width: 24, height: 20)
                .offset(y: -152)
            RealisticTriangle()
                .stroke(Color.white, lineWidth: 3)
                .frame(width: 22, height: 18)
                .offset(y: -118)
                .rotationEffect(.degrees(-smoothedRoll))
        }
    }

    private var aircraftSymbol: some View {
        ZStack {
            Path { path in
                path.move(to: CGPoint(x: -88, y: 0))
                path.addLine(to: CGPoint(x: -28, y: 0))
                path.addLine(to: CGPoint(x: 0, y: -14))
                path.addLine(to: CGPoint(x: 28, y: 0))
                path.addLine(to: CGPoint(x: 88, y: 0))
            }
            .stroke(Color.white, lineWidth: 5)
            Circle().stroke(Color.white, lineWidth: 4).frame(width: 34, height: 34)
        }
    }

    private var airspeedTape: some View {
        RealisticTapeView(
            title: "GS",
            value: smoothedSpeed,
            unit: "KT",
            majorStep: 10,
            minorStep: 5,
            visibleRange: 50,
            decimals: 0,
            pointerSide: .right
        )
    }

    private var altitudeTape: some View {
        RealisticTapeView(
            title: altitudeMode.rawValue,
            value: smoothedAltitude,
            unit: "FT",
            majorStep: 100,
            minorStep: 20,
            visibleRange: 700,
            decimals: 0,
            pointerSide: .left
        )
        .contentShape(Rectangle())
        .onTapGesture {
            altitudeMode = altitudeMode == .calculatedBaro ? .gps : .calculatedBaro
        }
        .overlay(alignment: .bottom) {
            Text(settings.altimeterSettingValue.map { String(format: "%.2f IN", $0) } ?? "-- IN")
                .font(.caption2.weight(.bold))
                .foregroundStyle(.white)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color.black.opacity(0.72), in: Capsule())
                .offset(y: 30)
        }
    }

    private var verticalSpeedIndicator: some View {
        ZStack {
            RoundedRectangle(cornerRadius: 12)
                .fill(Color.black.opacity(0.38))
                .overlay(RoundedRectangle(cornerRadius: 12).stroke(Color.white.opacity(0.85), lineWidth: 2))
            ForEach(-2...2, id: \.self) { mark in
                let y = -Double(mark) * 52
                Rectangle()
                    .fill(Color.white)
                    .frame(width: mark == 0 ? 30 : 18, height: 2)
                    .offset(x: -12, y: y)
                if mark != 0 {
                    Text("\(abs(mark))")
                        .font(.caption2.weight(.bold))
                        .foregroundStyle(.white)
                        .offset(x: 17, y: y)
                }
            }
            let clamped = max(-2000, min(2000, smoothedVerticalSpeed))
            Rectangle()
                .fill(Color.white)
                .frame(width: 32, height: 3)
                .offset(x: -8, y: -clamped / 2000 * 104)
            Text(String(format: "%.0f", smoothedVerticalSpeed))
                .font(.caption2.monospacedDigit().weight(.bold))
                .foregroundStyle(.white)
                .padding(5)
                .background(Color.black, in: RoundedRectangle(cornerRadius: 6))
                .offset(y: 136)
        }
        .contentShape(Rectangle())
        .onTapGesture {
            verticalSpeedMode = verticalSpeedMode == .calculatedBaro ? .gps : .calculatedBaro
        }
    }

    private var compassRose: some View {
        ZStack {
            Circle()
                .fill(Color(red: 0.18, green: 0.10, blue: 0.02).opacity(0.95))
                .overlay(Circle().stroke(Color.white.opacity(0.86), lineWidth: 2))
            ZStack {
                ForEach(0..<72, id: \.self) { index in
                    let degree = index * 5
                    let isMajor = degree % 10 == 0
                    let isCardinal = degree % 90 == 0
                    Rectangle()
                        .fill(Color.white)
                        .frame(width: isMajor ? 2.5 : 1.4, height: isMajor ? 17 : 9)
                        .offset(y: -104)
                        .rotationEffect(.degrees(Double(degree)))
                    if isMajor {
                        Text(compassLabel(degree))
                            .font((isCardinal ? Font.title3 : Font.caption).weight(.bold))
                            .foregroundStyle(.white)
                            .offset(y: -82)
                            .rotationEffect(.degrees(Double(degree)))
                    }
                }
            }
            .rotationEffect(.degrees(-smoothedHeading))
            Rectangle()
                .fill(Color.white)
                .frame(width: 2, height: 42)
                .offset(y: 116)
            Rectangle()
                .fill(Color.white)
                .frame(width: 2, height: 42)
                .offset(y: -116)
            Rectangle()
                .fill(Color.white)
                .frame(width: 52, height: 2)
                .offset(x: -128)
            Rectangle()
                .fill(Color.white)
                .frame(width: 52, height: 2)
                .offset(x: 128)
            Image(systemName: "airplane")
                .font(.title2.weight(.bold))
                .foregroundStyle(.white)
            Text(String(format: "%03.0f", smoothedHeading))
                .font(.headline.monospacedDigit().weight(.bold))
                .foregroundStyle(.white)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color.black.opacity(0.85), in: RoundedRectangle(cornerRadius: 7))
                .offset(y: -132)
        }
        .contentShape(Circle())
        .onTapGesture {
            headingMode = headingMode == .magnetic ? .trueHeading : .magnetic
        }
    }

    private var slipBall: some View {
        ZStack {
            Capsule().fill(Color.black.opacity(0.88)).frame(width: 116, height: 32)
            Rectangle().fill(Color.white.opacity(0.82)).frame(width: 2, height: 30).offset(x: -18)
            Rectangle().fill(Color.white.opacity(0.82)).frame(width: 2, height: 30).offset(x: 18)
            Circle()
                .fill(Color.white)
                .frame(width: 24, height: 24)
                .offset(x: max(-42, min(42, smoothedSlip * 190)))
        }
    }

    private var setupParameters: some View {
        IPCACard(title: "Setup Parameters", systemImage: "slider.horizontal.3") {
            TextField("Altimeter setting / QNH inHg", text: $settings.altimeterSettingInHg)
                .keyboardType(.decimalPad)
                .textFieldStyle(.roundedBorder)
            TextField("Airport field elevation ft", text: $settings.airportElevationFt)
                .keyboardType(.numbersAndPunctuation)
                .textFieldStyle(.roundedBorder)
            TextField("OAT °C", text: $settings.oatC)
                .keyboardType(.numbersAndPunctuation)
                .textFieldStyle(.roundedBorder)

            Divider()
            Text("Attitude Reference")
                .font(.subheadline.weight(.semibold))
            HStack {
                TextField("Pitch datum, e.g. 2.5", text: $datumPitchText)
                    .keyboardType(.numbersAndPunctuation)
                    .textFieldStyle(.roundedBorder)
                TextField("Roll datum, e.g. 2.0", text: $datumRollText)
                    .keyboardType(.numbersAndPunctuation)
                    .textFieldStyle(.roundedBorder)
            }
            HStack {
                Button("Align Zero") {
                    datumPitchText = "0.0"
                    datumRollText = "0.0"
                    applyAttitudeDatum()
                }
                .buttonStyle(.borderedProminent)
                .tint(IPCATheme.brightBlue)

                Button("Set Datum Reference") {
                    applyAttitudeDatum()
                }
                .buttonStyle(.bordered)
            }

            Divider()
            Text("Compass Reference")
                .font(.subheadline.weight(.semibold))
            HStack {
                TextField("Known Magnetic HDG", text: $compassReferenceText)
                    .keyboardType(.decimalPad)
                    .textFieldStyle(.roundedBorder)
                Button("Set Compass HDG Reference") {
                    applyCompassReference()
                }
                .buttonStyle(.bordered)
            }
            LabeledContent("Compass deviation d", value: String(format: "%.1f°", settings.ahrsCalibration.compassDeviation))
            LabeledContent("Magnetic variation V", value: String(format: "%.1f°", settings.ahrsCalibration.magneticVariation))
        }
    }

    private var sourceStatus: some View {
        IPCACard(title: "Source Status", systemImage: "checklist") {
            LabeledContent("Audio", value: audio.sourceSummary)
            LabeledContent("AHRS", value: "\(ahrsBLE.connectionState.rawValue) / \(String(format: "%.1f Hz", ahrsBLE.receiveRateHz))")
            LabeledContent("GPS", value: gps.state.rawValue)
            LabeledContent("Aircraft", value: settings.selectedAircraft?.label ?? "Not selected")
            if audio.isInternalMicWarning {
                Text("Audio is not using an accepted external cockpit input.")
                    .foregroundStyle(IPCATheme.warning)
            }
            if !ahrsBLE.lastError.isEmpty {
                Text(ahrsBLE.lastError).foregroundStyle(IPCATheme.danger)
            }
            if !gps.lastError.isEmpty {
                Text(gps.lastError).foregroundStyle(IPCATheme.danger)
            }
        }
    }

    private var hiddenTestTools: some View {
        IPCACard(title: "Hidden Test Tools", systemImage: "testtube.2") {
            Text("Creates a real long WAV file plus synthetic GPS/AHRS, then uploads using the normal chunked path.")
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)
            Picker("Duration", selection: $testDurationHours) {
                Text("1 hour").tag(1)
                Text("2 hours").tag(2)
                Text("3 hours").tag(3)
                Text("4 hours").tag(4)
            }
            .pickerStyle(.segmented)
            Button(isGeneratingTest ? "Generating..." : "Generate and Upload Test Recording") {
                Task { await generateAndUploadTest() }
            }
            .buttonStyle(.borderedProminent)
            .tint(IPCATheme.warning)
            .disabled(isGeneratingTest || !settings.isServerURLConfigured)
            if !testMessage.isEmpty {
                Text(testMessage)
                    .font(.caption)
                    .foregroundStyle(testMessage.hasPrefix("Failed") ? IPCATheme.danger : IPCATheme.secondaryText)
            }
        }
    }

    private func slipValue(from sample: AHRSSample?) -> Double {
        guard let sample else { return 0 }

        if let slip = sample.slipSkid {
            return clampSlip(applySlipDeadband(slip))
        }

        let roll = sample.calibratedRoll ?? sample.aviationRoll ?? sample.roll
        if abs(roll) > 0.5 {
            return clampSlip(applySlipDeadband(sin(roll * .pi / 180.0)))
        }

        if let gravityY = sample.gravityY {
            let gravityMagnitude = sqrt(
                pow(sample.gravityX ?? 0, 2) +
                pow(gravityY, 2) +
                pow(sample.gravityZ ?? 0, 2)
            )
            if gravityMagnitude > 7.0 && gravityMagnitude < 12.0 {
                return clampSlip(applySlipDeadband(gravityY / gravityMagnitude))
            }
        }

        return clampSlip(applySlipDeadband(sin(roll * .pi / 180.0)))
    }

    private func applySlipDeadband(_ value: Double) -> Double {
        abs(value) < 0.015 ? 0 : value
    }

    private func clampSlip(_ value: Double) -> Double {
        max(-0.35, min(0.35, value))
    }

    private func updateSmoothedInstrumentValues() {
        let sample = ahrsBLE.latestSample
        let gpsSample = gps.latestSample
        let targetPitch = sample?.calibratedPitch ?? sample?.aviationPitch ?? -(sample?.pitch ?? 0)
        let targetRoll = sample?.calibratedRoll ?? sample?.aviationRoll ?? sample?.roll ?? 0
        let magneticHeading = sample?.correctedMagneticHeading ?? sample?.fusedHeading ?? sample?.magneticHeading ?? 0
        let trueHeading = sample?.trueHeading ?? SettingsStore.normalizeDegrees(magneticHeading + settings.ahrsCalibration.magneticVariation)
        let gpsAltitudeFt = (gpsSample?.altitude ?? 0) * 3.280839895
        let baroAltitudeFt = calculatedBaroAltitudeFt(gpsAltitudeFt: gpsAltitudeFt)

        appendAltitudeHistory(baro: baroAltitudeFt, gps: gpsAltitudeFt)

        smoothedPitch = smooth(smoothedPitch, targetPitch, factor: 0.16)
        smoothedRoll = smooth(smoothedRoll, targetRoll, factor: 0.16)
        smoothedHeading = smoothAngle(smoothedHeading, headingMode == .magnetic ? magneticHeading : trueHeading, factor: 0.12)
        smoothedSpeed = smooth(smoothedSpeed, gpsSample?.speedKnots ?? 0, factor: 0.14)
        smoothedAltitude = smooth(smoothedAltitude, altitudeMode == .calculatedBaro ? baroAltitudeFt : gpsAltitudeFt, factor: 0.12)
        smoothedVerticalSpeed = smooth(smoothedVerticalSpeed, verticalSpeedMode == .calculatedBaro ? verticalSpeed(useBaro: true) : verticalSpeed(useBaro: false), factor: 0.10)
        smoothedSlip = smooth(smoothedSlip, slipValue(from: sample), factor: 0.08)
    }

    private func appendAltitudeHistory(baro: Double, gps: Double) {
        let now = Date()
        if let last = altitudeHistory.last, now.timeIntervalSince(last.time) < 0.5 {
            return
        }
        altitudeHistory.append(RealisticAltitudeSnapshot(time: now, baro: baro, gps: gps))
        altitudeHistory = altitudeHistory.filter { now.timeIntervalSince($0.time) <= 12 }
    }

    private func verticalSpeed(useBaro: Bool) -> Double {
        guard let latest = altitudeHistory.last else { return 0 }
        guard let earlier = altitudeHistory.first(where: { latest.time.timeIntervalSince($0.time) >= 5 }) ?? altitudeHistory.first else { return 0 }
        let dt = latest.time.timeIntervalSince(earlier.time)
        guard dt > 1 else { return 0 }
        let latestAlt = useBaro ? latest.baro : latest.gps
        let earlierAlt = useBaro ? earlier.baro : earlier.gps
        return (latestAlt - earlierAlt) / dt * 60
    }

    private func calculatedBaroAltitudeFt(gpsAltitudeFt: Double) -> Double {
        let altimeter = settings.altimeterSettingValue ?? 29.92
        let fieldElevation = settings.airportElevationValue ?? gpsAltitudeFt
        let pressureAdjusted = gpsAltitudeFt + (29.92 - altimeter) * 1000
        guard let oat = settings.oatValue else {
            return pressureAdjusted
        }
        let isaAtField = 15.0 - (fieldElevation / 1000.0) * 1.98
        let isaDeviation = oat - isaAtField
        let heightAboveField = pressureAdjusted - fieldElevation
        return fieldElevation + heightAboveField / max(0.75, 1.0 + isaDeviation * 0.004)
    }

    private func applyAttitudeDatum() {
        guard let sample = ahrsBLE.latestSample else { return }
        let datumPitch = Double(datumPitchText) ?? 0
        let datumRoll = Double(datumRollText) ?? 0
        let aviationPitch = sample.aviationPitch ?? -sample.pitch
        let aviationRoll = sample.aviationRoll ?? sample.roll
        var calibration = settings.ahrsCalibration
        calibration.pitchLevelOffset = aviationPitch - datumPitch
        calibration.rollLevelOffset = aviationRoll - datumRoll
        calibration.levelReferenceSetAt = Date()
        settings.ahrsCalibration = calibration
        ahrsBLE.updateCalibration(calibration)
    }

    private func applyCompassReference() {
        guard let sample = ahrsBLE.latestSample, let known = Double(compassReferenceText) else { return }
        settings.setCompassDeviation(knownMagneticHeading: known, rawCompassHeading: sample.fusedHeading ?? sample.magneticHeading)
        ahrsBLE.updateCalibration(settings.ahrsCalibration)
    }

    private func smooth(_ current: Double, _ target: Double, factor: Double) -> Double {
        current + (target - current) * factor
    }

    private func smoothAngle(_ current: Double, _ target: Double, factor: Double) -> Double {
        let delta = ((target - current + 540).truncatingRemainder(dividingBy: 360)) - 180
        return SettingsStore.normalizeDegrees(current + delta * factor)
    }

    private func compassLabel(_ degrees: Int) -> String {
        switch degrees {
        case 0: "N"
        case 90: "E"
        case 180: "S"
        case 270: "W"
        default: "\(degrees / 10)"
        }
    }

    private func compassRoseLabel(_ degrees: Int) -> String? {
        switch degrees {
        case 0: return "N"
        case 90: return "E"
        case 180: return "S"
        case 270: return "W"
        default:
            return degrees % 30 == 0 ? "\(degrees / 10)" : nil
        }
    }

    private func generateAndUploadTest() async {
        isGeneratingTest = true
        testMessage = "Generating \(testDurationHours)h test recording..."
        defer { isGeneratingTest = false }
        do {
            let recording = try store.createLongTestRecording(durationHours: testDurationHours, settings: settings)
            testMessage = "Created \(Formatters.bytes(recording.fileSize)). Uploading..."
            uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
        } catch {
            testMessage = "Failed: \(error.localizedDescription)"
        }
    }
}

private struct RealisticAltitudeSnapshot {
    var time: Date
    var baro: Double
    var gps: Double
}

private enum RealisticTapePointerSide {
    case left
    case right
}

private struct RealisticTapeView: View {
    var title: String
    var value: Double
    var unit: String
    var majorStep: Double
    var minorStep: Double
    var visibleRange: Double
    var decimals: Int
    var pointerSide: RealisticTapePointerSide

    var body: some View {
        GeometryReader { proxy in
            let height = proxy.size.height
            ZStack {
                RoundedRectangle(cornerRadius: 12)
                    .fill(Color.black.opacity(0.38))
                    .overlay(RoundedRectangle(cornerRadius: 12).stroke(Color.white.opacity(0.85), lineWidth: 2))
                ForEach(tickValues(), id: \.self) { tick in
                    let major = abs(tick.truncatingRemainder(dividingBy: majorStep)) < 0.001
                    let y = height / 2 - ((tick - value) / visibleRange) * height
                    HStack {
                        Rectangle().fill(Color.white).frame(width: major ? 24 : 12, height: 2)
                        if major {
                            Text(String(format: "%.0f", tick))
                                .font(.caption.weight(.bold))
                                .foregroundStyle(.white)
                        }
                        Spacer()
                    }
                    .offset(y: y - height / 2)
                }
                HStack(spacing: 4) {
                    Text(valueText)
                        .font(.title3.monospacedDigit().weight(.black))
                    Text(unit)
                        .font(.caption2.weight(.bold))
                }
                .foregroundStyle(.white)
                .padding(.horizontal, 8)
                .padding(.vertical, 7)
                .background(Color.black, in: RoundedRectangle(cornerRadius: 7))
                .overlay(alignment: pointerSide == .right ? .trailing : .leading) {
                    RealisticTriangle()
                        .fill(Color.black)
                        .frame(width: 10, height: 14)
                        .rotationEffect(.degrees(pointerSide == .right ? 90 : -90))
                        .offset(x: pointerSide == .right ? 8 : -8)
                }
                VStack {
                    Text(title)
                        .font(.caption2.weight(.bold))
                        .foregroundStyle(.white.opacity(0.9))
                        .padding(.top, 8)
                    Spacer()
                }
            }
        }
    }

    private var valueText: String {
        decimals == 0 ? String(format: "%.0f", value) : String(format: "%.\(decimals)f", value)
    }

    private func tickValues() -> [Double] {
        let start = floor((value - visibleRange / 2) / minorStep) * minorStep
        let end = ceil((value + visibleRange / 2) / minorStep) * minorStep
        var ticks: [Double] = []
        var tick = start
        while tick <= end {
            ticks.append(tick)
            tick += minorStep
        }
        return ticks
    }
}

private struct RealisticArcShape: Shape {
    var startAngle: Angle
    var endAngle: Angle

    func path(in rect: CGRect) -> Path {
        var path = Path()
        path.addArc(
            center: CGPoint(x: rect.midX, y: rect.midY),
            radius: min(rect.width, rect.height) / 2,
            startAngle: startAngle,
            endAngle: endAngle,
            clockwise: false
        )
        return path
    }
}

private struct RealisticTriangle: Shape {
    func path(in rect: CGRect) -> Path {
        var path = Path()
        path.move(to: CGPoint(x: rect.midX, y: rect.minY))
        path.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY))
        path.addLine(to: CGPoint(x: rect.minX, y: rect.maxY))
        path.closeSubpath()
        return path
    }
}
