import SwiftUI

struct RecordingDetailView: View {
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var uploadManager: UploadManager

    var recordingID: String

    private var recording: Recording? {
        store.recording(id: recordingID)
    }

    var body: some View {
        ScrollView {
            if let recording {
                VStack(alignment: .leading, spacing: 18) {
                    IPCAHeader(
                        title: "Recording Detail",
                        subtitle: recording.aircraftLabel,
                        systemImage: "doc.text.magnifyingglass"
                    )
                    metadataCard(recording)
                    mergeCard(recording)
                    transcriptCard(recording)
                }
                .padding()
            } else {
                ContentUnavailableView("Recording Missing", systemImage: "questionmark.folder")
            }
        }
        .background(IPCATheme.pageBackground.ignoresSafeArea())
        .navigationTitle("Recording")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if let recording {
                Button("Retry Upload") {
                    uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                }
                .disabled(uploadManager.activeUploads.contains(recording.id))
            }
        }
    }

    private func metadataCard(_ recording: Recording) -> some View {
        IPCACard(title: "Details", systemImage: "info.circle") {
            LabeledContent("Recording ID", value: recording.id)
            if let serverID = recording.serverID {
                LabeledContent("Server ID", value: serverID)
            }
            LabeledContent("Started", value: recording.startedAt.formatted(date: .abbreviated, time: .standard))
            LabeledContent("Duration", value: Formatters.duration(recording.duration))
            LabeledContent("File size", value: Formatters.bytes(recording.fileSize))
            LabeledContent("Input device", value: recording.inputDeviceName)
            LabeledContent("Aircraft", value: recording.aircraftLabel)
            LabeledContent("Flight session", value: recording.flightSessionLabel)
            LabeledContent("Session ID", value: recording.flightSessionID)
            if let previous = recording.previousSegmentID {
                LabeledContent("Previous segment", value: previous)
            }
            if recording.isTestRecording {
                IPCAStatusPill(text: "TEST RECORDING", color: IPCATheme.warning)
            }
            if let gap = recording.sourceGapSummary, !gap.isEmpty {
                Text(gap)
                    .font(.caption)
                    .foregroundStyle(IPCATheme.warning)
            }
            if let adsbHex = recording.aircraftADSBHex, !adsbHex.isEmpty {
                LabeledContent("ADS-B hex", value: adsbHex)
            }
            LabeledContent("AHRS samples", value: recording.ahrsSamplesPath == nil ? "None saved" : "Saved")
            LabeledContent("GPS samples", value: recording.gpsSamplesPath == nil ? "None saved" : "Saved")
            LabeledContent("Language", value: recording.language)
            LabeledContent("Upload", value: "\(recording.uploadStatus.label) \(Int(recording.uploadProgress * 100))%")
            LabeledContent("Transcript", value: "\(recording.transcriptStatus.label) \(recording.transcriptProgress)%")
            if !recording.lastError.isEmpty {
                Text(recording.lastError).foregroundStyle(IPCATheme.danger)
            }
        }
    }

    private func mergeCard(_ recording: Recording) -> some View {
        IPCACard(title: "Flight Merge", systemImage: "link") {
            if let candidate = store.previousMergeCandidate(for: recording.id) {
                Text("If this recording continues the same flight, merge it with the previous segment. Originals stay separate, but future uploads carry the same flight session metadata.")
                    .font(.caption)
                    .foregroundStyle(IPCATheme.secondaryText)
                LabeledContent("Previous segment", value: candidate.startedAt.formatted(date: .abbreviated, time: .shortened))
                LabeledContent("Previous input", value: candidate.inputDeviceName)
                Button("Merge With Previous Flight") {
                    store.mergeWithPreviousFlight(recordingID: recording.id)
                }
                .buttonStyle(.borderedProminent)
                .tint(IPCATheme.brightBlue)
            } else {
                Text("No likely previous segment was found within one hour for the same aircraft.")
                    .font(.caption)
                    .foregroundStyle(IPCATheme.secondaryText)
            }

            if recording.flightSessionID != recording.id || recording.segmentIndex > 1 {
                Button("Make This a New Flight") {
                    store.startNewFlight(recordingID: recording.id)
                }
                .buttonStyle(.bordered)
                .tint(IPCATheme.warning)
            }
        }
    }

    private func transcriptCard(_ recording: Recording) -> some View {
        IPCACard(title: "Transcript", systemImage: "text.quote") {
            if recording.transcript.isEmpty {
                Text("Transcript not ready.")
                    .foregroundStyle(IPCATheme.secondaryText)
            } else {
                Text(recording.transcript)
                    .textSelection(.enabled)
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
    }
}
