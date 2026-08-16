import CoreData
import PhotosUI
import SwiftUI
import UIKit
import UniformTypeIdentifiers

struct ConversationView: View {
    let conversationUUID: String
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var draft = ""
    @State private var pendingAttachments: [PendingAttachment] = []
    @State private var pickingPhotos = false
    @State private var pickingFiles = false
    @State private var capturingCamera = false
    @State private var showingAttachOptions = false
    @State private var photoItem: PhotosPickerItem?
    @State private var replyTarget: ReplyToDTO?
    @FocusState private var composerFocused: Bool

    @FetchRequest private var messages: FetchedResults<MessageEntity>
    @FetchRequest private var conversations: FetchedResults<ConversationEntity>
    @FetchRequest private var members: FetchedResults<MemberEntity>

    init(conversationUUID: String) {
        self.conversationUUID = conversationUUID
        _messages = FetchRequest(
            sortDescriptors: [
                NSSortDescriptor(key: "seq", ascending: true),
                NSSortDescriptor(key: "createdAt", ascending: true)
            ],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
        _conversations = FetchRequest(
            sortDescriptors: [],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
        _members = FetchRequest(
            sortDescriptors: [NSSortDescriptor(key: "name", ascending: true)],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
    }

    var body: some View {
        GeometryReader { geo in
            VStack(spacing: 0) {
                ScrollViewReader { proxy in
                    ScrollView {
                        LazyVStack(alignment: .leading, spacing: 10) {
                            ForEach(Array(orderedMessages.enumerated()), id: \.element.clientID) { index, message in
                                if shouldShowDayHeader(at: index) {
                                    dayHeader(message.createdAt)
                                }
                                MessageBubble(
                                    message: message,
                                    members: Array(members),
                                    currentUserUUID: session.user?.uuid ?? "",
                                    showReceipt: message.clientID == latestOutgoingClientID,
                                    onReply: { target in
                                        replyTarget = target
                                        composerFocused = true
                                    },
                                    onReact: { emoji in
                                        Task { await session.react(messageUUID: message.messageUUID, emoji: emoji) }
                                    }
                                )
                                .id(message.clientID)
                            }
                        }
                        .padding(.horizontal, IPCATheme.Spacing.md)
                        .padding(.vertical, IPCATheme.Spacing.xs)
                    }
                    .onChange(of: orderedMessages.count) {
                        if let last = orderedMessages.last {
                            proxy.scrollTo(last.clientID, anchor: .bottom)
                        }
                    }
                    .onChange(of: lastSeq) { _, seq in
                        guard seq > 0 else { return }
                        Task {
                            await session.markRead(conversationUUID: conversationUUID, seq: seq)
                        }
                    }
                    .onAppear {
                        if let last = orderedMessages.last {
                            proxy.scrollTo(last.clientID, anchor: .bottom)
                        }
                        if lastSeq > 0 {
                            Task {
                                await session.markRead(conversationUUID: conversationUUID, seq: lastSeq)
                            }
                        }
                    }
                }
                if composerVisible {
                    ComposerBar(
                        text: $draft,
                        pending: $pendingAttachments,
                        focused: $composerFocused,
                        attachmentsEnabled: session.capabilities.attachmentsEnabled,
                        replyTarget: replyTarget,
                        onCancelReply: { replyTarget = nil },
                        onAttach: { showingAttachOptions = true },
                        onSend: sendDraft
                    )
                }
            }
            .frame(width: geo.size.width, height: geo.size.height, alignment: .top)
        }
        .confirmationDialog("Attach", isPresented: $showingAttachOptions, titleVisibility: .visible) {
            if UIImagePickerController.isSourceTypeAvailable(.camera) {
                Button("Camera") { capturingCamera = true }
            }
            Button("Photo Library") { pickingPhotos = true }
            Button("Files") { pickingFiles = true }
            Button("Cancel", role: .cancel) {}
        }
        .fullScreenCover(isPresented: $capturingCamera) {
            CameraCaptureView(
                onCapture: { data, mime in
                    capturingCamera = false
                    addPending(data: data, filename: "photo.jpg", mimeType: mime)
                },
                onCancel: { capturingCamera = false }
            )
            .ignoresSafeArea()
        }
        .photosPicker(isPresented: $pickingPhotos, selection: $photoItem, matching: .images)
        .onChange(of: photoItem) { _, item in
            guard let item else { return }
            Task { await importPhoto(item) }
        }
        .fileImporter(isPresented: $pickingFiles, allowedContentTypes: [.image, .pdf], allowsMultipleSelection: false) { result in
            if case .success(let urls) = result, let url = urls.first {
                importFile(url)
            }
        }
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.hidden, for: .bottomBar)
        .toolbar(.visible, for: .navigationBar)
        .toolbarBackground(IPCATheme.Colors.navyBase, for: .navigationBar)
        .toolbarBackground(.visible, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .toolbar {
            ToolbarItem(placement: .principal) {
                HStack(spacing: 10) {
                    conversationAvatar
                    VStack(alignment: .leading, spacing: 1) {
                        Text(title)
                            .font(.headline)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .lineLimit(1)
                        if let subtitle = headerSubtitle {
                            Text(subtitle)
                                .font(.caption)
                                .foregroundStyle(IPCATheme.Colors.textSecondary)
                                .lineLimit(1)
                        }
                    }
                }
            }
        }
        .background(IPCABackground())
        .onAppear {
            session.selectedConversationUUID = conversationUUID
        }
    }

    private var title: String {
        conversations.first?.title ?? "Messages"
    }

    private var conversationType: String {
        conversations.first?.conversationType ?? ""
    }

    private var headerPeer: MemberEntity? {
        members.first { $0.userUUID != session.user?.uuid }
    }

    private var headerSubtitle: String? {
        if conversationType == "group" {
            return "Group"
        }
        if conversationType == "announcement" || conversationType == "system" {
            return "Official"
        }
        let role = IPCATheme.formattedRole(headerPeer?.role ?? "")
        return role.isEmpty ? nil : role
    }

    @ViewBuilder
    private var conversationAvatar: some View {
        if conversationType == "system" || conversationType == "announcement" {
            IPCAAvatar(name: title, systemImage: "megaphone.fill", size: 32)
        } else if conversationType == "group" {
            IPCAAvatar(name: title, systemImage: "person.3.fill", size: 32)
        } else {
            IPCAAvatar(name: title, size: 32)
        }
    }

    private var latestOutgoingClientID: String? {
        orderedMessages.last(where: { $0.isFromMe && $0.senderType != "system" })?.clientID
    }

    private func shouldShowDayHeader(at index: Int) -> Bool {
        let messages = orderedMessages
        guard messages.indices.contains(index) else { return false }
        if index == 0 { return true }
        return !Calendar.current.isDate(messages[index].createdAt, inSameDayAs: messages[index - 1].createdAt)
    }

    private func dayHeader(_ date: Date) -> some View {
        Text(dayLabel(date))
            .font(.caption.weight(.semibold))
            .foregroundStyle(IPCATheme.Colors.textTertiary)
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
            .overlay {
                HStack {
                    Rectangle().fill(IPCATheme.Colors.separator).frame(height: 1)
                    Spacer().frame(width: 88)
                    Rectangle().fill(IPCATheme.Colors.separator).frame(height: 1)
                }
            }
    }

    private func dayLabel(_ date: Date) -> String {
        if Calendar.current.isDateInToday(date) { return "Today" }
        if Calendar.current.isDateInYesterday(date) { return "Yesterday" }
        return date.formatted(date: .abbreviated, time: .omitted)
    }

    private var composerVisible: Bool {
        let type = conversations.first?.conversationType ?? ""
        return type != "announcement" && type != "system"
    }

    private var lastSeq: Int {
        Int(orderedMessages.map(\.seq).max() ?? 0)
    }

    private var orderedMessages: [MessageEntity] {
        let confirmed = messages.filter { $0.seq > 0 }.sorted { $0.seq < $1.seq }
        let pending = messages.filter { $0.seq == 0 }.sorted { $0.createdAt < $1.createdAt }
        return confirmed + pending
    }

    private func sendDraft() {
        let body = draft
        let attachments = pendingAttachments
        let reply = replyTarget
        draft = ""
        pendingAttachments = []
        replyTarget = nil
        Task {
            await session.send(
                conversationUUID: conversationUUID,
                body: body,
                attachments: attachments,
                replyTo: reply
            )
        }
    }

    private func importPhoto(_ item: PhotosPickerItem) async {
        photoItem = nil
        guard let data = try? await item.loadTransferable(type: Data.self) else { return }
        let mime = item.supportedContentTypes.first.flatMap(Self.mimeType(for:)) ?? "image/jpeg"
        let filename = mime == "image/png" ? "photo.png" : (mime.contains("heic") ? "photo.heic" : "photo.jpg")
        addPending(data: data, filename: filename, mimeType: mime)
    }

    private func importFile(_ url: URL) {
        let accessed = url.startAccessingSecurityScopedResource()
        defer {
            if accessed {
                url.stopAccessingSecurityScopedResource()
            }
        }
        guard let data = try? Data(contentsOf: url) else { return }
        let mime = Self.mimeType(for: url) ?? "application/pdf"
        addPending(data: data, filename: url.lastPathComponent, mimeType: mime)
    }

    private func addPending(data: Data, filename: String, mimeType: String) {
        guard data.count <= 25 * 1024 * 1024 else {
            session.actionError = "That file is too large to send."
            return
        }
        let uuid = UUID().uuidString.lowercased()
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("IPCAAttachments", isDirectory: true)
        do {
            try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
            let fileURL = dir.appendingPathComponent(uuid)
            try data.write(to: fileURL, options: .atomic)
            pendingAttachments.append(PendingAttachment(
                attachmentUUID: uuid,
                filename: filename,
                mimeType: mimeType,
                byteSize: data.count,
                localURL: fileURL
            ))
        } catch {
            session.actionError = "Couldn't attach that file."
        }
    }

    private static func mimeType(for type: UTType) -> String? {
        if type.conforms(to: .jpeg) { return "image/jpeg" }
        if type.conforms(to: .png) { return "image/png" }
        if type.conforms(to: .gif) { return "image/gif" }
        if type.conforms(to: .webP) { return "image/webp" }
        if type.identifier == "public.heic" || type.identifier == "public.heif" { return "image/heic" }
        if type.conforms(to: .pdf) { return "application/pdf" }
        if type.conforms(to: .image) { return "image/jpeg" }
        return type.preferredMIMEType
    }

    private static func mimeType(for url: URL) -> String? {
        mimeType(for: UTType(filenameExtension: url.pathExtension) ?? .data)
    }
}

struct MessageBubble: View {
    @ObservedObject var message: MessageEntity
    var members: [MemberEntity]
    var currentUserUUID: String
    var showReceipt: Bool = true
    var onReply: (ReplyToDTO) -> Void
    var onReact: (String) -> Void
    @EnvironmentObject private var session: AppSession
    @State private var showingActions = false

    private let availableReactions = ["👍", "❤️", "😂", "😮", "😢", "🙏"]

    var body: some View {
        Group {
            if message.senderType == "system" {
                officialBubble
            } else {
                HStack {
                    if message.isFromMe { Spacer(minLength: 48) }
                    VStack(alignment: message.isFromMe ? .trailing : .leading, spacing: 4) {
                        if !message.isFromMe, !message.senderDisplayName.isEmpty, conversationShowsSender {
                            Text(message.senderDisplayName)
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                        }
                        if let reply = parsedReply {
                            replyPreview(reply)
                        }
                        ForEach(parsedAttachments, id: \.attachmentUUID) { attachment in
                            AttachmentChip(attachment: attachment)
                        }
                        if !message.body.isEmpty {
                            Text(message.body)
                                .padding(.horizontal, 14)
                                .padding(.vertical, 10)
                                .foregroundStyle(IPCATheme.Colors.textPrimary)
                                .background {
                                    if message.isFromMe {
                                        UnevenRoundedRectangle(
                                            topLeadingRadius: IPCATheme.Radius.medium,
                                            bottomLeadingRadius: IPCATheme.Radius.medium,
                                            bottomTrailingRadius: 6,
                                            topTrailingRadius: IPCATheme.Radius.medium
                                        )
                                        .fill(IPCATheme.outgoingBubbleGradient)
                                    } else {
                                        UnevenRoundedRectangle(
                                            topLeadingRadius: IPCATheme.Radius.medium,
                                            bottomLeadingRadius: 6,
                                            bottomTrailingRadius: IPCATheme.Radius.medium,
                                            topTrailingRadius: IPCATheme.Radius.medium
                                        )
                                        .fill(IPCATheme.Colors.incomingBubble)
                                    }
                                }
                                .onTapGesture { presentUserActions() }
                        } else if parsedAttachments.isEmpty {
                            Text(" ")
                                .padding(.horizontal, 14)
                                .padding(.vertical, 10)
                                .background {
                                    if message.isFromMe {
                                        RoundedRectangle(cornerRadius: IPCATheme.Radius.medium)
                                            .fill(IPCATheme.outgoingBubbleGradient)
                                    } else {
                                        RoundedRectangle(cornerRadius: IPCATheme.Radius.medium)
                                            .fill(IPCATheme.Colors.incomingBubble)
                                    }
                                }
                                .onTapGesture { presentUserActions() }
                        }
                        if !parsedReactions.isEmpty {
                            reactionChips
                        }
                        caption
                    }
                    if !message.isFromMe { Spacer(minLength: 48) }
                }
                .onLongPressGesture { presentUserActions() }
                .accessibilityElement(children: .contain)
                .accessibilityLabel(accessibilityText)
            }
        }
        .confirmationDialog("Message", isPresented: $showingActions, titleVisibility: .visible) {
            if message.replyAllowed {
                Button("Reply") { onReply(replyTarget) }
            }
            ForEach(availableReactions, id: \.self) { emoji in
                Button("\(emoji) React") { onReact(emoji) }
            }
            Button("Cancel", role: .cancel) {}
        }
    }

    private func presentUserActions() {
        guard message.seq > 0, message.senderType != "system" else { return }
        showingActions = true
    }

    private var conversationShowsSender: Bool {
        members.filter { !$0.userUUID.isEmpty }.count > 2
    }

    private var replyTarget: ReplyToDTO {
        ReplyToDTO(
            messageUUID: message.messageUUID,
            senderDisplayName: message.isFromMe
                ? "You"
                : (message.senderDisplayName.isEmpty ? "Message" : message.senderDisplayName),
            bodyPreview: String((message.body.isEmpty ? "Attachment" : message.body).prefix(80))
        )
    }

    @ViewBuilder
    private func replyPreview(_ reply: ReplyToDTO) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(reply.senderDisplayName)
                .font(.caption2.weight(.semibold))
            Text(reply.bodyPreview)
                .font(.caption)
                .lineLimit(2)
        }
        .foregroundStyle(IPCATheme.Colors.textSecondary)
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .frame(maxWidth: 260, alignment: .leading)
        .background(IPCATheme.Colors.navyElevated, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.small, style: .continuous))
    }

