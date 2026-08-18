import SwiftUI
import UniformTypeIdentifiers

struct SafetyView: View {
    @EnvironmentObject private var session: AppSession
    @State private var reports: [SafetyReportDTO] = []
    @State private var loading = true
    @State private var errorMessage: String?
    @State private var composing = false
    @State private var showingAnonymousSafety = false
    @State private var selectedReport: SafetyReportDTO?

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                IPCARootHeader(title: "Safety", subtitle: "Report concerns. Strengthen our safety culture.") {
                    HStack(spacing: IPCATheme.Spacing.xs) {
                        if session.capabilities.anonymousReportingEnabled {
                            Button {
                                showingAnonymousSafety = true
                            } label: {
                                Image(systemName: "person.crop.circle.badge.questionmark")
                                    .font(.headline)
                                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                                    .frame(width: 36, height: 36)
                                    .background(IPCATheme.Colors.navyElevated, in: Circle())
                            }
                            .accessibilityLabel("Anonymous safety reporting")
                        }
                        Button {
                            composing = true
                        } label: {
                            Image(systemName: "plus")
                                .font(.headline)
                                .foregroundStyle(.white)
                                .frame(width: 36, height: 36)
                                .background(IPCATheme.interactiveGradient, in: Circle())
                        }
                        .accessibilityLabel("New safety report")
                    }
                }

                Group {
                    if loading && reports.isEmpty {
                        ProgressView()
                            .tint(IPCATheme.Colors.ipcaBlue)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if let errorMessage, reports.isEmpty {
                        ContentUnavailableView(
                            "Couldn't load reports",
                            systemImage: "exclamationmark.shield",
                            description: Text(errorMessage)
                        )
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else if reports.isEmpty {
                        ContentUnavailableView(
                            "No safety reports",
                            systemImage: "checkmark.shield",
                            description: Text("Use the plus button to report a hazard, occurrence, or safety concern.")
                        )
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: IPCATheme.Spacing.sm) {
                                ForEach(reports) { report in
                                    Button {
                                        selectedReport = report
                                    } label: {
                                        SafetyReportRow(report: report)
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                            .padding(IPCATheme.Spacing.screen)
                        }
                        .refreshable { await reload() }
                    }
                }
            }
            .background(IPCABackground())
            .toolbar(.hidden, for: .navigationBar)
            .task { await reload() }
            .sheet(isPresented: $composing) {
                SafetyReportFormView(mode: .identified) { report in
                    reports.insert(report, at: 0)
                }
                .environmentObject(session)
            }
            .sheet(isPresented: $showingAnonymousSafety) {
                AnonymousSafetyAccessView(serverURL: session.serverURLString)
                    .environmentObject(session)
            }
            .navigationDestination(item: $selectedReport) { report in
                SafetyReportDetailView(report: report)
                    .environmentObject(session)
            }
            .onChange(of: session.pendingSafetyReportUUID) { _, uuid in
                guard let uuid, !uuid.isEmpty else { return }
                Task { await openReport(uuid) }
            }
        }
    }

