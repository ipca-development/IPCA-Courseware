import SwiftUI

struct AdminRecordingsView: View {
    @EnvironmentObject private var store: RecordingStore
    @EnvironmentObject private var settings: SettingsStore
    @EnvironmentObject private var uploadManager: UploadManager
    @EnvironmentObject private var network: NetworkMonitor
    @EnvironmentObject private var system: SystemMonitor
    @State private var recordingPendingDeletion: Recording?
    @State private var isDeleteConfirmationPresented = false
    @State private var selectedRecordingIDs: Set<String> = []
    @State private var isBulkDeleteConfirmationPresented = false

    var body: some View {
        NavigationStack {
            List {
                if !store.recordings.isEmpty {
                    Section {
                        HStack {
                            VStack(alignment: .leading, spacing: 4) {
                                Text("\(selectedDeletableRecordings.count) selected")
                                    .font(.headline)
                                Text("\(format(bytes: selectedLocalStorageBytes)) will be freed from this iPhone")
                                    .font(.caption)
                                    .foregroundStyle(IPCATheme.secondaryText)
                            }
                            Spacer()
                            Button(isAllSelected ? "Clear" : "Select All") {
                                toggleSelectAll()
                            }
                            .disabled(deletableRecordings.isEmpty)
                            Button("Delete Selected", role: .destructive) {
                                isBulkDeleteConfirmationPresented = true
                            }
                            .disabled(selectedDeletableRecordings.isEmpty)
                        }
                    }
                }

                ForEach(store.recordings) { recording in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Toggle("", isOn: selectionBinding(for: recording))
                                .labelsHidden()
                                .disabled(recording.uploadStatus == .uploading)

                            Text(recording.startedAt, style: .date)
                                .font(.headline)
                            Text(recording.startedAt, style: .time)
                                .font(.headline)
                            Spacer()
                            IPCAStatusPill(text: recording.statusLabel, color: statusColor(recording))
                        }

                        Text(recording.aircraftLabel)
                            .foregroundStyle(IPCATheme.secondaryText)

                        HStack {
                            Text("Duration \(format(duration: recording.duration))")
                            Text(ByteCountFormatter.string(fromByteCount: recording.fileSize, countStyle: .file))
                            Text(recording.inputDeviceName)
                        }
                        .font(.caption)
                        .foregroundStyle(IPCATheme.secondaryText)

                        ProgressView(value: recording.uploadProgress)
                            .tint(statusColor(recording))

                        if !recording.lastError.isEmpty {
                            Text(recording.lastError)
                                .font(.caption)
                                .foregroundStyle(recording.uploadStatus == .failed ? IPCATheme.danger : IPCATheme.secondaryText)
                        }

                        HStack {
                            Button("Retry Upload") {
                                uploadManager.upload(recordingID: recording.id, store: store, settings: settings)
                            }
                            .disabled(!network.canUpload(allowCellular: settings.allowCellularUpload))

                            Button("Delete from iPhone", role: .destructive) {
                                recordingPendingDeletion = recording
                                isDeleteConfirmationPresented = true
                            }
                            .disabled(recording.uploadStatus == .uploading)

                            Text(recording.filePath)
                                .font(.caption2)
                                .foregroundStyle(IPCATheme.secondaryText)
                                .lineLimit(1)
                        }
                    }
                    .padding(.vertical, 6)
                }
            }
            .navigationTitle("Permanent Recordings")
            .toolbar {
                Button("Upload Pending") {
                    uploadManager.uploadPending(store: store, settings: settings, network: network)
                }
                .disabled(!network.canUpload(allowCellular: settings.allowCellularUpload))
            }
            .confirmationDialog(
                "Delete recording from this iPhone?",
                isPresented: $isDeleteConfirmationPresented,
                titleVisibility: .visible
            ) {
                if let recording = recordingPendingDeletion {
                    Button("Delete Local Recording", role: .destructive) {
                        store.delete(recording)
                        selectedRecordingIDs.remove(recording.id)
                        system.refresh()
                        recordingPendingDeletion = nil
                    }
                }
                Button("Cancel", role: .cancel) {
                    recordingPendingDeletion = nil
                }
            } message: {
                Text(singleDeleteConfirmationMessage)
            }
            .confirmationDialog(
                "Delete selected recordings from this iPhone?",
                isPresented: $isBulkDeleteConfirmationPresented,
                titleVisibility: .visible
            ) {
                Button("Delete \(selectedDeletableRecordings.count) Local Recordings", role: .destructive) {
                    let ids = Set(selectedDeletableRecordings.map(\.id))
                    store.delete(ids: ids)
                    selectedRecordingIDs.subtract(ids)
                    system.refresh()
                }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Are you sure you want to delete \(selectedDeletableRecordings.count) recording(s)? This action cannot be reversed. Estimated space freed: \(format(bytes: selectedLocalStorageBytes)).")
            }
        }
    }

    private var deletableRecordings: [Recording] {
        store.recordings.filter { $0.uploadStatus != .uploading }
    }

    private var selectedDeletableRecordings: [Recording] {
        deletableRecordings.filter { selectedRecordingIDs.contains($0.id) }
    }

    private var selectedLocalStorageBytes: Int64 {
        store.localStorageBytes(ids: Set(selectedDeletableRecordings.map(\.id)))
    }

    private var isAllSelected: Bool {
        !deletableRecordings.isEmpty && selectedDeletableRecordings.count == deletableRecordings.count
    }

    private var singleDeleteConfirmationMessage: String {
        let base: String
        if recordingPendingDeletion?.uploadStatus == .uploaded {
            base = "This removes the local audio and sidecar files from this iPhone. The uploaded server copy is not deleted."
        } else {
            base = "This recording has not completed upload. Deleting it removes the local audio and sidecar files from this iPhone."
        }
        let bytes = recordingPendingDeletion.map { store.localStorageBytes(for: $0) } ?? 0
        return "\(base) Are you sure you want to delete? This action cannot be reversed. Estimated space freed: \(format(bytes: bytes))."
    }

    private func selectionBinding(for recording: Recording) -> Binding<Bool> {
        Binding {
            selectedRecordingIDs.contains(recording.id)
        } set: { isSelected in
            if isSelected {
                selectedRecordingIDs.insert(recording.id)
            } else {
                selectedRecordingIDs.remove(recording.id)
            }
        }
    }

    private func toggleSelectAll() {
        let ids = Set(deletableRecordings.map(\.id))
        if isAllSelected {
            selectedRecordingIDs.removeAll()
        } else {
            selectedRecordingIDs = ids
        }
    }

    private func statusColor(_ recording: Recording) -> Color {
        if recording.uploadStatus == .uploaded && recording.transcriptStatus == .ready {
            return IPCATheme.success
        }
        if recording.uploadStatus == .failed || recording.transcriptStatus == .failed {
            return IPCATheme.danger
        }
        if recording.uploadStatus == .uploading || recording.transcriptStatus == .transcribing {
            return IPCATheme.brightBlue
        }
        return IPCATheme.warning
    }

    private func format(duration: TimeInterval) -> String {
        let total = Int(duration.rounded())
        let hours = total / 3600
        let minutes = (total % 3600) / 60
        let seconds = total % 60
        return String(format: "%02d:%02d:%02d", hours, minutes, seconds)
    }

    private func format(bytes: Int64) -> String {
        ByteCountFormatter.string(fromByteCount: bytes, countStyle: .file)
    }
}