    private var reactionChips: some View {
        HStack(spacing: 4) {
            ForEach(parsedReactions) { reaction in
                Button {
                    onReact(reaction.emoji)
                } label: {
                    Text("\(reaction.emoji) \(reaction.count)")
                        .font(.caption)
                        .padding(.horizontal, 7)
                        .padding(.vertical, 3)
                        .background(
                            reaction.reactedByMe ? IPCATheme.Colors.ipcaBlue.opacity(0.22) : IPCATheme.Colors.navyElevated,
                            in: Capsule()
                        )
                }
                .buttonStyle(.plain)
                .accessibilityLabel("\(reaction.emoji), \(reaction.count) reactions")
            }
        }
    }

    private var officialBubble: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                Image(systemName: "megaphone.fill")
                    .font(.caption)
                Text(message.senderDisplayName.isEmpty ? "IPCA" : message.senderDisplayName)
                    .font(.caption.weight(.semibold))
            }
            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
            if !message.body.isEmpty {
                Text(message.body)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 10)
                    .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                    .overlay(
                        RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                            .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                    )
                    .onTapGesture {
                        guard message.seq > 0 else { return }
                        showingActions = true
                    }
            }
            if !parsedReactions.isEmpty {
                reactionChips
            }
            if message.requiresAcknowledgement {
                if message.acknowledgedAt != nil {
                    Text("Acknowledged")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                } else {
                    Button("Acknowledge") {
                        Task { await session.acknowledge(messageUUID: message.messageUUID) }
                    }
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(IPCATheme.interactiveGradient, in: Capsule())
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.vertical, 4)
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilityText)
    }

    @ViewBuilder
    private var caption: some View {
        HStack(spacing: 6) {
            Text(message.createdAt, style: .time)
            switch LocalMessageState(rawValue: message.localState) {
            case .queued, .sending:
                Text("Sending…")
            case .failed:
                Button("Not Delivered — Tap to Retry") {
                    Task { await session.retry(clientID: message.clientID) }
                }
                .foregroundStyle(IPCATheme.Colors.destructive)
            case .serverReceived, .none:
                if message.isFromMe, showReceipt, let receipt = receiptLabel {
                    Text(receipt)
                }
            }
        }
        .font(.caption2)
        .foregroundStyle(IPCATheme.Colors.textTertiary)
    }

    private var receiptLabel: String? {
        guard message.seq > 0 else { return "Sent" }
        let others = members.filter { $0.userUUID != currentUserUUID }
        if others.isEmpty { return "Sent" }
        if others.allSatisfy({ $0.lastReadSeq >= message.seq }) { return "Read" }
        if others.allSatisfy({ $0.lastDeliveredSeq >= message.seq }) { return "Delivered" }
        return "Sent"
    }

    private var parsedAttachments: [AttachmentDTO] {
        (try? JSONDecoder().decode([AttachmentDTO].self, from: Data(message.attachmentsJSON.utf8))) ?? []
    }

    private var parsedReply: ReplyToDTO? {
        guard !message.replyToJSON.isEmpty,
              let data = message.replyToJSON.data(using: .utf8) else { return nil }
        return try? JSONDecoder().decode(ReplyToDTO.self, from: data)
    }

    private var parsedReactions: [ReactionDTO] {
        guard !message.reactionsJSON.isEmpty,
              let data = message.reactionsJSON.data(using: .utf8) else { return [] }
        return (try? JSONDecoder().decode([ReactionDTO].self, from: data)) ?? []
    }

    private var accessibilityText: String {
        var text = message.body
        if text.isEmpty, let first = parsedAttachments.first {
            text = first.filename.isEmpty ? "Attachment" : first.filename
        }
        if message.localState == LocalMessageState.sending.rawValue || message.localState == LocalMessageState.queued.rawValue {
            text += ", Sending"
        }
        if message.localState == LocalMessageState.failed.rawValue {
            text += ", Not delivered"
        } else if message.isFromMe, let receipt = receiptLabel {
            text += ", \(receipt)"
        }
        if message.requiresAcknowledgement {
            text += message.acknowledgedAt == nil ? ", Acknowledge" : ", Acknowledged"
        }
        return text
    }
}