    private func reload() async {
        loading = true
        defer { loading = false }
        do {
            reports = try await session.loadSafetyReports()
            errorMessage = nil
            if let uuid = session.pendingSafetyReportUUID {
                await openReport(uuid)
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func openReport(_ uuid: String) async {
        if let report = reports.first(where: { $0.reportUUID == uuid }) {
            selectedReport = report
        } else if let report = try? await session.loadSafetyReport(uuid) {
            selectedReport = report
        }
        session.pendingSafetyReportUUID = nil
    }
}

private struct SafetyReportRow: View {
    let report: SafetyReportDTO

    var body: some View {
        HStack(spacing: IPCATheme.Spacing.sm) {
            IPCAIconTile(systemImage: "shield.lefthalf.filled")
            VStack(alignment: .leading, spacing: 5) {
                Text(report.title.isEmpty ? "Safety report" : report.title)
                    .font(.headline)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .lineLimit(2)
                HStack(spacing: 8) {
                    if !report.reference.isEmpty {
                        Text(report.reference)
                    }
                    Text(report.category.replacingOccurrences(of: "_", with: " ").capitalized)
                }
                .font(.caption)
                .foregroundStyle(IPCATheme.Colors.textSecondary)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 8) {
                IPCAStatusBadge(text: report.status.replacingOccurrences(of: "_", with: " ").capitalized, tone: statusTone)
                Image(systemName: "chevron.right")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
            }
        }
        .padding(IPCATheme.Spacing.md)
        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
        .overlay {
            RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                .stroke(IPCATheme.Colors.separator, lineWidth: 1)
        }
    }

    private var statusTone: IPCAStatusBadge.Tone {
        switch report.status.lowercased() {
        case "closed", "resolved": return .success
        case "submitted", "under_review", "open": return .info
        default: return .muted
        }
    }
}

private struct SafetyReportDetailView: View {
    @EnvironmentObject private var session: AppSession
    @State var report: SafetyReportDTO
    @State private var mailbox: [SafetyMailboxMessageDTO] = []
    @State private var mailboxDraft = ""
    @State private var sendingMailbox = false
    @State private var mailboxError: String?

    var body: some View {
        ZStack {
            IPCABackground()
            ScrollView {
                VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                    VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
                        HStack {
                            IPCAStatusBadge(text: report.status.replacingOccurrences(of: "_", with: " ").capitalized, tone: .info)
                            Spacer()
                            Text(report.reference)
                                .font(.caption.monospaced())
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        }
                        Text(report.title)
                            .font(.title2.bold())
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                        Text(report.description)
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                        if !report.location.isEmpty {
                            Label(report.location, systemImage: "mappin.and.ellipse")
                        }
                        if !report.aircraftRegistration.isEmpty {
                            Label(report.aircraftRegistration, systemImage: "airplane")
                        }
                    }
                    .padding(IPCATheme.Spacing.md)
                    .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card))

                    IPCASectionHeader(title: "Timeline")
                    if report.timeline.isEmpty {
                        Text("No updates yet.")
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else {
                        ForEach(report.timeline) { event in
                            SafetyUpdateCard(title: event.title, message: event.body, date: event.createdAtUTC)
                        }
                    }

                    IPCASectionHeader(title: "Safety Team Mailbox")
                    if !mailbox.isEmpty {
                        ForEach(mailbox) { message in
                            SafetyUpdateCard(title: message.senderLabel, message: message.body, date: message.createdAtUTC)
                        }
                    }
                    SafetyMailboxComposer(
                        draft: $mailboxDraft,
                        sending: sendingMailbox,
                        errorMessage: mailboxError
                    ) {
                        await sendMailboxMessage()
                    }
                }
                .padding(IPCATheme.Spacing.screen)
            }
        }
        .navigationTitle(report.reference.isEmpty ? "Safety Report" : report.reference)
        .navigationBarTitleDisplayMode(.inline)
        .task {
            if let refreshed = try? await session.loadSafetyReport(report.reportUUID) {
                report = refreshed
            }
            mailbox = (try? await session.loadSafetyMailbox(report.reportUUID)) ?? []
        }
    }

    private func sendMailboxMessage() async {
        let body = mailboxDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty, !sendingMailbox else { return }
        sendingMailbox = true
        mailboxError = nil
        defer { sendingMailbox = false }
        do {
            try await session.postSafetyMailbox(report.reportUUID, body: body)
            mailboxDraft = ""
            mailbox = try await session.loadSafetyMailbox(report.reportUUID)
        } catch {
            mailboxError = error.localizedDescription
        }
    }
}

private struct SafetyUpdateCard: View {
    let title: String
    let message: String
    let date: String

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(title)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                Spacer()
                Text(date)
                    .font(.caption2)
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
            }
            if !message.isEmpty {
                Text(message)
                    .font(.subheadline)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
            }
        }
        .padding(IPCATheme.Spacing.md)
        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium))
    }
}

private struct SafetyMailboxComposer: View {
    @Binding var draft: String
    let sending: Bool
    let errorMessage: String?
    let send: () async -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: IPCATheme.Spacing.xs) {
            HStack(alignment: .bottom, spacing: IPCATheme.Spacing.xs) {
                TextField("Message the safety team", text: $draft, axis: .vertical)
                    .lineLimit(1...5)
                    .padding(10)
                    .background(IPCATheme.Colors.navyElevated, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium))
                Button {
                    Task { await send() }
                } label: {
                    if sending {
                        ProgressView()
                            .tint(.white)
                    } else {
                        Image(systemName: "arrow.up")
                    }
                }
                .frame(width: 40, height: 40)
                .foregroundStyle(.white)
                .background(IPCATheme.interactiveGradient, in: Circle())
                .disabled(sending || draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
            if let errorMessage {
                Text(errorMessage)
                    .font(.footnote)
                    .foregroundStyle(IPCATheme.Colors.destructive)
            }
        }
    }
}

