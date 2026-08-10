import SwiftUI
import UIKit
import UniformTypeIdentifiers

private let cvrReservationTimeFormatter: DateFormatter = {
    let formatter = DateFormatter()
    formatter.calendar = Calendar(identifier: .gregorian)
    formatter.locale = Locale(identifier: "en_US_POSIX")
    formatter.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
    formatter.dateFormat = "HH:mm"
    return formatter
}()

private func cvrReservationTime(_ date: Date?) -> String? {
    date.map { cvrReservationTimeFormatter.string(from: $0) }
}

private struct CVRQuarterHourTimePicker: UIViewRepresentable {
    @Binding var selection: Date

    func makeCoordinator() -> Coordinator {
        Coordinator(selection: $selection)
    }

    func makeUIView(context: Context) -> UIDatePicker {
        let picker = UIDatePicker()
        picker.datePickerMode = .time
        picker.preferredDatePickerStyle = .compact
        picker.minuteInterval = 15
        picker.locale = Locale(identifier: "en_GB")
        picker.calendar = Calendar(identifier: .gregorian)
        picker.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        picker.addTarget(context.coordinator, action: #selector(Coordinator.changed(_:)), for: .valueChanged)
        return picker
    }

    func updateUIView(_ picker: UIDatePicker, context: Context) {
        if abs(picker.date.timeIntervalSince(selection)) > 0.5 {
            picker.date = selection
        }
    }

    final class Coordinator: NSObject {
        private var selection: Binding<Date>

        init(selection: Binding<Date>) {
            self.selection = selection
        }

        @objc func changed(_ picker: UIDatePicker) {
            selection.wrappedValue = picker.date
        }
    }
}

private struct CVRReservationTimePickerRow: View {
    let title: String
    @Binding var selection: Date

    var body: some View {
        HStack {
            Text(title)
            Spacer()
            CVRQuarterHourTimePicker(selection: $selection)
                .frame(width: 112, height: 36)
        }
    }
}

@MainActor
private func completeSimulationDemo(
    workflow: CVRWorkflowStore,
    settings: SettingsStore,
    beacon: AvionicsBeaconManager
) {
    if workflow.finishSimulationDemo(clearAvionicsSimulation: {
        beacon.clearSimulationOverride()
    }) {
        settings.isSimulationModeEnabled = false
    }
}

@MainActor
private func operationalToggle(_ title: String, isOn: Binding<Bool>) -> some View {
    Toggle(isOn: isOn) {
        Text(title)
            .font(.caption.weight(.bold))
            .foregroundStyle(.white)
    }
    .tint(CVROperationalPalette.success)
}

private struct CVRScheduleDutySyncBanner: View {
    let info: CVRScheduleDutySyncInfo

    var body: some View {
        CVROperationalWarningCard(
            title: title,
            message: info.message,
            iconName: iconName,
            color: color
        )
    }

    private var title: String {
        switch info.phase {
        case .queued: "RESERVATION SYNC QUEUED"
        case .syncing: "SYNCING RESERVATION"
        case .synced: "RESERVATION SYNCED"
        case .syncedWithWarning: "RESERVATION SYNCED · OVERLAP"
        case .attention: "RESERVATION SYNC NEEDS ATTENTION"
        }
    }

    private var iconName: String {
        switch info.phase {
        case .queued: "icloud.slash.fill"
        case .syncing: "arrow.triangle.2.circlepath.icloud.fill"
        case .synced: "checkmark.icloud.fill"
        case .syncedWithWarning: "exclamationmark.icloud.fill"
        case .attention: "exclamationmark.icloud.fill"
        }
    }

    private var color: Color {
        switch info.phase {
        case .queued: CVROperationalPalette.warning
        case .syncing: CVROperationalPalette.secondaryBlue
        case .synced: CVROperationalPalette.success
        case .syncedWithWarning: CVROperationalPalette.warning
        case .attention: CVROperationalPalette.critical
        }
    }
}

private extension View {
    func operationalBottomFade() -> some View {
        overlay(alignment: .bottom) {
            LinearGradient(
                colors: [
                    Color.black.opacity(0),
                    Color.black.opacity(0.82),
                    Color.black
                ],
                startPoint: .top,
                endPoint: .bottom
            )
            .frame(height: 108)
            .allowsHitTesting(false)
            .accessibilityHidden(true)
        }
    }
}

struct OperationalTabsView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var garminSDCard: GarminSDCardImportCoordinator
    @EnvironmentObject private var flightLogs: CVRFlightLogStore
    @EnvironmentObject private var network: NetworkMonitor
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool
    @State private var logoTapCount = 0
    @State private var lastLogoTapAt: Date?

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            VStack(spacing: 0) {
                CVROperationalHeaderView(
                    aircraftRegistration: settings.selectedAircraft?.registration
                        ?? workflow.state.activeDispatch?.tailNumber
                        ?? "NO AIRCRAFT",
                    unitIdentifier: settings.cvrUnitIdentifier,
                    metrics: metrics,
                    onLogoTap: handleHiddenAdminLogoTap
                )
                .padding(.horizontal, metrics.outerHorizontalPadding)
                .padding(.vertical, metrics.outerVerticalPadding)
                .frame(maxWidth: .infinity)
                .background(CVROperationalPalette.background)
                .overlay(alignment: .bottom) {
                    Rectangle()
                        .fill(CVROperationalPalette.cardBorder.opacity(0.45))
                        .frame(height: 1)
                }
                .zIndex(2)

                selectedTabContent
                    .operationalBottomFade()
                    .frame(maxWidth: .infinity, maxHeight: .infinity)

                if let handoff = workflow.state.postFlightGarminHandoff,
                   handoff.phase != .completed {
                    CVRPostFlightGarminHandoffBanner(handoff: handoff)
                        .padding(.horizontal, metrics.outerHorizontalPadding)
                        .padding(.bottom, 8)
                }

                if settings.isSimulationModeEnabled {
                    SimulationModeChrome()
                }

                OperationalBottomTabBar()
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
        }
        .onChange(of: garminSDCard.lastImportResult) { _, result in
            guard result?.kind == .success,
                  let handoff = workflow.state.postFlightGarminHandoff,
                  handoff.phase == .selectingCSV || handoff.phase == .uploadingCSV else {
                return
            }
            workflow.advancePostFlightGarminHandoff(to: .uploadVerified)
            uploadManager.uploadQueuedWorkflowComponents(
                workflow: workflow,
                settings: settings,
                trigger: .explicitRetry
            )
        }
        .sheet(isPresented: $garminSDCard.showingFileSheet) {
            GarminSDCardImportSheet(coordinator: garminSDCard)
                .environmentObject(settings)
                .environmentObject(flightLogs)
                .environmentObject(network)
                .environmentObject(uploadManager)
        }
        .sheet(isPresented: $garminSDCard.showingSetupSheet) {
            GarminSDCardFolderPicker(
                onPick: { url in
                    garminSDCard.showingSetupSheet = false
                    garminSDCard.selectFolder(url, settings: settings)
                    garminSDCard.showingFileSheet = true
                },
                onCancel: { garminSDCard.showingSetupSheet = false }
            )
        }
    }

    /// Hidden Admin entry requires five logo taps within 3 seconds (same as Status dashboard).
    private func handleHiddenAdminLogoTap() {
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

    @ViewBuilder
    private var selectedTabContent: some View {
        switch workflow.state.selectedTab {
        case .scheduled:
            ScheduledFlightsView(showAdminUnlock: $showAdminUnlock)
        case .dispatch:
            DispatchWorkflowView(showAdminUnlock: $showAdminUnlock)
        case .recorder:
            RecorderWorkflowView(adminUnlocked: $adminUnlocked, showAdminUnlock: $showAdminUnlock)
        case .inFlight:
            InFlightWorkflowView(showAdminUnlock: $showAdminUnlock)
        case .garmin:
            FlightLogView(adminUnlocked: adminUnlocked)
        case .log:
            FlightLogView(adminUnlocked: adminUnlocked)
        }
    }
}

private struct CVRPostFlightGarminHandoffBanner: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @State private var showingLegReview = false
    let handoff: CVRPostFlightGarminHandoff

    var body: some View {
        TimelineView(.periodic(from: Date(), by: 1)) { timeline in
            let remaining = max(0, Int(ceil(handoff.countdownEndsAt.timeIntervalSince(timeline.date))))
            let isCountdownWarning = handoff.phase == .waitingForGarminData && remaining > 0
            VStack(alignment: .leading, spacing: isCountdownWarning ? 14 : 8) {
                HStack {
                    Image(systemName: iconName(remaining: remaining))
                        .font(isCountdownWarning ? .title : .body)
                    Text(title(remaining: remaining))
                        .font(isCountdownWarning ? .title2.weight(.heavy) : .caption.weight(.bold))
                        .tracking(0.8)
                    Spacer()
                    if handoff.phase == .waitingForGarminData && remaining > 0 {
                        Text("\(remaining)s")
                            .font(.headline.weight(.bold).monospacedDigit())
                    }
                }
                Text(message(remaining: remaining))
                    .font(isCountdownWarning ? .headline.weight(.bold) : .caption.weight(.semibold))
                if handoff.phase == .waitingForGarminData && remaining == 0 {
                    CVROperationalActionButton(
                        title: "SD CARD INSERTED",
                        subtitle: "Continue to Garmin CSV selection",
                        color: CVROperationalPalette.primaryBlue
                    ) {
                        workflow.advancePostFlightGarminHandoff(to: .selectingCSV)
                    }
                } else if handoff.phase == .uploadVerified {
                    CVROperationalActionButton(
                        title: "CONTINUE TO LEG VERIFICATION",
                        subtitle: "Open the verified leg editor",
                        color: CVROperationalPalette.primaryBlue
                    ) {
                        workflow.advancePostFlightGarminHandoff(to: .verifyingLegs)
                        showingLegReview = true
                    }
                } else if handoff.phase == .returnCardToGarmin {
                    CVROperationalActionButton(
                        title: "I CONFIRM THE SD CARD IS BACK IN THE GARMIN DEVICE",
                        subtitle: "Complete the post-flight Garmin handoff",
                        color: CVROperationalPalette.primaryBlue
                    ) {
                        workflow.completePostFlightGarminHandoff()
                    }
                }
            }
            .foregroundStyle(color(remaining: remaining))
            .padding(isCountdownWarning ? 22 : 14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(color(remaining: remaining).opacity(0.13), in: RoundedRectangle(cornerRadius: 14))
            .overlay(RoundedRectangle(cornerRadius: 14).stroke(color(remaining: remaining), lineWidth: 1))
        }
        .sheet(isPresented: $showingLegReview) {
            CVROperationalLegReviewSheet(handoff: handoff)
                .environmentObject(workflow)
                .environmentObject(settings)
        }
    }

    private func title(remaining: Int) -> String {
        switch handoff.phase {
        case .waitingForGarminData:
            remaining > 0 ? "WARNING! WAITING FOR GARMIN DATA" : "PLEASE INSERT THE SD CARD IN THE CVR UNIT"
        case .insertCardInCVR, .selectingCSV: "SELECT THE GARMIN CSV"
        case .uploadingCSV: "UPLOADING GARMIN CSV"
        case .uploadVerified: "GARMIN CSV UPLOAD SUCCESSFUL"
        case .verifyingLegs: "VERIFY DERIVED LEGS"
        case .returnCardToGarmin: "RETURN SD CARD TO GARMIN"
        case .completed: "GARMIN HANDOFF COMPLETE"
        }
    }

    private func message(remaining: Int) -> String {
        switch handoff.phase {
        case .waitingForGarminData:
            remaining > 0
                ? "DO NOT TAKE THE SD CARD OUT OF THE GARMIN UNIT YET."
                : "The Garmin data flush period is complete. Insert the SD card into this CVR Unit."
        case .insertCardInCVR, .selectingCSV:
            "Select the CSV that matches this aircraft and completed flight."
        case .uploadingCSV:
            "Keep the SD card inserted until upload and server linkage are verified."
        case .uploadVerified:
            "The CSV is stored and linked to this flight. Continue to leg verification."
        case .verifyingLegs:
            "The leg editor is available from this flight in the Log tab."
        case .returnCardToGarmin:
            "Remove the SD card from the CVR Unit and put it back into the Garmin device."
        case .completed:
            "The SD card return was confirmed."
        }
    }

    private func color(remaining: Int) -> Color {
        handoff.phase == .waitingForGarminData && remaining > 0
            ? CVROperationalPalette.warning
            : CVROperationalPalette.success
    }

    private func iconName(remaining: Int) -> String {
        handoff.phase == .waitingForGarminData && remaining > 0
            ? "clock.badge.exclamationmark"
            : "sdcard.fill"
    }
}

private struct CVROperationalLegReviewSheet: View {
    @Environment(\.dismiss) private var dismiss
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    let dispatchUUID: String
    let flightRecordUUID: String?
    let advancesPostFlightHandoff: Bool
    let onAccepted: ((String) -> Void)?
    @State private var legs: [CVROperationalLegReviewLeg] = []
    @State private var preview: CVROperationalLegReviewPreview?
    @State private var evidenceSHA256: String?
    @State private var isLoading = true
    @State private var isSaving = false
    @State private var errorMessage = ""
    @State private var loadingMessage = "Deriving legs from verified Garmin evidence…"
    @State private var isWaitingForCheckIn = false
    @State private var seededFromLocalCheckIn = false
    @State private var undoStack: [[CVROperationalLegReviewLeg]] = []

    init(
        handoff: CVRPostFlightGarminHandoff,
        onAccepted: ((String) -> Void)? = nil
    ) {
        dispatchUUID = handoff.dispatchUUID
        flightRecordUUID = handoff.flightRecordUUID
        advancesPostFlightHandoff = true
        self.onAccepted = onAccepted
    }

    init(
        dispatchUUID: String,
        flightRecordUUID: String? = nil,
        advancesPostFlightHandoff: Bool = false,
        onAccepted: ((String) -> Void)? = nil
    ) {
        self.dispatchUUID = dispatchUUID
        self.flightRecordUUID = flightRecordUUID
        self.advancesPostFlightHandoff = advancesPostFlightHandoff
        self.onAccepted = onAccepted
    }

