import SwiftUI

/// Log-row entry point: shown on flights that either have no linked Garmin CSV yet, or have
/// a pending Garmin import that needs attention (failed / unresolved). Opens the coordinator's
/// import sheet scoped to this specific flight.
struct GarminSDCardImportEntryButton: View {
    @ObservedObject var coordinator: GarminSDCardImportCoordinator
    @EnvironmentObject private var settings: SettingsStore
    var entry: CVRFlightLogEntry

    var body: some View {
        Button {
            coordinator.openFromLogRow(entry: entry, settings: settings)
        } label: {
            Label("SD CARD", systemImage: "sdcard")
        }
        .accessibilityLabel("Import Garmin CSV from SD card")
    }
}

/// Main "Garmin SD Card" sheet: setup / unavailable / empty-folder dialogs, plus the scanned
/// candidate list with filters once a folder is configured and available.
struct GarminSDCardImportSheet: View {
    @ObservedObject var coordinator: GarminSDCardImportCoordinator
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var flightLogs: CVRFlightLogStore
    @EnvironmentObject private var network: NetworkMonitor
    @EnvironmentObject private var uploadManager: UploadManager

    @State private var isShowingFolderPicker = false
    @State private var isShowingClearConfirmation = false

    var body: some View {
        NavigationStack {
            ZStack {
                CVROperationalPalette.background.ignoresSafeArea()
                content
            }
            .navigationTitle("Garmin SD Card")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { coordinator.showingFileSheet = false }
                }
                ToolbarItem(placement: .primaryAction) {
                    Menu {
                        Button("Change Folder…") { isShowingFolderPicker = true }
                        Button("Clear Folder", role: .destructive) { isShowingClearConfirmation = true }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                    .disabled(!settings.hasGarminSDCardFolderConfigured && coordinator.accessState == .notConfigured)
                }
            }
        }
        .preferredColorScheme(.dark)
        .task {
            await coordinator.probe(settings: settings)
            if coordinator.accessState == .available {
                await coordinator.refreshCandidates(
                    settings: settings,
                    flightLogs: flightLogs,
                    network: network,
                    uploadManager: uploadManager
                )
            }
        }
        .sheet(isPresented: $isShowingFolderPicker) {
            GarminSDCardFolderPicker(
                onPick: { url in
                    isShowingFolderPicker = false
                    coordinator.selectFolder(url, settings: settings)
                    Task {
                        await coordinator.refreshCandidates(
                            settings: settings,
                            flightLogs: flightLogs,
                            network: network,
                            uploadManager: uploadManager
                        )
                    }
                },
                onCancel: { isShowingFolderPicker = false }
            )
        }
        .confirmationDialog(
            "Clear the configured Garmin folder?",
            isPresented: $isShowingClearConfirmation,
            titleVisibility: .visible
        ) {
            Button("Clear Folder", role: .destructive) {
                coordinator.clearFolder(settings: settings)
            }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("IPCA will forget this folder. Select it again before importing more Garmin CSV files.")
        }
        .alert(
            coordinator.accessState == .accessNeedsRestoration ? "Access Needs Restoration" : "Garmin Folder Unavailable",
            isPresented: $coordinator.showingUnavailableAlert
        ) {
            Button("Re-select Folder") {
                coordinator.showingUnavailableAlert = false
                isShowingFolderPicker = true
            }
            Button("Cancel", role: .cancel) {
                coordinator.showingUnavailableAlert = false
            }
        } message: {
            Text(coordinator.lastError.isEmpty
                ? "IPCA can no longer access the configured Garmin folder. Insert the SD card reader or select the folder again."
                : coordinator.lastError)
        }
    }

    @ViewBuilder
    private var content: some View {
        switch coordinator.accessState {
        case .notConfigured:
            setupCase
        case .checking:
            ProgressView("Checking Garmin folder…")
                .tint(CVROperationalPalette.secondaryBlue)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        case .unavailable, .accessNeedsRestoration:
            unavailableCase
        case .configuredFolderEmpty:
            emptyFolderCase
        case .available, .error:
            browseCase
        }
    }

    // MARK: - Case A: Setup required

    private var setupCase: some View {
        VStack(spacing: 16) {
            Image(systemName: "sdcard.fill")
                .font(.system(size: 42, weight: .bold))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            Text("SELECT THE GARMIN FOLDER")
                .font(.headline.weight(.bold))
                .foregroundStyle(.white)
            Text("Choose the folder on the aircraft's Garmin SD card (usually the card's root, or a Data Log / FPL folder) using the Files browser. IPCA remembers this folder for future imports.")
                .font(.subheadline)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .multilineTextAlignment(.center)
            Button {
                isShowingFolderPicker = true
            } label: {
                Label("Choose Folder", systemImage: "folder.badge.plus")
            }
            .buttonStyle(.borderedProminent)
            .tint(CVROperationalPalette.primaryBlue)
        }
        .padding(28)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Case B: Unavailable / needs restoration

    private var unavailableCase: some View {
        VStack(spacing: 16) {
            Image(systemName: "externaldrive.trianglebadge.exclamationmark")
                .font(.system(size: 42, weight: .bold))
                .foregroundStyle(CVROperationalPalette.warning)
            Text(coordinator.accessState == .accessNeedsRestoration ? "ACCESS NEEDS RESTORATION" : "GARMIN FOLDER UNAVAILABLE")
                .font(.headline.weight(.bold))
                .foregroundStyle(.white)
            Text(coordinator.lastError.isEmpty
                ? "Insert the configured Garmin SD card reader, then try again. If this device or folder changed, select the folder again."
                : coordinator.lastError)
                .font(.subheadline)
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .multilineTextAlignment(.center)
            if settings.bookmarkIsStale {
                Text("This folder reference is stale and must be selected again — reinserting the card alone will not restore access.")
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.warning)
                    .multilineTextAlignment(.center)
            }
            HStack(spacing: 12) {
                Button("Try Again") {
                    Task { await coordinator.probe(settings: settings) }
                }
                .buttonStyle(.bordered)
                Button("Re-select Folder") {
                    isShowingFolderPicker = true
                }
                .buttonStyle(.borderedProminent)
                .tint(CVROperationalPalette.primaryBlue)
            }
        }
        .padding(28)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Case C: Configured folder currently empty

    private var emptyFolderCase: some View {
        VStack(spacing: 16) {
            Image(systemName: "folder.badge.questionmark")
                .font(.system(size: 42, weight: .bold))
                .foregroundStyle(CVROperationalPalette.standby)
            Text("NO CSV FILES FOUND")
                .font(.headline.weight(.bold))
                .foregroundStyle(.white)
            if let info = coordinator.folderInfo {
                Text("\(info.folderName) is accessible but contains no CSV files yet.")
                    .font(.subheadline)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .multilineTextAlignment(.center)
            }
            Button("Rescan") {
                Task { await coordinator.probe(settings: settings) }
            }
            .buttonStyle(.bordered)
        }
        .padding(28)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    // MARK: - Case D: Browse candidates

    private var browseCase: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                if let info = coordinator.folderInfo {
                    folderSummary(info)
                }
                targetPicker
                filterBar
                if coordinator.isImporting && !coordinator.importPhase.isEmpty {
                    importProgressBanner
                }
                if let result = coordinator.lastImportResult {
                    importResultBanner(result)
                }
                if !coordinator.serverStatusMessage.isEmpty {
                    Text(coordinator.serverStatusMessage)
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.warning)
                }
                if !coordinator.lastError.isEmpty && coordinator.lastImportResult == nil {
                    Text(coordinator.lastError)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.critical)
                }
                if coordinator.isScanning {
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text(coordinator.scanPhase.isEmpty ? "Scanning Garmin folder…" : coordinator.scanPhase)
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(CVROperationalPalette.textSecondary)
                            Spacer(minLength: 8)
                            if coordinator.scanFilesTotal > 0 {
                                Text("\(coordinator.scanFilesProcessed)/\(coordinator.scanFilesTotal)")
                                    .font(.caption.weight(.bold).monospacedDigit())
                                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                            }
                        }
                        if let progress = coordinator.scanProgress {
                            ProgressView(value: progress)
                                .tint(CVROperationalPalette.secondaryBlue)
                        } else {
                            ProgressView()
                                .tint(CVROperationalPalette.secondaryBlue)
                        }
                    }
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
                    .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
                }
                if coordinator.filteredCandidates.isEmpty && !coordinator.isScanning {
                    Text("No Garmin CSV files match the current filter.")
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                        .padding(.vertical, 8)
                }
                ForEach(coordinator.filteredCandidates) { candidate in
                    candidateRow(candidate)
                }
                Button {
                    Task {
                        await coordinator.refreshCandidates(
                            settings: settings,
                            flightLogs: flightLogs,
                            network: network,
                            uploadManager: uploadManager
                        )
                    }
                } label: {
                    Label("RESCAN FOLDER", systemImage: "arrow.clockwise")
                }
                .buttonStyle(.bordered)
                .tint(CVROperationalPalette.secondaryBlue)
                .disabled(coordinator.isScanning || coordinator.isImporting)
            }
            .padding(16)
        }
    }

    private var importProgressBanner: some View {
        HStack(spacing: 10) {
            ProgressView()
                .tint(CVROperationalPalette.secondaryBlue)
            Text(coordinator.importPhase)
                .font(.caption.weight(.bold))
                .foregroundStyle(.white)
            Spacer(minLength: 0)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).stroke(CVROperationalPalette.secondaryBlue.opacity(0.7), lineWidth: 1))
    }

    private func importResultBanner(_ result: GarminSDCardImportResult) -> some View {
        let color: Color = {
            switch result.kind {
            case .success: return CVROperationalPalette.success
            case .pending: return CVROperationalPalette.warning
            case .failure: return CVROperationalPalette.critical
            }
        }()
        let title: String = {
            switch result.kind {
            case .success: return "IMPORT COMPLETE"
            case .pending: return "STORED — ACTION NEEDED"
            case .failure: return "IMPORT FAILED"
            }
        }()
        let icon: String = {
            switch result.kind {
            case .success: return "checkmark.circle.fill"
            case .pending: return "exclamationmark.triangle.fill"
            case .failure: return "xmark.octagon.fill"
            }
        }()

        return VStack(alignment: .leading, spacing: 6) {
            HStack(alignment: .top, spacing: 10) {
                Image(systemName: icon)
                    .foregroundStyle(color)
                VStack(alignment: .leading, spacing: 4) {
                    Text(title)
                        .font(.caption.weight(.bold))
                        .foregroundStyle(color)
                    Text(result.filename)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.white)
                        .lineLimit(1)
                    Text(result.message)
                        .font(.caption)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                Spacer(minLength: 8)
                Button("Dismiss") {
                    coordinator.dismissImportResult()
                }
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).stroke(color.opacity(0.8), lineWidth: 1))
    }

    private func folderSummary(_ info: GarminExternalFolderDisplayInfo) -> some View {
        HStack(spacing: 10) {
            Image(systemName: "externaldrive.fill")
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
            VStack(alignment: .leading, spacing: 2) {
                Text(info.folderName)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(.white)
                Text(info.volumeName)
                    .font(.caption2)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
            }
            Spacer()
        }
        .padding(10)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 12))
    }

    private var targetPicker: some View {
        Menu {
            ForEach(flightLogs.entries.filter { !$0.hasGarminCSV }) { entry in
                Button(targetLabel(for: entry)) {
                    coordinator.selectedTargetFlightRecordID = entry.flightRecordID
                }
            }
        } label: {
            HStack {
                Text(currentTargetLabel)
                    .font(.caption.weight(.semibold))
                    .lineLimit(1)
                Image(systemName: "chevron.down")
                    .font(.caption2)
            }
            .foregroundStyle(.white)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 10))
            .overlay(RoundedRectangle(cornerRadius: 10).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
        }
    }

    private var currentTargetLabel: String {
        guard let id = coordinator.selectedTargetFlightRecordID,
              let entry = flightLogs.entries.first(where: { $0.flightRecordID == id }) else {
            return "Target flight: none selected"
        }
        return "Target: \(targetLabel(for: entry))"
    }

    private func targetLabel(for entry: CVRFlightLogEntry) -> String {
        "\(entry.scheduledDate) · \(entry.departureAirport)→\(entry.arrivalAirport)"
    }

    private var filterBar: some View {
        VStack(spacing: 8) {
            Picker("Filter", selection: $coordinator.filter) {
                ForEach(GarminSDCardImportFilter.allCases) { filter in
                    Text(filter.title).tag(filter)
                }
            }
            .pickerStyle(.segmented)
            Toggle("Show Excluded Files", isOn: $coordinator.showExcluded)
                .tint(CVROperationalPalette.primaryBlue)
                .font(.caption.weight(.semibold))
                .foregroundStyle(.white)
        }
    }

    private func candidateRow(_ candidate: GarminSDCardCandidate) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(candidate.filename)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(.white)
                        .lineLimit(1)
                    Text(candidate.relativePath)
                        .font(.caption2)
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                        .lineLimit(1)
                }
                Spacer()
                if candidate.isRecommended {
                    Label("RECOMMENDED", systemImage: "star.fill")
                        .font(.caption2.weight(.bold))
                        .foregroundStyle(CVROperationalPalette.success)
                }
            }
            HStack(spacing: 14) {
                metadataChip("TIME", value: timestampLabel(candidate))
                metadataChip("SIZE", value: sizeLabel(candidate.byteCount))
                if !candidate.aircraftIdent.isEmpty {
                    metadataChip("TAIL", value: candidate.aircraftIdent)
                }
                if !candidate.probableDurationLabel.isEmpty {
                    metadataChip("DURATION", value: candidate.probableDurationLabel)
                }
            }
            Text(candidate.displayStatusLabel)
                .font(.caption.weight(.bold))
                .foregroundStyle(statusColor(candidate))
            if let warning = candidate.matchWarning {
                Text(warning)
                    .font(.caption2.weight(.semibold))
                    .foregroundStyle(CVROperationalPalette.warning)
            }
            HStack(spacing: 12) {
                if candidate.canImport {
                    Button {
                        Task {
                            await coordinator.importCandidate(
                                candidate,
                                settings: settings,
                                flightLogs: flightLogs,
                                uploadManager: uploadManager,
                                network: network
                            )
                        }
                    } label: {
                        Label("IMPORT", systemImage: "square.and.arrow.down")
                    }
                    .foregroundStyle(CVROperationalPalette.primaryBlue)
                    .disabled(coordinator.isImporting || coordinator.selectedTargetFlightRecordID == nil)
                } else if candidate.canResume {
                    Button {
                        Task {
                            await coordinator.resumeCandidate(
                                candidate,
                                settings: settings,
                                flightLogs: flightLogs,
                                uploadManager: uploadManager
                            )
                        }
                    } label: {
                        Label("RESUME", systemImage: "arrow.clockwise")
                    }
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .disabled(coordinator.isImporting)
                }
                if let linkedID = candidate.linkedFlightRecordID, !linkedID.isEmpty {
                    Label("LINKED", systemImage: "link")
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                }
                Spacer()
                if coordinator.importingCandidateID == candidate.id {
                    HStack(spacing: 6) {
                        ProgressView()
                            .tint(CVROperationalPalette.secondaryBlue)
                        if !coordinator.importPhase.isEmpty {
                            Text(coordinator.importPhase)
                                .font(.caption2.weight(.semibold))
                                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                                .lineLimit(1)
                        }
                    }
                }
            }
            .font(.caption.weight(.bold))
            .buttonStyle(.plain)
        }
        .padding(12)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 14))
        .overlay(
            RoundedRectangle(cornerRadius: 14)
                .stroke(
                    candidate.isRecommended ? CVROperationalPalette.success.opacity(0.7) : CVROperationalPalette.cardBorder,
                    lineWidth: 1
                )
        )
        .opacity(candidate.isExcluded ? 0.6 : 1)
    }

    private func metadataChip(_ title: String, value: String) -> some View {
        VStack(alignment: .leading, spacing: 1) {
            Text(title)
                .font(.system(size: 8, weight: .bold))
                .tracking(0.5)
                .foregroundStyle(CVROperationalPalette.textSecondary)
            Text(value)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.white)
        }
    }

    private func statusColor(_ candidate: GarminSDCardCandidate) -> Color {
        switch candidate.importState {
        case .syncedAndLinked, .alreadySynced:
            return CVROperationalPalette.success
        case .syncFailed, .invalid, .unsupported, .unreadable:
            return CVROperationalPalette.critical
        case .new, .storedOnIPhone, .uploadPending, .uploading, .uploadedLinkingPending, .checkingStatus:
            return CVROperationalPalette.secondaryBlue
        case .gpsOnly, .unknown, .duplicateOfPendingImport:
            return CVROperationalPalette.standby
        }
    }

    private func timestampLabel(_ candidate: GarminSDCardCandidate) -> String {
        guard let date = candidate.startUtc ?? candidate.modificationDate else { return "—" }
        return Self.timestampFormatter.string(from: date)
    }

    private func sizeLabel(_ bytes: Int64) -> String {
        ByteCountFormatter.string(fromByteCount: bytes, countStyle: .file)
    }

    private static let timestampFormatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(identifier: "America/Los_Angeles") ?? .current
        formatter.dateFormat = "MMM d, HH:mm"
        return formatter
    }()
}
