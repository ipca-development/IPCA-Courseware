import SwiftUI
import UniformTypeIdentifiers

struct ContentView: View {
    @ObservedObject var model: SyncViewModel
    @State private var showingFolderPicker = false
    @State private var showingSettings = false
    @State private var showingDebug = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 24) {
                Spacer()
                Image(systemName: icon)
                    .font(.system(size: 58))
                    .foregroundStyle(.blue)
                Text(title)
                    .font(.largeTitle.bold())
                    .multilineTextAlignment(.center)
                Text(message)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .frame(maxWidth: 560)

                if model.step == .capturing || model.step == .uploading {
                    ProgressView(value: model.progressFraction)
                    Text(model.progressTitle).font(.headline)
                    Text(model.progressDetail)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                }
                if model.filesFound > 0 || model.captureErrors > 0 || model.step == .returnCard {
                    HStack(spacing: 18) {
                        summary("Found", model.filesFound)
                        summary("Known", model.filesPreviouslyKnown)
                        summary("New", model.filesNewlyCopied)
                        summary("Errors", model.captureErrors)
                    }
                    .font(.caption)
                }

                controls
                Spacer()
            }
            .padding(32)
            .navigationTitle("IPCA Garmin Sync")
            .toolbar {
                ToolbarItemGroup(placement: .topBarTrailing) {
                    Button { showingDebug = true } label: { Image(systemName: "ladybug") }
                    Button { showingSettings = true } label: { Image(systemName: "gearshape") }
                }
            }
            .fileImporter(isPresented: $showingFolderPicker, allowedContentTypes: [.folder]) { result in
                if case .success(let url) = result { model.selectFolder(url) }
            }
            .sheet(isPresented: $showingSettings) { SettingsView(model: model) }
            .sheet(isPresented: $showingDebug) {
                DebugView(model: model)
                    .task { await model.refreshDebugData() }
            }
        }
    }

    private func summary(_ label: String, _ value: Int) -> some View {
        VStack {
            Text(value.formatted()).font(.headline)
            Text(label).foregroundStyle(.secondary)
        }
    }

    @ViewBuilder
    private var controls: some View {
        switch model.step {
        case .chooseCard:
            Button("Choose Card Folder") { showingFolderPicker = true }
                .buttonStyle(.bordered)
            if let folder = model.selectedFolder {
                Text(folder.lastPathComponent).font(.caption)
                Button("Scan and Secure Files") { model.captureCard() }
                    .buttonStyle(.borderedProminent)
            }
        case .returnCard:
            Label("Safe to eject: every file in this scan is locally verified.", systemImage: "checkmark.shield")
                .foregroundStyle(.green)
            Button("SD Card Returned") { model.cardReturned() }
                .buttonStyle(.borderedProminent)
        case .readyToSynchronize:
            Button("Synchronize") { model.synchronize() }
                .buttonStyle(.borderedProminent)
            Text("The local capture is safe while offline. Settings are only needed for upload.")
                .font(.caption)
                .foregroundStyle(.secondary)
        case .failed:
            Button("Retry") { model.retry() }.buttonStyle(.borderedProminent)
            Button("Choose Card Folder") { showingFolderPicker = true }.buttonStyle(.bordered)
        case .complete:
            Button("Scan Another Card") { showingFolderPicker = true }.buttonStyle(.borderedProminent)
        case .capturing, .uploading:
            EmptyView()
        }
    }

    private var icon: String {
        switch model.step {
        case .chooseCard: "externaldrive"
        case .capturing: "doc.badge.arrow.up"
        case .returnCard: "eject"
        case .readyToSynchronize: "icloud.and.arrow.up"
        case .uploading: "arrow.up.circle"
        case .complete: "checkmark.circle"
        case .failed: "exclamationmark.triangle"
        }
    }

    private var title: String {
        switch model.step {
        case .chooseCard: "Insert or Choose Card Folder"
        case .capturing: "Securing Garmin Files"
        case .returnCard: "Return SD Card"
        case .readyToSynchronize: "Ready to Synchronize"
        case .uploading: "Synchronizing"
        case .complete: "Complete"
        case .failed: "Action Needed"
        }
    }

    private var message: String {
        switch model.step {
        case .chooseCard: "Select the Garmin card folder. Every CSV in every subfolder is scanned on each pass."
        case .capturing: "Keep the card connected until local verification finishes."
        case .returnCard: "The immutable scan snapshot is complete and all new files are stored privately."
        case .readyToSynchronize: "The SD card can remain with the aircraft. Upload when a network is available."
        case .uploading: "Verified local files are being uploaded in resumable chunks."
        case .complete: "The server verified the expected hash and size. Local files have been retained."
        case .failed(let error): error
        }
    }
}

private struct SettingsView: View {
    @ObservedObject var model: SyncViewModel
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Form {
                Section("Enrollment / Server") {
                    TextField("https://example.org", text: $model.serverURL)
                        .textInputAutocapitalization(.never)
                        .keyboardType(.URL)
                    SecureField("Bearer credential", text: $model.credential)
                        .textInputAutocapitalization(.never)
                }
                Section {
                    Text("The credential is stored in Keychain and is never included in logs.")
                }
            }
            .navigationTitle("Settings")
            .toolbar {
                Button("Done") {
                    _ = model.saveSettings()
                    dismiss()
                }
            }
        }
    }
}

private struct DebugView: View {
    @ObservedObject var model: SyncViewModel

    var body: some View {
        NavigationStack {
            List {
                Section("Snapshots") {
                    ForEach(model.snapshots) { snapshot in
                        VStack(alignment: .leading) {
                            Text(snapshot.folderDisplayName)
                            Text("""
                            \(snapshot.foundCount) found · \(snapshot.previouslyKnownCount) known · \
                            \(snapshot.newlyCopiedCount) new · \(snapshot.completionStatus)
                            """)
                                .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                Section("Files") {
                    ForEach(model.files) { file in
                        VStack(alignment: .leading) {
                            Text(file.relativePath)
                            Text("""
                            \(file.state.rawValue) · local \(file.localVerificationStatus) · \
                            upload \(file.uploadStatus) · retries \(file.retryCount)
                            """)
                                .font(.caption).foregroundStyle(.secondary)
                            if let error = file.errorMessage {
                                Text(error).font(.caption2).foregroundStyle(.red)
                            }
                        }
                    }
                }
            }
            .navigationTitle("Ingestion Ledger")
        }
    }
}