enum SafetyReportMode: Equatable {
    case identified
    case anonymous
}

struct SafetyReportFormView: View {
    @Environment(\.dismiss) private var dismiss
    @EnvironmentObject private var session: AppSession
    let mode: SafetyReportMode
    var onSubmitted: (SafetyReportDTO) -> Void = { _ in }
    var onAnonymousSubmitted: (AnonymousSafetyReceipt) -> Void = { _ in }

    @State private var category = "hazard"
    @State private var title = ""
    @State private var description = ""
    @State private var occurredAt = Date()
    @State private var location = ""
    @State private var aircraftRegistration = ""
    @State private var immediateAction = ""
    @State private var attachments: [SafetyDraftAttachment] = []
    @State private var pickingAttachment = false
    @State private var submitting = false
    @State private var submitted = false
    @State private var errorMessage: String?

    private let categories = ["hazard", "occurrence", "near_miss", "security", "fatigue", "other"]

    var body: some View {
        NavigationStack {
            ZStack {
                IPCABackground()
                Form {
                    Section("1. What are you reporting?") {
                        Picker("Category", selection: $category) {
                            ForEach(categories, id: \.self) {
                                Text($0.replacingOccurrences(of: "_", with: " ").capitalized).tag($0)
                            }
                        }
                        TextField("Short title", text: $title)
                        TextField("Describe what happened or could happen", text: $description, axis: .vertical)
                            .lineLimit(5...10)
                    }
                    Section("2. When and where?") {
                        DatePicker("Date and time", selection: $occurredAt)
                        TextField("Location (optional)", text: $location)
                        TextField("Aircraft registration (optional)", text: $aircraftRegistration)
                            .textInputAutocapitalization(.characters)
                    }
                    Section("3. Immediate action") {
                        TextField("What was done to reduce the risk? (optional)", text: $immediateAction, axis: .vertical)
                            .lineLimit(3...6)
                    }
                    if mode == .identified && session.capabilities.attachmentsEnabled {
                        Section("4. Private attachments") {
                            ForEach(attachments) { attachment in
                                HStack {
                                    Image(systemName: "paperclip")
                                    VStack(alignment: .leading) {
                                        Text(attachment.filename)
                                            .lineLimit(1)
                                        Text(ByteCountFormatter.string(fromByteCount: Int64(attachment.byteSize), countStyle: .file))
                                            .font(.caption)
                                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                                    }
                                    Spacer()
                                    Button(role: .destructive) {
                                        removeAttachment(attachment)
                                    } label: {
                                        Image(systemName: "trash")
                                    }
                                    .buttonStyle(.borderless)
                                }
                            }
                            Button {
                                pickingAttachment = true
                            } label: {
                                Label("Add Attachment", systemImage: "paperclip")
                            }
                            Text("Images, PDF, text, or CSV; maximum 25 MB each. Files upload through a private presigned URL.")
                                .font(.footnote)
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        }
                    }
                    Section {
                        if mode == .anonymous {
                            Label("Your account and device identity are not sent. Keep the receipt stored on this device to check updates.", systemImage: "person.crop.circle.badge.questionmark")
                                .font(.footnote)
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        } else {
                            Label("This identified report is submitted directly while online. A local draft is saved until submission succeeds.", systemImage: "lock.shield")
                                .font(.footnote)
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        }
                    }
                    if let errorMessage {
                        Section {
                            Text(errorMessage)
                                .font(.footnote)
                                .foregroundStyle(IPCATheme.Colors.destructive)
                        }
                    }
                }
                .scrollContentBackground(.hidden)
            }
            .navigationTitle(mode == .anonymous ? "Anonymous Report" : "Safety Report")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        saveDraft()
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(submitting ? "Submitting…" : "Submit") {
                        Task { await submit() }
                    }
                    .disabled(submitting || !canSubmit)
                }
            }
            .onAppear { restoreDraft() }
            .onDisappear { saveDraft() }
            .fileImporter(
                isPresented: $pickingAttachment,
                allowedContentTypes: [.image, .pdf, .plainText, .commaSeparatedText],
                allowsMultipleSelection: false
            ) { result in
                if case .success(let urls) = result, let url = urls.first {
                    importAttachment(url)
                }
            }
        }
    }

    private var canSubmit: Bool {
        !title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty &&
        !description.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    private var input: SafetyReportInput {
        SafetyReportInput(
            category: category,
            title: title.trimmingCharacters(in: .whitespacesAndNewlines),
            description: description.trimmingCharacters(in: .whitespacesAndNewlines),
            occurredAtUTC: ISO8601DateFormatter().string(from: occurredAt),
            location: location.trimmingCharacters(in: .whitespacesAndNewlines),
            aircraftRegistration: aircraftRegistration.trimmingCharacters(in: .whitespacesAndNewlines).uppercased(),
            immediateAction: immediateAction.trimmingCharacters(in: .whitespacesAndNewlines)
        )
    }

    private func submit() async {
        submitting = true
        errorMessage = nil
        defer { submitting = false }
        do {
            saveDraft()
            switch mode {
            case .identified:
                let report = try await session.createAndSubmitSafetyReport(input, attachments: attachments)
                attachments.forEach(SafetyDraftAttachmentFileStore.remove)
                IdentifiedSafetyDraftStore.clear(userUUID: session.user?.uuid ?? "")
                onSubmitted(report)
            case .anonymous:
                let receipt = try await session.submitAnonymousSafetyReport(input)
                onAnonymousSubmitted(receipt)
            }
            submitted = true
            dismiss()
        } catch {
            saveDraft()
            errorMessage = error.localizedDescription
        }
    }

    private func restoreDraft() {
        let submission: SafetySubmissionDraft?
        switch mode {
        case .identified:
            submission = IdentifiedSafetyDraftStore.loadSubmission(userUUID: session.user?.uuid ?? "")
        case .anonymous:
            submission = AnonymousSafetyDraftStore.load()
        }
        guard let submission else { return }
        let draft = submission.input
        category = draft.category
        title = draft.title
        description = draft.description
        location = draft.location
        aircraftRegistration = draft.aircraftRegistration
        immediateAction = draft.immediateAction
        if let date = ISO8601DateFormatter().date(from: draft.occurredAtUTC) {
            occurredAt = date
        }
        if mode == .identified {
            attachments = submission.attachments
        }
    }

    private func saveDraft() {
        guard !submitted, canSubmit else { return }
        switch mode {
        case .identified:
            let userUUID = session.user?.uuid ?? ""
            var state = IdentifiedSafetyDraftStore.loadSubmission(userUUID: userUUID)
                ?? SafetySubmissionDraft(
                    input: input,
                    idempotencyKey: UUID().uuidString.lowercased(),
                    remoteReportUUID: nil,
                    attachments: attachments
                )
            state.input = input
            state.attachments = attachments
            IdentifiedSafetyDraftStore.save(state, userUUID: userUUID)
        case .anonymous:
            var state = AnonymousSafetyDraftStore.load()
                ?? SafetySubmissionDraft(
                    input: input,
                    idempotencyKey: UUID().uuidString.lowercased(),
                    remoteReportUUID: nil,
                    attachments: []
                )
            if state.input != input {
                state.idempotencyKey = UUID().uuidString.lowercased()
            }
            state.input = input
            AnonymousSafetyDraftStore.save(state)
        }
    }

    private func importAttachment(_ url: URL) {
        do {
            guard let mimeType = Self.mimeType(for: url) else {
                throw SafetyAttachmentError.unsupportedType
            }
            attachments.append(try SafetyDraftAttachmentFileStore.importFile(
                at: url,
                filename: url.lastPathComponent,
                mimeType: mimeType
            ))
            saveDraft()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func removeAttachment(_ attachment: SafetyDraftAttachment) {
        SafetyDraftAttachmentFileStore.remove(attachment)
        attachments.removeAll { $0.attachmentUUID == attachment.attachmentUUID }
        saveDraft()
    }

    private static func mimeType(for url: URL) -> String? {
        let type = UTType(filenameExtension: url.pathExtension)
        if type?.conforms(to: .jpeg) == true { return "image/jpeg" }
        if type?.conforms(to: .png) == true { return "image/png" }
        if type?.conforms(to: .webP) == true { return "image/webp" }
        if type?.conforms(to: .heic) == true { return "image/heic" }
        if type?.conforms(to: .heif) == true { return "image/heif" }
        if type?.conforms(to: .pdf) == true { return "application/pdf" }
        if type?.conforms(to: .commaSeparatedText) == true { return "text/csv" }
        if type?.conforms(to: .plainText) == true { return "text/plain" }
        return nil
    }
}

struct AnonymousSafetyAccessView: View {
    @Environment(\.dismiss) private var dismiss
    @EnvironmentObject private var session: AppSession
    let serverURL: String
    @State private var composing = false
    @State private var status: AnonymousSafetyStatus?
    @State private var mailbox: [SafetyMailboxMessageDTO] = []
    @State private var mailboxDraft = ""
    @State private var sendingMailbox = false
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            ZStack {
                IPCABackground()
                ScrollView {
                    VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                        VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
                            IPCAIconTile(systemImage: "person.fill.questionmark", size: 50)
                            Text("Report without signing in")
                                .font(.title2.bold())
                                .foregroundStyle(IPCATheme.Colors.textPrimary)
                            Text("The anonymous reporting calls do not include your session token or IPCA device identity.")
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                            Button("Start Anonymous Report") { composing = true }
                                .font(.headline)
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 13)
                                .foregroundStyle(.white)
                                .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium))
                        }
                        .padding(IPCATheme.Spacing.md)
                        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card))

                        if let status {
                            IPCASectionHeader(title: "Saved Receipt")
                            SafetyUpdateCard(
                                title: status.reference.isEmpty ? status.receiptID : status.reference,
                                message: "Status: \(status.status.replacingOccurrences(of: "_", with: " ").capitalized)",
                                date: status.updatedAtUTC
                            )
                        } else if AnonymousSafetyReceiptStore.receiptID != nil {
                            ProgressView("Checking saved receipt…")
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                        }

                        if !mailbox.isEmpty {
                            IPCASectionHeader(title: "Anonymous Mailbox")
                            ForEach(mailbox) { message in
                                SafetyUpdateCard(title: message.senderLabel, message: message.body, date: message.createdAtUTC)
                            }
                        }
                        if status != nil {
                            SafetyMailboxComposer(
                                draft: $mailboxDraft,
                                sending: sendingMailbox,
                                errorMessage: nil
                            ) {
                                await sendMailboxMessage()
                            }
                        }
                        if let errorMessage {
                            Text(errorMessage)
                                .font(.footnote)
                                .foregroundStyle(IPCATheme.Colors.destructive)
                        }
                    }
                    .padding(IPCATheme.Spacing.screen)
                }
            }
            .navigationTitle("Anonymous Safety")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { dismiss() }
                }
            }
            .sheet(isPresented: $composing) {
                SafetyReportFormView(mode: .anonymous) { _ in
                } onAnonymousSubmitted: { receipt in
                    status = AnonymousSafetyStatus(
                        ok: true,
                        receiptID: receipt.receiptID,
                        reference: receipt.reference,
                        status: receipt.status,
                        updatedAtUTC: ""
                    )
                }
                .environmentObject(session)
            }
            .task {
                await session.configureSafetyServer(serverURL)
                await loadReceipt()
            }
        }
    }

    private func loadReceipt() async {
        guard AnonymousSafetyReceiptStore.receiptID != nil else { return }
        do {
            async let statusRequest = session.loadAnonymousSafetyStatus()
            async let mailboxRequest = session.loadAnonymousSafetyMailbox()
            status = try await statusRequest
            mailbox = (try? await mailboxRequest) ?? []
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func sendMailboxMessage() async {
        let body = mailboxDraft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty, !sendingMailbox else { return }
        sendingMailbox = true
        defer { sendingMailbox = false }
        do {
            try await session.postAnonymousSafetyMailbox(body)
            mailboxDraft = ""
            mailbox = try await session.loadAnonymousSafetyMailbox()
            errorMessage = nil
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