    var body: some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: 14) {
                        reviewHeader
                        crewCard
                        if isLoading {
                            CVROperationalWarningCard(
                                title: seededFromLocalCheckIn
                                    ? "LOCAL CHECK-IN LOADED"
                                    : "PREPARING LEG REVIEW",
                                message: loadingMessage,
                                iconName: "arrow.triangle.2.circlepath",
                                color: CVROperationalPalette.secondaryBlue
                            )
                        }
                        ForEach(Array(legs.indices), id: \.self) { index in
                            legEditorCard(index: index)
                        }
                        CVROperationalActionButton(
                            title: "ADD LEG",
                            subtitle: legs.isEmpty
                                ? "Create the first leg from the saved Check-In"
                                : "Append another evidence-review leg",
                            color: CVROperationalPalette.secondaryBlue
                        ) {
                            addLeg()
                        }
                        ForEach(validationWarnings, id: \.self) { warning in
                            CVROperationalWarningCard(
                                title: "VERIFY TOTALS",
                                message: warning,
                                iconName: "exclamationmark.triangle.fill",
                                color: CVROperationalPalette.warning
                            )
                        }
                        if !errorMessage.isEmpty {
                            CVROperationalWarningCard(
                                title: "LEG REVIEW",
                                message: errorMessage,
                                iconName: isWaitingForCheckIn
                                    ? "arrow.triangle.2.circlepath"
                                    : "xmark.octagon.fill",
                                color: isWaitingForCheckIn
                                    ? CVROperationalPalette.warning
                                    : CVROperationalPalette.critical
                            )
                            CVROperationalActionButton(
                                title: "RETRY LEG REVIEW",
                                subtitle: "Synchronize Check-In and reload verified evidence",
                                color: CVROperationalPalette.secondaryBlue
                            ) {
                                Task { await loadPreview() }
                            }
                        }
                        CVROperationalActionButton(
                            title: isSaving ? "SAVING LEGS" : "ACCEPT LEGS",
                            subtitle: validationWarnings.isEmpty
                                ? "Save verified legs on this CVR Unit · server sync follows later"
                                : "Correct the amber warnings before acceptance",
                            color: validationWarnings.isEmpty
                                ? CVROperationalPalette.success
                                : CVROperationalPalette.warning
                        ) {
                            Task { await acceptLegs() }
                        }
                        .disabled(isLoading || isSaving || legs.isEmpty || !validationWarnings.isEmpty)
                        .opacity(isLoading || isSaving || legs.isEmpty || !validationWarnings.isEmpty ? 0.55 : 1)
                    }
                    .padding(20)
                    .padding(.bottom, 24)
                }
            }
            .navigationTitle("VERIFY LEGS")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("NOT YET") { dismiss() }
                        .font(.headline.weight(.bold))
                }
                ToolbarItem(placement: .primaryAction) {
                    Button {
                        undoLastChange()
                    } label: {
                        Label("UNDO", systemImage: "arrow.uturn.backward")
                    }
                        .disabled(undoStack.isEmpty)
                        .font(.headline.weight(.bold))
                }
            }
            .task { await loadPreview() }
        }
        .preferredColorScheme(.dark)
    }

    private var reviewHeader: some View {
        HStack(spacing: 14) {
            Image(systemName: "list.clipboard.fill")
                .font(.title)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            VStack(alignment: .leading, spacing: 4) {
                Text("VERIFY LEGS")
                    .font(.title3.weight(.heavy))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                Text("ALL DATES AND TIMES ARE CALIFORNIA LOCAL TIME")
                    .font(.caption.weight(.bold))
                    .tracking(0.7)
                    .foregroundStyle(CVROperationalPalette.warning)
            }
            Spacer()
        }
        .padding(18)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    @ViewBuilder
    private var crewCard: some View {
        if let crew = preview?.crew, !crew.isEmpty {
            VStack(alignment: .leading, spacing: 10) {
                Label("CREW · ALL LEGS", systemImage: "person.2.fill")
                    .font(.headline.weight(.heavy))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                ForEach(Array(crew.enumerated()), id: \.offset) { _, member in
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text(member.personName)
                            .font(.subheadline.weight(.bold))
                            .foregroundStyle(CVROperationalPalette.textPrimary)
                        Text("— \(crewRoleLabel(member))")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(member.isPIC == true
                                ? CVROperationalPalette.warning
                                : CVROperationalPalette.textSecondary)
                        Spacer()
                    }
                }
            }
            .padding(18)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
            .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private func crewRoleLabel(_ member: CVRScheduledCrewMember) -> String {
        let role = member.role
            .replacingOccurrences(of: "_", with: " ")
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .capitalized
        return member.isPIC == true ? "\(role) (PIC)" : role
    }

    private func legEditorCard(index: Int) -> some View {
        let leg = $legs[index]
        return VStack(alignment: .leading, spacing: 14) {
            HStack {
                Text("LEG \(index + 1)")
                    .font(.headline.weight(.heavy))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                Spacer()
                Button(role: .destructive) {
                    deleteLeg(at: index)
                } label: {
                    Label("DELETE LEG", systemImage: "trash.fill")
                        .font(.caption.weight(.bold))
                }
                .disabled(legs.count <= 1)
            }
            HStack(spacing: 12) {
                operationalTextField("FROM", text: leg.departureAirport, keyboard: .asciiCapable)
                Image(systemName: "arrow.right")
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                operationalTextField("TO", text: leg.arrivalAirport, keyboard: .asciiCapable)
            }
            localDateTimeEditor(
                title: "OFF BLOCK · LOCAL",
                timestamp: leg.offBlockUTC
            )
            localDateTimeEditor(
                title: "ON BLOCK · LOCAL",
                timestamp: leg.onBlockUTC
            )
            HStack(spacing: 12) {
                operationalTextField(
                    "START HOBBS",
                    text: numberBinding(index: index, keyPath: \.startingHobbs),
                    keyboard: .decimalPad
                )
                operationalTextField(
                    "END HOBBS",
                    text: numberBinding(index: index, keyPath: \.endingHobbs),
                    keyboard: .decimalPad
                )
            }
            metricPair(
                leftTitle: "BLOCK TIME",
                leftValue: durationValue(hobbsDuration(at: index)),
                rightTitle: "BLOCK CUMUL",
                rightValue: durationValue(cumulativeHobbs(through: index))
            )
            HStack(spacing: 12) {
                operationalTextField(
                    "START TACHO",
                    text: numberBinding(index: index, keyPath: \.startingTacho),
                    keyboard: .decimalPad
                )
                operationalTextField(
                    "END TACHO",
                    text: numberBinding(index: index, keyPath: \.endingTacho),
                    keyboard: .decimalPad
                )
            }
            metricPair(
                leftTitle: "TACHO TIME",
                leftValue: durationValue(tachoDuration(at: index)),
                rightTitle: "TACHO CUMUL",
                rightValue: durationValue(cumulativeTacho(through: index))
            )
            HStack(spacing: 12) {
                operationalTextField(
                    "FUEL ONBOARD",
                    text: optionalNumberBinding(index: index, keyPath: \.fuelOnboard),
                    keyboard: .decimalPad
                )
                operationalTextField(
                    "FUEL REMAINING",
                    text: optionalNumberBinding(index: index, keyPath: \.fuelRemaining),
                    keyboard: .decimalPad
                )
            }
            metricPair(
                leftTitle: "CONSUMPTION",
                leftValue: fuelValue(fuelConsumption(at: index)),
                rightTitle: "CUMUL",
                rightValue: fuelValue(cumulativeFuel(through: index))
            )
            VStack(spacing: 10) {
                operationStepper(
                    title: "TAKEOFFS",
                    value: leg.takeoffCount
                )
                operationStepper(
                    title: "LANDINGS",
                    value: leg.landingCount
                )
            }
        }
        .padding(18)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func operationalTextField(
        _ title: String,
        text: Binding<String>,
        keyboard: UIKeyboardType
    ) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption2.weight(.bold))
                .tracking(0.6)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            TextField(title, text: text)
                .textInputAutocapitalization(.characters)
                .autocorrectionDisabled()
                .keyboardType(keyboard)
                .font(.title3.weight(.bold).monospaced())
                .foregroundStyle(CVROperationalPalette.textPrimary)
                .padding(12)
                .frame(maxWidth: .infinity, minHeight: 52)
                .background(CVROperationalPalette.background.opacity(0.72), in: RoundedRectangle(cornerRadius: 10))
                .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .frame(maxWidth: .infinity)
    }

    private func localDateTimeEditor(
        title: String,
        timestamp: Binding<String>
    ) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(0.7)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 4) {
                    Text("DATE")
                        .font(.caption2.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    DatePicker(
                        "Date",
                        selection: localComponentBinding(timestamp: timestamp, component: .date),
                        displayedComponents: .date
                    )
                    .labelsHidden()
                    .environment(\.locale, Locale(identifier: "en_GB"))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(alignment: .leading, spacing: 4) {
                    Text("TIME · LOCAL")
                        .font(.caption2.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    DatePicker(
                        "Time",
                        selection: localComponentBinding(timestamp: timestamp, component: .time),
                        displayedComponents: .hourAndMinute
                    )
                    .labelsHidden()
                    .environment(\.locale, Locale(identifier: "en_GB"))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .padding(12)
        .background(CVROperationalPalette.background.opacity(0.58), in: RoundedRectangle(cornerRadius: 10))
    }

    private func operationStepper(title: String, value: Binding<Int>) -> some View {
        Stepper(value: value, in: 0...99) {
            HStack {
            Text(title)
                    .font(.headline.weight(.heavy))
                    .tracking(0.6)
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                Spacer()
                Text("\(value.wrappedValue)")
                    .font(.title2.weight(.heavy).monospacedDigit())
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .frame(minWidth: 34)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity)
        .background(CVROperationalPalette.background.opacity(0.58), in: RoundedRectangle(cornerRadius: 10))
    }

    private func metricPair(
        leftTitle: String,
        leftValue: String,
        rightTitle: String,
        rightValue: String
    ) -> some View {
        HStack(spacing: 12) {
            metricBox(title: leftTitle, value: leftValue)
            metricBox(title: rightTitle, value: rightValue)
        }
    }

    private func metricBox(title: String, value: String) -> some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(title)
                .font(.caption2.weight(.bold))
                .tracking(0.6)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.title3.weight(.heavy).monospacedDigit())
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .padding(12)
        .frame(maxWidth: .infinity, minHeight: 58, alignment: .leading)
        .background(CVROperationalPalette.background.opacity(0.58), in: RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func hobbsDuration(at index: Int) -> Double {
        guard legs.indices.contains(index) else { return 0 }
        return max(0, legs[index].endingHobbs - legs[index].startingHobbs)
    }

    private func cumulativeHobbs(through index: Int) -> Double {
        legs.prefix(index + 1).reduce(0) { $0 + max(0, $1.endingHobbs - $1.startingHobbs) }
    }

    private func tachoDuration(at index: Int) -> Double {
        guard legs.indices.contains(index) else { return 0 }
        return max(0, legs[index].endingTacho - legs[index].startingTacho)
    }

    private func cumulativeTacho(through index: Int) -> Double {
        legs.prefix(index + 1).reduce(0) { $0 + max(0, $1.endingTacho - $1.startingTacho) }
    }

    private func fuelConsumption(at index: Int) -> Double? {
        guard legs.indices.contains(index),
              let onboard = legs[index].fuelOnboard,
              let remaining = legs[index].fuelRemaining else {
            return nil
        }
        return max(0, onboard - remaining)
    }

    private func cumulativeFuel(through index: Int) -> Double? {
        let selected = legs.prefix(index + 1)
        guard selected.allSatisfy({ $0.fuelOnboard != nil && $0.fuelRemaining != nil }) else {
            return nil
        }
        return selected.reduce(0) {
            $0 + max(0, ($1.fuelOnboard ?? 0) - ($1.fuelRemaining ?? 0))
        }
    }

    private func durationValue(_ value: Double) -> String {
        String(format: "%.1f", value)
    }

    private func fuelValue(_ value: Double?) -> String {
        value.map { String(format: "%.1f USG", $0) } ?? "— USG"
    }

    private func loadPreview() async {
        isLoading = true
        isWaitingForCheckIn = false
        loadingMessage = "Loading saved Check-In and Garmin evidence from this CVR Unit…"
        errorMessage = ""
        seedFirstLegFromLocalCheckInIfNeeded()
        if seededFromLocalCheckIn {
            isLoading = false
        }
        guard let baseURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              !credential.isEmpty else {
            isLoading = false
            return
        }
        if closureNeedsSynchronization {
            uploadManager.uploadQueuedWorkflowComponents(
                workflow: workflow,
                settings: settings,
                trigger: .routine
            )
        }
        let legsBeforeServerRefresh = legs
        do {
            let response = try await APIClient(serverURL: baseURL).operationalLegReview(
                dispatchUUID: dispatchUUID,
                credential: credential
            )
            preview = response.review
            if legs == legsBeforeServerRefresh {
                if !response.review.proposedLegs.isEmpty {
                    legs = response.review.proposedLegs
                } else if seededFromLocalCheckIn, legs.count == 1 {
                    legs[0].startingHobbs = response.review.startingHobbs ?? legs[0].startingHobbs
                    legs[0].endingHobbs = response.review.endingHobbs ?? legs[0].endingHobbs
                    legs[0].startingTacho = response.review.startingTacho ?? legs[0].startingTacho
                    legs[0].endingTacho = response.review.endingTacho ?? legs[0].endingTacho
                    legs[0].fuelOnboard = response.review.fuelStart ?? legs[0].fuelOnboard
                    legs[0].fuelRemaining = response.review.fuelEnd ?? legs[0].fuelRemaining
                    legs[0].takeoffCount = response.review.verifiedTakeoffCount ?? legs[0].takeoffCount
                    legs[0].landingCount = response.review.verifiedLandingCount ?? legs[0].landingCount
                    legs[0].offBlockUTC = response.review.offBlockUTC ?? legs[0].offBlockUTC
                    legs[0].onBlockUTC = response.review.onBlockUTC ?? legs[0].onBlockUTC
                }
                renumberLegs()
            }
            evidenceSHA256 = response.review.evidenceSHA256
        } catch {
            // Server enrichment is optional. Locally saved Check-In and Garmin
            // evidence remain fully editable and acceptable without connectivity.
            if legs.isEmpty {
                errorMessage = "No complete local Check-In was found for this flight."
            }
        }
        isLoading = false
    }

    private func seedFirstLegFromLocalCheckInIfNeeded() {
        guard legs.isEmpty, let flightRecordUUID else { return }
        if let flight = workflow.state.activeFlightRecord,
           let dispatch = workflow.state.activeDispatch,
           flight.id.lowercased() == flightRecordUUID.lowercased() {
            seedFirstLeg(
                dispatch: dispatch,
                flight: flight,
                events: workflow.state.flightEvents
            )
            return
        }
        guard let archive = workflow.archives.first(where: {
            $0.flightRecordID.lowercased() == flightRecordUUID.lowercased()
        }) else {
            return
        }
        seedFirstLeg(
            dispatch: archive.dispatch,
            flight: archive.flightRecord,
            events: archive.flightEvents
        )
    }

    private func seedFirstLeg(
        dispatch: CVRDispatchRecord,
        flight: CVRIncompleteFlightRecord,
        events: [CVRFlightEventRecord]
    ) {
        guard let startingHobbs = dispatch.startingHobbs,
              let endingHobbs = flight.endingHobbs,
              let startingTacho = dispatch.startingTacho,
              let endingTacho = flight.endingTacho else {
            return
        }
        let offBlock = events.first {
            $0.flightRecordID == flight.id && $0.eventType == "engine_start_off_block"
        }?.timestampUTC ?? flight.recordingStartedAt ?? flight.createdAt
        let onBlock = events.last {
            $0.flightRecordID == flight.id
                && ($0.eventType == "engine_shutdown_on_block"
                    || $0.eventType == "transient_stop_on_block")
        }?.timestampUTC ?? flight.calculatedArrivalAt ?? flight.updatedAt
        let route = dispatch.informativeRouteAirports ?? []
        let takeoffEvents = events.filter {
            $0.flightRecordID == flight.id
                && ($0.eventType == "gps_takeoff_provisional"
                    || $0.eventType == "manual_takeoff_adjustment")
        }.count
        let landingEvents = events.filter {
            $0.flightRecordID == flight.id
                && ($0.eventType == "gps_landing_provisional"
                    || $0.eventType == "manual_landing_adjustment")
        }.count
        let endingFuel = numericFuel(flight.fuelRemaining)
        if let gpsLegs = gpsDerivedLegs(
            flightRecordID: flight.id,
            events: events,
            offBlock: offBlock,
            onBlock: onBlock,
            startingHobbs: startingHobbs,
            endingHobbs: endingHobbs,
            startingTacho: startingTacho,
            endingTacho: endingTacho,
            startingFuel: numericFuel(dispatch.fuelOnboard),
            endingFuel: endingFuel
        ), !gpsLegs.isEmpty {
            legs = gpsLegs
            seededFromLocalCheckIn = true
            return
        }
        legs = [CVROperationalLegReviewLeg(
            sequenceNumber: 1,
            departureAirport: dispatch.plannedDepartureAirport.isEmpty
                ? (route.first ?? "")
                : dispatch.plannedDepartureAirport,
            arrivalAirport: (flight.verifiedDestinationAirport ?? dispatch.plannedDestinationAirport).isEmpty
                ? (route.last ?? "")
                : (flight.verifiedDestinationAirport ?? dispatch.plannedDestinationAirport),
            offBlockUTC: Self.utcString(from: offBlock),
            onBlockUTC: Self.utcString(from: onBlock),
            startingHobbs: startingHobbs,
            endingHobbs: endingHobbs,
            startingTacho: startingTacho,
            endingTacho: endingTacho,
            takeoffCount: flight.verifiedTakeoffCount
                ?? flight.autoDetectedTakeoffCount
                ?? takeoffEvents,
            landingCount: flight.verifiedLandingCount
                ?? flight.autoDetectedLandingCount
                ?? landingEvents,
            fuelOnboard: numericFuel(dispatch.fuelOnboard),
            fuelRemaining: endingFuel
        )]
        seededFromLocalCheckIn = true
    }

    private func gpsDerivedLegs(
        flightRecordID: String,
        events: [CVRFlightEventRecord],
        offBlock: Date,
        onBlock: Date,
        startingHobbs: Double,
        endingHobbs: Double,
        startingTacho: Double,
        endingTacho: Double,
        startingFuel: Double?,
        endingFuel: Double?
    ) -> [CVROperationalLegReviewLeg]? {
        struct EvidenceLeg {
            var departure: String
            var arrival: String
            var takeoffAt: Date
            var landingAt: Date
        }

        let evidence = events
            .filter {
                $0.flightRecordID == flightRecordID
                    && ($0.eventType == "gps_takeoff_provisional"
                        || $0.eventType == "gps_landing_provisional")
            }
            .sorted { $0.timestampUTC < $1.timestampUTC }
        var pendingTakeoff: (airport: String, timestamp: Date)?
        var evidenceLegs: [EvidenceLeg] = []
        for event in evidence {
            let airport = event.metadata?["airport_identifier"]?
                .uppercased()
                .trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
            guard !airport.isEmpty else { continue }
            if event.eventType == "gps_takeoff_provisional" {
                pendingTakeoff = (airport, event.timestampUTC)
            } else if let takeoff = pendingTakeoff, event.timestampUTC >= takeoff.timestamp {
                evidenceLegs.append(EvidenceLeg(
                    departure: takeoff.airport,
                    arrival: airport,
                    takeoffAt: takeoff.timestamp,
                    landingAt: event.timestampUTC
                ))
                pendingTakeoff = nil
            }
        }
        guard !evidenceLegs.isEmpty else { return nil }

        let sessionDuration = max(1, onBlock.timeIntervalSince(offBlock))
        func fraction(_ timestamp: Date) -> Double {
            min(1, max(0, timestamp.timeIntervalSince(offBlock) / sessionDuration))
        }
        func interpolate(_ start: Double, _ end: Double, at timestamp: Date) -> Double {
            start + ((end - start) * fraction(timestamp))
        }

        return evidenceLegs.enumerated().map { index, evidenceLeg in
            let legStart = index == 0
                ? offBlock
                : evidenceLegs[index - 1].landingAt
            let legEnd = index == evidenceLegs.count - 1
                ? onBlock
                : evidenceLeg.landingAt
            let fuelStart = startingFuel.flatMap { start in
                endingFuel.map { interpolate(start, $0, at: legStart) }
            }
            let fuelEnd = startingFuel.flatMap { start in
                endingFuel.map { interpolate(start, $0, at: legEnd) }
            }
            return CVROperationalLegReviewLeg(
                sequenceNumber: index + 1,
                departureAirport: evidenceLeg.departure,
                arrivalAirport: evidenceLeg.arrival,
                offBlockUTC: Self.utcString(from: legStart),
                onBlockUTC: Self.utcString(from: legEnd),
                startingHobbs: interpolate(startingHobbs, endingHobbs, at: legStart),
                endingHobbs: interpolate(startingHobbs, endingHobbs, at: legEnd),
                startingTacho: interpolate(startingTacho, endingTacho, at: legStart),
                endingTacho: interpolate(startingTacho, endingTacho, at: legEnd),
                takeoffCount: 1,
                landingCount: 1,
                fuelOnboard: fuelStart,
                fuelRemaining: fuelEnd
            )
        }
    }

    private func numericFuel(_ value: String?) -> Double? {
        guard let value else { return nil }
        let numeric = value.components(
            separatedBy: CharacterSet(charactersIn: "0123456789.-").inverted
        ).joined()
        return Double(numeric)
    }

    private var closureNeedsSynchronization: Bool {
        guard let flightRecordUUID else { return false }
        return workflow.state.uploadComponents.contains {
            $0.flightRecordID.lowercased() == flightRecordUUID.lowercased()
                && $0.componentType == "flight_record_closure"
                && $0.state != .serverVerified
        }
    }

    private func isMissingClosureError(_ error: Error) -> Bool {
        let message = error.localizedDescription.lowercased()
        return message.contains("completed check-in")
            || message.contains("flight closure")
            || message.contains("check-in must be synchronized")
    }

    private func addLeg() {
        if legs.isEmpty {
            let emptySnapshot = legs
            seedFirstLegFromLocalCheckInIfNeeded()
            if !legs.isEmpty {
                undoStack.append(emptySnapshot)
                renumberLegs()
                return
            }
            guard preview != nil else {
                isWaitingForCheckIn = true
                errorMessage = "The saved Check-In values are still loading. Start Hobbs, Start Tacho, and fuel must be available before the first leg can be created."
                return
            }
        }
        pushUndo()
        if let previous = legs.last {
            let offBlock = previous.onBlockUTC
            let onBlock = Self.utcString(
                from: Self.utcDate(from: previous.onBlockUTC).addingTimeInterval(3600)
            )
            legs.append(CVROperationalLegReviewLeg(
                sequenceNumber: legs.count + 1,
                departureAirport: previous.arrivalAirport,
                arrivalAirport: "",
                offBlockUTC: offBlock,
                onBlockUTC: onBlock,
                startingHobbs: previous.endingHobbs,
                endingHobbs: previous.endingHobbs,
                startingTacho: previous.endingTacho,
                endingTacho: previous.endingTacho,
                takeoffCount: 0,
                landingCount: 0,
                fuelOnboard: previous.fuelRemaining,
                fuelRemaining: previous.fuelRemaining
            ))
        } else {
            let offBlock = preview?.offBlockUTC ?? Self.utcString(from: Date())
            let onBlock = preview?.onBlockUTC
                ?? Self.utcString(from: Self.utcDate(from: offBlock).addingTimeInterval(3600))
            legs.append(CVROperationalLegReviewLeg(
                sequenceNumber: 1,
                departureAirport: "",
                arrivalAirport: "",
                offBlockUTC: offBlock,
                onBlockUTC: onBlock,
                startingHobbs: preview?.startingHobbs ?? 0,
                endingHobbs: preview?.endingHobbs ?? preview?.startingHobbs ?? 0,
                startingTacho: preview?.startingTacho ?? 0,
                endingTacho: preview?.endingTacho ?? preview?.startingTacho ?? 0,
                takeoffCount: preview?.verifiedTakeoffCount ?? 0,
                landingCount: preview?.verifiedLandingCount ?? 0,
                fuelOnboard: preview?.fuelStart,
                fuelRemaining: preview?.fuelEnd
            ))
        }
        renumberLegs()
    }

    private func deleteLeg(at index: Int) {
        guard legs.count > 1, legs.indices.contains(index) else { return }
        pushUndo()
        legs.remove(at: index)
        renumberLegs()
    }

    private func pushUndo() {
        undoStack.append(legs)
        if undoStack.count > 20 {
            undoStack.removeFirst(undoStack.count - 20)
        }
    }

    private func undoLastChange() {
        guard let previous = undoStack.popLast() else { return }
        legs = previous
        renumberLegs()
    }

    private func renumberLegs() {
        for index in legs.indices {
            legs[index].sequenceNumber = index + 1
        }
    }

    private var validationWarnings: [String] {
        guard !isLoading, let preview else { return [] }
        var warnings: [String] = []
        if legs.isEmpty {
            return ["At least one leg is required."]
        }
        for index in legs.indices {
            let leg = legs[index]
            if !CVRLocalDispatchDraft.isValidICAOIdentifier(leg.departureAirport.uppercased())
                || !CVRLocalDispatchDraft.isValidICAOIdentifier(leg.arrivalAirport.uppercased()) {
                warnings.append("Leg \(index + 1) requires valid FROM and TO airport identifiers.")
            }
            if Self.utcDate(from: leg.onBlockUTC) < Self.utcDate(from: leg.offBlockUTC) {
                warnings.append("Leg \(index + 1) ON BLOCK time cannot precede OFF BLOCK time.")
            }
            if leg.endingHobbs < leg.startingHobbs || leg.endingTacho < leg.startingTacho {
                warnings.append("Leg \(index + 1) Hobbs and Tacho values cannot decrease.")
            }
            if let fuelOnboard = leg.fuelOnboard,
               let fuelRemaining = leg.fuelRemaining,
               fuelRemaining > fuelOnboard + 0.05 {
                warnings.append("Leg \(index + 1) fuel remaining cannot exceed fuel onboard.")
            }
            if index > 0 {
                let previous = legs[index - 1]
                if previous.arrivalAirport.uppercased() != leg.departureAirport.uppercased() {
                    warnings.append("Leg \(index) destination must match Leg \(index + 1) departure.")
                }
                if abs(previous.endingHobbs - leg.startingHobbs) > 0.05 {
                    warnings.append("Hobbs is not continuous between Legs \(index) and \(index + 1).")
                }
                if abs(previous.endingTacho - leg.startingTacho) > 0.05 {
                    warnings.append("Tacho is not continuous between Legs \(index) and \(index + 1).")
                }
                if let previousFuel = previous.fuelRemaining,
                   let currentFuel = leg.fuelOnboard,
                   abs(previousFuel - currentFuel) > 0.05 {
                    warnings.append("Fuel is not continuous between Legs \(index) and \(index + 1).")
                }
                if Self.utcDate(from: leg.offBlockUTC) < Self.utcDate(from: previous.onBlockUTC) {
                    warnings.append("Leg \(index + 1) starts before Leg \(index) ends.")
                }
            }
        }
        let hobbsTotal = legs.reduce(0.0) { $0 + max(0, $1.endingHobbs - $1.startingHobbs) }
        if let start = preview.startingHobbs, abs(legs[0].startingHobbs - start) > 0.05 {
            warnings.append(String(format: "First-leg Hobbs %.1f does not match Dispatch Hobbs %.1f.", legs[0].startingHobbs, start))
        }
        if let end = preview.endingHobbs, abs(legs[legs.count - 1].endingHobbs - end) > 0.05 {
            warnings.append(String(format: "Last-leg Hobbs %.1f does not match Check-In Hobbs %.1f.", legs[legs.count - 1].endingHobbs, end))
        }
        if let start = preview.startingHobbs, let end = preview.endingHobbs,
           abs(hobbsTotal - max(0, end - start)) > 0.05 {
            warnings.append(String(
                format: "Leg Hobbs total %.1f does not match Check-In total %.1f.",
                hobbsTotal,
                max(0, end - start)
            ))
        }
        let tachoTotal = legs.reduce(0.0) { $0 + max(0, $1.endingTacho - $1.startingTacho) }
        if let start = preview.startingTacho, abs(legs[0].startingTacho - start) > 0.05 {
            warnings.append(String(format: "First-leg Tacho %.1f does not match Dispatch Tacho %.1f.", legs[0].startingTacho, start))
        }
        if let end = preview.endingTacho, abs(legs[legs.count - 1].endingTacho - end) > 0.05 {
            warnings.append(String(format: "Last-leg Tacho %.1f does not match Check-In Tacho %.1f.", legs[legs.count - 1].endingTacho, end))
        }
        if let start = preview.startingTacho, let end = preview.endingTacho,
           abs(tachoTotal - max(0, end - start)) > 0.05 {
            warnings.append(String(
                format: "Leg Tacho total %.1f does not match Check-In total %.1f.",
                tachoTotal,
                max(0, end - start)
            ))
        }
        let takeoffs = legs.reduce(0) { $0 + $1.takeoffCount }
        if let expected = preview.verifiedTakeoffCount, takeoffs != expected {
            warnings.append("Leg takeoffs total \(takeoffs) does not match Check-In total \(expected).")
        }
        let landings = legs.reduce(0) { $0 + $1.landingCount }
        if let expected = preview.verifiedLandingCount, landings != expected {
            warnings.append("Leg landings total \(landings) does not match Check-In total \(expected).")
        }
        if let expectedFuelBurn = preview.fuelBurnTotal {
            let hasMissingFuel = legs.contains { $0.fuelOnboard == nil || $0.fuelRemaining == nil }
            if hasMissingFuel {
                warnings.append("Fuel onboard and fuel remaining are required for every leg.")
            } else {
                if let expected = preview.fuelStart,
                   abs((legs[0].fuelOnboard ?? 0) - expected) > 0.05 {
                    warnings.append(String(format: "First-leg fuel %.1f does not match Dispatch fuel %.1f.", legs[0].fuelOnboard ?? 0, expected))
                }
                if let expected = preview.fuelEnd,
                   abs((legs[legs.count - 1].fuelRemaining ?? 0) - expected) > 0.05 {
                    warnings.append(String(format: "Last-leg fuel %.1f does not match Check-In fuel %.1f.", legs[legs.count - 1].fuelRemaining ?? 0, expected))
                }
                let fuelBurn = legs.reduce(0.0) {
                    $0 + max(0, ($1.fuelOnboard ?? 0) - ($1.fuelRemaining ?? 0))
                }
                if abs(fuelBurn - expectedFuelBurn) > 0.11 {
                    warnings.append(String(
                        format: "Leg fuel consumed %.1f does not match Check-In total %.1f.",
                        fuelBurn,
                        expectedFuelBurn
                    ))
                }
            }
        }
        return Array(Set(warnings)).sorted()
    }

    private func numberBinding(
        index: Int,
        keyPath: WritableKeyPath<CVROperationalLegReviewLeg, Double>
    ) -> Binding<String> {
        Binding(
            get: { String(format: "%.1f", legs[index][keyPath: keyPath]) },
            set: { value in
                if let number = Double(value.replacingOccurrences(of: ",", with: ".")) {
                    legs[index][keyPath: keyPath] = number
                }
            }
        )
    }

    private func optionalNumberBinding(
        index: Int,
        keyPath: WritableKeyPath<CVROperationalLegReviewLeg, Double?>
    ) -> Binding<String> {
        Binding(
            get: {
                legs[index][keyPath: keyPath].map { String(format: "%.1f", $0) } ?? ""
            },
            set: { value in
                let normalized = value.replacingOccurrences(of: ",", with: ".")
                legs[index][keyPath: keyPath] = normalized.isEmpty ? nil : Double(normalized)
            }
        )
    }

    private enum LocalDateTimeComponent {
        case date
        case time
    }

    private func localComponentBinding(
        timestamp: Binding<String>,
        component: LocalDateTimeComponent
    ) -> Binding<Date> {
        Binding(
            get: { Self.utcDate(from: timestamp.wrappedValue) },
            set: { selected in
                let current = Self.utcDate(from: timestamp.wrappedValue)
                var calendar = Calendar(identifier: .gregorian)
                calendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
                let dateParts = calendar.dateComponents([.year, .month, .day], from: component == .date ? selected : current)
                let timeParts = calendar.dateComponents([.hour, .minute], from: component == .time ? selected : current)
                var merged = DateComponents()
                merged.year = dateParts.year
                merged.month = dateParts.month
                merged.day = dateParts.day
                merged.hour = timeParts.hour
                merged.minute = timeParts.minute
                merged.second = 0
                if let date = calendar.date(from: merged) {
                    timestamp.wrappedValue = Self.utcString(from: date)
                }
            }
        )
    }

    private static func utcDate(from value: String) -> Date {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return formatter.date(from: value) ?? Date()
    }

    private static func utcString(from date: Date) -> String {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        formatter.dateFormat = "yyyy-MM-dd HH:mm:ss"
        return formatter.string(from: date)
    }

    private func acceptLegs() async {
        guard let flightRecordUUID, !flightRecordUUID.isEmpty else {
            errorMessage = "The local Flight Record identity is unavailable."
            return
        }
        isSaving = true
        defer { isSaving = false }
        do {
            let revisionUUID = UUID().uuidString.lowercased()
            let legPayloads: [[String: Any]] = legs.map {
                var leg: [String: Any] = [
                    "sequence_number": $0.sequenceNumber,
                    "departure_airport": $0.departureAirport.uppercased(),
                    "arrival_airport": $0.arrivalAirport.uppercased(),
                    "off_block_utc": $0.offBlockUTC,
                    "on_block_utc": $0.onBlockUTC,
                    "starting_hobbs": $0.startingHobbs,
                    "ending_hobbs": $0.endingHobbs,
                    "starting_tacho": $0.startingTacho,
                    "ending_tacho": $0.endingTacho,
                    "takeoff_count": $0.takeoffCount,
                    "landing_count": $0.landingCount,
                ]
                if let fuelOnboard = $0.fuelOnboard {
                    leg["fuel_onboard"] = fuelOnboard
                }
                if let fuelRemaining = $0.fuelRemaining {
                    leg["fuel_remaining"] = fuelRemaining
                }
                return leg
            }
            var payload: [String: Any] = [
                "revision_uuid": revisionUUID,
                "dispatch_uuid": dispatchUUID.lowercased(),
                "legs": legPayloads,
            ]
            if let evidenceSHA256 {
                payload["evidence_sha256"] = evidenceSHA256
            }
            let snapshot = try JSONSerialization.data(
                withJSONObject: payload,
                options: [.sortedKeys]
            )
            try workflow.acceptOperationalLegReviewLocally(
                revisionUUID: revisionUUID,
                dispatchUUID: dispatchUUID,
                flightRecordUUID: flightRecordUUID,
                payload: snapshot,
                advancesPostFlightHandoff: advancesPostFlightHandoff
            )
            onAccepted?(revisionUUID)
            dismiss()
            uploadManager.uploadQueuedWorkflowComponents(
                workflow: workflow,
                settings: settings,
                trigger: .routine
            )
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct OperationalBottomTabBar: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore

    var body: some View {
        HStack(spacing: 0) {
            ForEach(CVROperationalTab.allCases.filter { $0 != .garmin }) { tab in
                Button {
                    workflow.selectTab(tab)
                } label: {
                    VStack(spacing: 3) {
                        Image(systemName: tab.systemImage)
                            .font(.system(size: 14, weight: .semibold))
                        Text(tab.title)
                            .font(.system(size: 7, weight: .bold))
                            .lineLimit(1)
                            .minimumScaleFactor(0.8)
                    }
                    .foregroundStyle(
                        workflow.state.selectedTab == tab
                            ? CVROperationalPalette.primaryBlue
                            : Color.white.opacity(0.62)
                    )
                    .frame(maxWidth: .infinity)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityLabel(tab.title)
                .accessibilityAddTraits(workflow.state.selectedTab == tab ? .isSelected : [])
            }
        }
        .padding(.top, 6)
        .padding(.bottom, 6)
        .background(Color.black.ignoresSafeArea(edges: .bottom))
        .overlay(alignment: .top) {
            Rectangle()
                .fill(Color.white.opacity(0.1))
                .frame(height: 1)
        }
    }
}

private struct ScheduledFlightsView: View {
    @EnvironmentObject private var sessionsStore: ScheduledSessionsStore
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @EnvironmentObject private var uploadManager: UploadManager
    @Binding var showAdminUnlock: Bool
    @State private var pendingReplacementSession: CVRScheduledSession?
    @State private var showLocalDispatchSheet = false
    @State private var showEngineWasShutdownConfirm = false
    @State private var showCancelRemainingLegsConfirm = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        statusCard(metrics)
                        if let syncInfo = workflow.scheduleDutySyncInfo {
                            CVRScheduleDutySyncBanner(info: syncInfo)
                        }
                        scheduleTiles(metrics)
                        scheduleWarning
                        if workflow.state.engineSessionContinuityActive,
                           !workflow.remainingOpenPlannedLegs.isEmpty,
                           workflow.state.activeFlightRecord == nil {
                            CVROperationalWarningCard(
                                title: "ENGINE SESSION CONTINUING",
                                message: "Select the next leg if the engine is still running. If Transient Stop was used by mistake and the engine is off, hold Engine Was Shut Down, then open the unused leg with Engine Start. Completed-leg uploads keep running in Log.",
                                iconName: "flame.fill",
                                color: CVROperationalPalette.secondaryBlue
                            )
                        } else if !workflow.remainingOpenPlannedLegs.isEmpty,
                                  workflow.state.activeFlightRecord == nil,
                                  !workflow.hasQueuedScheduleDutyReplacement {
                            CVROperationalWarningCard(
                                title: "LOCAL PLANNED LEGS REMAIN",
                                message: "These legs are stored on this CVR Unit, not on the online schedule. Open one to continue, or hold Cancel Remaining Legs to clear them.",
                                iconName: "point.3.connected.trianglepath.dotted",
                                color: CVROperationalPalette.warning
                            )
                        }
                        ForEach(daySections, id: \.title) { section in
                            reservationSection(section.title, groups: section.groups, metrics: metrics)
                        }
                        actionButtons
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
                .refreshable {
                    if await sessionsStore.refresh(settings: settings) {
                        workflow.discardRejectedScheduledDraftMissingFromServer(
                            serverSessions: sessionsStore.sessions
                        )
                    }
                }
            }
        }
        .confirmationDialog(
            "Save the current leg before opening another?",
            isPresented: Binding(
                get: { pendingReplacementSession != nil },
                set: { if !$0 { pendingReplacementSession = nil } }
            ),
            titleVisibility: .visible
        ) {
            if let session = pendingReplacementSession {
                Button("Open Scheduled Leg", role: .destructive) {
                    pendingReplacementSession = nil
                    openScheduledSession(session)
                }
            }
            Button("Cancel", role: .cancel) {
                pendingReplacementSession = nil
            }
        } message: {
            Text("Complete Check-In for the current leg when possible. Pending evidence remains available in Log.")
        }
        .confirmationDialog(
            "Confirm engine was shut down?",
            isPresented: $showEngineWasShutdownConfirm,
            titleVisibility: .visible
        ) {
            Button("Yes — Engine Is Off", role: .destructive) {
                _ = workflow.endEngineContinuityPreservingUnusedLegs()
            }
            Button("No — Keep Continuity", role: .cancel) {}
        } message: {
            Text("This ends the continuous engine session. Remaining legs stay available, but the next leg will require a normal Engine Start. Use this only if Transient Stop was a mistake and the engine is actually off.")
        }
        .confirmationDialog(
            "Cancel remaining legs?",
            isPresented: $showCancelRemainingLegsConfirm,
            titleVisibility: .visible
        ) {
            Button("Yes — Cancel Remaining Legs", role: .destructive) {
                _ = workflow.cancelUnusedPlannedLegsAndEndSession()
            }
            Button("No — Keep Legs", role: .cancel) {}
        } message: {
            Text("Unused legs are cancelled on this CVR Unit only. The online schedule is unchanged. Completed legs and uploads are kept. You will not continue this reservation under engine continuity.")
        }
        .onAppear {
            workflow.clearIdleCompletedOperationalSessionIfNeeded()
        }
        .onChange(of: workflow.scheduleRefreshRevision) {
            Task {
                if await sessionsStore.refresh(settings: settings) {
                    workflow.discardRejectedScheduledDraftMissingFromServer(
                        serverSessions: sessionsStore.sessions
                    )
                }
            }
        }
        .sheet(isPresented: $showLocalDispatchSheet) {
            LocalDispatchSheet()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(beacon)
                .environmentObject(missionCatalog)
                .environmentObject(uploadManager)
        }
    }

    private func statusCard(_ metrics: CVROperationalMetrics) -> some View {
        CVROperationalStatusCard(
            title: scheduleStatusTitle,
            subtitle: scheduleStatusSubtitle,
            iconName: sessionsStore.isRefreshing ? "arrow.triangle.2.circlepath" : "calendar.badge.clock",
            color: scheduleStatusColor,
            value: visibleGroups.isEmpty ? nil : "\(visibleGroups.count)",
            caption: "SCHEDULED RESERVATIONS",
            metrics: metrics
        )
    }

    private func scheduleTiles(_ metrics: CVROperationalMetrics) -> some View {
        HStack(spacing: metrics.spacing) {
            CVROperationalTile(
                title: "ACFT",
                iconName: "airplane",
                value: settings.selectedAircraft?.registration ?? "None",
                color: settings.selectedAircraft == nil ? CVROperationalPalette.warning : CVROperationalPalette.success,
                metrics: metrics,
                compact: true
            )
            CVROperationalTile(
                title: "TODAY",
                iconName: "calendar",
                value: "\(todayGroups.count)",
                color: todayGroups.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.secondaryBlue,
                metrics: metrics,
                compact: true
            )
            CVROperationalTile(
                title: "LEGS",
                iconName: "point.topleft.down.to.point.bottomright.curvepath",
                value: "\(visibleGroups.flatMap(\.legs).count)",
                color: CVROperationalPalette.secondaryBlue,
                metrics: metrics,
                compact: true
            )
            CVROperationalTile(
                title: "STATUS",
                iconName: sessionsStore.isRefreshing ? "arrow.triangle.2.circlepath" : "checkmark.circle.fill",
                value: sessionsStore.isRefreshing ? "Loading" : "Ready",
                color: sessionsStore.lastError.isEmpty ? CVROperationalPalette.success : CVROperationalPalette.warning,
                metrics: metrics,
                compact: true
            )
        }
    }

    @ViewBuilder
    private var scheduleWarning: some View {
        if settings.selectedAircraft == nil {
            CVROperationalWarningCard(
                title: "AIRCRAFT CONFIGURATION REQUIRED",
                message: "Assign this CVR Unit to its aircraft before loading scheduled flights.",
                iconName: "lock.trianglebadge.exclamationmark",
                color: CVROperationalPalette.critical
            )
        } else if let error = sessionsStore.lastError.nilIfEmpty {
            CVROperationalWarningCard(
                title: "SCHEDULE UPDATE WARNING",
                message: error,
                iconName: "icloud.slash.fill",
                color: CVROperationalPalette.warning
            )
        } else if visibleGroups.isEmpty && !sessionsStore.isRefreshing {
            CVROperationalWarningCard(
                title: "NO SCHEDULED FLIGHTS",
                message: "No flights are scheduled for this aircraft. You can still create a local Dispatch.",
                iconName: "calendar.badge.exclamationmark",
                color: CVROperationalPalette.standby
            )
        }
    }

    @ViewBuilder
    private func reservationSection(
        _ title: String,
        groups: [CVRScheduledReservationGroup],
        metrics: CVROperationalMetrics
    ) -> some View {
        VStack(alignment: .leading, spacing: 9) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .frame(maxWidth: .infinity, alignment: .leading)
            if groups.isEmpty {
                HStack(spacing: 10) {
                    Image(systemName: "calendar")
                        .foregroundStyle(CVROperationalPalette.standby)
                    Text("No reservations")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    Spacer()
                }
                .padding(14)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
                .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            } else {
                ForEach(Array(groups.enumerated()), id: \.element.id) { index, group in
                    reservationCard(group, flightNumber: index + 1, metrics: metrics)
                }
            }
        }
    }

    private func reservationCard(
        _ group: CVRScheduledReservationGroup,
        flightNumber: Int,
        metrics: CVROperationalMetrics
    ) -> some View {
        Group {
            if let session = group.scheduledSession {
                Button {
                    openScheduledSession(session)
                } label: {
                    scheduledReservationSummary(group, flightNumber: flightNumber)
                }
                .buttonStyle(.plain)
            } else {
                VStack(alignment: .leading, spacing: 10) {
                    scheduledReservationSummary(group, flightNumber: flightNumber)
                    ForEach(group.legs) { leg in
                        legRow(leg, metrics: metrics)
                    }
                }
            }
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func scheduledReservationSummary(
        _ group: CVRScheduledReservationGroup,
        flightNumber: Int
    ) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text("Flight \(flightNumber)")
                    .font(.headline.weight(.bold))
                    .foregroundStyle(.white)
                Spacer()
                let status = reservationDisplayStatus(group)
                Text(status.label)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(status.color)
                    .padding(.horizontal, 8)
                    .padding(.vertical, 4)
                    .background(status.color.opacity(0.14), in: Capsule())
                if reservationHasOverlap(group) {
                    Text("OVERLAP")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.warning)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(CVROperationalPalette.warning.opacity(0.16), in: Capsule())
                }
            }
            scheduledWindowSummary(group)
            Text(group.aircraftRegistration)
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            if !group.crewNames.isEmpty {
                Text("Crew: \(group.crewNames.joined(separator: ", "))")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.white)
            }
            if !group.missionDisplay.isEmpty {
                Text("Mission: \(group.missionDisplay)")
                    .font(.subheadline)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
            Text("Route: \(group.routeSummary) (Informative)")
                .font(.subheadline)
                .foregroundStyle(CVROperationalPalette.textSecondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .contentShape(Rectangle())
    }

    private func scheduledWindowSummary(_ group: CVRScheduledReservationGroup) -> some View {
        let window = reservationScheduleWindow(group)
        return HStack(spacing: 10) {
            scheduleWindowValue(
                title: "SCHEDULED DEPARTURE",
                value: window?.start
            )
            scheduleWindowValue(
                title: "SCHEDULED ARRIVAL",
                value: window?.end
            )
        }
    }

    private func scheduleWindowValue(title: String, value: Date?) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(title)
                .font(.caption2.weight(.bold))
                .tracking(0.5)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(cvrReservationTime(value) ?? "TBD")
                .font(.subheadline.weight(.bold).monospacedDigit())
                .foregroundStyle(.white)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.white.opacity(0.04), in: RoundedRectangle(cornerRadius: 10))
    }

    private func reservationScheduleWindow(
        _ group: CVRScheduledReservationGroup
    ) -> (start: Date, end: Date)? {
        let starts = group.legs.compactMap(\.scheduledStartTime)
        let ends = group.legs.compactMap(\.scheduledEndTime)
        guard let start = starts.min(), let end = ends.max(), end > start else { return nil }
        return (start, end)
    }

    private func reservationHasOverlap(_ group: CVRScheduledReservationGroup) -> Bool {
        guard let window = reservationScheduleWindow(group) else { return false }
        let tail = normalizedTail(group.aircraftRegistration)
        return visibleGroups.contains { other in
            guard other.id != group.id,
                  normalizedTail(other.aircraftRegistration) == tail,
                  let otherWindow = reservationScheduleWindow(other) else {
                return false
            }
            return window.start < otherWindow.end && window.end > otherWindow.start
        }
    }

    private func legRow(_ leg: CVRScheduledLegItem, metrics: CVROperationalMetrics) -> some View {
        let blocked = isLegBlocked(leg)
        return Button {
            openLeg(leg)
        } label: {
            HStack(spacing: 12) {
                Text("LEG \(leg.sequenceNumber)")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .frame(width: 48, alignment: .leading)
                VStack(alignment: .leading, spacing: 3) {
                    Text(leg.routeLabel)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(.white)
                    Text(leg.missionCode.nilIfEmpty ?? timeRange(leg))
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                Spacer()
                Image(systemName: blocked ? "lock.fill" : "chevron.right")
                    .foregroundStyle(blocked ? CVROperationalPalette.warning : CVROperationalPalette.textSecondary)
            }
            .padding(.vertical, 8)
            .padding(.horizontal, 10)
            .background(Color.white.opacity(0.04), in: RoundedRectangle(cornerRadius: 12))
        }
        .buttonStyle(.plain)
        .disabled(blocked || leg.status == "checked_in")
        .opacity((blocked || leg.status == "checked_in") ? 0.55 : 1)
    }

    private func isLegBlocked(_ leg: CVRScheduledLegItem) -> Bool {
        let status = leg.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if status == "checked_in" || status == "claimed" || status == "dispatched" { return true }
        if let session = leg.session {
            return !workflow.canOpenScheduledSession(
                session,
                selectedAircraft: aircraftForSession(session),
                isAudioRecording: audio.isRecording
            )
        }
        if workflow.state.engineSessionContinuityActive { return false }
        return audio.isRecording && workflow.state.activeFlightRecord != nil
    }

    private func openLeg(_ leg: CVRScheduledLegItem) {
        if reservationMatchesActiveDispatch(leg) {
            workflow.selectTab(.dispatch)
            return
        }
        if let session = leg.session {
            if workflow.requiresArchivingBeforeScheduledSession(session) {
                pendingReplacementSession = session
            } else {
                openScheduledSession(session)
            }
            return
        }
        if let planned = workflow.state.plannedLegs.first(where: { $0.legUUID == leg.legUUID }) {
            workflow.openDispatchFromPlannedLeg(
                planned,
                selectedAircraft: settings.selectedAircraft,
                cvrUnitID: settings.cvrUnitIdentifier,
                beaconID: beacon.expectedBeaconIdentityHex,
                isAudioRecording: audio.isRecording,
                canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled,
                operationalSessionModelEnabled: settings.operationalSessionModelEnabled
            )
        }
    }

    private func openScheduledSession(_ session: CVRScheduledSession) {
        let reservationKey = session.reservationUUID?
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .lowercased() ?? ""
        let siblings: [CVRScheduledSession]
        if !reservationKey.isEmpty {
            siblings = sessionsStore.sessions.filter {
                ($0.reservationUUID ?? "")
                    .trimmingCharacters(in: .whitespacesAndNewlines)
                    .lowercased() == reservationKey
            }
        } else {
            siblings = [session]
        }
        workflow.openDispatchFromScheduledSession(
            session,
            reservationSessions: siblings.isEmpty ? [session] : siblings,
            selectedAircraft: aircraftForSession(session),
            cvrUnitID: settings.cvrUnitIdentifier,
            beaconID: beacon.expectedBeaconIdentityHex,
            isAudioRecording: audio.isRecording,
            canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled,
            operationalSessionModelEnabled: settings.operationalSessionModelEnabled
        )
    }

    private func aircraftForSession(_ session: CVRScheduledSession) -> CockpitAircraft {
        if let aircraft = settings.aircraft.first(where: {
            $0.id == session.aircraftID
                || normalizedTail($0.registration) == normalizedTail(session.aircraftRegistration)
        }) {
            return aircraft
        }
        return CockpitAircraft(
            id: session.aircraftID,
            registration: session.aircraftRegistration,
            displayName: session.aircraftRegistration,
            homeAirport: session.plannedDepartureAirport,
            operationalConfig: settings.selectedAircraft?.operationalConfig ?? .safeDefaults
        )
    }

    private func normalizedTail(_ value: String) -> String {
        value.uppercased().filter { $0.isLetter || $0.isNumber }
    }

    private var canCancelLeftoverPlannedLegs: Bool {
        !workflow.remainingOpenPlannedLegs.isEmpty
            && workflow.state.activeFlightRecord == nil
    }

    private var actionButtons: some View {
        VStack(spacing: 8) {
            if workflow.state.engineSessionContinuityActive, canCancelLeftoverPlannedLegs {
                CVRHoldActionButton(
                    title: "ENGINE WAS SHUT DOWN",
                    subtitle: "Hold 2 seconds — end continuity; next leg needs Engine Start",
                    color: CVROperationalPalette.warning,
                    minimumDuration: 2
                ) {
                    showEngineWasShutdownConfirm = true
                    return true
                }
            }
            // Always offer cancel when unused local planned legs remain (empty online
            // schedule / after Undispatch). Do not require engine continuity.
            if canCancelLeftoverPlannedLegs {
                CVRHoldActionButton(
                    title: "CANCEL REMAINING LEGS",
                    subtitle: "Hold 2 seconds — drop unused local legs (online schedule unchanged)",
                    color: CVROperationalPalette.standby,
                    minimumDuration: 2
                ) {
                    showCancelRemainingLegsConfirm = true
                    return true
                }
            }
            CVROperationalActionButton(
                title: sessionsStore.isRefreshing ? "REFRESHING SCHEDULE" : "REFRESH SCHEDULE",
                subtitle: "Load flights assigned to \(settings.selectedAircraft?.registration ?? "this aircraft")",
                color: CVROperationalPalette.secondaryBlue
            ) {
                Task {
                    if await sessionsStore.refresh(settings: settings) {
                        workflow.discardRejectedScheduledDraftMissingFromServer(
                            serverSessions: sessionsStore.sessions
                        )
                    }
                }
            }
            if settings.selectedAircraft == nil {
                CVROperationalActionButton(
                    title: "CONFIGURE AIRCRAFT",
                    subtitle: "Assign this CVR Unit",
                    color: CVROperationalPalette.critical
                ) {
                    showAdminUnlock = true
                }
            } else {
                CVROperationalActionButton(
                    title: "ADD RESERVATION",
                    subtitle: "Customer, crew and mission · route-free",
                    color: CVROperationalPalette.standby
                ) {
                    showLocalDispatchSheet = true
                }
            }
        }
    }

    private var scheduleStatusTitle: String {
        if settings.selectedAircraft == nil {
            return "AIRCRAFT REQUIRED"
        }
        if sessionsStore.isRefreshing {
            return "LOADING SCHEDULE"
        }
        return visibleGroups.isEmpty ? "NO FLIGHTS SCHEDULED" : "SCHEDULED"
    }

    private var scheduleStatusSubtitle: String {
        if settings.selectedAircraft == nil {
            return "CONFIGURE THE CVR UNIT AIRCRAFT"
        }
        if sessionsStore.isRefreshing {
            return "UPDATING SCHEDULE"
        }
        if visibleGroups.isEmpty {
            return "LOCAL DISPATCH REMAINS AVAILABLE"
        }
        return "SELECT YOUR LEG."
    }

    private var scheduleStatusColor: Color {
        if settings.selectedAircraft == nil {
            return CVROperationalPalette.critical
        }
        if sessionsStore.isRefreshing {
            return CVROperationalPalette.secondaryBlue
        }
        return visibleGroups.isEmpty ? CVROperationalPalette.standby : CVROperationalPalette.success
    }

    private var aircraftSessions: [CVRScheduledSession] {
        guard let aircraft = settings.selectedAircraft else { return [] }
        let startOfToday = operationalCalendar.startOfDay(for: Date())
        // Multi-leg expansions share one scheduler_record_id — consume by leg_uuid when present.
        var consumedSchedulerRecordIDs = Set(
            workflow.archives.compactMap { archive -> String? in
                if archive.dispatch.operationalIdentity?.legUUID != nil { return nil }
                return archive.dispatch.schedulerRecordID
            }
        )
        var consumedLegUUIDs = Set(
            workflow.archives.compactMap { $0.dispatch.operationalIdentity?.legUUID }
        )
        for planned in workflow.state.plannedLegs where planned.status == "checked_in" {
            consumedLegUUIDs.insert(planned.legUUID)
        }
        return sessionsStore.sessions
            .filter {
                let matchesAircraft = $0.aircraftID == aircraft.id
                    || CVRWorkflowStore.normalizedTail($0.aircraftRegistration)
                        == CVRWorkflowStore.normalizedTail(aircraft.registration)
                guard matchesAircraft,
                      ($0.dateTime(nil) ?? .distantPast) >= startOfToday else {
                    return false
                }
                if workflow.locallySupersededSchedulerRecordIDs.contains(
                    $0.schedulerRecordID.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
                ) {
                    return false
                }
                if let legUUID = $0.legUUID, consumedLegUUIDs.contains(legUUID) {
                    return false
                }
                // Also honor schedule-level consumption when hop expansions still carry leg UUIDs.
                if consumedSchedulerRecordIDs.contains($0.schedulerRecordID) {
                    return false
                }
                return true
            }
            .sorted { ($0.dateTime($0.scheduledStartTime) ?? .distantFuture) < ($1.dateTime($1.scheduledStartTime) ?? .distantFuture) }
    }

    private func reservationMatchesActiveDispatch(_ leg: CVRScheduledLegItem) -> Bool {
        guard let active = workflow.state.activeDispatch else { return false }
        let activeScheduler = active.schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let legScheduler = leg.schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if let activeScheduler, !activeScheduler.isEmpty, activeScheduler == legScheduler {
            return true
        }
        guard let activeLeg = active.operationalIdentity?.legUUID,
              let legUUID = leg.legUUID else { return false }
        return activeLeg.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            == legUUID.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
    }

    private func displayStatus(for leg: CVRScheduledLegItem) -> String {
        if reservationMatchesActiveDispatch(leg) {
            return workflow.state.activeFlightRecord == nil ? "scheduled" : "dispatched"
        }
        let serverStatus = leg.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if serverStatus == "claimed" || serverStatus == "dispatched" {
            return "dispatched"
        }
        return serverStatus.isEmpty ? "scheduled" : serverStatus
    }

    private func reservationDisplayStatus(
        _ group: CVRScheduledReservationGroup
    ) -> (label: String, color: Color) {
        let statuses = Set(group.legs.map {
            $0.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        })
        if statuses.contains("dispatched") || statuses.contains("claimed") {
            return ("DISPATCHED", CVROperationalPalette.success)
        }
        return ("SCHEDULED", CVROperationalPalette.textSecondary)
    }

    private var visibleGroups: [CVRScheduledReservationGroup] {
        let localLegs = workflow.state.plannedLegs.filter { planned in
            let status = planned.status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            // Only unfinished, not-yet-claimed legs belong on Scheduled.
            guard status == "planned" || status.isEmpty else { return false }
            return settings.selectedAircraft == nil
                || CVRWorkflowStore.normalizedTail(planned.tailNumber)
                    == CVRWorkflowStore.normalizedTail(settings.selectedAircraft?.registration ?? "")
        }
        var groups = CVRScheduledReservationGrouping.groups(
            from: aircraftSessions,
            localLegs: localLegs,
            calendar: operationalCalendar
        )
        for groupIndex in groups.indices {
            for legIndex in groups[groupIndex].legs.indices {
                groups[groupIndex].legs[legIndex].status = displayStatus(for: groups[groupIndex].legs[legIndex])
            }
        }
        return groups
    }

    private var todayGroups: [CVRScheduledReservationGroup] {
        visibleGroups.filter { operationalCalendar.isDate($0.dayStart, inSameDayAs: Date()) }
    }

    private var daySections: [(title: String, groups: [CVRScheduledReservationGroup])] {
        let grouped = Dictionary(grouping: visibleGroups) { group in
            operationalCalendar.startOfDay(for: group.dayStart)
        }
        let formatter = DateFormatter()
        formatter.calendar = operationalCalendar
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalCalendar.timeZone
        formatter.dateFormat = "EEEE"
        return grouped.keys.sorted().map { day in
            let title: String
            if operationalCalendar.isDate(day, inSameDayAs: Date()) {
                title = "Today"
            } else {
                title = formatter.string(from: day)
            }
            return (title, grouped[day] ?? [])
        }
    }

    private var operationalCalendar: Calendar {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        return calendar
    }

    private func timeRange(_ leg: CVRScheduledLegItem) -> String {
        let start = cvrReservationTime(leg.scheduledStartTime) ?? "TBD"
        let end = cvrReservationTime(leg.scheduledEndTime) ?? "TBD"
        return "\(start)–\(end)"
    }
}

private enum LocalCrewPickerTarget: String, Identifiable {
    case customer
    case personTwo
    case personThree

    var id: String { rawValue }

    var title: String {
        switch self {
        case .customer: "Select Customer"
        case .personTwo: "Select Person 2"
        case .personThree: "Select Person 3"
        }
    }
}

private struct LocalCrewUserPickerSheet: View {
    let title: String
    let users: [CVRCrewUser]
    let selectedUserID: Int
    let allowsEmptySelection: Bool
    let onSelect: (Int?) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var searchText = ""

    private var filteredUsers: [CVRCrewUser] {
        let query = searchText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !query.isEmpty else { return users }
        return users.filter {
            $0.displayName.localizedCaseInsensitiveContains(query)
                || $0.role.localizedCaseInsensitiveContains(query)
        }
    }

    var body: some View {
        NavigationStack {
            List {
                if allowsEmptySelection {
                    Button {
                        onSelect(nil)
                        dismiss()
                    } label: {
                        Label("No person selected", systemImage: selectedUserID == 0 ? "checkmark.circle.fill" : "circle")
                            .foregroundStyle(CVROperationalPalette.textPrimary)
                    }
                }
                ForEach(filteredUsers) { user in
                    Button {
                        onSelect(user.id)
                        dismiss()
                    } label: {
                        HStack(spacing: 12) {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(user.displayName)
                                    .font(.body.weight(.semibold))
                                    .foregroundStyle(CVROperationalPalette.textPrimary)
                                Text(user.role.uppercased())
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.textSecondary)
                            }
                            Spacer()
                            if selectedUserID == user.id {
                                Image(systemName: "checkmark.circle.fill")
                                    .foregroundStyle(CVROperationalPalette.success)
                            }
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .searchable(text: $searchText, prompt: "Search name or role")
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
        }
        .preferredColorScheme(.dark)
    }
}

private struct LocalDispatchSheet: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @EnvironmentObject private var uploadManager: UploadManager
    @Environment(\.dismiss) private var dismiss

    @State private var draft = CVRLocalDispatchDraft.fresh()
    @State private var validationHint = ""
    @State private var showMissionPicker = false
    @State private var customerUserID = 0
    @State private var customerIsPIC = false
    @State private var personTwoUserID = 0
    @State private var personTwoRole: CVRCrewRole = .unknown
    @State private var personTwoIsPIC = false
    @State private var personThreeUserID = 0
    @State private var personThreeRole: CVRCrewRole = .unknown
    @State private var scheduledDate = Date()
    @State private var scheduledStartTime = Date()
    @State private var scheduledEndTime = Date().addingTimeInterval(2 * 3600)
    @State private var activeCrewPicker: LocalCrewPickerTarget?
    @FocusState private var focusedRouteField: RouteField?

    private enum RouteField: Hashable {
        case departure(Int)
        case arrival(Int)
    }

    private var flightMissions: [CVRMissionCatalogEntry] {
        missionCatalog.flightMissions
    }

    private var canCreate: Bool {
        localValidationMessage == nil && settings.selectedAircraft != nil
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                List {
                    Section {
                        scheduleWindowSection
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    } header: {
                        Text("SCHEDULE")
                            .font(.caption.weight(.bold))
                            .tracking(1.0)
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                            .textCase(nil)
                    }

                    Section {
                        missionSection
                            .listRowInsets(EdgeInsets(top: 12, leading: 16, bottom: 12, trailing: 16))
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    }

                    Section {
                        localCrewEditor
                            .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    } header: {
                        Text("Crew")
                            .font(.caption.weight(.bold))
                            .tracking(1.0)
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                            .textCase(nil)
                    }

                    Section {
                        Text("Planning information only. The actual flown legs will be derived from session evidence.")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                        ForEach(Array(draft.legs.enumerated()), id: \.element.legUUID) { index, leg in
                            informativeRouteRow(index: index, leg: leg)
                                .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                                .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                                    if draft.legs.count > 1, leg.isErasable {
                                        Button(role: .destructive) {
                                            _ = draft.eraseLeg(id: leg.legUUID)
                                        } label: {
                                            Label("ERASE", systemImage: "trash")
                                        }
                                    }
                                }
                        }
                        informativeAddLegButton
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    } header: {
                        Text("INFORMATIVE ROUTE (OPTIONAL)")
                            .font(.caption.weight(.bold))
                            .tracking(1.0)
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                            .textCase(nil)
                    }

                    Section {
                        if !validationHint.isEmpty {
                            Text(validationHint)
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(CVROperationalPalette.warning)
                                .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                        }

                        CVROperationalActionButton(
                            title: "CREATE DISPATCH",
                            subtitle: "Route-free Operational Session",
                            color: canCreate ? CVROperationalPalette.success : CVROperationalPalette.standby
                        ) {
                            create()
                        }
                        .disabled(!canCreate)
                        .opacity(canCreate ? 1 : 0.55)
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 24, trailing: 16))
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                    }
                }
                .listStyle(.plain)
                .scrollContentBackground(.hidden)
                .scrollDismissesKeyboard(.interactively)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Add Reservation")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        CVRLocalDispatchDraft.clear()
                        dismiss()
                    }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedRouteField = nil }
                }
            }
            .onAppear(perform: loadDraft)
            .sheet(isPresented: $showMissionPicker) {
                CVRMissionPickerSheet(
                    missions: flightMissions,
                    selectedMissionCode: draft.selectedMissionCode,
                    titleProvider: { missionCatalog.flightMissionPickerTitle($0) }
                ) { code in
                    draft.selectedMissionCode = code
                }
            }
            .sheet(item: $activeCrewPicker) { target in
                LocalCrewUserPickerSheet(
                    title: target.title,
                    users: target == .customer ? studentCrewUsers : operationalCrewUsers,
                    selectedUserID: selectedUserID(for: target),
                    allowsEmptySelection: target != .customer
                ) { userID in
                    applyCrewSelection(userID, to: target)
                    activeCrewPicker = nil
                }
            }
            .onChange(of: draft.selectedMissionCode) {
                if canCreate { validationHint = "" }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var missionSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("ADD RESERVATION")
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text("Mission")
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            Button {
                showMissionPicker = true
            } label: {
                HStack {
                    Text(selectedMissionLabel)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(
                            draft.selectedMissionCode.isEmpty
                                ? CVROperationalPalette.textSecondary
                                : CVROperationalPalette.textPrimary
                        )
                        .multilineTextAlignment(.leading)
                    Spacer(minLength: 8)
                    Image(systemName: "chevron.up.chevron.down")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                .padding(14)
                .frame(minHeight: 52)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            }
            .buttonStyle(.plain)
            .disabled(flightMissions.isEmpty)
            if flightMissions.isEmpty {
                Text("No flight missions are available on this device.")
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.warning)
            }
        }
    }

    private var scheduleWindowSection: some View {
        VStack(alignment: .leading, spacing: 12) {
            DatePicker("DATE", selection: $scheduledDate, displayedComponents: .date)
            CVRReservationTimePickerRow(title: "START", selection: $scheduledStartTime)
            CVRReservationTimePickerRow(title: "END", selection: $scheduledEndTime)
            Text("Required planning window for synchronization with the online schedule.")
                .font(.caption2.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
        }
        .font(.body.weight(.semibold))
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        .environment(\.locale, Locale(identifier: "en_GB"))
    }

    private var selectedMissionLabel: String {
        if let selected = flightMissions.first(where: {
            $0.missionCode.caseInsensitiveCompare(draft.selectedMissionCode) == .orderedSame
        }) {
            return missionCatalog.flightMissionPickerTitle(selected)
        }
        return "Select Flight Mission"
    }

    private func informativeRouteRow(index: Int, leg: CVRLocalDispatchDraftLeg) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("LEG \(index + 1) · INFORMATIVE")
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            HStack(spacing: 10) {
                informativeAirportField(
                    "DEP AD",
                    text: Binding(
                        get: { draft.legs[index].departureAirport },
                        set: { draft.setDeparture(legIndex: index, airport: $0) }
                    ),
                    editable: index == 0,
                    focus: .departure(index)
                )
                Image(systemName: "arrow.right")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                informativeAirportField(
                    "ARR AD",
                    text: Binding(
                        get: { draft.legs[index].arrivalAirport },
                        set: { draft.setArrival(legIndex: index, airport: $0) }
                    ),
                    editable: true,
                    focus: .arrival(index)
                )
            }
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func informativeAirportField(
        _ title: String,
        text: Binding<String>,
        editable: Bool,
        focus: RouteField
    ) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            if editable {
                TextField(title, text: text)
                    .textInputAutocapitalization(.characters)
                    .autocorrectionDisabled()
                    .focused($focusedRouteField, equals: focus)
                    .font(.title3.weight(.bold).monospaced())
                    .padding(.horizontal, 10)
                    .frame(maxWidth: .infinity, minHeight: 52)
                    .background(Color.black.opacity(0.28), in: RoundedRectangle(cornerRadius: 10))
                    .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                    .onChange(of: text.wrappedValue) { _, value in
                        let sanitized = CVRLocalDispatchDraft.sanitizeAirportInput(value)
                        if sanitized != value {
                            text.wrappedValue = sanitized
                        }
                    }
            } else {
                Text(text.wrappedValue.isEmpty ? "—" : text.wrappedValue)
                    .font(.title3.weight(.bold).monospaced())
                    .frame(maxWidth: .infinity, minHeight: 52, alignment: .leading)
                    .padding(.horizontal, 10)
                    .background(Color.black.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
            }
        }
        .frame(maxWidth: .infinity)
    }

    private var informativeAddLegButton: some View {
        Button {
            draft.addLeg()
            CVRHaptics.impact(.medium)
        } label: {
            Label("ADD INFORMATIVE LEG", systemImage: "plus.circle.fill")
                .font(.subheadline.weight(.bold))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                .frame(maxWidth: .infinity, minHeight: 48)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    private var localCrewEditor: some View {
        VStack(alignment: .leading, spacing: 22) {
            localCrewPanel("CUSTOMER") {
                localCrewUserButton(customerDisplayName) {
                    activeCrewPicker = .customer
                }
                localPICCheckbox(isOn: $customerIsPIC)
            }

            localCrewPanel("PERSON 2 (OPTIONAL)") {
                localCrewUserButton(personTwoDisplayName) {
                    activeCrewPicker = .personTwo
                }
                localCockpitMenu(personTwoRole == .unknown ? "Select role" : personTwoRole.label) {
                    Button("Select role") { personTwoRole = .unknown }
                    Button("Instructor") { personTwoRole = .instructor }
                    Button("Pilot Monitoring") { personTwoRole = .pilotMonitoring }
                    Button("Safety Pilot") { personTwoRole = .safetyPilot }
                    Button("Examiner") { personTwoRole = .examiner }
                }
                localPICCheckbox(isOn: $personTwoIsPIC)
            }

            localCrewPanel("PERSON 3 (OPTIONAL)") {
                localCrewUserButton(personThreeDisplayName) {
                    activeCrewPicker = .personThree
                }
                localCockpitMenu(personThreeRole == .unknown ? "Select role" : personThreeRole.label) {
                    Button("Select role") { personThreeRole = .unknown }
                    Button("Supervising Instructor") { personThreeRole = .supervisingInstructor }
                    Button("Observer") { personThreeRole = .observer }
                }
            }
        }
    }

    private func localCrewPanel<Content: View>(
        _ title: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            content()
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.black.opacity(0.18), in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func localCockpitMenu<Content: View>(
        _ value: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        Menu {
            content()
        } label: {
            HStack(spacing: 12) {
                Text(value)
                    .font(.body.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
                Spacer(minLength: 12)
                Image(systemName: "chevron.up.chevron.down")
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
            }
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity, minHeight: 56, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.secondaryBlue.opacity(0.45), lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }

    private func localCrewUserButton(_ value: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 12) {
                Text(value)
                    .font(.body.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
                Spacer(minLength: 12)
                Image(systemName: "person.crop.circle.badge.chevron.down")
                    .font(.title3)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
            }
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity, minHeight: 56, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.secondaryBlue.opacity(0.45), lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private func localPICCheckbox(isOn: Binding<Bool>) -> some View {
        Button {
            isOn.wrappedValue.toggle()
        } label: {
            HStack(spacing: 10) {
                Image(systemName: isOn.wrappedValue ? "checkmark.square.fill" : "square")
                    .font(.title3)
                Text("PIC")
                    .font(.subheadline.weight(.bold))
            }
            .foregroundStyle(isOn.wrappedValue ? CVROperationalPalette.success : CVROperationalPalette.textPrimary)
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity, minHeight: 52, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private var customerDisplayName: String {
        studentCrewUsers.first(where: { $0.id == customerUserID })?.displayName ?? "Select Customer"
    }

    private var personTwoDisplayName: String {
        operationalCrewUsers.first(where: { $0.id == personTwoUserID }).map(accountDisplayName)
            ?? "No second pilot"
    }

    private var personThreeDisplayName: String {
        operationalCrewUsers.first(where: { $0.id == personThreeUserID }).map(accountDisplayName)
            ?? "No supervisor or observer"
    }

    private var studentCrewUsers: [CVRCrewUser] {
        settings.crewUsers.filter {
            $0.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == "student"
        }
    }

    private var operationalCrewUsers: [CVRCrewUser] {
        settings.crewUsers.filter {
            $0.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() != "admin"
        }
    }

    private func accountDisplayName(_ user: CVRCrewUser) -> String {
        let role = user.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let isInstructor = role.contains("instructor") || role.contains("supervisor")
        return isInstructor ? "\(user.displayName) (Instructor)" : user.displayName
    }

    private func selectedUserID(for target: LocalCrewPickerTarget) -> Int {
        switch target {
        case .customer: customerUserID
        case .personTwo: personTwoUserID
        case .personThree: personThreeUserID
        }
    }

    private func applyCrewSelection(_ userID: Int?, to target: LocalCrewPickerTarget) {
        switch target {
        case .customer:
            customerUserID = userID ?? 0
        case .personTwo:
            personTwoUserID = userID ?? 0
            if userID == nil {
                personTwoRole = .unknown
                personTwoIsPIC = false
            }
        case .personThree:
            personThreeUserID = userID ?? 0
            if userID == nil {
                personThreeRole = .unknown
            }
        }
    }

    private var localValidationMessage: String? {
        let window = resolvedScheduleWindow
        guard window.end > window.start else {
            return "Schedule End must be later than Schedule Start."
        }
        guard !draft.selectedMissionCode.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            return "Select the Mission."
        }
        guard studentCrewUsers.contains(where: { $0.id == customerUserID }) else {
            return "Select the Customer from the Student accounts."
        }
        if personTwoUserID > 0, personTwoRole == .unknown {
            return "Select the role for Person 2."
        }
        if personThreeUserID > 0, personThreeRole == .unknown {
            return "Select the role for Person 3."
        }
        let selectedIDs = [customerUserID, personTwoUserID, personThreeUserID].filter { $0 > 0 }
        guard Set(selectedIDs).count == selectedIDs.count else {
            return "Each position must use a different user account."
        }
        let picCount = (customerIsPIC ? 1 : 0) + (personTwoUserID > 0 && personTwoIsPIC ? 1 : 0)
        guard (1...2).contains(picCount) else {
            return "Select one or two pilots logging PIC."
        }
        if hasInformativeRouteInput, let routeMessage = draft.validationMessage {
            return "Informative route: \(routeMessage)"
        }
        return nil
    }

    private var hasInformativeRouteInput: Bool {
        draft.legs.contains {
            !$0.departureAirport.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                || !$0.arrivalAirport.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        }
    }

    private var informativeAirportChain: [String] {
        hasInformativeRouteInput && draft.validationMessage == nil ? draft.airportChain : []
    }

    private func localCrewAssignments() -> [CVRCrewAssignment] {
        guard let customer = settings.crewUsers.first(where: { $0.id == customerUserID }) else {
            return []
        }
        var crew = [
            CVRCrewAssignment(
                id: UUID().uuidString,
                personID: customer.id,
                personName: customer.displayName,
                role: .student,
                pilotFunction: .pilotFlying,
                isPIC: customerIsPIC
            )
        ]
        if let personTwo = settings.crewUsers.first(where: { $0.id == personTwoUserID }),
           personTwoRole != .unknown {
            crew.append(CVRCrewAssignment(
                id: UUID().uuidString,
                personID: personTwo.id,
                personName: personTwo.displayName,
                role: personTwoRole,
                pilotFunction: .pilotMonitoring,
                isPIC: personTwoIsPIC
            ))
        }
        if let personThree = settings.crewUsers.first(where: { $0.id == personThreeUserID }),
           personThreeRole != .unknown {
            crew.append(CVRCrewAssignment(
                id: UUID().uuidString,
                personID: personThree.id,
                personName: personThree.displayName,
                role: personThreeRole,
                pilotFunction: .none,
                isPIC: false
            ))
        }
        return crew
    }

    private func loadDraft() {
        if missionCatalog.missions.isEmpty {
            missionCatalog.loadBundledFallback()
        }
        // Always start a brand-new route-free local reservation.
        CVRLocalDispatchDraft.clear()
        draft = CVRLocalDispatchDraft.fresh()
        customerUserID = 0
        customerIsPIC = false
        personTwoUserID = 0
        personTwoRole = .unknown
        personTwoIsPIC = false
        personThreeUserID = 0
        personThreeRole = .unknown
        let defaults = Self.defaultScheduleWindow()
        scheduledDate = defaults.date
        scheduledStartTime = defaults.start
        scheduledEndTime = defaults.end
        validationHint = ""
    }

    private static func defaultScheduleWindow(now: Date = Date()) -> (date: Date, start: Date, end: Date) {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        let minute = calendar.component(.minute, from: now)
        let rounded = calendar.date(byAdding: .minute, value: (15 - minute % 15) % 15, to: now) ?? now
        let start = calendar.date(bySetting: .second, value: 0, of: rounded) ?? rounded
        return (calendar.startOfDay(for: start), start, start.addingTimeInterval(2 * 3600))
    }

    private var resolvedScheduleWindow: (start: Date, end: Date) {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        func combine(_ day: Date, _ time: Date) -> Date {
            let clock = calendar.dateComponents([.hour, .minute], from: time)
            return calendar.date(
                bySettingHour: clock.hour ?? 0,
                minute: clock.minute ?? 0,
                second: 0,
                of: day
            ) ?? day
        }
        return (combine(scheduledDate, scheduledStartTime), combine(scheduledDate, scheduledEndTime))
    }

    private func create() {
        if let message = localValidationMessage {
            validationHint = message
            return
        }
        guard settings.selectedAircraft != nil else {
            validationHint = "Aircraft configuration is required before creating a Dispatch."
            return
        }
        workflow.createOrOpenLocalDispatch(
            selectedAircraft: settings.selectedAircraft,
            cvrUnitID: settings.cvrUnitIdentifier,
            beaconID: beacon.expectedBeaconIdentityHex,
            canonicalWriteEnabled: settings.operationalIdentityCanonicalWriteEnabled,
            operationalSessionModelEnabled: settings.operationalSessionModelEnabled,
            missionCode: draft.selectedMissionCode,
            crew: localCrewAssignments(),
            informativeRouteAirports: informativeAirportChain,
            forceNewReservation: true,
            scheduledStartTime: resolvedScheduleWindow.start,
            scheduledEndTime: resolvedScheduleWindow.end
        )
        if workflow.lastError.isEmpty {
            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            CVRLocalDispatchDraft.clear()
            dismiss()
        } else {
            validationHint = workflow.lastError
        }
    }
}

/// Edit planned legs on an existing local Dispatch before the first DISPATCH FLIGHT.
private struct LocalRouteEditorSheet: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @Environment(\.dismiss) private var dismiss

    @State private var draft = CVRLocalDispatchDraft.fresh()
    @State private var validationHint = ""
    @FocusState private var focusedField: Field?

    private enum Field: Hashable {
        case departure(Int)
        case arrival(Int)
    }

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(Array(draft.legs.enumerated()), id: \.element.legUUID) { index, leg in
                        VStack(alignment: .leading, spacing: 8) {
                            Text("LEG \(index + 1)")
                                .font(.caption.weight(.bold))
                                .tracking(1.2)
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            HStack(spacing: 10) {
                                routeAirportField(
                                    label: "DEP AD",
                                    text: Binding(
                                        get: { draft.legs[index].departureAirport },
                                        set: { draft.setDeparture(legIndex: index, airport: $0) }
                                    ),
                                    editable: index == 0,
                                    focus: .departure(index)
                                )
                                Image(systemName: "arrow.right")
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.textSecondary)
                                routeAirportField(
                                    label: "ARR AD",
                                    text: Binding(
                                        get: { draft.legs[index].arrivalAirport },
                                        set: { draft.setArrival(legIndex: index, airport: $0) }
                                    ),
                                    editable: true,
                                    focus: .arrival(index)
                                )
                            }
                        }
                        .padding(14)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                        .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                        .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 8, trailing: 16))
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                        .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                            if draft.legs.count > 1, leg.isErasable {
                                Button(role: .destructive) {
                                    if draft.eraseLeg(id: leg.legUUID) {
                                        UINotificationFeedbackGenerator().notificationOccurred(.warning)
                                    }
                                } label: {
                                    Label("ERASE", systemImage: "trash")
                                }
                                .tint(CVROperationalPalette.critical)
                            }
                        }
                    }
                    .onDelete(perform: eraseLegs)
                } header: {
                    Text("Route")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        .textCase(nil)
                }

                Section {
                    Button {
                        draft.addLeg()
                        CVRHaptics.impact(.medium)
                    } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "plus.circle.fill")
                            Text("ADD LEG")
                                .font(.subheadline.weight(.bold))
                                .tracking(1.0)
                        }
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        .frame(maxWidth: .infinity, minHeight: 48)
                    }
                    .buttonStyle(.plain)
                    .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                    .listRowBackground(Color.clear)
                    .listRowSeparator(.hidden)

                    if !validationHint.isEmpty {
                        Text(validationHint)
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.warning)
                            .listRowBackground(Color.clear)
                            .listRowSeparator(.hidden)
                    }

                    CVROperationalActionButton(
                        title: "SAVE ROUTE",
                        subtitle: "\(draft.legs.count) leg\(draft.legs.count == 1 ? "" : "s")",
                        color: CVROperationalPalette.success
                    ) {
                        save()
                    }
                    .listRowInsets(EdgeInsets(top: 8, leading: 16, bottom: 24, trailing: 16))
                    .listRowBackground(Color.clear)
                    .listRowSeparator(.hidden)
                }
            }
            .listStyle(.plain)
            .scrollContentBackground(.hidden)
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Edit Route")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedField = nil }
                }
            }
            .onAppear(perform: loadFromSession)
        }
        .preferredColorScheme(.dark)
    }

    private func routeAirportField(
        label: String,
        text: Binding<String>,
        editable: Bool,
        focus: Field
    ) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label)
                .font(.caption2.weight(.bold))
                .tracking(0.8)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            if editable {
                TextField(label, text: text)
                    .textInputAutocapitalization(.characters)
                    .autocorrectionDisabled()
                    .font(.title3.weight(.bold).monospaced())
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .focused($focusedField, equals: focus)
                    .frame(minHeight: 44)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color.black.opacity(0.28), in: RoundedRectangle(cornerRadius: 10))
                    .onChange(of: text.wrappedValue) { _, newValue in
                        let sanitized = CVRLocalDispatchDraft.sanitizeAirportInput(newValue)
                        if sanitized != newValue {
                            text.wrappedValue = sanitized
                        }
                    }
            } else {
                Text(text.wrappedValue.isEmpty ? "—" : text.wrappedValue)
                    .font(.title3.weight(.bold).monospaced())
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color.black.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func eraseLegs(at offsets: IndexSet) {
        let ids = offsets.compactMap { index -> String? in
            guard draft.legs.indices.contains(index) else { return nil }
            return draft.legs[index].legUUID
        }
        for id in ids {
            if draft.eraseLeg(id: id) {
                UINotificationFeedbackGenerator().notificationOccurred(.warning)
            }
        }
    }

    private func loadFromSession() {
        let session = workflow.state.operationalSession
        let planned = CVRDispatchRouteOverview.ordered((session?.plannedLegs ?? []).map {
            CVRDispatchRouteOverview.Leg(
                legUUID: $0.legUUID,
                sequenceNumber: $0.sequenceNumber,
                departureAirport: $0.departureAirport,
                destinationAirport: $0.destinationAirport,
                status: $0.status
            )
        })
        if planned.isEmpty {
            let dep = workflow.state.activeDispatch?.plannedDepartureAirport
                ?? settings.selectedAircraft?.homeAirport
                ?? ""
            let arr = workflow.state.activeDispatch?.plannedDestinationAirport ?? ""
            draft = CVRLocalDispatchDraft(
                reservationUUID: session?.reservationUUID ?? UUID().uuidString.lowercased(),
                selectedMissionCode: workflow.state.activeDispatch?.missionCode ?? "",
                legs: [
                    CVRLocalDispatchDraftLeg(
                        legUUID: UUID().uuidString.lowercased(),
                        departureAirport: CVROperationalIdentityLocal.normalizeAirport(dep),
                        arrivalAirport: CVROperationalIdentityLocal.normalizeAirport(arr),
                        status: "planned"
                    ),
                ]
            )
        } else {
            draft = CVRLocalDispatchDraft(
                reservationUUID: session?.reservationUUID ?? UUID().uuidString.lowercased(),
                selectedMissionCode: workflow.state.activeDispatch?.missionCode ?? "",
                legs: planned.map {
                    CVRLocalDispatchDraftLeg(
                        legUUID: $0.legUUID,
                        departureAirport: $0.departureAirport,
                        arrivalAirport: $0.destinationAirport,
                        status: $0.status
                    )
                }
            )
            draft.reapplyContinuity()
        }
        _ = missionCatalog
    }

    private func save() {
        workflow.applyLocalRouteDraft(draft)
        if workflow.lastError.isEmpty {
            dismiss()
        } else {
            validationHint = workflow.lastError
        }
    }
}