struct AttachmentChip: View {
    let attachment: AttachmentDTO
    @EnvironmentObject private var session: AppSession
    @State private var remoteURL: URL?
    @State private var showingFullScreen = false

    var body: some View {
        Group {
            if attachment.mimeType.hasPrefix("image/") {
                Button {
                    showingFullScreen = true
                } label: {
                    imagePreview
                        .frame(maxWidth: 220, maxHeight: 220)
                        .clipShape(RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                        .overlay(
                            RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                                .stroke(IPCATheme.Colors.ipcaBlue.opacity(0.45), lineWidth: 1)
                        )
                        .contentShape(RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                }
                .buttonStyle(.plain)
                .accessibilityLabel(attachment.filename.isEmpty ? "Photo" : attachment.filename)
                .accessibilityHint("Shows the photo full screen")
            } else {
                Label(attachment.filename.isEmpty ? "File" : attachment.filename, systemImage: "doc")
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .background(IPCATheme.Colors.navyElevated, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
            }
        }
        .fullScreenCover(isPresented: $showingFullScreen) {
            ImageLightboxView(attachment: attachment)
                .environmentObject(session)
        }
        .task(id: attachment.attachmentUUID) {
            guard attachment.mimeType.hasPrefix("image/"), localImage == nil else { return }
            remoteURL = await session.downloadURL(for: attachment.attachmentUUID)
        }
    }

    @ViewBuilder
    private var imagePreview: some View {
        if let image = localImage {
            Image(uiImage: image)
                .resizable()
                .scaledToFit()
        } else if let remoteURL {
            AsyncImage(url: remoteURL) { phase in
                switch phase {
                case .success(let image):
                    image.resizable().scaledToFit()
                default:
                    ProgressView().padding()
                }
            }
        } else {
            Label("Photo", systemImage: "photo")
                .padding()
                .foregroundStyle(IPCATheme.Colors.textSecondary)
                .background(IPCATheme.Colors.navyElevated)
        }
    }

    private var localImage: UIImage? {
        guard let path = attachment.localPath, FileManager.default.fileExists(atPath: path) else { return nil }
        return UIImage(contentsOfFile: path)
    }
}

struct ImageLightboxView: View {
    let attachment: AttachmentDTO
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var image: UIImage?
    @State private var failed = false

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            if let image {
                ZoomableImageView(image: image)
                    .ignoresSafeArea()
            } else if failed {
                ContentUnavailableView("Couldn't Load Photo", systemImage: "photo")
                    .foregroundStyle(.white)
            } else {
                ProgressView()
                    .tint(.white)
                    .controlSize(.large)
            }
            VStack {
                HStack {
                    Spacer()
                    Button {
                        dismiss()
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .font(.title)
                            .symbolRenderingMode(.hierarchical)
                            .foregroundStyle(.white)
                    }
                    .accessibilityLabel("Close")
                }
                Spacer()
            }
            .padding()
        }
        .statusBarHidden()
        .task { await loadImage() }
    }

    private func loadImage() async {
        if let path = attachment.localPath, FileManager.default.fileExists(atPath: path),
           let local = UIImage(contentsOfFile: path) {
            image = local
            return
        }
        guard let url = await session.downloadURL(for: attachment.attachmentUUID) else {
            failed = true
            return
        }
        do {
            let (data, _) = try await URLSession.shared.data(from: url)
            if let loaded = UIImage(data: data) {
                image = loaded
            } else {
                failed = true
            }
        } catch {
            failed = true
        }
    }
}

struct ZoomableImageView: UIViewRepresentable {
    let image: UIImage

