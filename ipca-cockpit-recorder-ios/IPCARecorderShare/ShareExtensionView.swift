import SwiftUI

struct ShareExtensionView: View {
    let metadata: G3XFlightStreamMetadata
    let candidates: [G3XMatchCandidate]
    let indexedRecordingCount: Int
    let onAttach: (String) -> Void
    let onCancel: () -> Void

    @State private var selectedRecordingID: String
    @State private var manualServerRecordingID: String = ""

    init(
        metadata: G3XFlightStreamMetadata,
        candidates: [G3XMatchCandidate],
        indexedRecordingCount: Int,
        onAttach: @escaping (String) -> Void,
        onCancel: @escaping () -> Void
    ) {
        self.metadata = metadata
        self.candidates = candidates
        self.indexedRecordingCount = indexedRecordingCount
        self.onAttach = onAttach
        self.onCancel = onCancel
        _selectedRecordingID = State(initialValue: candidates.first?.id ?? "")
    }

    private var showsManualFallback: Bool {
        candidates.contains(where: \.isManualFallback)
    }

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 16) {
                VStack(alignment: .leading, spacing: 6) {
                    Text("Garmin CSV")
                        .font(.headline)
                    Text(metadata.importProfile == "garmin_g1000nxi" ? "Garmin G1000 NXi data log" : "Garmin G3X / GDU 460")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    if !metadata.aircraftIdent.isEmpty {
                        Text("Aircraft: \(metadata.aircraftIdent)")
                    }
                    if let start = metadata.startUtc, let end = metadata.endUtc {
                        Text("Flight window: \(start.formatted(date: .abbreviated, time: .shortened)) – \(end.formatted(date: .omitted, time: .shortened))")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    }
                    Text("\(metadata.rowCount) panel samples")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }

                if candidates.isEmpty {
                    ContentUnavailableView(
                        emptyStateTitle,
                        systemImage: "airplane.circle",
                        description: Text(emptyStateMessage)
                    )
                    manualServerRecordingField
                } else {
                    if showsManualFallback {
                        Text("No automatic match. Select the flight this Garmin file belongs to.")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    } else {
                        Text("Attach to flight")
                            .font(.subheadline.weight(.semibold))
                    }

                    List {
                        ForEach(candidates) { candidate in
                            Button {
                                selectedRecordingID = candidate.id
                            } label: {
                                HStack {
                                    VStack(alignment: .leading, spacing: 4) {
                                        Text(candidate.recording.aircraftLabel)
                                            .font(.body.weight(.medium))
                                        Text(candidate.recording.startedAt.formatted(date: .abbreviated, time: .shortened))
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                        Text(candidateDetail(for: candidate))
                                            .font(.caption2)
                                            .foregroundStyle(.secondary)
                                    }
                                    Spacer()
                                    if selectedRecordingID == candidate.id {
                                        Image(systemName: "checkmark.circle.fill")
                                            .foregroundStyle(.blue)
                                    }
                                }
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .listStyle(.insetGrouped)
                    manualServerRecordingField
                }

                Spacer()

                HStack {
                    Button("Cancel", action: onCancel)
                    Spacer()
                    Button("Attach to Flight") {
                        onAttach(attachmentRecordingID)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(attachmentRecordingID.isEmpty)
                }
            }
            .padding()
            .navigationTitle("IPCA Flight Recorder")
            .navigationBarTitleDisplayMode(.inline)
        }
    }

    private var attachmentRecordingID: String {
        let manual = manualServerRecordingID.trimmingCharacters(in: .whitespacesAndNewlines)
        return manual.isEmpty ? selectedRecordingID : manual
    }

    private var manualServerRecordingField: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text("Server Recording ID")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
            TextField("Paste recording UID", text: $manualServerRecordingID)
                .textInputAutocapitalization(.characters)
                .autocorrectionDisabled()
                .textFieldStyle(.roundedBorder)
            Text("Use this when the flight exists online but is not stored locally on this iPad.")
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
    }

    private var emptyStateTitle: String {
        indexedRecordingCount == 0 ? "No Local Flights Found" : "No Matching Recording"
    }

    private var emptyStateMessage: String {
        if indexedRecordingCount == 0 {
            return "No local flights are indexed on this iPad. Paste the server recording ID below to attach this Garmin CSV to an online recording."
        }
        return "None of your \(indexedRecordingCount) saved flights overlap this Garmin file. Select a local flight or paste the server recording ID below."
    }

    private func candidateDetail(for candidate: G3XMatchCandidate) -> String {
        if candidate.overlapSeconds > 0 {
            return "Overlap \(Int(candidate.overlapSeconds / 60)) min"
        }
        if let g3xStart = metadata.startUtc {
            let delta = abs(candidate.recording.startedAt.timeIntervalSince(g3xStart))
            if delta < 3600 {
                return "Starts \(Int(delta / 60)) min from Garmin"
            }
            return "Starts \(Int(delta / 3600)) hr from Garmin"
        }
        return candidate.recording.hasG3X ? "Already has G3X data" : "Manual selection"
    }
}