private struct ScheduleWindowEditorSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State private var departure: Date
    @State private var arrival: Date
    let onSave: (Date, Date) -> Void

    init(start: Date, end: Date, onSave: @escaping (Date, Date) -> Void) {
        _departure = State(initialValue: start)
        _arrival = State(initialValue: end)
        self.onSave = onSave
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("SCHEDULE WINDOW") {
                    CVRReservationTimePickerRow(
                        title: "Scheduled Departure",
                        selection: $departure
                    )
                    CVRReservationTimePickerRow(
                        title: "Scheduled Arrival",
                        selection: $arrival
                    )
                    if !isValid {
                        Text("Scheduled Arrival must be later than Scheduled Departure.")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.critical)
                    }
                }
                Section {
                    Text("This edits the online schedule only. It does not create a new reservation or change the Duty Assignment identity.")
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
            }
            .navigationTitle("Schedule Times")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        onSave(departure, arrival)
                        dismiss()
                    }
                    .disabled(!isValid)
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var isValid: Bool {
        arrival > departure && Calendar.current.isDate(departure, inSameDayAs: arrival)
    }
}

private struct InformativeRouteEditorSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State private var airports: [String]
    let onSave: ([String]) -> Void

    init(airports: [String], onSave: @escaping ([String]) -> Void) {
        let initial = airports.isEmpty ? ["", ""] : airports
        _airports = State(initialValue: initial)
        self.onSave = onSave
    }

    var body: some View {
        NavigationStack {
            Form {
                Section("INFORMATIVE ROUTE") {
                    ForEach(airports.indices, id: \.self) { index in
                        HStack {
                            Text(index == 0 ? "FROM" : (index == airports.count - 1 ? "TO" : "VIA"))
                                .font(.caption.weight(.bold))
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                                .frame(width: 44, alignment: .leading)
                            TextField("ICAO", text: $airports[index])
                                .textInputAutocapitalization(.characters)
                                .autocorrectionDisabled()
                                .font(.title3.weight(.bold).monospaced())
                            if airports.count > 2 && index > 0 && index < airports.count - 1 {
                                Button(role: .destructive) {
                                    airports.remove(at: index)
                                } label: {
                                    Image(systemName: "minus.circle.fill")
                                }
                            }
                        }
                    }
                    Button("ADD INTERMEDIATE STOP") {
                        airports.insert("", at: max(1, airports.count - 1))
                    }
                    Button("CLEAR ROUTE", role: .destructive) {
                        airports = ["", ""]
                    }
                }
                Section {
                    Text("This route is planning information only. Editing it does not create a new reservation or define the actual flown legs.")
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
            }
            .navigationTitle("Edit Informative Route")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        let normalized = airports.map {
                            $0.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
                        }
                        onSave(normalized.allSatisfy(\.isEmpty) ? [] : normalized)
                        dismiss()
                    }
                    .disabled(!isValid)
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var isValid: Bool {
        let normalized = airports.map {
            $0.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        }
        if normalized.allSatisfy(\.isEmpty) {
            return true
        }
        return normalized.count >= 2
            && normalized.allSatisfy(CVRLocalDispatchDraft.isValidICAOIdentifier)
    }
}

struct DispatchWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var sessionsStore: ScheduledSessionsStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var system: SystemMonitor
    @Binding var showAdminUnlock: Bool
    @State private var activeBlockEditor: DispatchBlockEditor?
    @State private var showMissionPicker = false
    @State private var showFuelRefuelConfirm = false
    @State private var showOilServiceConfirm = false
    @State private var isUndispatching = false
    @State private var recoveryExportURL: URL?
    @State private var recoveryExportError = ""
    @State private var repairRefueledSincePreviousFlight = false
    @State private var repairOilServicedSincePreviousFlight = false
    @State private var repairOilPercent = 0.0
    @State private var repairHasOilSelection = false
    @State private var showLocalDispatchSheet = false
    @State private var showScheduleWindowEditor = false
    @State private var showInformativeRouteEditor = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        statusCard(metrics)
                        if let syncInfo = workflow.scheduleDutySyncInfo {
                            CVRScheduleDutySyncBanner(info: syncInfo)
                        }
                        dispatchTiles(metrics)
                        scheduleWindowEditor
                        missionSelector
                        routeOverview
                        dispatchOilUploadSection
                        dispatchWarningsSection
                        actionButtons
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
                .scrollDismissesKeyboard(.interactively)
            }
        }
        .onAppear {
            syncContinuityRepairState()
            workflow.sanitizeRouteStatusesIfNeeded()
            Task {
                await settings.refreshFuelState()
                await MainActor.run {
                    workflow.backfillDispatchCarryoverIfNeeded(
                        serverFuelUSG: settings.serverFuelState?.quantityUSG
                    )
                }
            }
            if missionCatalog.missions.isEmpty {
                missionCatalog.loadBundledFallback()
            }
        }
        .onChange(of: workflow.state.activeDispatch?.modifiedAt) {
            syncContinuityRepairState()
        }
        .onChange(of: workflow.scheduleRefreshRevision) {
            Task {
                if await sessionsStore.refresh(settings: settings) {
                    workflow.discardRejectedScheduledDraftMissingFromServer(
                        serverSessions: sessionsStore.sessions
                    )
                }
            }
        }
        .sheet(isPresented: $showLocalDispatchSheet) {
            LocalDispatchSheet()
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(beacon)
                .environmentObject(missionCatalog)
                .environmentObject(uploadManager)
        }
        .sheet(isPresented: $showScheduleWindowEditor) {
            if let dispatch = workflow.state.activeDispatch,
               let start = dispatch.scheduledStartTime,
               let end = dispatch.scheduledEndTime {
                ScheduleWindowEditorSheet(start: start, end: end) { newStart, newEnd in
                    if workflow.updateActiveScheduleWindow(start: newStart, end: newEnd) {
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    }
                }
            }
        }
        .sheet(isPresented: $showInformativeRouteEditor) {
            let airports = workflow.state.activeDispatch?.informativeRouteAirports ?? []
            InformativeRouteEditorSheet(airports: airports) { updatedAirports in
                if workflow.updateActiveInformativeRoute(airports: updatedAirports) {
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                }
            }
        }
        .confirmationDialog(
            "Confirm the airplane was refueled before this flight",
            isPresented: $showFuelRefuelConfirm,
            titleVisibility: .visible
        ) {
            Button("Confirm") {
                confirmAircraftWasRefueled()
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("This records the fuel uplift acknowledgment.")
        }
        .confirmationDialog(
            "Confirm oil was serviced?",
            isPresented: $showOilServiceConfirm,
            titleVisibility: .visible
        ) {
            Button("Yes — Oil Was Serviced") {
                confirmOilWasServiced()
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Oil quantity is more than 20% above the previous flight’s ending value. Confirm only if oil was serviced before this flight. You can also confirm this on the FUEL/OIL tab.")
        }
        .sheet(isPresented: $showMissionPicker) {
            CVRMissionPickerSheet(
                missions: flightMissions,
                selectedMissionCode: workflow.state.activeDispatch?.missionCode ?? "",
                titleProvider: { missionCatalog.flightMissionPickerTitle($0) }
            ) { code in
                workflow.updateActiveDispatch { dispatch in
                    dispatch.missionCode = code
                }
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
        }
        .sheet(item: $activeBlockEditor) { editor in
            DispatchEditorView(focus: editor)
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(missionCatalog)
                .environmentObject(uploadManager)
                .presentationDetents([.large])
        }
    }

    private func statusCard(_ metrics: CVROperationalMetrics) -> some View {
        CVROperationalStatusCard(
            title: dispatchStatus.displayTitle,
            subtitle: statusSubtitle,
            iconName: dispatchStatusIcon,
            color: dispatchStatusColor,
            value: nil,
            caption: nil,
            hugsContent: true,
            metrics: metrics
        )
    }

    private func dispatchTiles(_ metrics: CVROperationalMetrics) -> some View {
        LazyVGrid(
            columns: [GridItem(.flexible(), spacing: metrics.spacing), GridItem(.flexible(), spacing: metrics.spacing)],
            spacing: metrics.spacing
        ) {
            CVROperationalTile(
                title: "ACFT",
                iconName: "airplane",
                value: aircraftTile,
                color: aircraftTileColor,
                metrics: metrics,
                caption: aircraftTileCaption,
                action: aircraftTileAction
            )
            CVROperationalTile(
                title: "CREW",
                iconName: "person.2.fill",
                value: crewTile,
                color: crewTileColor,
                metrics: metrics,
                caption: crewTileCaption,
                action: workflow.state.activeDispatch == nil
                    || workflow.isDispatchLocked
                    || workflow.isReservationCrewLocked
                    ? nil
                    : {
                    activeBlockEditor = .crew
                }
            )
            CVROperationalTile(
                title: "METERS",
                iconName: "gauge.with.dots.needle.bottom.50percent",
                value: meterTile,
                color: meterTileColor,
                metrics: metrics,
                caption: meterTileCaption,
                action: workflow.state.activeDispatch == nil || workflow.isDispatchLocked ? nil : {
                    activeBlockEditor = .meters
                }
            )
            CVROperationalTile(
                title: "FUEL/OIL",
                iconName: "fuelpump.fill",
                value: fuelTile,
                color: fuelTileColor,
                metrics: metrics,
                caption: fuelTileCaption,
                action: workflow.state.activeDispatch == nil || workflow.isDispatchLocked ? nil : {
                    activeBlockEditor = .fuelOil
                }
            )
        }
    }

    @ViewBuilder
    private var scheduleWindowEditor: some View {
        if let dispatch = workflow.state.activeDispatch {
            HStack(spacing: 12) {
                scheduleTimeField(
                    title: "SCHEDULED DEPARTURE",
                    value: dispatch.scheduledStartTime
                )
                scheduleTimeField(
                    title: "SCHEDULED ARRIVAL",
                    value: dispatch.scheduledEndTime
                )
            }
        }
    }

    private func scheduleTimeField(title: String, value: Date?) -> some View {
        Button {
            guard !workflow.isDispatchLocked else { return }
            showScheduleWindowEditor = true
        } label: {
            VStack(alignment: .leading, spacing: 7) {
                Text(title)
                    .font(.caption2.weight(.bold))
                    .tracking(0.8)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                Text(cvrReservationTime(value) ?? "SET TIME")
                    .font(.title3.weight(.bold).monospacedDigit())
                    .foregroundStyle(CVROperationalPalette.textPrimary)
            }
            .padding(14)
            .frame(maxWidth: .infinity, minHeight: 68, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .buttonStyle(.plain)
        .disabled(workflow.isDispatchLocked || value == nil)
    }

    private var routeOverview: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text("ROUTE · INFORMATIVE")
                    .font(.caption.weight(.bold))
                    .tracking(1.2)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                Spacer()
                if workflow.state.activeDispatch != nil && !workflow.isDispatchLocked {
                    Text("TAP TO EDIT")
                        .font(.caption2.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
            }
            Button {
                guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return }
                showInformativeRouteEditor = true
            } label: {
                HStack {
                    Text(informativeDispatchRoute)
                        .font(.subheadline.weight(.semibold).monospaced())
                        .foregroundStyle(CVROperationalPalette.textPrimary)
                    Spacer()
                    if !workflow.isDispatchLocked {
                        Image(systemName: "pencil")
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                }
                .padding(14)
                .frame(maxWidth: .infinity, minHeight: 52, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            }
            .buttonStyle(.plain)
            .disabled(workflow.state.activeDispatch == nil || workflow.isDispatchLocked)
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Informative scheduled route")
    }

    private var informativeDispatchRoute: String {
        guard let dispatch = workflow.state.activeDispatch else {
            return "No scheduled route"
        }
        let scheduledAirports = (dispatch.informativeRouteAirports ?? []).compactMap { value -> String? in
            let airport = value.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
            return airport.isEmpty ? nil : airport
        }
        if !scheduledAirports.isEmpty {
            return scheduledAirports.joined(separator: " → ")
        }
        let dep = dispatch.plannedDepartureAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        let arr = dispatch.plannedDestinationAirport.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        if dep.isEmpty && arr.isEmpty { return "No scheduled route" }
        return [dep, arr].filter { !$0.isEmpty }.joined(separator: " → ")
    }

    private var missionSelector: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("MISSION")
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            if workflow.state.activeDispatch == nil {
                Text("Open a Dispatch to select a mission.")
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            } else if workflow.isDispatchLocked {
                Text(selectedMissionLabel)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .padding(14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            } else {
                Button {
                    showMissionPicker = true
                } label: {
                    HStack {
                        Text(selectedMissionLabel)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(
                                (workflow.state.activeDispatch?.missionCode ?? "").isEmpty
                                    ? CVROperationalPalette.textSecondary
                                    : CVROperationalPalette.textPrimary
                            )
                            .multilineTextAlignment(.leading)
                        Spacer(minLength: 8)
                        Image(systemName: "chevron.up.chevron.down")
                            .font(.caption.weight(.bold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                    }
                    .padding(14)
                    .frame(minHeight: 52)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    .overlay(RoundedRectangle(cornerRadius: 12).stroke(
                        (workflow.state.activeDispatch?.missionCode ?? "").isEmpty
                            ? CVROperationalPalette.warning.opacity(0.7)
                            : CVROperationalPalette.cardBorder,
                        lineWidth: 1
                    ))
                }
                .buttonStyle(.plain)
                .disabled(flightMissions.isEmpty)
            }
        }
    }

    private var flightMissions: [CVRMissionCatalogEntry] {
        missionCatalog.flightMissions
    }

    private var selectedMissionLabel: String {
        let code = workflow.state.activeDispatch?.missionCode ?? ""
        if code.isEmpty { return "Select a flight mission" }
        if let selected = flightMissions.first(where: {
            $0.missionCode.caseInsensitiveCompare(code) == .orderedSame
        }) {
            return missionCatalog.flightMissionPickerTitle(selected)
        }
        return code
    }

    private var hasExceptionalDispatchWarning: Bool {
        workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration)
            || (workflow.lastError.nilIfEmpty.map {
                !$0.localizedCaseInsensitiveContains("upload")
                    && !$0.localizedCaseInsensitiveContains("workflow")
            } ?? false)
            || settings.selectedAircraft == nil
            || workflow.state.activeDispatch == nil
            || exceptionalCrewConflict != nil
    }

    private var hasContinuityDispatchWarnings: Bool {
        !(workflow.state.activeDispatch?.continuityDiscrepancies ?? []).isEmpty
    }

    private var dispatchWarningsSection: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("WARNINGS")
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.warning)
                .frame(maxWidth: .infinity, alignment: .leading)
            if hasExceptionalDispatchWarning || hasContinuityDispatchWarnings {
                exceptionalWarningCard
                continuityUploadRepairCard
            } else {
                Text("No Dispatch warnings.")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                    .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            }
        }
    }

    @ViewBuilder
    private var exceptionalWarningCard: some View {
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            CVROperationalWarningCard(
                title: "AIRCRAFT MISMATCH",
                message: "Dispatch tail \(aircraftTile) does not match enrolled aircraft \(settings.selectedAircraft?.registration ?? "—"). Correct the aircraft before continuing.",
                iconName: "airplane.circle.fill",
                color: CVROperationalPalette.critical
            )
        } else if let error = workflow.lastError.nilIfEmpty,
                  !error.localizedCaseInsensitiveContains("upload"),
                  !error.localizedCaseInsensitiveContains("workflow") {
            CVROperationalWarningCard(title: "STORAGE WARNING", message: error, iconName: "externaldrive.badge.exclamationmark", color: CVROperationalPalette.warning)
        } else if settings.selectedAircraft == nil {
            CVROperationalWarningCard(title: "AIRCRAFT CONFIGURATION REQUIRED", message: "Assign this CVR Unit to its aircraft before Dispatch.", iconName: "lock.trianglebadge.exclamationmark", color: CVROperationalPalette.critical)
        } else if workflow.state.activeDispatch == nil {
            CVROperationalWarningCard(title: "NO ACTIVE DISPATCH", message: "Create or open a Dispatch for this leg.", iconName: "exclamationmark.triangle.fill", color: CVROperationalPalette.standby)
        } else if let conflict = exceptionalCrewConflict {
            CVROperationalWarningCard(
                title: "CREW FUNCTION REQUIRED",
                message: conflict,
                iconName: "person.crop.circle.badge.exclamationmark",
                color: CVROperationalPalette.warning
            )
        }
    }

    /// Only exceptional crew conflicts — not the normal empty-crew case (shown in CREW block).
    private var exceptionalCrewConflict: String? {
        guard let dispatch = workflow.state.activeDispatch,
              dispatch.crew.contains(where: { $0.role == .unknown }) else { return nil }
        return "One or more crew members still need a flight function assigned."
    }

    @ViewBuilder
    private var dispatchOilUploadSection: some View {
        if workflow.dispatchUploadVerified() {
            Text("Oil has been uploaded")
                .font(.caption.weight(.bold))
                .tracking(0.8)
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 4)
        }
    }

    @ViewBuilder
    private var continuityUploadRepairCard: some View {
        let warnings = workflow.state.activeDispatch?.continuityDiscrepancies ?? []
        if !warnings.isEmpty {
            let needsRefuelConfirm = warnings.contains(where: { $0.contains("CONFIRM AIRCRAFT WAS REFUELED") })
            let needsOilConfirm = warnings.contains(where: { $0.contains("CONFIRM OIL WAS SERVICED") })
            let advisoryWarnings = warnings.filter {
                !$0.contains("CONFIRM AIRCRAFT WAS REFUELED") && !$0.contains("CONFIRM OIL WAS SERVICED")
            }
            VStack(spacing: 8) {
                if needsRefuelConfirm {
                    Button {
                        showFuelRefuelConfirm = true
                    } label: {
                        CVROperationalWarningCard(
                            title: "FUEL DISCREPANCY >20%",
                            message: "Confirm the airplane was refueled before this flight",
                            iconName: "exclamationmark.triangle.fill",
                            color: CVROperationalPalette.warning
                        )
                    }
                    .buttonStyle(.plain)
                }
                if needsOilConfirm {
                    Button {
                        showOilServiceConfirm = true
                    } label: {
                        CVROperationalWarningCard(
                            title: "OIL DISCREPANCY >20%",
                            message: "Confirm oil was serviced — tap to acknowledge",
                            iconName: "exclamationmark.triangle.fill",
                            color: CVROperationalPalette.warning
                        )
                    }
                    .buttonStyle(.plain)
                }
                if !advisoryWarnings.isEmpty {
                    CVROperationalWarningCard(
                        title: "DISPATCH CONTINUITY WARNING",
                        message: advisoryWarnings.joined(separator: "\n"),
                        iconName: "exclamationmark.triangle.fill",
                        color: CVROperationalPalette.warning
                    )
                }
            }
        }
    }

    private func confirmAircraftWasRefueled() {
        applyContinuityAcknowledgement { dispatch in
            dispatch.refueledSincePreviousFlight = true
        }
    }

    private func confirmOilWasServiced() {
        applyContinuityAcknowledgement { dispatch in
            dispatch.oilServicedSincePreviousFlight = true
        }
    }

    private func applyContinuityAcknowledgement(_ update: (inout CVRDispatchRecord) -> Void) {
        if workflow.canRepairFailedDispatchUpload {
            if workflow.updateActiveDispatchForUploadRepair(update) {
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
            return
        }
        workflow.updateActiveDispatch(update)
    }

    private var actionButtons: some View {
        VStack(spacing: 8) {
            if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
                CVROperationalActionButton(
                    title: "FIX AIRCRAFT ALIGNMENT",
                    subtitle: settings.selectedAircraft?.registration ?? "Select enrolled aircraft in Admin",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft)
                }
            }
            if workflow.state.activeDispatch == nil {
                if settings.selectedAircraft == nil {
                    CVROperationalActionButton(title: "CONFIGURE AIRCRAFT", subtitle: "Assign aircraft", color: CVROperationalPalette.critical) {
                        showAdminUnlock = true
                    }
                } else {
                    CVROperationalActionButton(title: "ADD RESERVATION", subtitle: "Works offline", color: CVROperationalPalette.secondaryBlue) {
                        showLocalDispatchSheet = true
                    }
                }
            } else if workflow.isDispatchLocked {
                CVRHoldActionButton(
                    title: isUndispatching ? "UNDISPATCHING…" : "UNDISPATCH",
                    subtitle: workflow.canUndispatchActiveFlight
                        ? "Hold for 2 seconds to confirm"
                        : "Unavailable after Off Block, recording, or Check-In",
                    color: CVROperationalPalette.warning,
                    minimumDuration: 2,
                    asyncAction: {
                        guard workflow.canUndispatchActiveFlight else { return false }
                        isUndispatching = true
                        defer { isUndispatching = false }
                        let released = await workflow.undispatchActiveFlight(settings: settings)
                        if released {
                            if await sessionsStore.refresh(settings: settings) {
                                workflow.discardRejectedScheduledDraftMissingFromServer(
                                    serverSessions: sessionsStore.sessions
                                )
                            }
                        }
                        return released
                    }
                )
                .disabled(isUndispatching || !workflow.canUndispatchActiveFlight)
                .opacity((isUndispatching || !workflow.canUndispatchActiveFlight) ? 0.55 : 1)
            } else {
                CVRHoldActionButton(
                    title: "DISPATCH NOW",
                    subtitle: "Hold 2 seconds to confirm",
                    color: canConfirmDispatch ? CVROperationalPalette.success : CVROperationalPalette.standby,
                    minimumDuration: 2
                ) {
                    guard canConfirmDispatch else { return false }
                    let acceptedWarnings = (workflow.state.activeDispatch?.continuityDiscrepancies ?? [])
                        + (audio.isInternalMicWarning ? ["IPHONE MICROPHONE ACTIVE"] : [])
                    workflow.verifyDispatchAndCreateFlightRecord()
                    // Preserve recorder verification evidence, but skip the manual check screen.
                    workflow.recordRecorderVerification(
                        audioRouteStatus: audio.sourceSummary,
                        beaconStatus: beacon.currentState.rawValue,
                        gpsStatus: gps.state.rawValue,
                        storageStatus: system.storageText,
                        thermalStatus: Self.dispatchThermalLabel,
                        batteryStatus: "\(system.batteryStateText) \(system.batteryLevelPercent)%",
                        permissionStatus: "app-level-checks-pending",
                        fileWritingTestResult: "deferred-to-recording-start",
                        warnings: acceptedWarnings,
                        acceptedWarnings: acceptedWarnings,
                        appVersion: Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0",
                        deviceID: UIDevice.current.identifierForVendor?.uuidString ?? "local-device"
                    )
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    return workflow.state.activeFlightRecord != nil
                }
                .disabled(!canConfirmDispatch)
                .opacity(canConfirmDispatch ? 1 : 0.55)
            }
        }
    }

    private static var dispatchThermalLabel: String {
        switch ProcessInfo.processInfo.thermalState {
        case .nominal: return "NOMINAL"
        case .fair: return "FAIR"
        case .serious: return "SERIOUS"
        case .critical: return "CRITICAL"
        @unknown default: return "UNKNOWN"
        }
    }

    private var dispatchDisabledReason: String {
        if crewNeedsEntry { return "Add crew in the CREW block" }
        if metersNeedEntry { return "Enter meters in the METERS block" }
        if fuelOilNeedEntry { return "Enter fuel and oil in the FUEL/OIL block" }
        if (workflow.state.activeDispatch?.missionCode ?? "").isEmpty { return "Select a flight mission" }
        if let first = workflow.dispatchMissingItems.first { return first }
        return "Complete required Dispatch items"
    }

    private var dispatchStatus: CVRDispatchStatus {
        workflow.state.activeDispatch?.status ?? .noDispatch
    }

    private var statusSubtitle: String {
        switch dispatchStatus {
        case .noDispatch:
            return "OPEN OR CREATE A FLIGHT ASSIGNMENT"
        case .dispatchIncomplete:
            return "COMPLETE THE HIGHLIGHTED BLOCKS"
        case .consentRequired:
            return "COMPLETE REQUIRED DISPATCH ITEMS"
        case .tailNumberConflict:
            return "AIRCRAFT DOES NOT MATCH CVR UNIT"
        case .readyForVerification:
            return ""
        case .dispatchVerified, .flightRecordLoggingEnabled:
            return "IN-FLIGHT READY - STBY FOR AVIONICS"
        }
    }

    private var dispatchStatusIcon: String {
        switch dispatchStatus {
        case .flightRecordLoggingEnabled, .dispatchVerified, .readyForVerification:
            return "checkmark.seal.fill"
        case .tailNumberConflict:
            return "xmark.octagon.fill"
        default:
            return "circle.fill"
        }
    }

    private var dispatchStatusColor: Color {
        switch dispatchStatus {
        case .flightRecordLoggingEnabled, .dispatchVerified:
            return CVROperationalPalette.success
        case .tailNumberConflict:
            return CVROperationalPalette.critical
        case .readyForVerification:
            return CVROperationalPalette.secondaryBlue
        default:
            return CVROperationalPalette.standby
        }
    }

    private var canConfirmDispatch: Bool {
        workflow.dispatchMissingItems.isEmpty
    }

    private var showsRepairRefuelConfirmation: Bool {
        switch workflow.dispatchContinuityUploadIssue() {
        case .refueling:
            return true
        case .oilServicing, nil:
            return workflow.dispatchMissingItems.contains(where: { $0.contains("CONFIRM AIRCRAFT WAS REFUELED") })
        }
    }

    private var showsRepairOilServiceConfirmation: Bool {
        switch workflow.dispatchContinuityUploadIssue() {
        case .oilServicing:
            return true
        case .refueling, nil:
            return workflow.dispatchMissingItems.contains(where: { $0.contains("CONFIRM OIL WAS SERVICED") })
        }
    }

    private func syncContinuityRepairState() {
        guard let dispatch = workflow.state.activeDispatch else { return }
        repairRefueledSincePreviousFlight = dispatch.refueledSincePreviousFlight ?? false
        repairOilServicedSincePreviousFlight = dispatch.oilServicedSincePreviousFlight ?? false
        if let oil = dispatch.effectiveStartingOilQuantity {
            repairOilPercent = min(max(oil, 0), operationalConfig.oilCapacity)
            repairHasOilSelection = true
        } else {
            repairOilPercent = operationalConfig.oilCapacity / 2
            repairHasOilSelection = false
        }
    }

    private func applyContinuityRepairAndRetryUpload() {
        guard workflow.updateActiveDispatchForUploadRepair({ dispatch in
            if repairHasOilSelection {
                dispatch.startingOilQuantity = repairOilPercent
                dispatch.startingOilUnit = operationalConfig.oilUnit
                dispatch.oilPercentage = operationalConfig.oilUnit == "%" ? Int(repairOilPercent.rounded()) : nil
            }
            if showsRepairRefuelConfirmation {
                dispatch.refueledSincePreviousFlight = repairRefueledSincePreviousFlight
            }
            if showsRepairOilServiceConfirmation {
                dispatch.oilServicedSincePreviousFlight = repairOilServicedSincePreviousFlight
            }
        }) else {
            return
        }
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
    }

    private var aircraftRegistration: String {
        settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT"
    }

    private var aircraftTile: String {
        workflow.state.activeDispatch?.tailNumber.nilIfEmpty
            ?? settings.selectedAircraft?.registration
            ?? "None"
    }

    private var aircraftTileCaption: String? {
        if settings.selectedAircraft == nil { return "Tap to configure" }
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            return "Tap to fix alignment"
        }
        return "Locked to this unit"
    }

    private var aircraftTileAction: (() -> Void)? {
        if settings.selectedAircraft == nil {
            return { showAdminUnlock = true }
        }
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            return { _ = workflow.repairDispatchAircraftAlignment(selectedAircraft: settings.selectedAircraft) }
        }
        return nil
    }

    private var aircraftTileColor: Color {
        if workflow.dispatchTailMismatch(enrolledRegistration: settings.selectedAircraft?.registration) {
            return CVROperationalPalette.critical
        }
        return aircraftTile == "None" ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var crewNeedsEntry: Bool {
        guard let dispatch = workflow.state.activeDispatch else { return true }
        return dispatch.crew.isEmpty
    }

    private var crewTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "0 selected" }
        return dispatch.crew.isEmpty ? "0 selected" : "\(dispatch.crew.count) selected"
    }

    private var crewTileCaption: String? {
        guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return nil }
        if workflow.isReservationCrewLocked {
            return "Reservation crew locked — new crew needs a new reservation"
        }
        return crewNeedsEntry ? "Tap to add crew" : "Tap to edit crew"
    }

    private var crewTileColor: Color {
        crewNeedsEntry ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var metersNeedEntry: Bool {
        guard let dispatch = workflow.state.activeDispatch else { return true }
        return dispatch.startingHobbs == nil || dispatch.startingTacho == nil
    }

    private var meterTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Starting meters required" }
        if dispatch.startingHobbs == nil || dispatch.startingTacho == nil {
            return "Starting meters required"
        }
        let hobbs = dispatch.startingHobbs.map { String(format: "H: %.1f", $0) } ?? "H: ?"
        let tacho = dispatch.startingTacho.map { String(format: "T: %.1f", $0) } ?? "T: ?"
        return "\(hobbs)\n\(tacho)"
    }

    private var meterTileCaption: String? {
        guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return nil }
        return metersNeedEntry ? "Tap to enter" : "Tap to edit"
    }

    private var meterTileColor: Color {
        metersNeedEntry ? CVROperationalPalette.warning : CVROperationalPalette.success
    }

    private var fuelOilNeedEntry: Bool {
        guard let dispatch = workflow.state.activeDispatch else { return true }
        return dispatch.fuelOnboard.isEmpty || dispatch.effectiveStartingOilQuantity == nil
    }

    private var fuelTile: String {
        guard let dispatch = workflow.state.activeDispatch else { return "Fuel and oil required" }
        if dispatch.fuelOnboard.isEmpty || dispatch.effectiveStartingOilQuantity == nil {
            return "Fuel and oil required"
        }
        let fuel = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit)
            .map { "F: \(Self.quantityText($0)) \(operationalConfig.fuelUnit)" }
            ?? "F: ? \(operationalConfig.fuelUnit)"
        let oil = dispatch.effectiveStartingOilQuantity
            .map { "O: \(Self.quantityText($0)) \(dispatch.effectiveStartingOilUnit)" }
            ?? "O: ? \(operationalConfig.oilUnit)"
        return "\(fuel)\n\(oil)"
    }

    private var fuelTileCaption: String? {
        guard workflow.state.activeDispatch != nil, !workflow.isDispatchLocked else { return nil }
        return fuelOilNeedEntry ? "Tap to enter" : "Tap to edit"
    }

    private var fuelTileColor: Color {
        guard let dispatch = workflow.state.activeDispatch, !fuelOilNeedEntry else {
            return CVROperationalPalette.warning
        }
        if let quantity = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit),
           quantity <= operationalConfig.fuelCapacity * (3.0 / 13.0) {
            return CVROperationalPalette.critical
        }
        return CVROperationalPalette.success
    }

    private var appVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
    }

    private func consentColor(for assignment: CVRCrewAssignment) -> Color {
        guard let dispatch = workflow.state.activeDispatch else { return CVROperationalPalette.warning }
        let hasConsent = workflow.state.consents.contains {
            $0.dispatchID == dispatch.id && $0.personName == assignment.personName && $0.crewRole == assignment.role && $0.consentResult
        }
        return hasConsent ? CVROperationalPalette.success : CVROperationalPalette.standby
    }

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }

    private static func quantityText(_ value: Double) -> String {
        String(format: "%.1f", value)
    }
}