    func makeCoordinator() -> Coordinator {
        Coordinator()
    }

    func makeUIView(context: Context) -> ZoomScrollView {
        let scroll = ZoomScrollView()
        scroll.delegate = context.coordinator
        scroll.minimumZoomScale = 1
        scroll.maximumZoomScale = 4
        scroll.bouncesZoom = true
        scroll.showsHorizontalScrollIndicator = false
        scroll.showsVerticalScrollIndicator = false
        scroll.backgroundColor = .black
        scroll.contentInsetAdjustmentBehavior = .never

        let imageView = UIImageView(image: image)
        imageView.contentMode = .scaleAspectFit
        imageView.isUserInteractionEnabled = true
        imageView.backgroundColor = .black
        scroll.addSubview(imageView)
        scroll.imageView = imageView
        context.coordinator.scroll = scroll

        let doubleTap = UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.handleDoubleTap(_:)))
        doubleTap.numberOfTapsRequired = 2
        scroll.addGestureRecognizer(doubleTap)
        return scroll
    }

    func updateUIView(_ scroll: ZoomScrollView, context: Context) {
        scroll.imageView?.image = image
        if scroll.zoomScale == 1 {
            scroll.layoutImage()
        }
    }

    final class Coordinator: NSObject, UIScrollViewDelegate {
        weak var scroll: ZoomScrollView?

        func viewForZooming(in scrollView: UIScrollView) -> UIView? {
            (scrollView as? ZoomScrollView)?.imageView
        }

        @objc func handleDoubleTap(_ gesture: UITapGestureRecognizer) {
            guard let scroll else { return }
            if scroll.zoomScale > 1.01 {
                scroll.setZoomScale(1, animated: true)
            } else {
                let point = gesture.location(in: scroll.imageView)
                let size = scroll.bounds.size
                let rect = CGRect(
                    x: point.x - size.width / 4,
                    y: point.y - size.height / 4,
                    width: size.width / 2,
                    height: size.height / 2
                )
                scroll.zoom(to: rect, animated: true)
            }
        }
    }
}

