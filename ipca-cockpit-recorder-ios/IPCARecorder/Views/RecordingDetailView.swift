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
                    g3xCard(recording)
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
            LabeledContent("G3X CSV", value: recording.g3xLabel)
            if let importedAt = recording.g3xImportedAt {
                LabeledContent("G3X imported", value: importedAt.formatted(date: .abbreviated, time: .shortened))
            }
            if let matchMethod = recording.g3xMatchMethod, !matchMethod.isEmpty {
                LabeledContent("G3X match", value: matchMethod)
            }
            LabeledContent("Language", value: recording.language)
            LabeledContent("Upload", value: "\(recording.uploadStatus.label) \(Int(recording.uploadProgress * 100))%")
            LabeledContent("Transcript", value: "\(recording.transcriptStatus.label) \(recording.transcriptProgress)%")
            if !recording.lastError.isEmpty {
                Text(recording.lastError).foregroundStyle(IPCATheme.danger)
            }
            if recording.needsUploadRetry {
                uploadRetryButton(for: recording)
            }
        }
    }

    @ViewBuilder
    private func uploadRetryButton(for recording: Recording) -> some View {
        let isUploadingNow = uploadManager.activeUploads.contains(recording.id)
        VStack(alignment: .leading, spacing: 8) {
            Text("Your audio and flight data stay on this iPad until upload completes. Reinstalling the app is not required.")
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)
            Button(isUploadingNow ? "Uploading..." : "Retry Upload") {
                uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
            }
            .buttonStyle(.borderedProminent)
            .tint(IPCATheme.brightBlue)
            .disabled(isUploadingNow)
        }
        .padding(.top, 4)
    }

    private func g3xCard(_ recording: Recording) -> some View {
        IPCACard(title: "Garmin G3X", systemImage: "airplane.circle") {
            Text("Share a Garmin Pilot G3X CSV to this app after the flight to enrich replay with panel AHRS, air data, and engine values.")
                .font(.caption)
                .foregroundStyle(IPCATheme.secondaryText)
            LabeledContent("Status", value: recording.g3xLabel)
            if recording.needsG3XUpload && recording.uploadStatus == .uploaded {
                Button(uploadManager.activeUploads.contains(recording.id) ? "Syncing G3X..." : "Sync G3X to Server") {
                    uploadManager.uploadG3XSupplement(recordingID: recording.id, store: store, settings: settings)
                }
                .buttonStyle(.borderedProminent)
                .tint(IPCATheme.brightBlue)
                .disabled(uploadManager.activeUploads.contains(recording.id))
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