enum DispatchBlockEditor: String, Identifiable {
    case crew
    case meters
    case fuelOil

    var id: String { rawValue }

    var title: String {
        switch self {
        case .crew: return "Crew"
        case .meters: return "Meters"
        case .fuelOil: return "Fuel / Oil"
        }
    }
}

struct RecorderWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var system: SystemMonitor
    @Binding var adminUnlocked: Bool
    @Binding var showAdminUnlock: Bool

    var body: some View {
        if workflow.state.activeDispatch == nil {
            NoActiveFlightView(caption: "RECORDER")
        } else if !workflow.isDispatchVerified {
            LockedOperationalView(
                title: "LOCKED",
                subtitle: "DISPATCH VERIFICATION REQUIRED",
                iconName: "lock.fill",
                color: CVROperationalPalette.standby,
                showAdminUnlock: $showAdminUnlock
            )
        } else if !workflow.isRecorderVerified {
            RecorderVerificationView(showAdminUnlock: $showAdminUnlock)
        } else {
            StatusDashboardView(
                adminUnlocked: $adminUnlocked,
                showAdminUnlock: $showAdminUnlock,
                showsHeader: false
            )
        }
    }
}

struct RecorderVerificationView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var system: SystemMonitor
    @Binding var showAdminUnlock: Bool

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(title: "READY FOR CHECK", subtitle: "RECORDER VERIFICATION REQUIRED", iconName: "waveform.badge.magnifyingglass", color: CVROperationalPalette.secondaryBlue, value: nil, caption: "RECORDER", metrics: metrics)
                        HStack(spacing: metrics.spacing) {
                            CVROperationalTile(title: "AUDIO", iconName: "mic.fill", value: audio.isInternalMicWarning ? "iPhone Mic" : audio.sourceSummary.replacingOccurrences(of: "Audio source: ", with: ""), color: audio.isInternalMicWarning ? CVROperationalPalette.warning : CVROperationalPalette.success, metrics: metrics)
                            CVROperationalTile(title: "BEACON", iconName: "dot.radiowaves.left.and.right", value: beacon.currentState.operationalStatus(secondsSinceLastAdvertisement: beacon.secondsSinceLastAdvertisement).label, color: beaconColor, metrics: metrics)
                            CVROperationalTile(title: "GPS", iconName: "location.fill", value: gpsLabel, color: gpsColor, metrics: metrics)
                            CVROperationalTile(title: "STORAGE", iconName: "externaldrive.fill", value: system.storageText, color: system.availableStorageBytes > 512_000_000 ? CVROperationalPalette.success : CVROperationalPalette.warning, metrics: metrics)
                        }
                        CVROperationalWarningCard(title: "VISUAL VERIFICATION", message: "Confirm recorder health before enabling in-flight logging.", iconName: "eye.fill", color: CVROperationalPalette.standby)
                        CVROperationalActionButton(title: "VERIFY RECORDER", subtitle: "Persist health check", color: CVROperationalPalette.success) {
                            workflow.recordRecorderVerification(
                                audioRouteStatus: audio.sourceSummary,
                                beaconStatus: beacon.currentState.rawValue,
                                gpsStatus: gps.state.rawValue,
                                storageStatus: system.storageText,
                                thermalStatus: thermalLabel,
                                batteryStatus: "\(system.batteryStateText) \(system.batteryLevelPercent)%",
                                permissionStatus: "app-level-checks-pending",
                                fileWritingTestResult: "deferred-to-recording-start",
                                warnings: audio.isInternalMicWarning ? ["IPHONE MICROPHONE ACTIVE"] : [],
                                acceptedWarnings: [],
                                appVersion: appVersion,
                                deviceID: UIDevice.current.identifierForVendor?.uuidString ?? "local-device"
                            )
                        }
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
            }
        }
    }

    private var aircraftRegistration: String {
        settings.selectedAircraft?.registration ?? workflow.state.activeDispatch?.tailNumber ?? "NO AIRCRAFT"
    }

    private var beaconColor: Color {
        beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing ? CVROperationalPalette.success : CVROperationalPalette.warning
    }

    private var gpsLabel: String {
        switch gps.state {
        case .ready, .recording: return "Ready"
        case .permissionNeeded: return "Acquiring"
        default: return "Unavailable"
        }
    }

    private var gpsColor: Color {
        switch gps.state {
        case .ready, .recording: return CVROperationalPalette.success
        case .permissionNeeded, .unavailable: return CVROperationalPalette.standby
        default: return CVROperationalPalette.critical
        }
    }

    private var thermalLabel: String {
        switch ProcessInfo.processInfo.thermalState {
        case .nominal: return "NOMINAL"
        case .fair: return "FAIR"
        case .serious: return "SERIOUS"
        case .critical: return "CRITICAL"
        @unknown default: return "UNKNOWN"
        }
    }

    private var appVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
    }
}