final class ZoomScrollView: UIScrollView {
    var imageView: UIImageView?

    override func layoutSubviews() {
        super.layoutSubviews()
        if zoomScale == 1 {
            layoutImage()
        }
    }

    func layoutImage() {
        guard let imageView else { return }
        imageView.frame = bounds
        contentSize = bounds.size
    }
}

struct ComposerBar: View {
    @Binding var text: String
    @Binding var pending: [PendingAttachment]
    var focused: FocusState<Bool>.Binding
    var attachmentsEnabled: Bool
    var replyTarget: ReplyToDTO?
    var onCancelReply: () -> Void
    var onAttach: () -> Void
    var onSend: () -> Void

    var body: some View {
        VStack(spacing: IPCATheme.Spacing.xs) {
            if let replyTarget {
                HStack(spacing: IPCATheme.Spacing.xs) {
                    Rectangle()
                        .fill(IPCATheme.Colors.ipcaBlue)
                        .frame(width: 3)
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Replying to \(replyTarget.senderDisplayName)")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                        Text(replyTarget.bodyPreview)
                            .font(.caption)
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                            .lineLimit(1)
                    }
                    Spacer()
                    Button(action: onCancelReply) {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(IPCATheme.Colors.textTertiary)
                    }
                    .accessibilityLabel("Cancel reply")
                }
                .padding(.horizontal, IPCATheme.Spacing.md)
            }
            if !pending.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: IPCATheme.Spacing.xs) {
                        ForEach(pending) { item in
                            HStack(spacing: 6) {
                                Image(systemName: item.mimeType.hasPrefix("image/") ? "photo" : "doc")
                                Text(item.filename)
                                    .lineLimit(1)
                                Button {
                                    pending.removeAll { $0.attachmentUUID == item.attachmentUUID }
                                } label: {
                                    Image(systemName: "xmark.circle.fill")
                                }
                                .accessibilityLabel("Remove \(item.filename)")
                            }
                            .font(.caption)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 6)
                            .background(IPCATheme.Colors.navyElevated, in: Capsule())
                        }
                    }
                    .padding(.horizontal, IPCATheme.Spacing.md)
                }
            }
            HStack(alignment: .bottom, spacing: IPCATheme.Spacing.xs) {
                if attachmentsEnabled {
                    Button(action: onAttach) {
                        Image(systemName: "paperclip")
                            .font(.system(size: 18, weight: .semibold))
                            .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                            .frame(width: 40, height: 40)
                            .background(IPCATheme.Colors.navySurface, in: Circle())
                            .overlay(Circle().stroke(IPCATheme.Colors.separator, lineWidth: 1))
                    }
                    .accessibilityLabel("Attach")
                }
                TextField("Message", text: $text, axis: .vertical)
                    .textFieldStyle(.plain)
                    .lineLimit(1...6)
                    .focused(focused)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 10)
                    .background(IPCATheme.Colors.navySurface, in: Capsule())
                    .overlay(Capsule().stroke(IPCATheme.Colors.separator, lineWidth: 1))
                Button(action: onSend) {
                    Image(systemName: "arrow.up")
                        .font(.system(size: 16, weight: .bold))
                        .foregroundStyle(.white)
                        .frame(width: 40, height: 40)
                        .background(
                            canSend ? AnyShapeStyle(IPCATheme.interactiveGradient) : AnyShapeStyle(IPCATheme.Colors.navyElevated),
                            in: Circle()
                        )
                }
                .disabled(!canSend)
                .accessibilityLabel("Send")
            }
            .padding(.horizontal, IPCATheme.Spacing.md)
            .padding(.vertical, IPCATheme.Spacing.xs)
        }
        .padding(.top, 6)
        .background(IPCATheme.Colors.navyBase)
        .overlay(alignment: .top) {
            Rectangle().fill(IPCATheme.Colors.separator).frame(height: 0.5)
        }
    }

    private var canSend: Bool {
        !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || !pending.isEmpty
    }
}

struct CameraCaptureView: UIViewControllerRepresentable {
    var onCapture: (Data, String) -> Void
    var onCancel: () -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(onCapture: onCapture, onCancel: onCancel)
    }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.cameraCaptureMode = .photo
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onCapture: (Data, String) -> Void
        let onCancel: () -> Void

        init(onCapture: @escaping (Data, String) -> Void, onCancel: @escaping () -> Void) {
            self.onCapture = onCapture
            self.onCancel = onCancel
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            onCancel()
        }

        func imagePickerController(
            _ picker: UIImagePickerController,
            didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]
        ) {
            guard let image = (info[.originalImage] as? UIImage),
                  let data = image.jpegData(compressionQuality: 0.85) else {
                onCancel()
                return
            }
            onCapture(data, "image/jpeg")
        }
    }
}
