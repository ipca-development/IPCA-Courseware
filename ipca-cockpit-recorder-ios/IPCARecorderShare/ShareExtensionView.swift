import SwiftUI

struct ShareExtensionView: View {
    let metadata: G3XFlightStreamMetadata
    let candidates: [G3XMatchCandidate]
    let onAttach: (String) -> Void
    let onCancel: () -> Void

    @State private var selectedRecordingID: String

    init(
        metadata: G3XFlightStreamMetadata,
        candidates: [G3XMatchCandidate],
        onAttach: @escaping (String) -> Void,
        onCancel: @escaping () -> Void
    ) {
        self.metadata = metadata
        self.candidates = candidates
        self.onAttach = onAttach
        self.onCancel = onCancel
        _selectedRecordingID = State(initialValue: candidates.first?.id ?? "")
    }

    var body: some View {
        NavigationStack {
            VStack(alignment: .leading, spacing: 16) {
                VStack(alignment: .leading, spacing: 6) {
                    Text("Garmin G3X CSV")
                        .font(.headline)
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
                        "No Matching Recording",
                        systemImage: "airplane.circle",
                        description: Text("Open IPCA Flight Recorder and make sure the matching flight is saved on this iPad, then share the Garmin CSV again.")
                    )
                } else {
                    Text("Attach to flight")
                        .font(.subheadline.weight(.semibold))

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
                                        Text("Overlap \(Int(candidate.overlapSeconds / 60)) min")
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
                }

                Spacer()

                HStack {
                    Button("Cancel", action: onCancel)
                    Spacer()
                    Button("Attach to Flight") {
                        onAttach(selectedRecordingID)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(selectedRecordingID.isEmpty)
                }
            }
            .padding()
            .navigationTitle("IPCA Flight Recorder")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}