struct InFlightWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager
    @EnvironmentObject private var audio: AudioRecorderManager
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var coordinator: CVRUnitCoordinator
    @Binding var showAdminUnlock: Bool
    @State private var isShowingCheckIn = false
    @State private var exerciseConfirmed = false
    @State private var trainingRemarkConfirmed = false
    @State private var showTransientStopConfirm = false
    @State private var showEngineShutdownConfirm = false
    @State private var showForceFinalizeConfirm = false
    @State private var preferTransientStopHighlight = false

    var body: some View {
        Group {
            if workflow.state.activeDispatch == nil {
                NoActiveFlightView(caption: "IN-FLIGHT")
            } else if !workflow.isRecorderVerified {
                LockedOperationalView(title: "LOCKED", subtitle: "RECORDER VERIFICATION REQUIRED", iconName: "lock.fill", color: CVROperationalPalette.standby, showAdminUnlock: $showAdminUnlock)
            } else {
                TimelineView(.periodic(from: Date(), by: 0.2)) { timeline in
                    GeometryReader { proxy in
                        let metrics = CVROperationalMetrics(size: proxy.size)
                        ZStack {
                            CVROperationalPalette.background.ignoresSafeArea()
                            ScrollView {
                                VStack(spacing: metrics.spacing) {
                                    CVROperationalStatusCard(
                                        title: "IN-FLIGHT",
                                        subtitle: "",
                                        iconName: "airplane",
                                        color: inFlightColor,
                                        value: inFlightValue(now: timeline.date),
                                        caption: nil,
                                        hugsContent: true,
                                        metrics: metrics
                                    )
                                    inFlightRecordingStrip(now: timeline.date)
                                    HStack(spacing: metrics.spacing) {
                                        CVROperationalTile(
                                            title: "HOBBS",
                                            iconName: "gauge.with.dots.needle.67percent",
                                            value: liveHobbsLabel(now: timeline.date),
                                            color: engineRunning ? CVROperationalPalette.success : CVROperationalPalette.standby,
                                            metrics: metrics,
                                            compact: true
                                        )
                                        CVROperationalTile(
                                            title: "DISPATCH",
                                            iconName: "checkmark.seal.fill",
                                            value: "Verified",
                                            color: CVROperationalPalette.success,
                                            metrics: metrics,
                                            compact: true
                                        )
                                        CVROperationalTile(
                                            title: "REC",
                                            iconName: audio.isRecording ? "record.circle" : "waveform",
                                            value: audio.isRecording ? "ON" : "Idle",
                                            color: audio.isRecording ? CVROperationalPalette.critical : CVROperationalPalette.standby,
                                            metrics: metrics,
                                            compact: true
                                        )
                                        CVROperationalTile(
                                            title: "GPS",
                                            iconName: "location.fill",
                                            value: gps.state == .ready || gps.state == .recording ? "Ready" : "Acquiring",
                                            color: gps.state == .ready || gps.state == .recording ? CVROperationalPalette.success : CVROperationalPalette.standby,
                                            metrics: metrics,
                                            compact: true
                                        )
                                    }
                                    inFlightControlPanel(metrics: metrics)
                                }
                                .padding(.horizontal, metrics.outerHorizontalPadding)
                                .padding(.top, metrics.outerVerticalPadding)
                                .padding(.bottom, 132)
                                .frame(width: proxy.size.width, alignment: .top)
                            }
                        }
                    }
                }
            }
        }
        .onAppear {
            if workflow.synthesizeEngineContinuityIfNeeded(gpsSample: gps.latestSample) {
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
            Task {
                if workflow.state.operationalSession?.pendingSoftStartRecording == true
                    || workflow.state.engineSessionContinuityActive {
                    await coordinator.softStartRecordingIfAvionicsOn()
                }
            }
        }
        .sheet(isPresented: $isShowingCheckIn) {
            Group {
                if workflow.usesOperationalSessionModelV1 {
                    SecureFlightDataView()
                } else {
                    CheckInView()
                }
            }
            .environmentObject(workflow)
            .environmentObject(settings)
            .environmentObject(uploadManager)
            .environmentObject(gps)
            .environmentObject(coordinator)
            .presentationDetents([.large])
        }
        .confirmationDialog(
            "Confirm you will keep your engine running and depart on your next leg?",
            isPresented: $showTransientStopConfirm,
            titleVisibility: .visible
        ) {
            Button("Yes") {
                preferTransientStopHighlight = false
                workflow.recordTransientStopOnBlock(gpsSample: gps.latestSample)
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                Task {
                    await coordinator.finalizeRecordingForLegBoundary(reason: "Transient stop leg boundary.")
                    workflow.beginTransientStopCheckIn()
                    isShowingCheckIn = true
                }
            }
            Button("No", role: .cancel) {}
        }
        .confirmationDialog(
            "Did you shut down the engine?",
            isPresented: $showEngineShutdownConfirm,
            titleVisibility: .visible
        ) {
            Button("YES") {
                workflow.recordEngineShutdownOnBlock(gpsSample: gps.latestSample)
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                workflow.beginEngineShutdownCheckIn()
                isShowingCheckIn = true
            }
            Button("NOT YET", role: .cancel) {}
        }
        .confirmationDialog(
            "Finalize without Avionics OFF?",
            isPresented: $showForceFinalizeConfirm,
            titleVisibility: .visible
        ) {
            Button("Yes — Finalize Now", role: .destructive) {
                Task {
                    await coordinator.finalizeRecordingForLegBoundary(reason: "Forced finalize after Check-In.")
                    if workflow.forceFinalizeEngineShutdownAfterCheckIn() {
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    }
                }
            }
            Button("No — Wait for Avionics OFF", role: .cancel) {}
        } message: {
            Text("Check-In is already saved. Prefer turning Avionics OFF so recording stops cleanly. Finalize now only if Avionics OFF is unavailable — the flight will be archived to History/Log immediately.")
        }
    }

    private func inFlightRecordingStrip(now: Date) -> some View {
        HStack(spacing: 10) {
            Circle()
                .fill(audio.isRecording ? CVROperationalPalette.critical : CVROperationalPalette.standby)
                .frame(width: 10, height: 10)
                .opacity(audio.isRecording ? (Int(now.timeIntervalSince1970 * 2) % 2 == 0 ? 1 : 0.35) : 0.7)
            Text(audio.isRecording ? "RECORDING" : "RECORDER IDLE")
                .font(.caption2.weight(.bold))
                .foregroundStyle(audio.isRecording ? CVROperationalPalette.critical : CVROperationalPalette.textSecondary)
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(CVROperationalPalette.cardBorder.opacity(0.45))
                    Capsule()
                        .fill(audio.isRecording ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby)
                        .frame(width: max(4, proxy.size.width * recordingLevelFraction))
                }
            }
            .frame(height: 8)
            Text(String(format: "%.0f dB", Double(audio.averagePowerDB)))
                .font(.caption2.monospacedDigit().weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .frame(width: 52, alignment: .trailing)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private var recordingLevelFraction: CGFloat {
        // Map typical mic dB (-60…0) into 0…1 for a compact bar.
        let clamped = max(-60, min(0, Double(audio.averagePowerDB)))
        return CGFloat((clamped + 60) / 60)
    }

    private func liveHobbsLabel(now: Date) -> String {
        if let estimated = workflow.estimatedCheckInHobbs() {
            return String(format: "%.1f", estimated)
        }
        if let start = workflow.state.activeDispatch?.startingHobbs {
            return String(format: "%.1f", start)
        }
        return "—"
    }

    private var avionicsReady: Bool {
        beacon.isSimulationOverrideActive || beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing
    }

    private var hasEngineStartEvent: Bool {
        offBlockEvent != nil
    }

    private var engineRunning: Bool {
        hasEngineStartEvent || workflow.state.engineSessionContinuityActive
    }

    private var hasTransientStopEvent: Bool {
        event("transient_stop_on_block") != nil
    }

    private var hasEngineShutdownEvent: Bool {
        onBlockEvent != nil
    }

    private var hasLegBoundaryEvent: Bool {
        hasEngineShutdownEvent || hasTransientStopEvent
    }

    private var hasShutdownVerificationEvent: Bool {
        event("shutdown_verification_completed") != nil
    }

    private var awaitingAvionicsOff: Bool {
        workflow.state.activeFlightRecord?.status == .awaitingAvionicsOff
            || workflow.state.operationalSession?.awaitingAvionicsOffConfirmation == true
    }

    private var offBlockEvent: CVRFlightEventRecord? {
        event("engine_start_off_block")
    }

    private var onBlockEvent: CVRFlightEventRecord? {
        event("engine_shutdown_on_block")
    }

    private func event(_ type: String) -> CVRFlightEventRecord? {
        guard let flightRecord = workflow.state.activeFlightRecord else { return nil }
        return workflow.state.flightEvents.last {
            $0.flightRecordID == flightRecord.id && $0.eventType == type
        }
    }

    private var inFlightColor: Color {
        if awaitingAvionicsOff { return CVROperationalPalette.warning }
        if hasTransientStopEvent || hasEngineShutdownEvent { return CVROperationalPalette.success }
        if hasEngineStartEvent || workflow.state.engineSessionContinuityActive { return CVROperationalPalette.success }
        return avionicsReady ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby
    }

    private func inFlightValue(now: Date) -> String? {
        guard engineRunning else { return nil }
        return elapsedText(seconds: gpsAirborneSeconds(now: now))
    }

    private var canOfferTransientStop: Bool {
        !workflow.usesOperationalSessionModelV1
            && workflow.hasRemainingPlannedLegAfterCurrent
    }

    @ViewBuilder
    private func inFlightControlPanel(metrics: CVROperationalMetrics) -> some View {
        if awaitingAvionicsOff {
            VStack(spacing: 8) {
                CVROperationalWarningCard(
                    title: workflow.usesOperationalSessionModelV1 ? "FLIGHT METERS SAVED" : "CHECK-IN SAVED",
                    message: workflow.usesOperationalSessionModelV1
                        ? "SAFE TO TURN OFF AVIONICS. Audio and GPS remain active until Avionics OFF is detected."
                        : "Turn avionics OFF to finalize the recording and archive this flight. Do not Reset — that no longer discards a checked-in flight.",
                    iconName: "powerplug.fill",
                    color: CVROperationalPalette.warning
                )
                if workflow.canForceFinalizeEngineShutdown {
                    CVROperationalActionButton(
                        title: "FINALIZE WITHOUT AVIONICS OFF",
                        subtitle: "Use only if master/avionics cannot be switched off",
                        color: CVROperationalPalette.critical
                    ) {
                        showForceFinalizeConfirm = true
                    }
                }
            }
        } else if hasTransientStopEvent {
            VStack(spacing: 8) {
                CVROperationalWarningCard(
                    title: "TRANSIENT STOP",
                    message: "Engine may keep running. Complete Check-In, then open the next leg from Schedule. If this was actually a full stop, convert to Engine Shutdown first.",
                    iconName: "airplane.arrival",
                    color: CVROperationalPalette.secondaryBlue
                )
                CVROperationalActionButton(
                    title: hasShutdownVerificationEvent ? "EDIT CHECK-IN" : "CHECK-IN",
                    subtitle: "Tacho, Hobbs, Fuel, Destination, Takeoffs, Landings",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    workflow.beginTransientStopCheckIn()
                    isShowingCheckIn = true
                }
                if !hasShutdownVerificationEvent {
                    CVROperationalActionButton(
                        title: "ACTUALLY ENGINE SHUTDOWN",
                        subtitle: "Convert mistaken Transient Stop before Check-In",
                        color: CVROperationalPalette.critical
                    ) {
                        if workflow.convertTransientStopToEngineShutdown(gpsSample: gps.latestSample) {
                            uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                            workflow.beginEngineShutdownCheckIn()
                            isShowingCheckIn = true
                        }
                    }
                }
            }
        } else if hasEngineShutdownEvent {
            VStack(spacing: 8) {
                CVROperationalWarningCard(
                    title: hasShutdownVerificationEvent
                        ? "CHECK-IN COMPLETE"
                        : (workflow.usesOperationalSessionModelV1 ? "ENGINE SHUTDOWN CONFIRMED" : "ENGINE SHUTDOWN RECORDED"),
                    message: hasShutdownVerificationEvent
                        ? "Turn avionics OFF when ready. Recording finalizes automatically."
                        : (workflow.usesOperationalSessionModelV1
                            ? "Secure the ending meters and fuel now. Keep avionics ON."
                            : "Complete Check-In now, then turn avionics OFF."),
                    iconName: "checkmark.seal.fill",
                    color: CVROperationalPalette.success
                )
                if !hasShutdownVerificationEvent || workflow.canEditFlightClosure {
                    CVROperationalActionButton(
                        title: workflow.usesOperationalSessionModelV1
                            ? "SECURE FLIGHT DATA"
                            : (hasShutdownVerificationEvent ? "EDIT CHECK-IN" : "CHECK-IN"),
                        subtitle: workflow.usesOperationalSessionModelV1
                            ? "Ending Hobbs, Tacho and Fuel Remaining"
                            : "Tacho, Hobbs, Fuel, Destination, Takeoffs, Landings",
                        color: CVROperationalPalette.secondaryBlue
                    ) {
                        workflow.beginEngineShutdownCheckIn()
                        isShowingCheckIn = true
                    }
                }
            }
        } else if engineRunning {
            VStack(spacing: 8) {
                HStack(spacing: 8) {
                    CVROperationalActionButton(title: "EXERCISE START", subtitle: "Mark training item", color: CVROperationalPalette.secondaryBlue, isConfirmed: exerciseConfirmed) {
                        workflow.recordInFlightAction(eventType: "exercise_marker", creationMethod: "tap", gpsSample: gps.latestSample)
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        flashConfirmation($exerciseConfirmed)
                    }
                    CVROperationalActionButton(title: "TRAINING REMARK", subtitle: "Mark comment", color: CVROperationalPalette.secondaryBlue, isConfirmed: trainingRemarkConfirmed) {
                        workflow.recordInFlightAction(eventType: "training_remark_marker", creationMethod: "tap", gpsSample: gps.latestSample)
                        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                        flashConfirmation($trainingRemarkConfirmed)
                    }
                }
                CVRHoldActionButton(title: "SAFETY EVENT", subtitle: "Hold 2 seconds", color: CVROperationalPalette.warning, minimumDuration: 2) {
                    workflow.recordInFlightAction(eventType: "safety_event", creationMethod: "two_second_hold", gpsSample: gps.latestSample)
                    uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                    return true
                }
                takeoffLandingControls(metrics: metrics)
                if canOfferTransientStop {
                    CVROperationalWarningCard(
                        title: "MORE LEGS PLANNED",
                        message: preferTransientStopHighlight
                            ? "Use Transient Stop for the next leg while the engine keeps running."
                            : "Use Transient Stop to keep the engine running. Engine Shutdown is a full stop — remaining legs stay available after Check-In.",
                        iconName: "arrow.triangle.branch",
                        color: preferTransientStopHighlight ? CVROperationalPalette.success : CVROperationalPalette.secondaryBlue
                    )
                    CVRHoldActionButton(
                        title: "TRANSIENT STOP",
                        subtitle: "Hold 2 seconds — then confirm keep engine running",
                        color: CVROperationalPalette.success,
                        minimumDuration: 2
                    ) {
                        showTransientStopConfirm = true
                        return true
                    }
                    CVRHoldActionButton(
                        title: "ENGINE SHUTDOWN",
                        subtitle: "Hold 2 seconds — then confirm engine is off",
                        color: CVROperationalPalette.critical,
                        minimumDuration: 2
                    ) {
                        showEngineShutdownConfirm = true
                        return true
                    }
                } else {
                    CVRHoldActionButton(
                        title: "ENGINE SHUTDOWN",
                        subtitle: "Hold 2 seconds — then confirm engine is off",
                        color: CVROperationalPalette.critical,
                        minimumDuration: 2
                    ) {
                        showEngineShutdownConfirm = true
                        return true
                    }
                }
            }
        } else if avionicsReady && workflow.needsEngineStart {
            CVRHoldActionButton(
                title: "ENGINE START",
                subtitle: "Hold for 2 seconds",
                color: CVROperationalPalette.success,
                minimumDuration: 2
            ) {
                // Persist Off Block before UI confirmation flash / haptic.
                let saved = workflow.recordEngineStartOffBlock(gpsSample: gps.latestSample)
                guard saved else { return false }
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                return true
            }
        } else if workflow.state.engineSessionContinuityActive {
            VStack(spacing: 8) {
                CVROperationalWarningCard(
                    title: "CONTINUE THIS LEG",
                    message: "Engine is already running from the previous leg. No Engine Start. Recording starts with avionics ON.",
                    iconName: "flame.fill",
                    color: CVROperationalPalette.secondaryBlue
                )
                if workflow.canClearFalseContinuityOnActiveLeg {
                    CVROperationalActionButton(
                        title: "ENGINE WAS SHUT DOWN",
                        subtitle: "Clear false continuity and require Engine Start",
                        color: CVROperationalPalette.warning
                    ) {
                        _ = workflow.clearFalseContinuityOnActiveLeg()
                    }
                }
            }
        } else {
            CVROperationalWarningCard(title: "WAITING FOR AVIONICS POWER", message: "Engine Start will appear when the paired beacon reports avionics power.", iconName: "timer", color: CVROperationalPalette.standby)
        }
    }

    private func takeoffLandingControls(metrics: CVROperationalMetrics) -> some View {
        HStack(spacing: metrics.spacing) {
            CVROperationalHoldTile(
                title: "TAKE OFFS",
                iconName: "airplane.departure",
                value: "\(operationCounts.displayTakeoffs)",
                subtitle: "Hold 1s to +1",
                color: operationCounts.displayTakeoffs > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby,
                metrics: metrics,
                minimumDuration: 1,
                isEnabled: engineRunning && !hasLegBoundaryEvent
            ) {
                workflow.recordManualTakeoffAdjustment(gpsSample: gps.latestSample)
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
            CVROperationalHoldTile(
                title: "LANDINGS",
                iconName: "airplane.arrival",
                value: "\(operationCounts.displayLandings)",
                subtitle: "Hold 1s to +1",
                color: operationCounts.displayLandings > 0 ? CVROperationalPalette.success : CVROperationalPalette.standby,
                metrics: metrics,
                minimumDuration: 1,
                isEnabled: engineRunning && !hasLegBoundaryEvent
            ) {
                workflow.recordManualLandingAdjustment(gpsSample: gps.latestSample)
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
        }
    }

    private var operationCounts: (autoTakeoffs: Int, autoLandings: Int, manualTakeoffs: Int, manualLandings: Int, displayTakeoffs: Int, displayLandings: Int) {
        guard let flightRecord = workflow.state.activeFlightRecord else {
            return (0, 0, 0, 0, 0, 0)
        }
        return workflow.operationCounts(for: flightRecord.id)
    }

    private var flightEvents: [CVRFlightEventRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.flightEvents
            .filter { $0.flightRecordID == flightRecord.id }
            .sorted { $0.timestampUTC < $1.timestampUTC }
    }

    private func gpsAirborneSeconds(now: Date) -> TimeInterval {
        var sessionStart: Date?
        var total: TimeInterval = 0
        for event in flightEvents {
            switch event.eventType {
            case "gps_takeoff_provisional", "manual_takeoff_adjustment":
                if sessionStart == nil {
                    sessionStart = event.timestampUTC
                }
            case "gps_landing_provisional":
                let landingKind = event.metadata?["landing_kind"] ?? LandingCycleKind.fullStop.rawValue
                if landingKind == LandingCycleKind.fullStop.rawValue, let start = sessionStart {
                    total += max(0, event.timestampUTC.timeIntervalSince(start))
                    sessionStart = nil
                }
            case "engine_shutdown_on_block", "transient_stop_on_block":
                if let start = sessionStart {
                    total += max(0, event.timestampUTC.timeIntervalSince(start))
                    sessionStart = nil
                }
            default:
                break
            }
        }
        if let sessionStart, !hasLegBoundaryEvent {
            total += max(0, now.timeIntervalSince(sessionStart))
        }
        return total
    }

    private func elapsedText(seconds rawSeconds: TimeInterval) -> String {
        let seconds = max(0, Int(rawSeconds))
        let hours = seconds / 3600
        let minutes = (seconds % 3600) / 60
        let remainingSeconds = seconds % 60
        return String(format: "%02d:%02d:%02d", hours, minutes, remainingSeconds)
    }

    private func flashConfirmation(_ confirmation: Binding<Bool>) {
        confirmation.wrappedValue = true
        Task {
            try? await Task.sleep(for: .milliseconds(700))
            confirmation.wrappedValue = false
        }
    }
}


private struct CVRHoldActionButton: View {
    let title: String
    let subtitle: String
    let color: Color
    let minimumDuration: TimeInterval
    /// Returns true only after the operational change is persisted (or otherwise accepted).
    let action: () async -> Bool
    @State private var isPressing = false
    @State private var holdProgress = 0.0
    @State private var confirmedFlash = false

    init(
        title: String,
        subtitle: String,
        color: Color,
        minimumDuration: TimeInterval = 3,
        action: @escaping () -> Bool
    ) {
        self.title = title
        self.subtitle = subtitle
        self.color = color
        self.minimumDuration = minimumDuration
        self.action = { action() }
    }

    init(
        title: String,
        subtitle: String,
        color: Color,
        minimumDuration: TimeInterval = 3,
        asyncAction: @escaping () async -> Bool
    ) {
        self.title = title
        self.subtitle = subtitle
        self.color = color
        self.minimumDuration = minimumDuration
        self.action = asyncAction
    }

    var body: some View {
        VStack(spacing: 3) {
            Text(title)
                .font(.title3.weight(.bold))
                .tracking(1.0)
            Text(subtitle)
                .font(.caption.weight(.semibold))
                .foregroundStyle(confirmedFlash ? Color.white.opacity(0.9) : CVROperationalPalette.textSecondary)
        }
        .foregroundStyle(confirmedFlash ? Color.white : color)
        .frame(maxWidth: .infinity, minHeight: 72)
        .background {
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    CVROperationalPalette.cardBackground
                    color.opacity(confirmedFlash ? 0.95 : 0.30)
                        .frame(width: proxy.size.width * holdProgress)
                }
                .clipShape(RoundedRectangle(cornerRadius: 18))
            }
        }
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(color.opacity(0.85), lineWidth: 1))
        .scaleEffect(isPressing ? 0.985 : 1.0)
        .contentShape(RoundedRectangle(cornerRadius: 18))
        .onLongPressGesture(
            minimumDuration: minimumDuration,
            maximumDistance: 45,
            pressing: { pressing in
                isPressing = pressing
                if pressing {
                    confirmedFlash = false
                    holdProgress = 0
                    CVRHaptics.prepare(.heavy)
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
                Task { @MainActor in
                    // Confirm UI only after the action reports local persistence success.
                    let accepted = await action()
                    guard accepted else {
                        withAnimation(.easeOut(duration: 0.15)) {
                            holdProgress = 0
                        }
                        return
                    }
                    CVRHaptics.impact(.heavy)
                    confirmedFlash = true
                    holdProgress = 1
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

/// Stage 2 endpoint capture. This deliberately excludes destination, legs,
/// operations, oil, and Garmin; those belong to later evidence analysis.
private struct SecureFlightDataView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var gps: GPSLocationManager
    @Environment(\.dismiss) private var dismiss
    @State private var endingHobbs = ""
    @State private var endingTacho = ""
    @State private var fuelQuantity = 0.0
    @State private var hasFuelSelection = false
    @State private var localError = ""
    @State private var showSavedConfirmation = false
    @FocusState private var focusedField: MeterField?

    private enum MeterField: Hashable {
        case hobbs
        case tacho
    }

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    Text("SECURE FLIGHT DATA")
                        .font(.title2.weight(.bold))
                        .tracking(1.0)
                    Text("Confirm the ending aircraft state while avionics remain ON.")
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)

                    section("ENDING METERS") {
                        HStack(spacing: 10) {
                            meterField("TACHO", text: $endingTacho, field: .tacho)
                            meterField("HOBBS", text: $endingHobbs, field: .hobbs)
                        }
                        Text("Enter exactly one decimal.")
                            .font(.caption2.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                    }

                    section("FUEL REMAINING") {
                        HStack {
                            Spacer(minLength: 0)
                            CVRFluidCylinderPicker(
                                title: "FUEL",
                                unit: operationalConfig.fuelUnit,
                                value: $fuelQuantity,
                                hasSelection: $hasFuelSelection,
                                maxValue: operationalConfig.fuelCapacity,
                                warningThreshold: operationalConfig.fuelCapacity * (3.0 / 13.0),
                                fillColor: CVROperationalPalette.success,
                                warningColor: CVROperationalPalette.critical
                            )
                            .frame(width: 150)
                            Spacer(minLength: 0)
                        }
                    }

                    if !localError.isEmpty || !workflow.lastError.isEmpty {
                        Text(localError.isEmpty ? workflow.lastError : localError)
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.critical)
                    }

                    CVROperationalActionButton(
                        title: "SAVE FLIGHT METERS",
                        subtitle: "Store safely on this device",
                        color: CVROperationalPalette.success
                    ) {
                        save()
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Secure Flight Data")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedField = nil }
                }
            }
            .onAppear(perform: prefill)
            .alert("Flight meters saved.", isPresented: $showSavedConfirmation) {
                Button("Continue") { dismiss() }
            } message: {
                Text("SAFE TO TURN OFF AVIONICS. Audio and GPS continue until Avionics OFF is detected.")
            }
        }
        .preferredColorScheme(.dark)
    }

    private func prefill() {
        let flight = workflow.state.activeFlightRecord
        if let value = flight?.endingHobbs ?? workflow.estimatedCheckInHobbs() {
            endingHobbs = String(format: "%.1f", value)
        } else if let value = workflow.state.activeDispatch?.startingHobbs {
            endingHobbs = String(format: "%.1f", value)
        }
        if let value = flight?.endingTacho ?? workflow.estimatedCheckInTacho() {
            endingTacho = String(format: "%.1f", value)
        } else if let value = workflow.state.activeDispatch?.startingTacho {
            endingTacho = String(format: "%.1f", value)
        }
        if let value = quantity(from: flight?.fuelRemaining)
            ?? estimatedFuelRemaining()
            ?? quantity(from: workflow.state.activeDispatch?.fuelOnboard) {
            fuelQuantity = min(max(value, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        }
        localError = ""
    }

    private func estimatedFuelRemaining() -> Double? {
        guard let startingFuel = quantity(from: workflow.state.activeDispatch?.fuelOnboard),
              let startingHobbs = workflow.state.activeDispatch?.startingHobbs else {
            return nil
        }
        let ending = Double(endingHobbs.replacingOccurrences(of: ",", with: "."))
            ?? workflow.estimatedCheckInHobbs()
            ?? startingHobbs
        let burn = max(0, ending - startingHobbs) * operationalConfig.fuelBurnUSGPerHobbsHour
        return min(max(startingFuel - burn, 0), operationalConfig.fuelCapacity)
    }

    private func save() {
        localError = ""
        guard hasFuelSelection else {
            localError = "Enter the fuel remaining."
            return
        }
        let hobbs = Double(endingHobbs.replacingOccurrences(of: ",", with: "."))
        let tacho = Double(endingTacho.replacingOccurrences(of: ",", with: "."))
        let saved = workflow.secureOperationalSessionEndingValues(
            endingHobbs: hobbs,
            endingTacho: tacho,
            fuelRemaining: String(format: "%.1f", fuelQuantity),
            gpsSample: gps.latestSample
        )
        guard saved else { return }
        // Local atomic persistence has already succeeded; synchronization is independent.
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
        showSavedConfirmation = true
    }

    private func section<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            content()
        }
    }

    private func meterField(_ title: String, text: Binding<String>, field: MeterField) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedField, equals: field)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .frame(maxWidth: .infinity)
    }

    private func quantity(from value: String?) -> Double? {
        guard let value else { return nil }
        let cleaned = value
            .replacingOccurrences(of: operationalConfig.fuelUnit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned.replacingOccurrences(of: ",", with: "."))
    }
}

private struct CheckInView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var gps: GPSLocationManager
    @Environment(\.dismiss) private var dismiss
    var repairExistingClosureUpload = false
    @State private var endingHobbs = ""
    @State private var endingTacho = ""
    @State private var fuelGallons = 0.0
    @State private var hasFuelSelection = false
    @State private var destination = ""
    @State private var verifiedTakeoffs = 0
    @State private var verifiedLandings = 0
    @State private var comments = ""
    @State private var showSavedConfirmation = false
    @State private var localError = ""
    @FocusState private var focusedField: NumericField?

    private enum NumericField: Hashable {
        case hobbs
        case tacho
        case destination
    }

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    Text(modeTitle)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    section("METERS") {
                        HStack(spacing: 10) {
                            largeMeterField("TACHO", text: $endingTacho, field: .tacho)
                            largeMeterField("HOBBS", text: $endingHobbs, field: .hobbs)
                        }
                        Text("Estimates until verified. Tacho ≈ start + Hobbs increment × 0.70")
                            .font(.caption2.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                    }
                    section("FUEL REMAINING") {
                        HStack {
                            Spacer(minLength: 0)
                            CVRFluidCylinderPicker(
                                title: "FUEL",
                                unit: operationalConfig.fuelUnit,
                                value: $fuelGallons,
                                hasSelection: $hasFuelSelection,
                                maxValue: operationalConfig.fuelCapacity,
                                warningThreshold: operationalConfig.fuelCapacity * (3.0 / 13.0),
                                fillColor: CVROperationalPalette.success,
                                warningColor: CVROperationalPalette.critical
                            )
                            .frame(width: 132)
                            Spacer(minLength: 0)
                        }
                    }
                    section("AIRPORT OF ARRIVAL") {
                        largeAirportField("ARR AD", text: $destination)
                    }
                    section("OPERATIONS") {
                        HStack(spacing: 12) {
                            operationStepper("TAKEOFFS", value: $verifiedTakeoffs)
                            operationStepper("LANDINGS", value: $verifiedLandings)
                        }
                    }
                    section("COMMENTS") {
                        TextField("Comments", text: $comments, axis: .vertical)
                            .lineLimit(3...6)
                            .padding(12)
                            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    }
                    if !localError.isEmpty || !workflow.lastError.isEmpty {
                        Text(localError.isEmpty ? workflow.lastError : localError)
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.critical)
                    }
                    CVROperationalActionButton(
                        title: "SAVE CHECK-IN",
                        subtitle: modeSubtitle,
                        color: CVROperationalPalette.success
                    ) {
                        save()
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Check-In")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") { focusedField = nil }
                }
            }
            .onAppear(perform: prefill)
            .alert("Leg stored safely on this device.", isPresented: $showSavedConfirmation) {
                Button("Continue") { dismiss() }
            } message: {
                Text("This leg is complete on the iPhone. You can continue the lesson.")
            }
        }
        .preferredColorScheme(.dark)
    }

    private var modeTitle: String {
        switch workflow.pendingCheckInMode {
        case .transientStop: return "TRANSIENT STOP CHECK-IN"
        case .engineShutdown: return "ENGINE SHUTDOWN CHECK-IN"
        case .none: return "CHECK-IN"
        }
    }

    private var modeSubtitle: String {
        switch workflow.pendingCheckInMode {
        case .transientStop: return "Store this leg, then return to Schedule"
        case .engineShutdown: return "Store this leg, then wait for avionics OFF"
        case .none: return "Verify meters and operations"
        }
    }

    private func prefill() {
        let flight = workflow.state.activeFlightRecord
        let dispatch = workflow.state.activeDispatch
        if let hobbs = flight?.endingHobbs {
            endingHobbs = String(format: "%.1f", hobbs)
        } else if let estimated = workflow.estimatedCheckInHobbs() {
            endingHobbs = String(format: "%.1f", estimated)
        } else if let start = dispatch?.startingHobbs {
            endingHobbs = String(format: "%.1f", start)
        }
        if let tacho = flight?.endingTacho {
            endingTacho = String(format: "%.1f", tacho)
        } else if let estimated = workflow.estimatedCheckInTacho() {
            endingTacho = String(format: "%.1f", estimated)
        } else if let start = dispatch?.startingTacho {
            endingTacho = String(format: "%.1f", start)
        }
        let fuelText = flight?.fuelRemaining ?? ""
        if let fuel = Self.quantity(from: fuelText, unit: operationalConfig.fuelUnit) {
            fuelGallons = min(max(fuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        } else if let estimatedFuel = estimatedFuelRemainingAfterHobbs() {
            fuelGallons = estimatedFuel
            hasFuelSelection = true
        } else if let dispatchFuel = Self.quantity(from: dispatch?.fuelOnboard ?? "", unit: operationalConfig.fuelUnit) {
            fuelGallons = min(max(dispatchFuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        } else {
            fuelGallons = 0
            hasFuelSelection = false
        }
        destination = (flight?.verifiedDestinationAirport
            ?? dispatch?.plannedDestinationAirport
            ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
            .uppercased()
        localError = ""
        if let flight {
            let counts = workflow.operationCounts(for: flight.id)
            verifiedTakeoffs = flight.verifiedTakeoffCount ?? counts.displayTakeoffs
            verifiedLandings = flight.verifiedLandingCount ?? counts.displayLandings
        }
        comments = flight?.checkInComments ?? flight?.maintenanceRemark ?? ""
    }

    /// Fuel remaining ≈ fuel onboard − (Hobbs increment × aircraft burn rate).
    private func estimatedFuelRemainingAfterHobbs() -> Double? {
        let dispatch = workflow.state.activeDispatch
        guard let startFuel = Self.quantity(from: dispatch?.fuelOnboard ?? "", unit: operationalConfig.fuelUnit) else {
            return nil
        }
        let startHobbs = dispatch?.startingHobbs
        let endHobbs = Double(endingHobbs.replacingOccurrences(of: ",", with: "."))
            ?? workflow.estimatedCheckInHobbs()
            ?? startHobbs
        guard let startHobbs, let endHobbs else { return nil }
        let hobbsDelta = max(0, endHobbs - startHobbs)
        let burned = hobbsDelta * operationalConfig.fuelBurnUSGPerHobbsHour
        return min(max(startFuel - burned, 0), operationalConfig.fuelCapacity)
    }

    private func save() {
        localError = ""
        guard hasFuelSelection else {
            localError = "Enter the fuel remaining."
            return
        }
        let hobbs = Double(endingHobbs.replacingOccurrences(of: ",", with: "."))
        let tacho = Double(endingTacho.replacingOccurrences(of: ",", with: "."))
        let saved = workflow.saveCheckInValues(
            endingHobbs: hobbs,
            endingTacho: tacho,
            fuelRemaining: Self.quantityText(fuelGallons),
            verifiedDestinationAirport: destination.trimmingCharacters(in: .whitespacesAndNewlines).uppercased(),
            verifiedTakeoffCount: verifiedTakeoffs,
            verifiedLandingCount: verifiedLandings,
            comments: comments,
            gpsSample: gps.latestSample,
            repairExistingClosureUpload: repairExistingClosureUpload
        )
        guard saved else { return }
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
        showSavedConfirmation = true
    }

    private func section<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            content()
        }
    }

    private func largeMeterField(_ title: String, text: Binding<String>, field: NumericField) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedField, equals: field)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .frame(maxWidth: .infinity)
    }

    private func largeAirportField(_ title: String, text: Binding<String>) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .textInputAutocapitalization(.characters)
                .autocorrectionDisabled()
                .focused($focusedField, equals: .destination)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospaced())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                .onChange(of: text.wrappedValue) { _, newValue in
                    let sanitized = CVRLocalDispatchDraft.sanitizeAirportInput(newValue)
                    if sanitized != newValue {
                        text.wrappedValue = sanitized
                    }
                }
        }
        .frame(maxWidth: .infinity)
    }

    private func operationStepper(_ title: String, value: Binding<Int>) -> some View {
        VStack(spacing: 8) {
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            HStack {
                Button { value.wrappedValue = max(0, value.wrappedValue - 1) } label: {
                    Image(systemName: "minus.circle.fill").font(.title2)
                }
                Text("\(value.wrappedValue)")
                    .font(.title2.weight(.bold).monospacedDigit())
                    .frame(minWidth: 36)
                Button { value.wrappedValue += 1 } label: {
                    Image(systemName: "plus.circle.fill").font(.title2)
                }
            }
            .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .frame(maxWidth: .infinity)
        .padding(12)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned.replacingOccurrences(of: ",", with: "."))
    }

    private static func quantityText(_ value: Double) -> String {
        String(format: "%.1f", value)
    }
}


struct GarminWorkflowView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var recordingStore: RecordingStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var garminVault: GarminCsvVaultStore
    @EnvironmentObject private var garminSync: GarminCsvSyncManager
    @EnvironmentObject private var network: NetworkMonitor
    @EnvironmentObject private var gps: GPSLocationManager
    @Binding var showAdminUnlock: Bool
    @State private var isShowingClosureEditor = false

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(title: "GARMIN RECOVERY", subtitle: "IMPORT AND UPLOAD QUEUE", iconName: "doc.badge.arrow.up", color: CVROperationalPalette.secondaryBlue, value: nil, caption: "GARMIN", metrics: metrics)
                        HStack(spacing: metrics.spacing) {
                            CVROperationalTile(title: "UPLOAD", iconName: "icloud.and.arrow.up.fill", value: uploadTileValue, color: uploadTileColor, metrics: metrics)
                            CVROperationalTile(title: "TRANSCRIPT", iconName: "text.bubble.fill", value: transcriptTileValue, color: transcriptTileColor, metrics: metrics)
                            CVROperationalTile(title: "REPLAY", iconName: "play.rectangle.fill", value: replayTileValue, color: replayTileColor, metrics: metrics)
                        }
                        CVROperationalWarningCard(
                            title: garminWarningTitle,
                            message: garminWarningMessage,
                            iconName: garminWarningIcon,
                            color: garminWarningColor,
                            progress: activeRecoveryProgress
                        )
                        workflowUploadRepairActions
                        CVROperationalActionButton(title: uploadButtonTitle, subtitle: uploadButtonSubtitle, color: garminComponents.isEmpty ? CVROperationalPalette.textSecondary : CVROperationalPalette.secondaryBlue) {
                            if settings.isSimulationModeEnabled {
                                completeSimulationDemo(workflow: workflow, settings: settings, beacon: beacon)
                            } else if workflow.canEditFlightClosure {
                                isShowingClosureEditor = true
                            } else if workflow.canRepairFailedDispatchUpload {
                                workflow.selectTab(.dispatch)
                            } else if workflow.dispatchUploadFailure() != nil || !workflow.failedActiveUploadComponents().isEmpty {
                                workflow.requeueFailedUploads()
                                uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)
                            } else if allWorkflowComponentsVerified {
                                workflow.resetForNextFlightIfComplete()
                            } else {
                                uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)
                            }
                        }
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
            }
        }
        .sheet(isPresented: $isShowingClosureEditor) {
            CheckInView(repairExistingClosureUpload: true)
                .environmentObject(workflow)
                .environmentObject(settings)
                .environmentObject(uploadManager)
                .environmentObject(gps)
                .presentationDetents([.large])
        }
        .task {
            if workflow.repairCompletedClosureUploadIfNeeded() {
                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
            }
            await runRecoveryPipelineOnce()
            await pollRecoveryProcessingStatus()
        }
    }

    private func runRecoveryPipelineOnce() async {
        await garminSync.syncPending(
            settings: settings,
            vault: garminVault,
            workflow: workflow,
            network: network,
            uploadManager: uploadManager
        )
    }

    private var workflowUploadRepairActions: some View {
        VStack(spacing: 8) {
            if workflow.canEditFlightClosure {
                CVROperationalActionButton(
                    title: "FIX ENDING METERS",
                    subtitle: "Enter Ending Hobbs and Tacho",
                    color: CVROperationalPalette.warning
                ) {
                    isShowingClosureEditor = true
                }
            }
            if workflow.canRepairFailedDispatchUpload {
                CVROperationalActionButton(
                    title: "FIX DISPATCH ON DISPATCH TAB",
                    subtitle: "Oil, fuel continuity, crew, and meters",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    workflow.selectTab(.dispatch)
                }
            }
            if failedWorkflowComponent?.componentType == "recorder_verification" {
                CVROperationalActionButton(
                    title: "FIX RECORDER ON RECORDER TAB",
                    subtitle: "Repeat recorder verification",
                    color: CVROperationalPalette.secondaryBlue
                ) {
                    workflow.selectTab(.recorder)
                }
            }
        }
    }

    private var garminComponents: [CVRUploadComponentRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.uploadComponents.filter {
            $0.flightRecordID == flightRecord.id && $0.componentType == "garmin_csv"
        }
    }

    private var workflowComponents: [CVRUploadComponentRecord] {
        guard let flightRecord = workflow.state.activeFlightRecord else { return [] }
        return workflow.state.uploadComponents.filter { $0.flightRecordID == flightRecord.id }
    }

    private var recoveryRecording: Recording? {
        guard let sessionID = workflow.state.activeFlightRecord?.recordingSessionID else { return nil }
        return recordingStore.recordings.first { $0.flightSessionID == sessionID || $0.id == sessionID }
    }

    private var uploadTileValue: String {
        if let recording = recoveryRecording, recording.uploadStatus == .uploading {
            return "\(Int((recording.uploadProgress * 100).rounded()))%"
        }
        if garminSync.isSyncing {
            return progressPercent(garminSync.syncProgress) ?? "Syncing"
        }
        if garminComponents.isEmpty {
            if vaultFailedCount > 0 { return "Failed" }
            if vaultPendingCount > 0 { return "Queued" }
            if vaultSyncedCount > 0 { return "Synced" }
            return "Recovery"
        }
        if let uploading = workflowComponents.first(where: { $0.state == .uploading }) {
            return "\(Int(((uploading.progress ?? 0) * 100).rounded()))%"
        }
        if workflowComponents.contains(where: { $0.state == .failed || $0.state == .needsUserAction }) {
            return "Failed"
        }
        if allWorkflowComponentsVerified || workflowComponents.contains(where: { $0.state == .uploaded }) {
            return "Uploaded"
        }
        return "Queued"
    }

    private var activeRecoveryProgress: Double? {
        if garminSync.isSyncing {
            return garminSync.syncProgress
        }
        return nil
    }

    private func progressPercent(_ progress: Double?) -> String? {
        guard let progress else { return nil }
        return "\(Int((progress * 100).rounded()))%"
    }

    private var uploadTileColor: Color {
        if vaultFailedCount > 0 {
            return CVROperationalPalette.critical
        }
        if garminSync.isSyncing || vaultPendingCount > 0 {
            return CVROperationalPalette.secondaryBlue
        }
        if vaultSyncedCount > 0 || !garminComponents.isEmpty {
            return CVROperationalPalette.success
        }
        return CVROperationalPalette.standby
    }

    private var vaultPendingCount: Int {
        garminVault.records.filter {
            $0.syncState == .pending || $0.syncState == .uploading
        }.count
    }

    private var vaultFailedCount: Int {
        garminVault.records.filter { $0.syncState == .failed }.count
    }

    private var vaultSyncedCount: Int {
        garminVault.records.filter {
            $0.syncState == .synced || $0.syncState == .duplicate
        }.count
    }

    private var transcriptTileValue: String {
        guard let recording = recoveryRecording else { return "N/A" }
        switch recording.transcriptStatus {
        case .ready: return "Ready"
        case .transcribing: return "\(recording.transcriptProgress)%"
        case .failed: return "Failed"
        case .pending: return recording.uploadStatus == .uploaded ? "Queued" : "Pending"
        }
    }

    private var transcriptTileColor: Color {
        guard let recording = recoveryRecording else { return CVROperationalPalette.standby }
        if recording.transcriptStatus == .ready { return CVROperationalPalette.success }
        if recording.transcriptStatus == .failed { return CVROperationalPalette.critical }
        return CVROperationalPalette.secondaryBlue
    }

    private var replayTileValue: String {
        guard let recording = recoveryRecording else { return "N/A" }
        let status = (recording.replayStatus ?? "not_started").lowercased()
        if status == "ready" || status == "complete" || status == "completed" { return "Ready" }
        if status == "failed" { return "Failed" }
        if status == "processing" || status == "running" {
            return "\(max(0, min(100, recording.replayProgress ?? 0)))%"
        }
        return "Pending"
    }

    private var replayTileColor: Color {
        guard let recording = recoveryRecording else { return CVROperationalPalette.standby }
        let status = (recording.replayStatus ?? "not_started").lowercased()
        if status == "ready" || status == "complete" || status == "completed" { return CVROperationalPalette.success }
        if status == "failed" { return CVROperationalPalette.critical }
        return status == "processing" || status == "running" ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby
    }

    private var uploadButtonSubtitle: String {
        if settings.isSimulationModeEnabled {
            return "Skip Garmin and return to Dispatch"
        }
        guard !garminComponents.isEmpty else { return "Waiting for CSV" }
        if allWorkflowComponentsVerified {
            return "Return to Dispatch"
        }
        if let uploading = workflowComponents.first(where: { $0.state == .uploading }) {
            return "Uploading \(Int(((uploading.progress ?? 0) * 100).rounded()))%"
        }
        if failedWorkflowComponent != nil {
            if workflow.canEditFlightClosure {
                return "Enter Ending Hobbs and Tacho"
            }
            if workflow.canRepairFailedDispatchUpload {
                return "Open Dispatch tab to fix and retry"
            }
            return "Retry missing / failed components"
        }
        return "CSV and flight data"
    }

    private var uploadButtonTitle: String {
        if settings.isSimulationModeEnabled {
            return workflow.state.activeFlightRecord == nil ? "DEMO READY" : "FINISH SIMULATION"
        }
        if allWorkflowComponentsVerified {
            return "NEXT FLIGHT"
        }
        if failedWorkflowComponent != nil {
            if workflow.canEditFlightClosure {
                return "FIX ENDING METERS"
            }
            if workflow.canRepairFailedDispatchUpload {
                return "FIX DISPATCH"
            }
            return "RETRY FAILED ITEMS"
        }
        return "UPLOAD QUEUED ITEMS"
    }

    private var allWorkflowComponentsVerified: Bool {
        !workflowComponents.isEmpty && workflowComponents.allSatisfy { $0.state == .serverVerified }
    }

    private var failedWorkflowComponent: CVRUploadComponentRecord? {
        workflowComponents.first { $0.state == .failed || $0.state == .needsUserAction }
    }

    private var garminWarningTitle: String {
        if settings.isSimulationModeEnabled {
            return "SIMULATION MODE"
        }
        if garminSync.isSyncing {
            return "SYNCHRONIZING GARMIN FILES"
        }
        if garminComponents.isEmpty {
            if vaultFailedCount > 0 {
                return "GARMIN SYNC NEEDS RETRY"
            }
            if garminSync.isSyncing || vaultPendingCount > 0 {
                return "GARMIN FILES QUEUED FOR SYNC"
            }
            if vaultSyncedCount > 0 {
                return "GARMIN FILES SYNCHRONIZED"
            }
            return "IMPORT GARMIN CSV"
        }
        if let failedWorkflowComponent {
            return switch failedWorkflowComponent.componentType {
            case "dispatch_metadata": "DISPATCH UPLOAD FAILED"
            case "flight_record_closure": "FLIGHT CLOSURE UPLOAD FAILED"
            case "flight_events": "FLIGHT EVENT UPLOAD FAILED"
            case "recorder_verification": "RECORDER VERIFICATION UPLOAD FAILED"
            default: "GARMIN UPLOAD FAILED"
            }
        }
        if allWorkflowComponentsVerified { return "FLIGHT DATA SERVER VERIFIED" }
        return "GARMIN CSV IMPORTED"
    }

    private var garminWarningMessage: String {
        if settings.isSimulationModeEnabled {
            return "Simulation mode skips Garmin import and server uploads."
        }
        if garminSync.isSyncing {
            let total = garminSync.syncFilesTotal
            let processed = min(garminSync.syncFilesProcessed, total)
            let file = garminSync.currentFileName.isEmpty ? "" : " \(garminSync.currentFileName)"
            let counts = total > 0 ? " \(processed)/\(total) complete." : ""
            return "\(garminSync.syncPhase).\(file)\(counts)"
        }
        if garminComponents.isEmpty {
            if vaultFailedCount > 0 {
                let detail = garminSync.lastError.trimmingCharacters(in: .whitespacesAndNewlines)
                return detail.isEmpty
                    ? "\(vaultFailedCount) Garmin file(s) failed to synchronize and will retry automatically."
                    : "\(vaultFailedCount) Garmin file(s) failed to synchronize: \(detail)"
            }
            if garminSync.isSyncing || vaultPendingCount > 0 {
                return "\(vaultPendingCount) Garmin file(s) are queued. \(vaultSyncedCount) already synchronized or confirmed on the server."
            }
            if vaultSyncedCount > 0 {
                return "\(vaultSyncedCount) Garmin file(s) are synchronized or already existed on the server."
            }
            return "Open a Garmin CSV from Files or AirDrop on the Log tab. Matching files are stored locally and synced when the flight is online."
        }
        if let failedWorkflowComponent {
            if workflow.canEditFlightClosure {
                let detail = failedWorkflowComponent.lastError.nilIfEmpty ?? "Ending Hobbs and Ending Tacho are required."
                return "\(detail) Tap FIX ENDING METERS below."
            }
            return failedWorkflowComponent.lastError.nilIfEmpty ?? "Retry missing / failed components."
        }
        if allWorkflowComponentsVerified {
            return "Flight data is server verified. Direct Garmin G3X Bluetooth connection state is not exposed to this iOS app; avionics power is \(avionicsPowerLabel)."
        }
        return "Shared CSV is stored locally and queued. Direct Garmin G3X Bluetooth connection state is not exposed to this iOS app; avionics power is \(avionicsPowerLabel)."
    }

    private var garminWarningIcon: String {
        if garminSync.isSyncing {
            return "arrow.triangle.2.circlepath"
        }
        if failedWorkflowComponent != nil || vaultFailedCount > 0 {
            return "exclamationmark.triangle.fill"
        }
        if vaultPendingCount > 0 || garminSync.isSyncing {
            return "arrow.triangle.2.circlepath"
        }
        return garminComponents.isEmpty && vaultSyncedCount == 0 ? "doc.badge.arrow.up" : "checkmark.seal.fill"
    }

    private var garminWarningColor: Color {
        if garminSync.isSyncing {
            return CVROperationalPalette.secondaryBlue
        }
        if failedWorkflowComponent != nil || vaultFailedCount > 0 {
            return CVROperationalPalette.critical
        }
        if vaultPendingCount > 0 || garminSync.isSyncing {
            return CVROperationalPalette.secondaryBlue
        }
        return garminComponents.isEmpty && vaultSyncedCount == 0
            ? CVROperationalPalette.secondaryBlue
            : CVROperationalPalette.success
    }

    private var avionicsPowerLabel: String {
        beacon.currentState == .avionicsOn || beacon.currentState == .temporarilyMissing ? "ON" : "not detected"
    }

    private func pollRecoveryProcessingStatus() async {
        while !Task.isCancelled {
            if let recording = recoveryRecording,
               let serverID = recording.serverID,
               !serverID.isEmpty,
               let baseURL = settings.normalizedServerURL {
                do {
                    let response = try await APIClient(serverURL: baseURL).status(recordingID: serverID)
                    if let remote = response.recording {
                        recordingStore.update(recording.id) {
                            $0.transcriptProgress = remote.progress
                            $0.replayStatus = remote.reconstructionStatus
                            $0.replayProgress = remote.reconstructionProgress
                            $0.replayStage = remote.reconstructionStage
                            if remote.transcriptionStatus == "ready" {
                                $0.transcriptStatus = .ready
                            } else if remote.transcriptionStatus == "failed" {
                                $0.transcriptStatus = .failed
                            } else if remote.transcriptionStatus == "transcribing" || remote.transcriptionStatus == "queued" {
                                $0.transcriptStatus = .transcribing
                            }
                        }
                    }
                } catch {
                    // Keep the last known processing state; the next poll retries.
                }
            }
            try? await Task.sleep(for: .seconds(5))
        }
    }
}

struct FlightLogView: View {
    @EnvironmentObject private var flightLogs: CVRFlightLogStore
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var recordingStore: RecordingStore
    @EnvironmentObject private var sessionsStore: ScheduledSessionsStore
    @EnvironmentObject private var network: NetworkMonitor
    @EnvironmentObject private var garminSDCard: GarminSDCardImportCoordinator
    let adminUnlocked: Bool
    @State private var isShowingFileImporter = false
    @State private var isShowingGarminAssignment = false
    @State private var isDirectGarminUpload = false
    @State private var directImportTarget: CVRFlightLogEntry?
    @State private var pinTarget: CVRFlightLogEntry?
    @State private var pinPurpose: LogPINPurpose = .adjust
    @State private var adjustmentTarget: CVRFlightLogEntry?
    @State private var voidConfirmTarget: CVRFlightLogEntry?
    @State private var adjustmentPIN = ""
    @State private var pinError = ""
    @State private var voidHoldFlightRecordID: String?
    @State private var voidHoldProgress = 0.0
    @State private var legReviewTarget: CVRFlightLogEntry?
    @State private var verifiedLegReviewDispatchUUIDs: Set<String> = []
    @State private var legReviewEligibleDispatchUUIDs: Set<String> = []

    private enum LogPINPurpose {
        case adjust
        case voidLog
    }

    private let voidHoldDuration: TimeInterval = 5

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(
                            title: "AIRCRAFT FLIGHT LOG",
                            subtitle: adminUnlocked
                                ? "ADMIN MODE · HOLD A LOG CARD 5 SECONDS TO VOID"
                                : "FLIGHT RECORDS AND SYNCHRONIZATION",
                            iconName: "list.bullet.clipboard",
                            color: missingCount > 0 ? CVROperationalPalette.warning : CVROperationalPalette.success,
                            value: "\(displayEntries.count)",
                            caption: "LOGS",
                            metrics: metrics
                        )
                        HStack(spacing: metrics.spacing) {
                            CVROperationalTile(
                                title: "COMPLETE",
                                iconName: "checkmark.seal.fill",
                                value: "\(completeCount)",
                                color: CVROperationalPalette.success,
                                metrics: metrics
                            )
                            Button {
                                syncPendingLogUploads()
                            } label: {
                                CVROperationalTile(
                                    title: "SYNC PENDING",
                                    iconName: "arrow.triangle.2.circlepath",
                                    value: "\(missingCount)",
                                    color: missingCount > 0 ? CVROperationalPalette.secondaryBlue : CVROperationalPalette.standby,
                                    metrics: metrics
                                )
                            }
                            .buttonStyle(.plain)
                            .disabled(missingCount == 0)
                            .accessibilityLabel("Sync \(missingCount) pending flight logs now")
                        }
                        Text("TIMES SHOWN IN CALIFORNIA LOCAL TIME (PT)")
                            .font(.caption2.weight(.bold))
                            .tracking(0.7)
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        if !flightLogs.lastError.isEmpty {
                            CVROperationalWarningCard(
                                title: "FLIGHT LOG",
                                message: flightLogs.lastError,
                                iconName: "exclamationmark.triangle.fill",
                                color: CVROperationalPalette.warning
                            )
                        }
                        if displayEntries.isEmpty && !flightLogs.isRefreshing {
                            CVROperationalWarningCard(
                                title: "NO DISPATCHED FLIGHTS",
                                message: "Completed Dispatch records for \(settings.selectedAircraft?.registration ?? "this aircraft") will appear here.",
                                iconName: "clock.arrow.circlepath",
                                color: CVROperationalPalette.secondaryBlue
                            )
                        } else {
                            VStack(spacing: 10) {
                                ForEach(displayEntries) { entry in
                                    flightLogRow(entry)
                                }
                            }
                        }
                        if activeFlightIsClosed {
                            CVROperationalActionButton(
                                title: activeWorkflowVerified ? "RETURN TO SCHEDULE" : "RETRY PENDING ITEMS",
                                subtitle: activeWorkflowVerified
                                    ? "This leg is complete on the device"
                                    : "Send remaining Dispatch and Check-In data when connected",
                                color: activeWorkflowVerified
                                    ? CVROperationalPalette.success
                                    : CVROperationalPalette.secondaryBlue
                            ) {
                                if activeWorkflowVerified {
                                    workflow.resetForNextFlightIfComplete()
                                    Task { await flightLogs.refresh(settings: settings) }
                                } else {
                                    syncPendingLogUploads()
                                }
                            }
                        }
                        if missingCount > 0 || flightLogs.pendingGarminCSV?.targetFlightRecordID != nil {
                            CVROperationalActionButton(
                                title: "SYNC NOW",
                                subtitle: flightLogs.pendingGarminCSV?.targetFlightRecordID != nil
                                    ? "Upload queued flight data and retry the stored Garmin file"
                                    : "Upload queued Dispatch, Check-In, events, and cockpit audio",
                                color: CVROperationalPalette.secondaryBlue
                            ) {
                                syncPendingLogUploads()
                            }
                        }
                        CVROperationalActionButton(
                            title: flightLogs.isRefreshing ? "REFRESHING LOG" : "REFRESH FLIGHT LOG",
                            subtitle: "Reload online status only — does not start uploads",
                            color: CVROperationalPalette.standby
                        ) {
                            Task { await flightLogs.refresh(settings: settings) }
                        }
                        HStack(spacing: 12) {
                            Button {
                                isShowingFileImporter = true
                            } label: {
                                Label("FILES", systemImage: "doc.badge.plus")
                            }
                            Button {
                                garminSDCard.openBrowse(settings: settings)
                            } label: {
                                Label("SD CARD", systemImage: "sdcard")
                            }
                            .accessibilityLabel("Import Garmin CSV from SD card")
                        }
                        .font(.caption.weight(.bold))
                        .buttonStyle(.bordered)
                        .tint(CVROperationalPalette.secondaryBlue)
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
                .refreshable {
                    syncPendingLogUploads()
                    await flightLogs.refresh(settings: settings)
                    _ = workflow.pruneServerVerifiedArchives(
                        keepingFlightRecordIDs: Set(flightLogs.entries.map(\.flightRecordID))
                    )
                }
                if flightLogs.isUploading || flightLogs.isAdjusting {
                    uploadOverlay
                }
            }
        }
        .task {
            flightLogs.preparePendingGarminImportForLog()
            await flightLogs.refresh(settings: settings)
            reconcilePostFlightGarminUploadState()
            await refreshLegReviewStatuses()
            _ = workflow.pruneServerVerifiedArchives(
                keepingFlightRecordIDs: Set(flightLogs.entries.map(\.flightRecordID))
            )
            // Only open assignment when a new file needs a flight pick.
            // Restored pending with an existing flight association must not reopen the picker.
            if flightLogs.pendingGarminCSV != nil
                && flightLogs.pendingGarminCSV?.targetFlightRecordID == nil
                && directImportTarget == nil {
                isShowingGarminAssignment = true
            }
            if let handoff = workflow.state.postFlightGarminHandoff,
               handoff.phase == .selectingCSV,
               let entry = displayEntries.first(where: {
                   $0.flightRecordID.lowercased() == handoff.flightRecordUUID.lowercased()
               }) {
                garminSDCard.openGuidedFromLogRow(entry: entry, settings: settings)
            }
        }
        .onChange(of: flightLogs.entries) {
            reconcilePostFlightGarminUploadState()
            Task { await refreshLegReviewStatuses() }
        }
        .onChange(of: flightLogs.pendingGarminCSV?.id) { _, pendingID in
            if pendingID == nil {
                isShowingGarminAssignment = false
            } else if flightLogs.pendingGarminCSV?.targetFlightRecordID == nil
                && directImportTarget == nil
                && !isShowingFileImporter
                && !isDirectGarminUpload {
                isShowingGarminAssignment = true
            }
        }
        .fileImporter(
            isPresented: $isShowingFileImporter,
            allowedContentTypes: [.commaSeparatedText],
            allowsMultipleSelection: false
        ) { result in
            switch result {
            case .success(let urls):
                guard let url = urls.first, flightLogs.stageGarminCSV(from: url) else {
                    isDirectGarminUpload = false
                    return
                }
                if let target = directImportTarget {
                    directImportTarget = nil
                    Task {
                        await flightLogs.uploadPendingGarminCSV(
                            to: target,
                            settings: settings,
                            uploadManager: uploadManager
                        )
                        isDirectGarminUpload = false
                    }
                } else {
                    isShowingGarminAssignment = true
                }
            case .failure(let error):
                isDirectGarminUpload = false
                directImportTarget = nil
                _ = error
            }
        }
        .sheet(isPresented: $isShowingGarminAssignment) {
            garminAssignmentSheet
                .interactiveDismissDisabled()
        }
        .sheet(item: $pinTarget) { entry in
            flightLogPINSheet(entry)
        }
        .sheet(item: $adjustmentTarget) { entry in
            FlightLogAdjustmentView(entry: entry)
                .environmentObject(flightLogs)
                .environmentObject(settings)
                .presentationDetents([.medium, .large])
        }
        .sheet(item: $legReviewTarget) { entry in
            CVROperationalLegReviewSheet(
                dispatchUUID: entry.dispatchUUID,
                flightRecordUUID: entry.flightRecordID,
                advancesPostFlightHandoff: shouldAdvanceHandoff(afterReviewOf: entry),
                onAccepted: { _ in
                    verifiedLegReviewDispatchUUIDs.insert(entry.dispatchUUID.lowercased())
                }
            )
            .environmentObject(workflow)
            .environmentObject(settings)
        }
        .confirmationDialog(
            "Void this Log entry?",
            isPresented: Binding(
                get: { voidConfirmTarget != nil },
                set: { if !$0 { voidConfirmTarget = nil } }
            ),
            titleVisibility: .visible
        ) {
            if let entry = voidConfirmTarget {
                Button("Yes — Hide From Log", role: .destructive) {
                    _ = workflow.voidFlightLog(flightRecordID: entry.flightRecordID)
                    voidConfirmTarget = nil
                }
            }
            Button("No", role: .cancel) {
                voidConfirmTarget = nil
            }
        } message: {
            Text("Hides this flight from the Log overview and from COMPLETE / SYNC PENDING counts. Does not delete a server Master Logbook copy if already uploaded. History export may still be available in Admin.")
        }
    }

    private func flightLogRow(_ entry: CVRFlightLogEntry) -> some View {
        let overall = overallLogStatus(entry)
        let isHoldingVoid = voidHoldFlightRecordID == entry.flightRecordID
        return VStack(alignment: .leading, spacing: 10) {
            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    Text(displayDate(entry.scheduledDate))
                        .font(.headline.weight(.bold))
                        .foregroundStyle(.white)
                    Spacer()
                    Label(
                        overall.title,
                        systemImage: overall.icon
                    )
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(overall.color)
                }
                Text((entry.crewNames ?? []).isEmpty ? "Crew not recorded" : (entry.crewNames ?? []).joined(separator: " · "))
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                HStack(spacing: 8) {
                    logValue("DEPARTURE", value: airport(entry.departureAirport), detail: displayTime(entry.departureTime))
                    Image(systemName: "arrow.right")
                        .font(.caption.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                    logValue("ARRIVAL", value: airport(entry.arrivalAirport), detail: displayTime(entry.arrivalTime))
                    logValue("HOBBS", value: hobbs(entry.totalHobbsTime), detail: "TOTAL")
                }
                HStack(spacing: 8) {
                    logStatusValue(
                        "DISPATCH",
                        value: uploadStatusText(entry),
                        color: uploadStatusColor(entry)
                    )
                    logStatusValue(
                        "AUDIO",
                        value: audioStatusText(entry),
                        color: audioStatusColor(entry)
                    )
                    logStatusValue(
                        "TRANSCRIPT",
                        value: transcriptStatusText(entry),
                        color: transcriptStatusColor(entry)
                    )
                }
                HStack(spacing: 8) {
                    logStatusValue(
                        "TAKEOFFS",
                        value: "\(entry.takeoffCount ?? 0)",
                        color: CVROperationalPalette.secondaryBlue
                    )
                    logStatusValue(
                        "LANDINGS",
                        value: "\(entry.landingCount ?? 0)",
                        color: CVROperationalPalette.secondaryBlue
                    )
                }
                if let failure = logFailureMessage(entry) {
                    Text(failure)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(
                            entry.serverUploadStatus?.lowercased() == "failed"
                                || entry.audioUploadStatus?.lowercased() == "failed"
                                || entry.transcriptStatus?.lowercased() == "failed"
                                ? CVROperationalPalette.critical
                                : CVROperationalPalette.secondaryBlue
                        )
                        .lineLimit(3)
                }
                if adminUnlocked {
                    Text(isHoldingVoid ? "KEEP HOLDING TO VOID…" : "HOLD CARD 5 SECONDS TO VOID")
                        .font(.system(size: 9, weight: .bold))
                        .tracking(0.6)
                        .foregroundStyle(
                            isHoldingVoid
                                ? CVROperationalPalette.warning
                                : CVROperationalPalette.textSecondary.opacity(0.85)
                        )
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .contentShape(Rectangle())
            .onLongPressGesture(
                minimumDuration: voidHoldDuration,
                maximumDistance: 64,
                pressing: { pressing in
                    guard adminUnlocked else { return }
                    if pressing {
                        voidHoldFlightRecordID = entry.flightRecordID
                        voidHoldProgress = 0
                        CVRHaptics.prepare(.heavy)
                        withAnimation(.linear(duration: voidHoldDuration)) {
                            voidHoldProgress = 1
                        }
                    } else if voidHoldFlightRecordID == entry.flightRecordID {
                        withAnimation(.easeOut(duration: 0.15)) {
                            voidHoldProgress = 0
                        }
                        voidHoldFlightRecordID = nil
                    }
                },
                perform: {
                    guard adminUnlocked else { return }
                    CVRHaptics.impact(.heavy)
                    voidHoldProgress = 0
                    voidHoldFlightRecordID = nil
                    adjustmentPIN = ""
                    pinError = ""
                    pinPurpose = .voidLog
                    pinTarget = entry
                }
            )

            HStack(spacing: 8) {
                if logNeedsRetry(entry) {
                    Button {
                        retryLogUpload(entry)
                    } label: {
                        Label("RETRY", systemImage: "arrow.clockwise")
                    }
                    .foregroundStyle(CVROperationalPalette.critical)
                } else if logNeedsManualSync(entry) {
                    Button {
                        syncLogEntry(entry)
                    } label: {
                        Label("SYNC", systemImage: "arrow.triangle.2.circlepath")
                    }
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                }
                if garminSDCardNeedsAttention(entry) {
                    let hasPendingGarmin = flightLogs.pendingGarminCSV?.targetFlightRecordID
                        == entry.flightRecordID
                    Button {
                        if hasPendingGarmin {
                            Task {
                                await flightLogs.uploadPendingGarminCSV(
                                    to: entry,
                                    settings: settings,
                                    uploadManager: uploadManager
                                )
                            }
                        } else {
                            directImportTarget = entry
                            isDirectGarminUpload = true
                            isShowingFileImporter = true
                        }
                    } label: {
                        Label(
                            hasPendingGarmin ? "RETRY GARMIN" : "SELECT GARMIN CSV",
                            systemImage: hasPendingGarmin ? "arrow.clockwise.icloud" : "doc.badge.plus"
                        )
                    }
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .accessibilityLabel(
                        hasPendingGarmin
                            ? "Retry the locally stored Garmin CSV upload"
                            : "Select the Garmin CSV for this flight"
                    )
                }
                if entry.hasGarminCSV && legsCanBeReviewed(entry) {
                    let verified = legsAreVerified(entry)
                    Button {
                        guard !verified || adminUnlocked else { return }
                        legReviewTarget = entry
                    } label: {
                        Label(
                            verified ? "LEGS VERIFIED" : "VERIFY LEGS",
                            systemImage: verified ? "checkmark.seal.fill" : "list.clipboard"
                        )
                    }
                    .foregroundStyle(
                        verified ? CVROperationalPalette.success : CVROperationalPalette.warning
                    )
                    .disabled(verified && !adminUnlocked)
                    .accessibilityHint(
                        verified && adminUnlocked
                            ? "Admin may open and append a corrected leg revision"
                            : ""
                    )
                }
                Spacer()
                Button {
                    adjustmentPIN = ""
                    pinError = ""
                    pinPurpose = .adjust
                    pinTarget = entry
                } label: {
                    Label("ADJUST", systemImage: "lock.fill")
                }
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            }
            .font(.caption.weight(.bold))
            .buttonStyle(.plain)
        }
        .padding(14)
        .background {
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    CVROperationalPalette.cardBackground
                    if isHoldingVoid {
                        CVROperationalPalette.warning.opacity(0.28)
                            .frame(width: proxy.size.width * voidHoldProgress)
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: 16))
            }
        }
        .overlay(
            RoundedRectangle(cornerRadius: 16)
                .stroke(
                    isHoldingVoid
                        ? CVROperationalPalette.warning.opacity(0.9)
                        : (entry.hasGarminCSV ? CVROperationalPalette.cardBorder : CVROperationalPalette.warning.opacity(0.55)),
                    lineWidth: isHoldingVoid ? 2 : 1
                )
        )
        .accessibilityHint(adminUnlocked ? "Hold for 5 seconds to void this Log entry" : "")
    }

    private func logValue(_ title: String, value: String, detail: String) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(title)
                .font(.system(size: 9, weight: .bold))
                .tracking(0.7)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.subheadline.weight(.bold))
                .foregroundStyle(.white)
            Text(detail)
                .font(.caption2.weight(.semibold).monospacedDigit())
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func logStatusValue(_ title: String, value: String, color: Color) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(title)
                .font(.system(size: 9, weight: .bold))
                .tracking(0.7)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.caption.weight(.bold).monospacedDigit())
                .foregroundStyle(color)
                .lineLimit(1)
                .minimumScaleFactor(0.7)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func uploadStatusText(_ entry: CVRFlightLogEntry) -> String {
        switch entry.serverUploadStatus?.lowercased() {
        case "complete": return "UPLOADED"
        case "failed": return "FAILED"
        case "uploading":
            let progress = entry.serverUploadProgress ?? 0
            return progress < 1 ? "UPLOADING" : "\(progress)%"
        case "partial", "pending":
            return "\(entry.serverUploadProgress ?? 0)%"
        default: return "\(entry.serverUploadProgress ?? 0)%"
        }
    }

    private func uploadStatusColor(_ entry: CVRFlightLogEntry) -> Color {
        switch entry.serverUploadStatus?.lowercased() {
        case "complete": return CVROperationalPalette.success
        case "failed": return CVROperationalPalette.critical
        default: return CVROperationalPalette.secondaryBlue
        }
    }

    private func audioStatusText(_ entry: CVRFlightLogEntry) -> String {
        switch entry.audioUploadStatus?.lowercased() {
        case "uploaded", "complete": return "UPLOADED"
        case "failed": return "FAILED"
        case "uploading": return "UPLOADING"
        case "missing", nil, "": return "NONE"
        default: return "PENDING"
        }
    }

    private func audioStatusColor(_ entry: CVRFlightLogEntry) -> Color {
        switch entry.audioUploadStatus?.lowercased() {
        case "uploaded", "complete": return CVROperationalPalette.success
        case "failed": return CVROperationalPalette.critical
        case "uploading": return CVROperationalPalette.secondaryBlue
        case "missing", nil, "": return CVROperationalPalette.textSecondary
        default: return CVROperationalPalette.standby
        }
    }

    private func transcriptStatusText(_ entry: CVRFlightLogEntry) -> String {
        switch entry.transcriptStatus?.lowercased() {
        case "ready": return "READY"
        case "failed": return "FAILED"
        case "transcribing", "queued": return "\(entry.transcriptProgress ?? 0)%"
        default: return "PENDING"
        }
    }

    private func transcriptStatusColor(_ entry: CVRFlightLogEntry) -> Color {
        switch entry.transcriptStatus?.lowercased() {
        case "ready": return CVROperationalPalette.success
        case "failed": return CVROperationalPalette.critical
        case "transcribing", "queued": return CVROperationalPalette.secondaryBlue
        default: return CVROperationalPalette.standby
        }
    }

    private func overallLogStatus(_ entry: CVRFlightLogEntry) -> (title: String, icon: String, color: Color) {
        if entry.serverUploadStatus?.lowercased() == "failed"
            || entry.audioUploadStatus?.lowercased() == "failed"
            || entry.transcriptStatus?.lowercased() == "failed" {
            return ("FAILED", "exclamationmark.octagon.fill", CVROperationalPalette.critical)
        }
        let operationalEvidenceComplete = entry.serverUploadStatus?.lowercased() == "complete"
            && (entry.audioUploadStatus?.lowercased() == "uploaded"
                || entry.audioUploadStatus?.lowercased() == "complete")
            && entry.transcriptStatus?.lowercased() == "ready"
        if operationalEvidenceComplete,
           flightLogs.pendingGarminCSV?.targetFlightRecordID == entry.flightRecordID {
            return ("GARMIN SYNC", "arrow.clockwise.icloud.fill", CVROperationalPalette.warning)
        }
        if operationalEvidenceComplete, !entry.hasGarminCSV {
            return ("GARMIN REQUIRED", "sdcard.fill", CVROperationalPalette.warning)
        }
        if operationalEvidenceComplete, !legsAreVerified(entry) {
            return ("VERIFY LEGS", "list.clipboard.fill", CVROperationalPalette.warning)
        }
        if operationalEvidenceComplete {
            return ("COMPLETE", "checkmark.seal.fill", CVROperationalPalette.success)
        }
        if entry.serverUploadStatus?.lowercased() == "complete" {
            return ("DISPATCH UPLOADED", "checkmark.icloud.fill", CVROperationalPalette.secondaryBlue)
        }
        // Check-In is the crew terminal state. Pending sync must not read as incomplete ops data.
        if isOperationallyCheckedIn(entry) {
            let syncing = ["uploading", "partial", "pending", "queued"].contains(
                entry.serverUploadStatus?.lowercased() ?? ""
            ) || ["uploading", "pending"].contains(entry.audioUploadStatus?.lowercased() ?? "")
                || ["transcribing", "queued", "pending"].contains(entry.transcriptStatus?.lowercased() ?? "")
            if syncing {
                return ("SYNCING", "arrow.triangle.2.circlepath", CVROperationalPalette.secondaryBlue)
            }
            return ("CHECKED IN", "checkmark.circle.fill", CVROperationalPalette.secondaryBlue)
        }
        return ("INCOMPLETE", "exclamationmark.triangle.fill", CVROperationalPalette.warning)
    }

    private func isOperationallyCheckedIn(_ entry: CVRFlightLogEntry) -> Bool {
        entry.endingHobbs != nil && entry.endingTacho != nil
    }

    private func logNeedsRetry(_ entry: CVRFlightLogEntry) -> Bool {
        entry.serverUploadStatus?.lowercased() == "failed"
            || entry.audioUploadStatus?.lowercased() == "failed"
            || entry.transcriptStatus?.lowercased() == "failed"
    }

    private func logNeedsManualSync(_ entry: CVRFlightLogEntry) -> Bool {
        guard isOperationallyCheckedIn(entry) else { return false }
        if logNeedsRetry(entry) { return false }
        let dispatchDone = entry.serverUploadStatus?.lowercased() == "complete"
        let audioDone = entry.audioUploadStatus?.lowercased() == "uploaded"
            || entry.audioUploadStatus?.lowercased() == "complete"
        let transcriptDone = entry.transcriptStatus?.lowercased() == "ready"
        return !(dispatchDone && audioDone && transcriptDone)
    }

    private func garminSDCardNeedsAttention(_ entry: CVRFlightLogEntry) -> Bool {
        if !entry.hasGarminCSV { return true }
        guard let pending = flightLogs.pendingGarminCSV,
              pending.targetFlightRecordID == entry.flightRecordID else { return false }
        return pending.lastFailureMessage?.isEmpty == false
    }

    private func logFailureMessage(_ entry: CVRFlightLogEntry) -> String? {
        let message = (entry.serverUploadError ?? entry.transcriptError ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return message.isEmpty ? nil : message
    }

    private func syncPendingLogUploads() {
        _ = workflow.repairDispatchCrewFromScheduledSessions(sessionsStore.sessions)
        for entry in displayEntries where logNeedsManualSync(entry) {
            _ = workflow.repairArchivedDispatchCrewFromScheduledSessions(
                flightRecordID: entry.flightRecordID,
                sessions: sessionsStore.sessions
            )
            _ = workflow.forceRetryPendingUploads(forFlightRecordID: entry.flightRecordID)
        }
        workflow.requeueFailedUploads()
        workflow.requeueConnectivityFailedUploads()
        _ = recordingStore.requeueConnectivityFailedUploads()
        uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)

        let flightIDs = Set(displayEntries.map(\.flightRecordID))
        for recording in recordingStore.recordings {
            let linkedFlightID = recording.flightSessionID ?? ""
            guard flightIDs.contains(linkedFlightID) || flightIDs.contains(recording.id) else { continue }
            guard recording.uploadStatus != .uploaded else { continue }
            recordingStore.update(recording.id) {
                $0.nextUploadRetryAt = nil
                if $0.uploadStatus == .failed {
                    $0.uploadStatus = .pending
                    $0.lastError = ""
                }
            }
            uploadManager.upload(recordingID: recording.id, store: recordingStore, settings: settings)
        }

        Task {
            await flightLogs.retryPendingGarminCSV(
                settings: settings,
                uploadManager: uploadManager
            )
            try? await Task.sleep(for: .seconds(4))
            await flightLogs.refresh(settings: settings)
        }
    }

    private func syncLogEntry(_ entry: CVRFlightLogEntry) {
        _ = workflow.repairDispatchCrewFromScheduledSessions(sessionsStore.sessions)
        _ = workflow.repairArchivedDispatchCrewFromScheduledSessions(
            flightRecordID: entry.flightRecordID,
            sessions: sessionsStore.sessions
        )
        _ = workflow.forceRetryPendingUploads(forFlightRecordID: entry.flightRecordID)
        workflow.requeueFailedUploads(forFlightRecordID: entry.flightRecordID)
        uploadManager.retryWorkflowSynchronization(workflow: workflow, settings: settings)
        for recording in linkedRecordings(forFlightRecordID: entry.flightRecordID) {
            let needsFlightRelink = recording.flightSessionID != entry.flightRecordID
            recordingStore.update(recording.id) {
                $0.flightSessionID = entry.flightRecordID
                $0.nextUploadRetryAt = nil
                $0.uploadRetryCount = nil
                if $0.uploadStatus == .failed {
                    $0.uploadStatus = .pending
                    $0.lastError = ""
                }
            }
            if needsFlightRelink || recording.uploadStatus != .uploaded {
                uploadManager.upload(recordingID: recording.id, store: recordingStore, settings: settings)
            }
        }
        Task {
            try? await Task.sleep(for: .seconds(4))
            await flightLogs.refresh(settings: settings)
        }
    }

    private func retryLogUpload(_ entry: CVRFlightLogEntry) {
        syncLogEntry(entry)
        guard entry.transcriptStatus?.lowercased() == "failed" else { return }
        Task {
            try? await Task.sleep(for: .seconds(4))
            await flightLogs.retryServerProcessing(entry, settings: settings)
            await flightLogs.refresh(settings: settings)
        }
    }

    private func linkedRecordings(forFlightRecordID flightRecordID: String) -> [Recording] {
        let identifiers = workflow.recordingIdentifiers(forFlightRecordID: flightRecordID)
        return recordingStore.recordings.filter {
            $0.flightSessionID == flightRecordID
                || identifiers.contains($0.id)
                || identifiers.contains($0.flightSessionID)
        }
    }

    private func flightLogPINSheet(_ entry: CVRFlightLogEntry) -> some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                VStack(spacing: 18) {
                    Image(systemName: pinPurpose == .voidLog ? "eye.slash.circle.fill" : "lock.shield.fill")
                        .font(.system(size: 42, weight: .bold))
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    Text("ADMIN AUTHORIZATION")
                        .font(.headline.weight(.bold))
                        .foregroundStyle(.white)
                    Text(
                        pinPurpose == .voidLog
                            ? "Enter the CVR Unit Admin PIN to void (hide) this Log entry."
                            : "Enter the CVR Unit Admin PIN to adjust Hobbs, Tacho, or fuel."
                    )
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                        .multilineTextAlignment(.center)
                    SecureField("Admin PIN", text: $adjustmentPIN)
                        .keyboardType(.numberPad)
                        .textContentType(.password)
                        .font(.title3.weight(.bold))
                        .foregroundStyle(.white)
                        .padding(12)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                        .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                    if !pinError.isEmpty {
                        Text(pinError)
                            .font(.caption.weight(.bold))
                            .foregroundStyle(CVROperationalPalette.critical)
                    }
                    CVROperationalActionButton(
                        title: pinPurpose == .voidLog ? "UNLOCK VOID" : "UNLOCK ADJUSTMENT",
                        subtitle: displayDate(entry.scheduledDate),
                        color: CVROperationalPalette.secondaryBlue
                    ) {
                        guard adjustmentPIN == settings.adminPIN else {
                            pinError = "Incorrect Admin PIN"
                            return
                        }
                        let purpose = pinPurpose
                        pinTarget = nil
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.25) {
                            if purpose == .voidLog {
                                voidConfirmTarget = entry
                            } else {
                                adjustmentTarget = entry
                            }
                        }
                    }
                }
                .padding(24)
            }
            .navigationTitle(pinPurpose == .voidLog ? "Void Log Entry" : "Protected Adjustment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { pinTarget = nil }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var garminAssignmentSheet: some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        if let file = flightLogs.pendingGarminCSV {
                            Text(file.originalFilename)
                                .font(.subheadline.weight(.bold))
                                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                        }
                        Text("Select the dispatched flight that belongs to this Garmin CSV.")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(CVROperationalPalette.textSecondary)
                        ForEach(displayEntries.filter { !$0.hasGarminCSV }) { entry in
                            Button {
                                isShowingGarminAssignment = false
                                Task {
                                    await flightLogs.uploadPendingGarminCSV(
                                        to: entry,
                                        settings: settings,
                                        uploadManager: uploadManager
                                    )
                                }
                            } label: {
                                HStack {
                                    VStack(alignment: .leading, spacing: 4) {
                                        Text(displayDate(entry.scheduledDate))
                                            .font(.headline.weight(.bold))
                                        Text("\(airport(entry.departureAirport)) \(displayTime(entry.departureTime)) → \(airport(entry.arrivalAirport)) \(displayTime(entry.arrivalTime))")
                                            .font(.caption.weight(.semibold))
                                            .foregroundStyle(CVROperationalPalette.textSecondary)
                                    }
                                    Spacer()
                                    Image(systemName: "paperclip")
                                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                }
                                .foregroundStyle(.white)
                                .padding(14)
                                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(16)
                }
            }
            .navigationTitle("Assign Garmin CSV")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        isShowingGarminAssignment = false
                        flightLogs.cancelPendingGarminCSV()
                    }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var uploadOverlay: some View {
        ZStack {
            Color.black.opacity(0.72).ignoresSafeArea()
            VStack(spacing: 14) {
                ProgressView(value: flightLogs.isAdjusting ? nil : flightLogs.uploadProgress)
                    .tint(CVROperationalPalette.secondaryBlue)
                    .frame(width: 220)
                Text(flightLogs.isAdjusting ? "SAVING LOG ADJUSTMENT" : "ATTACHING GARMIN CSV")
                    .font(.headline.weight(.bold))
                    .foregroundStyle(.white)
                if !flightLogs.isAdjusting {
                    Text("\(Int((flightLogs.uploadProgress * 100).rounded()))%")
                        .font(.title2.weight(.bold).monospacedDigit())
                        .foregroundStyle(CVROperationalPalette.secondaryBlue)
                }
            }
            .padding(24)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
            .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private func reconcilePostFlightGarminUploadState() {
        let verifiedFlightIDs = Set(
            flightLogs.entries
                .filter(\.hasGarminCSV)
                .map { $0.flightRecordID.lowercased() }
        )
        _ = workflow.reconcilePostFlightGarminUpload(
            flightRecordIDsWithCSV: verifiedFlightIDs
        )
    }

    private func legsAreVerified(_ entry: CVRFlightLogEntry) -> Bool {
        if verifiedLegReviewDispatchUUIDs.contains(entry.dispatchUUID.lowercased()) {
            return true
        }
        if workflow.locallyAcceptedLegReviewDispatchUUIDs.contains(entry.dispatchUUID.lowercased()) {
            return true
        }
        guard let handoff = workflow.state.postFlightGarminHandoff else { return false }
        return handoff.dispatchUUID.lowercased() == entry.dispatchUUID.lowercased()
            && handoff.legReviewRevisionUUID?.isEmpty == false
    }

    private func legsCanBeReviewed(_ entry: CVRFlightLogEntry) -> Bool {
        if legReviewEligibleDispatchUUIDs.contains(entry.dispatchUUID.lowercased()) {
            return true
        }
        return workflow.state.postFlightGarminHandoff?.dispatchUUID.lowercased()
            == entry.dispatchUUID.lowercased()
    }

    private func shouldAdvanceHandoff(afterReviewOf entry: CVRFlightLogEntry) -> Bool {
        guard let handoff = workflow.state.postFlightGarminHandoff else { return false }
        return handoff.dispatchUUID.lowercased() == entry.dispatchUUID.lowercased()
            && handoff.legReviewRevisionUUID?.isEmpty != false
            && (handoff.phase == .uploadVerified || handoff.phase == .verifyingLegs)
    }

    @MainActor
    private func refreshLegReviewStatuses() async {
        guard let baseURL = settings.normalizedServerURL,
              let credential = settings.deviceCredential,
              !credential.isEmpty else {
            return
        }
        var verified = verifiedLegReviewDispatchUUIDs
        var eligible = legReviewEligibleDispatchUUIDs
        if let handoff = workflow.state.postFlightGarminHandoff,
           handoff.legReviewRevisionUUID?.isEmpty == false {
            verified.insert(handoff.dispatchUUID.lowercased())
        }
        if let handoff = workflow.state.postFlightGarminHandoff {
            eligible.insert(handoff.dispatchUUID.lowercased())
        }
        for entry in displayEntries where entry.hasGarminCSV && !entry.dispatchUUID.isEmpty {
            do {
                let status = try await APIClient(serverURL: baseURL).operationalLegReviewStatus(
                    dispatchUUID: entry.dispatchUUID,
                    credential: credential
                )
                eligible.insert(entry.dispatchUUID.lowercased())
                if status.verified {
                    verified.insert(entry.dispatchUUID.lowercased())
                } else {
                    verified.remove(entry.dispatchUUID.lowercased())
                }
            } catch {
                // Keep the last known status; Log remains usable offline.
            }
        }
        verified.formUnion(workflow.locallyAcceptedLegReviewDispatchUUIDs)
        verifiedLegReviewDispatchUUIDs = verified
        legReviewEligibleDispatchUUIDs = eligible
    }

    private var displayEntries: [CVRFlightLogEntry] {
        var byIdentity: [String: CVRFlightLogEntry] = [:]
        let remoteLoaded = !flightLogs.entries.isEmpty || !flightLogs.isRefreshing
        for remote in flightLogs.entries {
            guard !workflow.isFlightLogVoided(remote.flightRecordID) else { continue }
            let identity = logIdentity(remote)
            if let existing = byIdentity[identity] {
                byIdentity[identity] = mergeLogEntries(existing, remote)
            } else {
                byIdentity[identity] = remote
            }
        }
        let selectedTail = normalizedTail(settings.selectedAircraft?.registration ?? "")
        for archive in workflow.archives where selectedTail.isEmpty
            || normalizedTail(archive.dispatch.tailNumber) == selectedTail {
            guard archive.voidedAt == nil,
                  !workflow.isFlightLogVoided(archive.flightRecordID) else { continue }
            let local = localLogEntry(
                dispatch: archive.dispatch,
                flightRecord: archive.flightRecord,
                events: archive.flightEvents,
                components: archive.uploadComponents
            )
            let identity = logIdentity(local)
            if let existing = byIdentity[identity] {
                byIdentity[identity] = mergeLogEntries(existing, local)
            } else if archive.status != .serverVerified {
                // Still needs sync — keep visible even if the server list omitted it.
                byIdentity[identity] = local
            } else if !remoteLoaded || flightLogs.entries.isEmpty {
                // Offline / empty remote refresh — fall back to local History.
                byIdentity[identity] = local
            }
            // Else: server-verified archive absent from the live server Log — hide (purged online).
        }
        if let dispatch = workflow.state.activeDispatch,
           let flightRecord = workflow.state.activeFlightRecord {
            guard !workflow.isFlightLogVoided(flightRecord.id) else {
                return byIdentity.values.sorted {
                    if $0.scheduledDate == $1.scheduledDate {
                        return ($0.departureTime ?? "") > ($1.departureTime ?? "")
                    }
                    return $0.scheduledDate > $1.scheduledDate
                }
            }
            let local = localLogEntry(
                dispatch: dispatch,
                flightRecord: flightRecord,
                events: workflow.state.flightEvents,
                components: workflow.state.uploadComponents
            )
            let identity = logIdentity(local)
            if let existing = byIdentity[identity] {
                byIdentity[identity] = mergeLogEntries(existing, local)
            } else {
                byIdentity[identity] = local
            }
        }
        // `hasGarminCSV` means server-verified evidence. A locally staged or failed
        // attachment remains pending and must never make the Log look complete.
        // Drop any merge result whose flight id was voided (remote+local merge edge cases).
        return byIdentity.values
            .filter { !workflow.isFlightLogVoided($0.flightRecordID) }
            .sorted {
                if $0.scheduledDate == $1.scheduledDate {
                    return ($0.departureTime ?? "") > ($1.departureTime ?? "")
                }
                return $0.scheduledDate > $1.scheduledDate
            }
    }

    private func logIdentity(_ entry: CVRFlightLogEntry) -> String {
        // A reusable reservation can produce multiple immutable Operational Sessions.
        // Garmin evidence belongs to one flight record and must never leak across them.
        let flightRecordID = entry.flightRecordID.trimmingCharacters(in: .whitespacesAndNewlines)
        if !flightRecordID.isEmpty {
            return "flight:\(flightRecordID.lowercased())"
        }
        // Multi-leg expansions share scheduler_record_id — prefer leg_uuid so each leg is its own card.
        if let legUUID = entry.legUUID?.trimmingCharacters(in: .whitespacesAndNewlines),
           !legUUID.isEmpty {
            return "leg:\(legUUID.lowercased())"
        }
        if let schedulerRecordID = entry.schedulerRecordID?.trimmingCharacters(in: .whitespacesAndNewlines),
           !schedulerRecordID.isEmpty {
            return "schedule:\(schedulerRecordID.lowercased())"
        }
        let dispatchUUID = entry.dispatchUUID.trimmingCharacters(in: .whitespacesAndNewlines)
        if !dispatchUUID.isEmpty {
            return "dispatch:\(dispatchUUID.lowercased())"
        }
        return "flight:\(entry.flightRecordID.lowercased())"
    }

    private func mergeLogEntries(
        _ existing: CVRFlightLogEntry,
        _ candidate: CVRFlightLogEntry
    ) -> CVRFlightLogEntry {
        var merged = candidate.hasGarminCSV && !existing.hasGarminCSV ? candidate : existing
        merged.hasGarminCSV = existing.hasGarminCSV || candidate.hasGarminCSV
        if merged.schedulerRecordID?.isEmpty != false {
            merged.schedulerRecordID = existing.schedulerRecordID ?? candidate.schedulerRecordID
        }
        if (merged.crewNames ?? []).isEmpty {
            merged.crewNames = (existing.crewNames ?? []).isEmpty ? candidate.crewNames : existing.crewNames
        }
        if merged.departureAirport.isEmpty {
            merged.departureAirport = existing.departureAirport.isEmpty
                ? candidate.departureAirport
                : existing.departureAirport
        }
        if merged.arrivalAirport.isEmpty {
            merged.arrivalAirport = existing.arrivalAirport.isEmpty
                ? candidate.arrivalAirport
                : existing.arrivalAirport
        }
        if (merged.departureTime ?? "").isEmpty {
            merged.departureTime = existing.departureTime ?? candidate.departureTime
        }
        // Prefer a non-empty server arrival (Hobbs-derived) over a later local fill.
        // displayEntries merges remote first, then local — so existing is usually the server row.
        if !(existing.arrivalTime ?? "").isEmpty {
            merged.arrivalTime = existing.arrivalTime
        } else if (merged.arrivalTime ?? "").isEmpty {
            merged.arrivalTime = candidate.arrivalTime
        }
        merged.startingHobbs = merged.startingHobbs ?? existing.startingHobbs ?? candidate.startingHobbs
        merged.startingTacho = merged.startingTacho ?? existing.startingTacho ?? candidate.startingTacho
        merged.endingHobbs = merged.endingHobbs ?? existing.endingHobbs ?? candidate.endingHobbs
        merged.endingTacho = merged.endingTacho ?? existing.endingTacho ?? candidate.endingTacho
        merged.totalHobbsTime = merged.totalHobbsTime ?? existing.totalHobbsTime ?? candidate.totalHobbsTime
        if (merged.fuelRemaining ?? "").isEmpty {
            merged.fuelRemaining = existing.fuelRemaining ?? candidate.fuelRemaining
        }
        merged.endingOilPercentage = merged.endingOilPercentage
            ?? existing.endingOilPercentage
            ?? candidate.endingOilPercentage
        merged.endingOilQuantity = merged.endingOilQuantity
            ?? existing.endingOilQuantity
            ?? candidate.endingOilQuantity
        if (merged.endingOilUnit ?? "").isEmpty {
            merged.endingOilUnit = existing.endingOilUnit ?? candidate.endingOilUnit
        }
        merged.serverUploadStatus = preferredStatus(
            existing.serverUploadStatus,
            candidate.serverUploadStatus,
            success: "complete"
        )
        merged.serverUploadProgress = max(
            existing.serverUploadProgress ?? 0,
            candidate.serverUploadProgress ?? 0
        )
        merged.serverUploadError = {
            let status = merged.serverUploadStatus?.lowercased()
            if status == "failed" {
                return candidate.serverUploadError ?? existing.serverUploadError
            }
            if status == "uploading" || status == "pending" || status == "partial" {
                let localMessage = (candidate.serverUploadError ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                if !localMessage.isEmpty { return localMessage }
            }
            return nil
        }()
        merged.audioUploadStatus = preferredAudioStatus(
            existing.audioUploadStatus,
            candidate.audioUploadStatus
        )
        merged.transcriptStatus = preferredStatus(
            existing.transcriptStatus,
            candidate.transcriptStatus,
            success: "ready"
        )
        merged.transcriptProgress = max(
            existing.transcriptProgress ?? 0,
            candidate.transcriptProgress ?? 0
        )
        merged.transcriptError = merged.transcriptStatus?.lowercased() == "failed"
            ? (candidate.transcriptError ?? existing.transcriptError)
            : nil
        merged.takeoffCount = max(existing.takeoffCount ?? 0, candidate.takeoffCount ?? 0)
        merged.landingCount = max(existing.landingCount ?? 0, candidate.landingCount ?? 0)
        merged.serverComponentCount = max(
            existing.serverComponentCount ?? 0,
            candidate.serverComponentCount ?? 0
        )
        return merged
    }

    private func preferredAudioStatus(_ first: String?, _ second: String?) -> String? {
        let values = [first, second].compactMap { $0?.lowercased() }
        if values.contains("uploaded") || values.contains("complete") { return "uploaded" }
        return preferredStatus(first, second, success: "uploaded")
    }

    private func preferredStatus(_ first: String?, _ second: String?, success: String) -> String? {
        let values = [first, second].compactMap { $0?.lowercased() }
        if values.contains(success) { return success }
        if values.contains("uploading") { return "uploading" }
        if values.contains("transcribing") { return "transcribing" }
        if values.contains("queued") { return "queued" }
        if values.contains("failed") { return "failed" }
        if values.contains("pending") { return "pending" }
        if values.contains("partial") { return "partial" }
        return values.first
    }

    private func localLogEntry(
        dispatch: CVRDispatchRecord,
        flightRecord: CVRIncompleteFlightRecord,
        events: [CVRFlightEventRecord],
        components: [CVRUploadComponentRecord]
    ) -> CVRFlightLogEntry {
        let flightEvents = events.filter { $0.flightRecordID == flightRecord.id }
        let departure = flightEvents
            .filter { $0.eventType == "engine_start_off_block" }
            .min { $0.timestampLocal < $1.timestampLocal }
        let arrival = flightEvents
            .filter {
                $0.eventType == "engine_shutdown_on_block"
                    || $0.eventType == "transient_stop_on_block"
            }
            .max { $0.timestampLocal < $1.timestampLocal }
        let totalHobbs: Double? = if let start = dispatch.startingHobbs,
                                     let end = flightRecord.endingHobbs {
            end - start
        } else {
            nil
        }
        let calculatedArrival: Date? = if let stored = flightRecord.calculatedArrivalAt {
            stored
        } else if let departure,
                  let startHobbs = dispatch.startingHobbs,
                  let endHobbs = flightRecord.endingHobbs {
            departure.timestampLocal.addingTimeInterval(max(0, endHobbs - startHobbs) * 3600)
        } else {
            arrival?.timestampLocal
        }
        let crewDisplay = dispatch.crew.map { assignment in
            "\(assignment.personName) (\(assignment.role.label))"
        }
        let relevantComponents = components.filter { $0.flightRecordID == flightRecord.id }
        // DISPATCH column tracks dispatch_metadata only. Averaging every component
        // (events/closure/Garmin) kept the UI at 0% while Dispatch was uploading.
        let dispatchComponent = relevantComponents.first { $0.componentType == "dispatch_metadata" }
        let failedComponent = relevantComponents.first {
            $0.state == .failed || $0.state == .needsUserAction
        }
        let verifiedComponentCount = relevantComponents.filter { $0.state == .serverVerified }.count
        let dispatchFailed = dispatchComponent?.state == .failed
            || dispatchComponent?.state == .needsUserAction
        let componentProgress: Int = {
            guard let dispatchComponent else { return 0 }
            if dispatchComponent.state == .serverVerified { return 100 }
            return Int((min(max(dispatchComponent.progress ?? 0, 0), 1) * 100).rounded())
        }()
        let workflowUploadStatus: String = {
            if dispatchFailed { return "failed" }
            if dispatchComponent?.state == .serverVerified { return "complete" }
            if dispatchComponent?.state == .uploading { return "uploading" }
            if failedComponent != nil { return "failed" }
            return "pending"
        }()
        let dispatchStatusMessage: String? = {
            let message = (dispatchComponent?.lastError ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
            guard !message.isEmpty else { return nil }
            if dispatchFailed { return message }
            if dispatchComponent?.state == .uploading || dispatchComponent?.state == .queued {
                return message
            }
            return nil
        }()
        let linkedRecordings = linkedRecordings(forFlightRecordID: flightRecord.id)
        let audioUploadStatus: String? = {
            guard !linkedRecordings.isEmpty else { return nil }
            if linkedRecordings.contains(where: { $0.uploadStatus == .failed }) { return "failed" }
            if linkedRecordings.allSatisfy({ $0.uploadStatus == .uploaded }) { return "uploaded" }
            if linkedRecordings.contains(where: { $0.uploadStatus == .uploading }) { return "uploading" }
            return "pending"
        }()
        let transcriptStatus: String? = {
            guard !linkedRecordings.isEmpty else { return nil }
            if linkedRecordings.contains(where: { $0.transcriptStatus == .failed }) { return "failed" }
            if linkedRecordings.allSatisfy({ $0.transcriptStatus == .ready }) { return "ready" }
            if linkedRecordings.contains(where: { $0.transcriptStatus == .transcribing }) { return "transcribing" }
            return "pending"
        }()
        let transcriptProgress = linkedRecordings.isEmpty
            ? 0
            : linkedRecordings.map(\.transcriptProgress).min() ?? 0
        let takeoffCount = flightRecord.verifiedTakeoffCount
            ?? flightEvents.filter {
                $0.eventType == "gps_takeoff_provisional" || $0.eventType == "manual_takeoff_adjustment"
            }.count
        let landingCount = flightRecord.verifiedLandingCount
            ?? flightEvents.filter {
                $0.eventType == "gps_landing_provisional" || $0.eventType == "manual_landing_adjustment"
            }.count
        return CVRFlightLogEntry(
            flightRecordID: flightRecord.id,
            dispatchUUID: dispatch.id,
            schedulerRecordID: dispatch.schedulerRecordID,
            reservationUUID: dispatch.operationalIdentity?.reservationUUID,
            legUUID: dispatch.operationalIdentity?.legUUID,
            aircraftRegistration: dispatch.tailNumber,
            scheduledDate: Self.logDateFormatter.string(from: dispatch.scheduledDate),
            crewNames: crewDisplay.isEmpty ? dispatch.crew.map(\.personName) : crewDisplay,
            departureAirport: dispatch.plannedDepartureAirport,
            departureTime: departure.map { ISO8601DateFormatter().string(from: $0.timestampLocal) },
            arrivalAirport: flightRecord.verifiedDestinationAirport ?? dispatch.plannedDestinationAirport,
            arrivalTime: calculatedArrival.map { ISO8601DateFormatter().string(from: $0) },
            startingHobbs: dispatch.startingHobbs,
            startingTacho: dispatch.startingTacho,
            endingHobbs: flightRecord.endingHobbs,
            endingTacho: flightRecord.endingTacho,
            fuelRemaining: flightRecord.fuelRemaining,
            endingOilPercentage: flightRecord.endingOilPercentage,
            endingOilQuantity: flightRecord.endingOilQuantity,
            endingOilUnit: flightRecord.endingOilUnit,
            totalHobbsTime: totalHobbs,
            hasGarminCSV: components.contains {
                $0.flightRecordID == flightRecord.id
                    && $0.componentType == "garmin_csv"
                    && $0.state == .serverVerified
            },
            serverUploadStatus: workflowUploadStatus,
            serverUploadProgress: componentProgress,
            serverUploadError: dispatchStatusMessage ?? failedComponent?.lastError,
            audioUploadStatus: audioUploadStatus,
            transcriptStatus: transcriptStatus,
            transcriptProgress: transcriptProgress,
            transcriptError: linkedRecordings.first(where: { $0.transcriptStatus == .failed })?.lastError,
            takeoffCount: takeoffCount,
            landingCount: landingCount,
            serverComponentCount: verifiedComponentCount
        )
    }

    private var completeCount: Int {
        displayEntries.filter {
            $0.serverUploadStatus?.lowercased() == "complete"
                && ($0.audioUploadStatus?.lowercased() == "uploaded" || $0.audioUploadStatus?.lowercased() == "complete")
        }.count
    }

    private var missingCount: Int {
        displayEntries.count - completeCount
    }

    private var activeFlightIsClosed: Bool {
        guard let flightRecord = workflow.state.activeFlightRecord else { return false }
        return workflow.flightClosureIsComplete(flightRecord)
    }

    private var activeWorkflowVerified: Bool {
        guard let flightRecordID = workflow.state.activeFlightRecord?.id else { return false }
        let components = workflow.state.uploadComponents.filter { $0.flightRecordID == flightRecordID }
        return !components.isEmpty && components.allSatisfy { $0.state == .serverVerified }
    }

    private func displayDate(_ value: String) -> String {
        guard let date = Self.inputDateFormatter.date(from: value) else { return value }
        return Self.outputDateFormatter.string(from: date)
    }

    private func displayTime(_ value: String?) -> String {
        guard let value, !value.isEmpty else { return "—" }
        let hasExplicitTimeZone = value.hasSuffix("Z")
            || value.range(of: #"[+-]\d{2}:\d{2}$"#, options: .regularExpression) != nil
        if hasExplicitTimeZone,
           let date = Self.isoTimestampFormatter.date(from: value) {
            return Self.californiaTimeFormatter.string(from: date)
        }
        if let localDate = Self.californiaLocalTimestampFormatter.date(from: value) {
            return Self.californiaTimeFormatter.string(from: localDate)
        }
        return value
    }

    private func airport(_ value: String) -> String {
        value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? "—" : value
    }

    private func normalizedTail(_ value: String) -> String {
        value.uppercased().filter { $0.isLetter || $0.isNumber }
    }

    private func hobbs(_ value: Double?) -> String {
        value.map { String(format: "%.1f", $0) } ?? "—"
    }

    private static let inputDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter
    }()

    private static let operationalTimeZone =
        TimeZone(identifier: "America/Los_Angeles") ?? .current

    private static let isoTimestampFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        return formatter
    }()

    private static let californiaLocalTimestampFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "yyyy-MM-dd'T'HH:mm:ss"
        return formatter
    }()

    private static let californiaTimeFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "HH:mm"
        return formatter
    }()

    private static let logDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "yyyy-MM-dd"
        return formatter
    }()

    private static let outputDateFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = operationalTimeZone
        formatter.dateFormat = "EEE, MMM d, yyyy"
        return formatter
    }()
}

private struct FlightLogAdjustmentView: View {
    @EnvironmentObject private var flightLogs: CVRFlightLogStore
    @EnvironmentObject private var settings: SettingsStore
    @Environment(\.dismiss) private var dismiss
    let entry: CVRFlightLogEntry
    @State private var departureAirport: String
    @State private var arrivalAirport: String
    @State private var crewNames: String
    @State private var startingHobbs: String
    @State private var startingTacho: String
    @State private var endingHobbs: String
    @State private var endingTacho: String
    @State private var fuelRemaining: String
    @State private var saveError = ""

    init(entry: CVRFlightLogEntry) {
        self.entry = entry
        _departureAirport = State(initialValue: entry.departureAirport)
        _arrivalAirport = State(initialValue: entry.arrivalAirport)
        _crewNames = State(initialValue: (entry.crewNames ?? []).joined(separator: ", "))
        _startingHobbs = State(initialValue: entry.startingHobbs.map { String(format: "%.1f", $0) } ?? "")
        _startingTacho = State(initialValue: entry.startingTacho.map { String(format: "%.1f", $0) } ?? "")
        _endingHobbs = State(initialValue: entry.endingHobbs.map { String(format: "%.1f", $0) } ?? "")
        _endingTacho = State(initialValue: entry.endingTacho.map { String(format: "%.1f", $0) } ?? "")
        _fuelRemaining = State(initialValue: entry.fuelRemaining ?? "")
    }

    var body: some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: 14) {
                        CVROperationalWarningCard(
                            title: "ADMINISTRATIVE ADJUSTMENT",
                            message: "Correct the Dispatch start baseline or Flight Closure values. This creates an append-only correction; the original evidence remains preserved.",
                            iconName: "lock.shield.fill",
                            color: CVROperationalPalette.warning
                        )
                        if !saveError.isEmpty {
                            CVROperationalWarningCard(
                                title: "CANNOT SAVE ADJUSTMENT",
                                message: saveError,
                                iconName: "exclamationmark.triangle.fill",
                                color: CVROperationalPalette.critical
                            )
                        }
                        adjustmentField(
                            "DEPARTURE AIRPORT (OPTIONAL IF UNKNOWN)",
                            value: $departureAirport,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "ARRIVAL AIRPORT (OPTIONAL IF UNKNOWN)",
                            value: $arrivalAirport,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "CREW NAMES",
                            value: $crewNames,
                            baseline: nil,
                            keyboard: .default
                        )
                        adjustmentField(
                            "STARTING HOBBS",
                            value: $startingHobbs,
                            baseline: nil
                        )
                        adjustmentField(
                            "STARTING TACHO",
                            value: $startingTacho,
                            baseline: nil
                        )
                        adjustmentField(
                            "ENDING HOBBS",
                            value: $endingHobbs,
                            baseline: nil
                        )
                        adjustmentField(
                            "ENDING TACHO",
                            value: $endingTacho,
                            baseline: nil
                        )
                        adjustmentField(
                            "FUEL REMAINING",
                            value: $fuelRemaining,
                            baseline: nil
                        )
                    }
                    .padding(16)
                }
            }
            .navigationTitle("Adjust Flight Log")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        if let validationMessage {
                            saveError = validationMessage
                            return
                        }
                        saveError = ""
                        Task {
                            if await flightLogs.adjustFlightLog(
                                entry,
                                departureAirport: departureAirport,
                                arrivalAirport: arrivalAirport,
                                crewNames: crewNames.split(separator: ",").map(String.init),
                                startingHobbs: Double(startingHobbs),
                                startingTacho: Double(startingTacho),
                                endingHobbs: Double(endingHobbs),
                                endingTacho: Double(endingTacho),
                                fuelRemaining: fuelRemaining,
                                settings: settings
                            ) {
                                dismiss()
                            } else {
                                saveError = flightLogs.lastError.nilIfEmpty
                                    ?? "The server did not accept this adjustment."
                            }
                        }
                    }
                }
            }
        }
        .preferredColorScheme(.dark)
    }

    private var validationMessage: String? {
        let departure = departureAirport.trimmingCharacters(in: .whitespacesAndNewlines)
        let arrival = arrivalAirport.trimmingCharacters(in: .whitespacesAndNewlines)
        guard departure.isEmpty
                || departure.range(of: "^[A-Za-z0-9]{3,8}$", options: .regularExpression) != nil else {
            return "Enter a valid 3–8 character departure airport identifier."
        }
        guard arrival.isEmpty
                || arrival.range(of: "^[A-Za-z0-9]{3,8}$", options: .regularExpression) != nil else {
            return "Enter a valid 3–8 character arrival airport identifier."
        }
        guard crewNames.split(separator: ",").contains(where: {
            !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        }) else {
            return "Enter at least one crew member. Separate multiple names with commas."
        }
        guard let startHobbs = Double(startingHobbs),
              let startTacho = Double(startingTacho),
              startHobbs >= 0,
              startTacho >= 0 else {
            return "Starting Hobbs and Tacho must be valid non-negative values."
        }
        guard let endHobbs = Double(endingHobbs),
              let endTacho = Double(endingTacho),
              endHobbs >= startHobbs,
              endTacho >= startTacho else {
            return "Ending Hobbs and Tacho must be valid and cannot be lower than their starting values."
        }
        let numericFuel = fuelRemaining.components(
            separatedBy: CharacterSet(charactersIn: "0123456789.-").inverted
        ).joined()
        guard let fuel = Double(numericFuel), fuel >= 0 else {
            return "Fuel remaining must be a valid non-negative quantity."
        }
        return nil
    }

    private func adjustmentField(
        _ title: String,
        value: Binding<String>,
        baseline: Double?,
        keyboard: UIKeyboardType = .decimalPad
    ) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: value)
                .keyboardType(keyboard)
                .textInputAutocapitalization(keyboard == .default ? .characters : .never)
                .font(.title3.weight(.bold).monospacedDigit())
                .foregroundStyle(.white)
                .padding(12)
                .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
                .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            if let baseline {
                Text("Starting value: \(String(format: "%.1f", baseline))")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

struct NoActiveFlightView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    var caption: String

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(
                            title: "NO ACTIVE FLIGHT",
                            subtitle: "READY FOR THE NEXT DISPATCH",
                            iconName: "checkmark.seal.fill",
                            color: CVROperationalPalette.standby,
                            value: nil,
                            caption: caption,
                            metrics: metrics
                        )
                        CVROperationalWarningCard(
                            title: workflow.archives.isEmpty ? "READY FOR DISPATCH" : "PREVIOUS FLIGHT ENDED",
                            message: workflow.archives.isEmpty
                                ? "Select Scheduled or Dispatch to begin a flight."
                                : "Completed flights and Garmin CSV status are available in Log. Select Scheduled or Dispatch to begin another flight.",
                            iconName: "list.bullet.clipboard.fill",
                            color: CVROperationalPalette.success
                        )
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
            }
        }
    }
}

struct LockedOperationalView: View {
    @EnvironmentObject private var settings: SettingsStore
    var title: String
    var subtitle: String
    var iconName: String
    var color: Color
    @Binding var showAdminUnlock: Bool

    var body: some View {
        GeometryReader { proxy in
            let metrics = CVROperationalMetrics(size: proxy.size)
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                ScrollView {
                    VStack(spacing: metrics.spacing) {
                        CVROperationalStatusCard(title: title, subtitle: subtitle, iconName: iconName, color: color, value: nil, caption: "WORKFLOW", metrics: metrics)
                        CVROperationalWarningCard(title: subtitle, message: "Complete the previous operational step before using this tab.", iconName: "lock.fill", color: color)
                    }
                    .padding(.horizontal, metrics.outerHorizontalPadding)
                    .padding(.top, metrics.outerVerticalPadding)
                    .padding(.bottom, 132)
                    .frame(width: proxy.size.width, alignment: .top)
                }
            }
        }
    }
}

struct DispatchEditorView: View {
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var missionCatalog: MissionCatalogStore
    @EnvironmentObject private var uploadManager: UploadManager
    @Environment(\.dismiss) private var dismiss
    var focus: DispatchBlockEditor
    @State private var startingHobbs = ""
    @State private var startingTacho = ""
    @State private var fuelGallons = 0.0
    @State private var oilPercent = 0.0
    @State private var hasFuelSelection = false
    @State private var hasOilSelection = false
    @State private var refueledSincePreviousFlight = false
    @State private var oilServicedSincePreviousFlight = false
    @State private var customerUserID = 0
    @State private var customerIsPIC = false
    @State private var personTwoUserID = 0
    @State private var personTwoRole: CVRCrewRole = .unknown
    @State private var personTwoIsPIC = false
    @State private var personThreeUserID = 0
    @State private var personThreeRole: CVRCrewRole = .unknown
    @State private var crewValidationMessage = ""
    // Retained for decoding/edit compatibility in legacy helper paths; the UI uses fixed positions.
    @State private var selectedCrewUserID = 0
    @State private var selectedCrewRole: CVRCrewRole = .student
    @State private var selectedPilotFunction: CVRPilotFunction = .none
    @State private var selectedIsPIC = false
    @State private var showCrewUserList = false
    @State private var editingCrewAssignmentID: String?
    @FocusState private var focusedNumericField: NumericField?

    private enum CrewUserKind {
        case student
        case instructor
        case other
    }

    private enum NumericField: Hashable {
        case hobbs
        case tacho
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    switch focus {
                    case .crew:
                        VStack(alignment: .leading, spacing: 14) {
                            if workflow.isReservationCrewLocked {
                                CVROperationalWarningCard(
                                    title: "RESERVATION CREW LOCKED",
                                    message: "This reservation keeps one accountable crew and PF/PM/PIC assignment. A material role change requires a new reservation and cannot occur while the engine session is active.",
                                    iconName: "lock.fill",
                                    color: CVROperationalPalette.warning
                                )
                            }
                            fixedCrewPositionEditor
                                .disabled(workflow.isReservationCrewLocked)
                                .opacity(workflow.isReservationCrewLocked ? 0.65 : 1)
                            if !crewValidationMessage.isEmpty {
                                Text(crewValidationMessage)
                                    .font(.caption.weight(.bold))
                                    .foregroundStyle(CVROperationalPalette.critical)
                            }
                        }
                    case .meters:
                        editorSection("METERS") {
                            if workflow.state.activeDispatch?.dispatchSource == "verified_previous_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "VERIFIED PREVIOUS FLIGHT VALUES",
                                    message: "Starting Hobbs and Starting Tacho were prefilled from the latest locally archived flight for this aircraft after all server receipts were verified. Confirm the physical indications before dispatch.",
                                    iconName: "checkmark.icloud.fill",
                                    color: CVROperationalPalette.success
                                )
                            } else if workflow.state.activeDispatch?.dispatchSource == "previous_locally_closed_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "PREVIOUS FLIGHT VALUES SAVED ON THIS IPHONE",
                                    message: "Starting Hobbs and Starting Tacho were carried forward from the previous completed flight. Confirm the physical indications before dispatch.",
                                    iconName: "iphone.and.arrow.forward",
                                    color: CVROperationalPalette.secondaryBlue
                                )
                            }
                            HStack(spacing: 10) {
                                largeMeterField("TACHO", text: $startingTacho, field: .tacho)
                                largeMeterField("HOBBS", text: $startingHobbs, field: .hobbs)
                            }
                            if !meterContinuityMessages.isEmpty {
                                continuityBanner(meterContinuityMessages)
                            }
                        }
                    case .fuelOil:
                        editorSection("FUEL / OIL") {
                            if workflow.state.activeDispatch?.dispatchSource == "verified_previous_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "VERIFIED PREVIOUS FLIGHT VALUES",
                                    message: "Fuel and oil were prefilled from the latest locally archived flight for this aircraft after all server receipts were verified. Confirm the physical indications before dispatch.",
                                    iconName: "checkmark.icloud.fill",
                                    color: CVROperationalPalette.success
                                )
                            } else if workflow.state.activeDispatch?.dispatchSource == "previous_locally_closed_flight_carryover" {
                                CVROperationalWarningCard(
                                    title: "PREVIOUS FLIGHT VALUES SAVED ON THIS IPHONE",
                                    message: "Fuel and oil were carried forward from the previous completed flight. Confirm the physical indications before dispatch.",
                                    iconName: "iphone.and.arrow.forward",
                                    color: CVROperationalPalette.secondaryBlue
                                )
                            }
                            HStack(alignment: .top, spacing: 10) {
                                Spacer(minLength: 0)
                                CVRFluidCylinderPicker(
                                    title: "FUEL",
                                    unit: operationalConfig.fuelUnit,
                                    value: $fuelGallons,
                                    hasSelection: $hasFuelSelection,
                                    maxValue: operationalConfig.fuelCapacity,
                                    warningThreshold: operationalConfig.fuelCapacity * (3.0 / 13.0),
                                    fillColor: CVROperationalPalette.success,
                                    warningColor: CVROperationalPalette.critical
                                )
                                .frame(width: 132)
                                CVRFluidCylinderPicker(
                                    title: "OIL",
                                    unit: operationalConfig.oilUnit,
                                    value: $oilPercent,
                                    hasSelection: $hasOilSelection,
                                    maxValue: operationalConfig.oilCapacity,
                                    warningThreshold: nil,
                                    fillColor: CVROperationalPalette.standby,
                                    warningColor: CVROperationalPalette.standby,
                                    stepIncrement: max(operationalConfig.oilCapacity / 10, 0.1)
                                )
                                .frame(width: 132)
                                Spacer(minLength: 0)
                            }
                            if !fuelOilContinuityMessages.isEmpty {
                                continuityBanner(fuelOilContinuityMessages)
                            }
                            if requiresRefuelConfirmation || workflow.dispatchContinuityUploadIssue() == .refueling {
                                operationalToggle("Confirm the airplane was refueled before this flight", isOn: $refueledSincePreviousFlight)
                            }
                            if requiresOilServiceConfirmation || workflow.dispatchContinuityUploadIssue() == .oilServicing {
                                operationalToggle("Oil was serviced before this flight", isOn: $oilServicedSincePreviousFlight)
                            }
                        }
                    }
                }
                .padding(16)
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle(focus.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") {
                        if save() {
                            if workflow.canRepairFailedDispatchUpload {
                                uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
                            }
                            dismiss()
                        }
                    }
                }
                ToolbarItemGroup(placement: .keyboard) {
                    Spacer()
                    Button("Done") {
                        focusedNumericField = nil
                    }
                    .font(.headline.weight(.bold))
                }
            }
        }
        .preferredColorScheme(.dark)
        .onAppear {
            load()
        }
    }

    private func continuityBanner(_ messages: [String]) -> some View {
        VStack(alignment: .leading, spacing: 7) {
            ForEach(messages, id: \.self) { message in
                Text(message)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.warning)
            }
        }
        .padding(10)
        .background(CVROperationalPalette.warning.opacity(0.12), in: RoundedRectangle(cornerRadius: 10))
    }

    private var fixedCrewPositionEditor: some View {
        VStack(alignment: .leading, spacing: 22) {
            crewPositionPanel("CUSTOMER") {
                cockpitMenu(customerDisplayName) {
                    Button("Select Customer") { customerUserID = 0 }
                    ForEach(studentCrewUsers) { user in
                        Button(user.displayName) { customerUserID = user.id }
                    }
                }
                picCheckbox(isOn: $customerIsPIC)
            }

            crewPositionPanel("PERSON 2 (OPTIONAL)") {
                cockpitMenu(personTwoDisplayName) {
                    Button("No second pilot") { personTwoUserID = 0 }
                    ForEach(operationalCrewUsers) { user in
                        Button(accountDisplayName(user)) { personTwoUserID = user.id }
                    }
                }
                cockpitMenu(personTwoRole == .unknown ? "Select role" : personTwoRole.label) {
                    Button("Select role") { personTwoRole = .unknown }
                    Button("Instructor") { personTwoRole = .instructor }
                    Button("Pilot Monitoring") { personTwoRole = .pilotMonitoring }
                    Button("Safety Pilot") { personTwoRole = .safetyPilot }
                    Button("Examiner") { personTwoRole = .examiner }
                }
                picCheckbox(isOn: $personTwoIsPIC)
            }

            crewPositionPanel("PERSON 3 (OPTIONAL)") {
                cockpitMenu(personThreeDisplayName) {
                    Button("No supervisor or observer") { personThreeUserID = 0 }
                    ForEach(operationalCrewUsers) { user in
                        Button(accountDisplayName(user)) { personThreeUserID = user.id }
                    }
                }
                cockpitMenu(personThreeRole == .unknown ? "Select role" : personThreeRole.label) {
                    Button("Select role") { personThreeRole = .unknown }
                    Button("Supervising Instructor") { personThreeRole = .supervisingInstructor }
                    Button("Observer") { personThreeRole = .observer }
                }
            }
        }
    }

    private func crewPositionPanel<Content: View>(
        _ title: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            content()
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.black.opacity(0.18), in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func cockpitMenu<Content: View>(
        _ value: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        Menu {
            content()
        } label: {
            HStack(spacing: 12) {
                Text(value)
                    .font(.body.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(2)
                    .multilineTextAlignment(.leading)
                Spacer(minLength: 12)
                Image(systemName: "chevron.up.chevron.down")
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
            }
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity, minHeight: 56, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.secondaryBlue.opacity(0.45), lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }

    private func picCheckbox(isOn: Binding<Bool>) -> some View {
        Button {
            isOn.wrappedValue.toggle()
        } label: {
            HStack(spacing: 10) {
                Image(systemName: isOn.wrappedValue ? "checkmark.square.fill" : "square")
                    .font(.title3)
                Text("PIC")
                    .font(.subheadline.weight(.bold))
            }
            .foregroundStyle(isOn.wrappedValue ? CVROperationalPalette.success : CVROperationalPalette.textPrimary)
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity, minHeight: 52, alignment: .leading)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
            .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }

    private var customerDisplayName: String {
        studentCrewUsers.first(where: { $0.id == customerUserID })?.displayName ?? "Select Customer"
    }

    private var personTwoDisplayName: String {
        operationalCrewUsers.first(where: { $0.id == personTwoUserID }).map(accountDisplayName)
            ?? "No second pilot"
    }

    private var personThreeDisplayName: String {
        operationalCrewUsers.first(where: { $0.id == personThreeUserID }).map(accountDisplayName)
            ?? "No supervisor or observer"
    }

    private var studentCrewUsers: [CVRCrewUser] {
        settings.crewUsers.filter {
            $0.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == "student"
        }
    }

    private var operationalCrewUsers: [CVRCrewUser] {
        settings.crewUsers.filter {
            $0.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() != "admin"
        }
    }

    private func accountDisplayName(_ user: CVRCrewUser) -> String {
        let role = user.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let isInstructor = role.contains("instructor") || role.contains("supervisor")
        return isInstructor ? "\(user.displayName) (Instructor)" : user.displayName
    }

    @ViewBuilder
    private var crewUserPicker: some View {
        if settings.crewUsers.isEmpty {
            CVROperationalWarningCard(
                title: "NO USERS LOADED",
                message: settings.crewUsersError.isEmpty ? "Refresh users when online." : settings.crewUsersError,
                iconName: "person.crop.circle.badge.exclamationmark",
                color: CVROperationalPalette.warning
            )
        } else {
            VStack(spacing: 8) {
                Button {
                    showCrewUserList.toggle()
                } label: {
                    HStack {
                        Text(selectedCrewUser?.displayName ?? "Select IPCA User")
                            .font(.subheadline.weight(.bold))
                            .foregroundStyle(selectedCrewUser.map(crewUserColor) ?? CVROperationalPalette.textPrimary)
                            .lineLimit(1)
                        Spacer()
                        Image(systemName: showCrewUserList ? "chevron.up" : "chevron.down")
                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    }
                    .padding(10)
                    .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
                    .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                }
                .buttonStyle(.plain)

                if showCrewUserList {
                    ScrollView {
                        VStack(spacing: 6) {
                            ForEach(settings.crewUsers) { user in
                                Button {
                                    selectCrewUser(user)
                                } label: {
                                    HStack {
                                        Text(user.displayName)
                                            .font(.caption.weight(.bold))
                                            .foregroundStyle(crewUserColor(user))
                                            .lineLimit(1)
                                        Spacer()
                                        if selectedCrewUserID == user.id {
                                            Image(systemName: "checkmark.circle.fill")
                                                .foregroundStyle(crewUserColor(user))
                                        }
                                    }
                                    .padding(.horizontal, 10)
                                    .padding(.vertical, 8)
                                    .background(Color.black.opacity(0.14), in: RoundedRectangle(cornerRadius: 10))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .frame(maxHeight: 240)
                }
            }
        }
    }

    private var roleButtons: some View {
        LazyVGrid(columns: [GridItem(.adaptive(minimum: 128), spacing: 8)], spacing: 8) {
            ForEach(roleOptions) { role in
                let allowed = isRoleAllowed(role)
                Button {
                    if allowed {
                        selectedCrewRole = role
                    }
                } label: {
                    Text(role.label.uppercased())
                        .font(.caption.weight(.bold))
                        .foregroundStyle(roleTextColor(role, allowed: allowed))
                        .frame(maxWidth: .infinity, minHeight: 44)
                        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                        .overlay(RoundedRectangle(cornerRadius: 14).stroke((selectedCrewRole == role && allowed ? CVROperationalPalette.success : CVROperationalPalette.cardBorder), lineWidth: 1))
                }
                .buttonStyle(.plain)
                .disabled(!allowed)
            }
        }
    }

    private var pilotFunctionButtons: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("PILOT FUNCTION")
                .font(.caption2.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            LazyVGrid(columns: [GridItem(.adaptive(minimum: 92), spacing: 8)], spacing: 8) {
                ForEach(CVRPilotFunction.allCases) { function in
                    Button {
                        selectedPilotFunction = function
                    } label: {
                        Text(function.label.uppercased())
                            .font(.caption.weight(.bold))
                            .foregroundStyle(
                                selectedPilotFunction == function
                                    ? CVROperationalPalette.success
                                    : CVROperationalPalette.textPrimary
                            )
                            .frame(maxWidth: .infinity, minHeight: 40)
                            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                            .overlay(RoundedRectangle(cornerRadius: 12).stroke(
                                selectedPilotFunction == function
                                    ? CVROperationalPalette.success
                                    : CVROperationalPalette.cardBorder,
                                lineWidth: 1
                            ))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    private var picResponsibilityButton: some View {
        Toggle(isOn: $selectedIsPIC) {
            VStack(alignment: .leading, spacing: 2) {
                Text("PIC RESPONSIBILITY")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                Text("Independent from PF/PM")
                    .font(.caption2)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
        }
        .tint(CVROperationalPalette.success)
        .padding(10)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
    }

    private var selectedDutyLabel: String {
        var labels = [selectedCrewRole.label]
        if selectedPilotFunction != .none {
            labels.append(selectedPilotFunction.label)
        }
        if selectedIsPIC {
            labels.append("PIC")
        }
        return labels.joined(separator: " / ")
    }

    private func dutyLabel(for assignment: CVRCrewAssignment) -> String {
        var labels = [assignment.role.label]
        if assignment.effectivePilotFunction != .none {
            labels.append(assignment.effectivePilotFunction.label)
        }
        if assignment.hasPICResponsibility {
            labels.append("PIC")
        }
        return labels.joined(separator: " / ")
    }

    private func editorSection<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.2)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            content()
        }
        .padding(14)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func darkTextField(_ placeholder: String, text: Binding<String>) -> some View {
        TextField(placeholder, text: text)
            .textInputAutocapitalization(.characters)
            .autocorrectionDisabled()
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(CVROperationalPalette.textPrimary)
            .padding(10)
            .background(Color.black.opacity(0.22), in: RoundedRectangle(cornerRadius: 10))
            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }

    private func largeMeterField(_ title: String, text: Binding<String>, field: NumericField) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.0)
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            TextField(title, text: text)
                .keyboardType(.decimalPad)
                .focused($focusedNumericField, equals: field)
                .font(.system(size: 34, weight: .bold, design: .rounded).monospacedDigit())
                .padding(.vertical, 14)
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 72, alignment: .leading)
                .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
                .overlay(RoundedRectangle(cornerRadius: 14).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .frame(maxWidth: .infinity)
    }

    private func load() {
        guard let dispatch = workflow.state.activeDispatch else { return }
        let customer = dispatch.crew.first {
            $0.role == .student && $0.effectivePilotFunction == .pilotFlying
        }
        customerUserID = customer?.personID ?? 0
        customerIsPIC = customer?.hasPICResponsibility ?? false
        let personTwo = dispatch.crew.first {
            [.instructor, .pilotMonitoring, .safetyPilot, .examiner].contains($0.role)
                && $0.effectivePilotFunction == .pilotMonitoring
        }
        personTwoUserID = personTwo?.personID ?? 0
        personTwoRole = personTwo?.role ?? .unknown
        personTwoIsPIC = personTwo?.hasPICResponsibility ?? false
        let personThree = dispatch.crew.first {
            [.supervisingInstructor, .observer].contains($0.role)
        }
        personThreeUserID = personThree?.personID ?? 0
        personThreeRole = personThree?.role ?? .unknown
        startingHobbs = dispatch.startingHobbs.map { String(format: "%.1f", $0) } ?? ""
        startingTacho = dispatch.startingTacho.map { String(format: "%.1f", $0) } ?? ""
        if let fuel = Self.quantity(from: dispatch.fuelOnboard, unit: operationalConfig.fuelUnit) {
            fuelGallons = min(max(fuel, 0), operationalConfig.fuelCapacity)
            hasFuelSelection = true
        } else {
            fuelGallons = 0
            hasFuelSelection = false
        }
        if let oil = dispatch.effectiveStartingOilQuantity {
            oilPercent = min(max(oil, 0), operationalConfig.oilCapacity)
            hasOilSelection = true
        } else {
            oilPercent = operationalConfig.oilCapacity / 2
            hasOilSelection = true
        }
        refueledSincePreviousFlight = dispatch.refueledSincePreviousFlight ?? false
        oilServicedSincePreviousFlight = dispatch.oilServicedSincePreviousFlight ?? false
    }

    private func save() -> Bool {
        let applyChanges = { (dispatch: inout CVRDispatchRecord) in
            switch focus {
            case .crew:
                break
            case .meters:
                dispatch.startingHobbs = Double(startingHobbs)
                dispatch.startingTacho = Double(startingTacho)
            case .fuelOil:
                dispatch.fuelOnboard = hasFuelSelection ? Self.quantityText(fuelGallons) : ""
                dispatch.startingOilQuantity = hasOilSelection ? oilPercent : nil
                dispatch.startingOilUnit = hasOilSelection ? operationalConfig.oilUnit : nil
                dispatch.oilPercentage = hasOilSelection && operationalConfig.oilUnit == "%" ? Int(oilPercent.rounded()) : nil
                // Uplift/service acknowledgment is explicit. A full gauge alone
                // does not prove that fuel or oil was added before this flight.
                dispatch.refueledSincePreviousFlight = refueledSincePreviousFlight
                dispatch.oilServicedSincePreviousFlight = oilServicedSincePreviousFlight
            }
        }
        if focus == .crew {
            return saveFixedCrewPositions()
        }
        if workflow.canRepairFailedDispatchUpload {
            _ = workflow.updateActiveDispatchForUploadRepair(applyChanges)
        } else {
            workflow.updateActiveDispatch(applyChanges)
        }
        return true
    }

    private var requiresRefuelConfirmation: Bool {
        guard let dispatch = workflow.state.activeDispatch,
              let previous = dispatch.previousFuelRemaining.flatMap({ Self.quantity(from: $0, unit: operationalConfig.fuelUnit) }),
              hasFuelSelection,
              fuelGallons > previous else { return false }
        return Self.relativeDifference(fuelGallons, previous) > 0.20
    }

    private var requiresOilServiceConfirmation: Bool {
        guard let previous = workflow.state.activeDispatch?.effectivePreviousOilQuantity,
              hasOilSelection,
              oilPercent > previous else { return false }
        return Self.relativeDifference(oilPercent, previous) > 0.20
    }

    private var meterContinuityMessages: [String] {
        guard let dispatch = workflow.state.activeDispatch else { return [] }
        var messages: [String] = []
        if let expected = dispatch.previousEndingHobbs,
           let actual = Double(startingHobbs),
           abs(actual - expected) > 0.1 {
            messages.append(String(format: "Hobbs discrepancy: previous ending value was %.1f.", expected))
        }
        if let expected = dispatch.previousEndingTacho,
           let actual = Double(startingTacho),
           abs(actual - expected) > 0.1 {
            messages.append(String(format: "Tacho discrepancy: previous ending value was %.1f.", expected))
        }
        return messages
    }

    private var fuelOilContinuityMessages: [String] {
        guard let dispatch = workflow.state.activeDispatch else { return [] }
        var messages: [String] = []
        if let previous = dispatch.previousFuelRemaining.flatMap({ Self.quantity(from: $0, unit: operationalConfig.fuelUnit) }),
           hasFuelSelection,
           Self.relativeDifference(fuelGallons, previous) > 0.20 {
            messages.append(fuelGallons > previous
                ? "Fuel differs by more than 20%. Confirm refueling."
                : "Fuel is more than 20% below the previous ending value; refueling cannot explain this.")
        }
        if let previous = dispatch.effectivePreviousOilQuantity,
           hasOilSelection,
           Self.relativeDifference(oilPercent, previous) > 0.20 {
            messages.append(oilPercent > previous
                ? "Oil differs by more than 20%. Confirm oil servicing."
                : "Oil is more than 20% below the previous ending value; servicing cannot explain this.")
        }
        return messages
    }

    private static func relativeDifference(_ lhs: Double, _ rhs: Double) -> Double {
        abs(lhs - rhs) / max(abs(rhs), 0.1)
    }

    private func saveFixedCrewPositions() -> Bool {
        crewValidationMessage = ""
        guard !workflow.isReservationCrewLocked else {
            crewValidationMessage = "This reservation crew is locked."
            return false
        }
        guard let customer = crewUser(id: customerUserID),
              customer.role.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == "student" else {
            crewValidationMessage = "Select the Customer from the Student accounts."
            return false
        }
        if personTwoUserID > 0, personTwoRole == .unknown {
            crewValidationMessage = "Select the role for Person 2."
            return false
        }
        if personThreeUserID > 0, personThreeRole == .unknown {
            crewValidationMessage = "Select the role for Person 3."
            return false
        }
        let selectedIDs = [customerUserID, personTwoUserID, personThreeUserID].filter { $0 > 0 }
        guard Set(selectedIDs).count == selectedIDs.count else {
            crewValidationMessage = "Each position must use a different user account."
            return false
        }
        let picCount = (customerIsPIC ? 1 : 0) + (personTwoUserID > 0 && personTwoIsPIC ? 1 : 0)
        guard (1...2).contains(picCount) else {
            crewValidationMessage = "Select one or two pilots logging PIC."
            return false
        }

        let existing = workflow.state.activeDispatch?.crew ?? []
        var crew = [
            CVRCrewAssignment(
                id: existing.first(where: {
                    $0.role == .student && $0.effectivePilotFunction == .pilotFlying
                })?.id ?? UUID().uuidString,
                personID: customer.id,
                personName: customer.displayName,
                role: .student,
                pilotFunction: .pilotFlying,
                isPIC: customerIsPIC
            )
        ]
        if let personTwo = crewUser(id: personTwoUserID), personTwoRole != .unknown {
            crew.append(CVRCrewAssignment(
                id: existing.first(where: {
                    [.instructor, .pilotMonitoring, .safetyPilot, .examiner].contains($0.role)
                        && $0.effectivePilotFunction == .pilotMonitoring
                })?.id ?? UUID().uuidString,
                personID: personTwo.id,
                personName: personTwo.displayName,
                role: personTwoRole,
                pilotFunction: .pilotMonitoring,
                isPIC: personTwoIsPIC
            ))
        }
        if let personThree = crewUser(id: personThreeUserID), personThreeRole != .unknown {
            crew.append(CVRCrewAssignment(
                id: existing.first(where: {
                    [.supervisingInstructor, .observer].contains($0.role)
                })?.id ?? UUID().uuidString,
                personID: personThree.id,
                personName: personThree.displayName,
                role: personThreeRole,
                pilotFunction: .none,
                isPIC: false
            ))
        }
        workflow.updateActiveDispatch { dispatch in
            dispatch.crew = crew
        }
        uploadManager.uploadQueuedWorkflowComponents(workflow: workflow, settings: settings)
        return true
    }

    private func crewUser(id: Int) -> CVRCrewUser? {
        guard id > 0 else { return nil }
        return settings.crewUsers.first { $0.id == id }
    }

    private func addCrew() {
        guard let user = selectedCrewUser, isRoleAllowed(selectedCrewRole) else { return }
        workflow.updateActiveDispatch { dispatch in
            if let editingCrewAssignmentID {
                dispatch.crew.removeAll { $0.id == editingCrewAssignmentID }
            }
            dispatch.crew.removeAll { $0.personID == user.id && $0.role == selectedCrewRole }
            dispatch.crew.append(CVRCrewAssignment(
                id: UUID().uuidString,
                personID: user.id,
                personName: user.displayName,
                role: selectedCrewRole,
                pilotFunction: selectedPilotFunction,
                isPIC: selectedIsPIC
            ))
        }
        selectedCrewUserID = 0
        editingCrewAssignmentID = nil
        showCrewUserList = false
    }

    private func editCrew(_ assignment: CVRCrewAssignment) {
        editingCrewAssignmentID = assignment.id
        selectedCrewUserID = assignment.personID ?? settings.crewUsers.first(where: { $0.displayName == assignment.personName })?.id ?? 0
        selectedCrewRole = assignment.role
        selectedPilotFunction = assignment.effectivePilotFunction
        selectedIsPIC = assignment.hasPICResponsibility
        showCrewUserList = false
    }

    private func deleteCrew(_ assignment: CVRCrewAssignment) {
        workflow.updateActiveDispatch { dispatch in
            dispatch.crew.removeAll { $0.id == assignment.id }
        }
        if editingCrewAssignmentID == assignment.id {
            editingCrewAssignmentID = nil
            selectedCrewUserID = 0
        }
    }

    private var selectedCrewUser: CVRCrewUser? {
        settings.crewUsers.first { $0.id == selectedCrewUserID }
    }

    private var roleOptions: [CVRCrewRole] {
        [.student, .instructor, .supervisingInstructor, .examiner, .safetyPilot, .observer]
    }

    private func selectCrewUser(_ user: CVRCrewUser) {
        selectedCrewUserID = user.id
        showCrewUserList = false
        if !isRoleAllowed(selectedCrewRole, for: user) {
            switch crewUserKind(user) {
            case .student:
                selectedCrewRole = .student
            case .instructor:
                selectedCrewRole = .instructor
            case .other:
                selectedCrewRole = .pic
            }
        }
    }

    private func isRoleAllowed(_ role: CVRCrewRole) -> Bool {
        guard let user = selectedCrewUser else { return true }
        return isRoleAllowed(role, for: user)
    }

    private func isRoleAllowed(_ role: CVRCrewRole, for user: CVRCrewUser) -> Bool {
        switch crewUserKind(user) {
        case .student:
            return ![.instructor, .supervisingInstructor, .examiner].contains(role)
        case .instructor:
            return role != .student
        case .other:
            return true
        }
    }

    private func roleTextColor(_ role: CVRCrewRole, allowed: Bool) -> Color {
        guard allowed else { return CVROperationalPalette.textSecondary.opacity(0.45) }
        return selectedCrewRole == role ? CVROperationalPalette.success : CVROperationalPalette.textPrimary
    }

    private func crewUserColor(_ user: CVRCrewUser) -> Color {
        switch crewUserKind(user) {
        case .student:
            return CVROperationalPalette.success
        case .instructor:
            return CVROperationalPalette.secondaryBlue
        case .other:
            return CVROperationalPalette.textPrimary
        }
    }

    private func crewUserKind(_ user: CVRCrewUser) -> CrewUserKind {
        let role = user.role.lowercased()
        if role.contains("student") {
            return .student
        }
        if role.contains("instructor") || role.contains("supervisor") {
            return .instructor
        }
        return .other
    }

    private var operationalConfig: AircraftOperationalConfig {
        settings.selectedAircraft?.operationalConfig ?? .safeDefaults
    }

    private static func quantity(from value: String, unit: String) -> Double? {
        let cleaned = value
            .replacingOccurrences(of: unit, with: "", options: .caseInsensitive)
            .replacingOccurrences(of: "USG", with: "", options: .caseInsensitive)
            .trimmingCharacters(in: .whitespacesAndNewlines)
        return Double(cleaned)
    }

    private static func quantityText(_ value: Double) -> String {
        String(format: "%.1f", value)
    }
}

/// Scrollable mission list — replaces SwiftUI `Menu`, which blinks and fails to scroll
/// reliably with a large catalogue inside ScrollView/List parents.
private struct CVRMissionPickerSheet: View {
    @Environment(\.dismiss) private var dismiss

    let missions: [CVRMissionCatalogEntry]
    let selectedMissionCode: String
    let titleProvider: (CVRMissionCatalogEntry) -> String
    let onSelect: (String) -> Void

    @State private var query = ""

    private var filteredMissions: [CVRMissionCatalogEntry] {
        let trimmed = query.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return missions }
        return missions.filter {
            titleProvider($0).localizedCaseInsensitiveContains(trimmed)
                || $0.missionCode.localizedCaseInsensitiveContains(trimmed)
                || $0.missionDescription.localizedCaseInsensitiveContains(trimmed)
        }
    }

    var body: some View {
        NavigationStack {
            Group {
                if missions.isEmpty {
                    ContentUnavailableView(
                        "No Flight Missions",
                        systemImage: "list.bullet.rectangle",
                        description: Text("No flight missions are available on this device.")
                    )
                } else {
                    List {
                        ForEach(filteredMissions) { mission in
                            Button {
                                onSelect(mission.missionCode)
                                dismiss()
                            } label: {
                                HStack(alignment: .top, spacing: 12) {
                                    VStack(alignment: .leading, spacing: 4) {
                                        Text(titleProvider(mission))
                                            .font(.subheadline.weight(.semibold))
                                            .foregroundStyle(CVROperationalPalette.textPrimary)
                                            .multilineTextAlignment(.leading)
                                    }
                                    Spacer(minLength: 8)
                                    if mission.missionCode.caseInsensitiveCompare(selectedMissionCode) == .orderedSame {
                                        Image(systemName: "checkmark")
                                            .font(.body.weight(.bold))
                                            .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                    }
                                }
                                .padding(.vertical, 4)
                            }
                            .buttonStyle(.plain)
                            .listRowBackground(CVROperationalPalette.cardBackground)
                        }
                    }
                    .listStyle(.plain)
                    .scrollContentBackground(.hidden)
                    .searchable(text: $query, prompt: "Search missions")
                }
            }
            .background(CVROperationalPalette.background.ignoresSafeArea())
            .navigationTitle("Select Flight Mission")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .preferredColorScheme(.dark)
        }
    }
}

private struct CVRFluidCylinderPicker: View {
    let title: String
    let unit: String
    @Binding var value: Double
    @Binding var hasSelection: Bool
    let maxValue: Double
    let warningThreshold: Double?
    let fillColor: Color
    let warningColor: Color
    var stepIncrement: Double = 0

    private var normalizedLevel: Double {
        guard maxValue > 0 else { return 0 }
        return min(max(value / maxValue, 0), 1)
    }

    private var activeColor: Color {
        if let warningThreshold, hasSelection, value <= warningThreshold {
            return warningColor
        }
        return fillColor
    }

    private var displayText: String {
        guard hasSelection else { return "SET" }
        if unit == "%" {
            return "\(Int(value.rounded()))%"
        }
        return "\(String(format: "%.1f", value)) \(unit)"
    }

    var body: some View {
        VStack(spacing: 8) {
            Text(displayText)
                .font(.system(size: displayText == "SET" ? 21 : 26, weight: .bold, design: .rounded))
                .foregroundStyle(activeColor)
                .lineLimit(1)
                .minimumScaleFactor(0.75)
                .frame(height: 32)
            ZStack(alignment: .bottom) {
                GeometryReader { proxy in
                    let cylinderHeight = proxy.size.height
                    let fillHeight = cylinderHeight * normalizedLevel
                    ZStack(alignment: .bottom) {
                        Rectangle()
                            .stroke(CVROperationalPalette.cardBorder, lineWidth: 1)
                            .background(Color.black.opacity(0.18))
                        Rectangle()
                            .fill(activeColor.opacity(0.78))
                            .frame(height: fillHeight)
                    }
                    .contentShape(Rectangle())
                    .gesture(
                        DragGesture(minimumDistance: 0)
                            .onChanged { gesture in
                                let y = min(max(gesture.location.y, 0), cylinderHeight)
                                let selected = (1 - y / cylinderHeight) * maxValue
                                let defaultStep = unit == "%" ? 1.0 : 0.1
                                let step = stepIncrement > 0 ? stepIncrement : defaultStep
                                let stepped = (selected / step).rounded() * step
                                value = min(max(stepped, 0), maxValue)
                                hasSelection = true
                            }
                    )
                }
            }
            .frame(height: 190)
            Text(title)
                .font(.caption.weight(.bold))
                .tracking(1.1)
                .foregroundStyle(CVROperationalPalette.textSecondary)
        }
        .frame(maxWidth: .infinity)
        .padding(12)
        .background(Color.black.opacity(0.18), in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

private struct SimulationModeChrome: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var workflow: CVRWorkflowStore
    @EnvironmentObject private var beacon: AvionicsBeaconManager
    @EnvironmentObject private var gps: GPSLocationManager

    var body: some View {
        VStack(spacing: 0) {
            simulationControls
            simulationBanner
        }
        .background(CVROperationalPalette.background.opacity(0.98))
    }

    private var simulationControls: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                simButton("Avionics ON", icon: "bolt.fill") {
                    beacon.simulateAvionicsOn()
                }
                simButton("Avionics OFF", icon: "bolt.slash.fill") {
                    beacon.simulateAvionicsOff()
                }
                simButton("Takeoff", icon: "airplane.departure") {
                    let kind: TakeoffCycleKind
                    if let flightRecord = workflow.state.activeFlightRecord {
                        let counts = workflow.operationCounts(for: flightRecord.id)
                        kind = counts.displayTakeoffs <= counts.displayLandings ? .initial : .cycle
                    } else {
                        kind = .initial
                    }
                    injectTransition(.takeoff(
                        timestamp: Date(),
                        sample: sample(),
                        kind: kind,
                        airportIdentifier: settings.selectedAircraft?.homeAirport
                    ))
                }
                simButton("T&G Land", icon: "airplane.arrival") {
                    injectTransition(.landing(
                        timestamp: Date(),
                        sample: sample(),
                        kind: .touchAndGo,
                        airportIdentifier: settings.selectedAircraft?.homeAirport
                    ))
                }
                simButton("Full Stop", icon: "parkingsign.circle.fill") {
                    injectTransition(.landing(
                        timestamp: Date(),
                        sample: sample(),
                        kind: .fullStop,
                        airportIdentifier: settings.selectedAircraft?.homeAirport
                    ))
                }
                simButton("Finish Demo", icon: "flag.checkered") {
                    completeSimulationDemo(workflow: workflow, settings: settings, beacon: beacon)
                }
                simButton("Reset", icon: "arrow.counterclockwise") {
                    workflow.resetSimulationWorkflow {
                        beacon.clearSimulationOverride()
                    }
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
        }
    }

    private var simulationBanner: some View {
        HStack(spacing: 8) {
            Image(systemName: "play.rectangle.on.rectangle")
                .font(.caption.weight(.bold))
            Text("SIMULATION MODE")
                .font(.caption.weight(.bold))
                .tracking(1.1)
            Spacer(minLength: 0)
            Text("No logging · No uploads")
                .font(.caption2.weight(.semibold))
        }
        .foregroundStyle(Color.black.opacity(0.92))
        .padding(.horizontal, 14)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity)
        .background(CVROperationalPalette.warning)
    }

    private func simButton(_ title: String, icon: String, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Label(title, systemImage: icon)
                .font(.caption.weight(.bold))
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(CVROperationalPalette.cardBackground, in: Capsule())
                .overlay(Capsule().stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
        .buttonStyle(.plain)
        .foregroundStyle(CVROperationalPalette.textPrimary)
    }

    private func injectTransition(_ transition: GPSFlightTransition) {
        guard workflow.state.activeFlightRecord != nil else { return }
        gps.injectSimulatedTransition(transition)
    }

    private func sample() -> GPSSample {
        if let latest = gps.latestSample {
            return latest
        }
        return GPSSample(
            timestamp: Date(),
            secondsSinceRecordingStart: 0,
            latitude: 33.626667,
            longitude: -116.159722,
            altitude: 120,
            speedMetersPerSecond: 25,
            speedKnots: 48.6,
            course: 0,
            horizontalAccuracy: 8,
            verticalAccuracy: 12
        )
    }
}

private extension String {
    var nilIfEmpty: String? {
        let value = trimmingCharacters(in: .whitespacesAndNewlines)
        return value.isEmpty ? nil : value
    }
}
