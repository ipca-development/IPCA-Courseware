import AVFoundation
import AVKit
import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

struct CommunityView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var posts: [CommunityPostDTO] = []
    @State private var postingEnabled = true
    @State private var nextCursor: Int?
    @State private var loading = true
    @State private var loadFailed = false
    @State private var selectedPostUUID: String?
    @State private var composing = false

    var body: some View {
        Group {
            if sizeClass == .regular {
                HStack(spacing: 0) {
                    feedStack
                        .frame(width: 380)
                    Divider()
                        .background(IPCATheme.Colors.separator)
                    NavigationStack {
                        if let post = selectedPost {
                            CommunityPostDetailView(post: post, onChange: replacePost, onDeleted: removePost)
                        } else {
                            ContentUnavailableView("Community", systemImage: "person.3", description: Text("Choose a post."))
                        }
                    }
                    .id(selectedPostUUID ?? "empty")
                }
            } else {
                feedStack
            }
        }
        .task { await reload() }
        .onChange(of: session.pendingCommunityPostUUID) { _, uuid in
            guard let uuid else { return }
            selectedPostUUID = uuid
            session.pendingCommunityPostUUID = nil
            Task { await ensurePost(uuid) }
        }
    }

    private var selectedPost: CommunityPostDTO? {
        posts.first { $0.postUUID == selectedPostUUID }
    }

    private var feedStack: some View {
        NavigationStack {
            VStack(spacing: 0) {
                IPCARootHeader(title: "Community", subtitle: "Share moments. Inspire each other.") {
                    if postingEnabled {
                        IPCAGradientCircleButton(systemImage: "plus", accessibilityLabel: "New Post") {
                            composing = true
                        }
                    }
                }
                Group {
                    if loading && posts.isEmpty {
                        ProgressView()
                            .tint(IPCATheme.Colors.ipcaBlue)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if loadFailed && posts.isEmpty {
                        ContentUnavailableView(
                            "Couldn't load Community",
                            systemImage: "person.3",
                            description: Text("Pull to try again. Messages still work independently.")
                        )
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else if posts.isEmpty {
                        ContentUnavailableView(
                            "No posts yet",
                            systemImage: "person.3",
                            description: Text("Share a photo from the ramp or a short video from the field.")
                        )
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else {
                        ScrollView {
                            LazyVStack(spacing: IPCATheme.Spacing.md) {
                                feedRows
                            }
                            .padding(.horizontal, IPCATheme.Spacing.screen)
                            .padding(.bottom, IPCATheme.Spacing.lg)
                        }
                        .refreshable { await reload() }
                    }
                }
            }
            .background(IPCABackground())
            .toolbar(.hidden, for: .navigationBar)
            .refreshable { await reload() }
            .sheet(isPresented: $composing) {
                CommunityComposeView { post in
                    posts.insert(post, at: 0)
                    selectedPostUUID = post.postUUID
                }
                .environmentObject(session)
            }
            .navigationDestination(for: String.self) { uuid in
                if let post = posts.first(where: { $0.postUUID == uuid }) {
                    CommunityPostDetailView(post: post, onChange: replacePost, onDeleted: removePost)
                        .ipcaHidesTabBar()
                }
            }
        }
    }

    @ViewBuilder
    private var feedRows: some View {
        ForEach(posts) { post in
            if sizeClass == .regular {
                CommunityPostRow(
                    post: post,
                    suppressPlayback: selectedPostUUID == post.postUUID,
                    onSelect: { selectedPostUUID = post.postUUID }
                )
            } else {
                CommunityPostRow(post: post)
            }
        }
        if nextCursor != nil {
            ProgressView()
                .tint(IPCATheme.Colors.ipcaBlue)
                .frame(maxWidth: .infinity)
                .task { await loadMore() }
        }
    }

    private func replacePost(_ post: CommunityPostDTO) {
        if let index = posts.firstIndex(where: { $0.postUUID == post.postUUID }) {
            posts[index] = post
        }
    }

    private func removePost(_ postUUID: String) {
        posts.removeAll { $0.postUUID == postUUID }
        if selectedPostUUID == postUUID {
            selectedPostUUID = posts.first?.postUUID
        }
    }

    private func reload() async {
        let loaded = await session.loadCommunityFeed()
        if let loaded {
            posts = loaded.posts
            nextCursor = loaded.nextCursor
            postingEnabled = loaded.postingEnabled
            loadFailed = false
            if selectedPostUUID == nil {
                selectedPostUUID = loaded.posts.first?.postUUID
            }
        } else if posts.isEmpty {
            loadFailed = true
        }
        loading = false
        if let pending = session.pendingCommunityPostUUID {
            selectedPostUUID = pending
            session.pendingCommunityPostUUID = nil
            await ensurePost(pending)
        }
    }

    private func loadMore() async {
        guard let cursor = nextCursor else { return }
        nextCursor = nil
        guard let loaded = await session.loadCommunityFeed(cursor: cursor) else { return }
        let existing = Set(posts.map(\.postUUID))
        posts.append(contentsOf: loaded.posts.filter { !existing.contains($0.postUUID) })
        nextCursor = loaded.nextCursor
        postingEnabled = loaded.postingEnabled
    }

    private func ensurePost(_ uuid: String) async {
        if posts.contains(where: { $0.postUUID == uuid }) { return }
        if let post = await session.loadCommunityPost(uuid) {
            posts.insert(post, at: 0)
        }
    }
}

struct CommunityPostRow: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    let post: CommunityPostDTO
    var suppressPlayback = false
    var onSelect: (() -> Void)? = nil

    var body: some View {
        VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
            openable {
                HStack(spacing: 10) {
                    IPCAAvatar(
                        name: post.author.name,
                        photoPath: post.author.photoPath,
                        serverURL: session.serverURLString,
                        size: 36
                    )
                    VStack(alignment: .leading, spacing: 2) {
                        Text(post.author.name)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.lightText)
                        Text(CommunityTime.short(post.createdAtUTC))
                            .font(.caption)
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                    }
                    Spacer()
                }
            }
            if post.media.first?.kind == "video" {
                CommunityMediaView(media: post.media.first, autoplays: false, playbackEnabled: !suppressPlayback)
            } else {
                openable {
                    CommunityMediaView(media: post.media.first)
                }
            }
            openable {
                VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
                    CommunityPostText(caption: post.caption, bodyText: post.body, light: true)
                    HStack(spacing: 16) {
                        Label("\(post.likeCount)", systemImage: post.liked ? "heart.fill" : "heart")
                            .foregroundStyle(post.liked ? Color.red : IPCATheme.Colors.lightSecondary)
                            .accessibilityLabel("Like")
                        Label("\(post.commentCount)", systemImage: "bubble.right")
                            .foregroundStyle(IPCATheme.Colors.lightSecondary)
                            .accessibilityLabel("Comment")
                    }
                    .font(.subheadline.weight(.medium))
                }
            }
        }
        .padding(IPCATheme.Spacing.sm)
        .background(IPCATheme.Colors.lightCard, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                .stroke(Color.black.opacity(0.04), lineWidth: 1)
        )
        .shadow(color: Color.black.opacity(0.18), radius: 12, y: 6)
    }

    @ViewBuilder
    private func openable<Content: View>(@ViewBuilder content: () -> Content) -> some View {
        if sizeClass == .regular {
            content()
                .contentShape(Rectangle())
                .onTapGesture { onSelect?() }
        } else {
            NavigationLink(value: post.postUUID) {
                content()
            }
            .buttonStyle(.plain)
        }
    }
}

struct CommunityPostDetailView: View {
    @EnvironmentObject private var session: AppSession
    @State var post: CommunityPostDTO
    var onChange: (CommunityPostDTO) -> Void
    var onDeleted: (String) -> Void
    @State private var comments: [CommunityCommentDTO] = []
    @State private var draft = ""
    @State private var reporting = false
    @State private var reportReason = "inappropriate"
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                HStack(spacing: 10) {
                    IPCAAvatar(
                        name: post.author.name,
                        photoPath: post.author.photoPath,
                        serverURL: session.serverURLString,
                        size: 40
                    )
                    VStack(alignment: .leading, spacing: 2) {
                        Text(post.author.name)
                            .font(.headline)
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                        Text(CommunityTime.short(post.createdAtUTC))
                            .font(.caption)
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                    }
                    Spacer()
                }
                CommunityMediaView(media: post.media.first, autoplays: true)
                CommunityPostText(caption: post.caption, bodyText: post.body, light: false)
                HStack(spacing: 20) {
                    Button {
                        Task { await toggleLike() }
                    } label: {
                        Label("\(post.likeCount)", systemImage: post.liked ? "heart.fill" : "heart")
                            .foregroundStyle(post.liked ? Color.red : IPCATheme.Colors.textPrimary)
                    }
                    .accessibilityLabel("Like")
                    Label("\(post.commentCount)", systemImage: "bubble.right")
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                        .accessibilityLabel("Comment")
                    Spacer()
                }
                .buttonStyle(.plain)

                VStack(alignment: .leading, spacing: 10) {
                    Text("Comments")
                        .font(.headline)
                        .foregroundStyle(IPCATheme.Colors.textPrimary)
                    if comments.isEmpty {
                        Text("No comments yet.")
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                    } else {
                        ForEach(comments) { comment in
                            HStack(alignment: .top, spacing: 10) {
                                IPCAAvatar(
                                    name: comment.author.name,
                                    photoPath: comment.author.photoPath,
                                    serverURL: session.serverURLString,
                                    size: 32
                                )
                                VStack(alignment: .leading, spacing: 4) {
                                    HStack {
                                        Text(comment.author.name)
                                            .font(.subheadline.weight(.semibold))
                                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                                        Spacer()
                                        Text(CommunityTime.short(comment.createdAtUTC))
                                            .font(.caption)
                                            .foregroundStyle(IPCATheme.Colors.textTertiary)
                                    }
                                    Text(comment.body)
                                        .foregroundStyle(IPCATheme.Colors.textPrimary)
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    }
                }
            }
            .padding(IPCATheme.Spacing.md)
        }
        .background(IPCABackground())
        .navigationTitle("Post")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyBase, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .safeAreaInset(edge: .bottom) {
            HStack(spacing: IPCATheme.Spacing.xs) {
                TextField("Add a comment...", text: $draft, axis: .vertical)
                    .textFieldStyle(.plain)
                    .lineLimit(1...4)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 10)
                    .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                    .overlay(
                        RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                            .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                    )
                Button("Send") {
                    Task { await sendComment() }
                }
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.white)
                .padding(.horizontal, 16)
                .padding(.vertical, 10)
                .background(
                    draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                    ? AnyShapeStyle(IPCATheme.Colors.navyElevated)
                    : AnyShapeStyle(IPCATheme.interactiveGradient),
                    in: Capsule()
                )
                .disabled(draft.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
            .padding(IPCATheme.Spacing.md)
            .background(IPCATheme.Colors.navySurface)
        }
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Menu {
                    Button("Report") { reporting = true }
                    if post.canDelete {
                        Button("Delete Post", role: .destructive) {
                            Task { await deletePost() }
                        }
                    }
                } label: {
                    Image(systemName: "ellipsis.circle")
                }
            }
        }
        .confirmationDialog("Report", isPresented: $reporting, titleVisibility: .visible) {
            Button("Inappropriate") { Task { await report("inappropriate") } }
            Button("Spam") { Task { await report("spam") } }
            Button("Harassment") { Task { await report("harassment") } }
            Button("Other") { Task { await report("other") } }
            Button("Cancel", role: .cancel) {}
        }
        .task { await loadComments() }
    }

    private func toggleLike() async {
        if let updated = await session.toggleCommunityLike(post) {
            post = updated
            onChange(updated)
        }
    }

    private func sendComment() async {
        let body = draft.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        draft = ""
        if let comment = await session.commentOnCommunityPost(post.postUUID, body: body) {
            comments.append(comment)
            if let updated = await session.loadCommunityPost(post.postUUID) {
                post = updated
                onChange(updated)
            }
        }
    }

    private func loadComments() async {
        comments = await session.loadCommunityComments(post.postUUID)
    }

    private func deletePost() async {
        if await session.deleteCommunityPost(post.postUUID) {
            onDeleted(post.postUUID)
            dismiss()
        }
    }

    private func report(_ reason: String) async {
        reportReason = reason
        _ = await session.reportCommunityPost(post.postUUID, reason: reason)
    }
}

struct CommunityComposeView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    var onPosted: (CommunityPostDTO) -> Void
    @State private var caption = ""
    @State private var bodyText = ""
    @State private var mediaData: Data?
    @State private var filename = "photo.jpg"
    @State private var mimeType = "image/jpeg"
    @State private var durationMs = 0
    @State private var preview: UIImage?
    @State private var posterData: Data?
    @State private var pickingPhotos = false
    @State private var capturingCamera = false
    @State private var photoItem: PhotosPickerItem?
    @State private var publishing = false
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Form {
                Section("Photo or Video") {
                    if let preview {
                        if mimeType.hasPrefix("video/") {
                            Image(uiImage: preview)
                                .resizable()
                                .scaledToFill()
                                .frame(maxWidth: .infinity)
                                .aspectRatio(CGSize(width: 9, height: 16), contentMode: .fit)
                                .clipped()
                                .clipShape(RoundedRectangle(cornerRadius: IPCATheme.Radius.large, style: .continuous))
                                .overlay {
                                    Image(systemName: "play.circle.fill")
                                        .font(.system(size: 44))
                                        .foregroundStyle(.white)
                                        .shadow(radius: 6)
                                }
                        } else {
                            Image(uiImage: preview)
                                .resizable()
                                .scaledToFit()
                                .frame(maxHeight: 240)
                        }
                    } else if mediaData != nil {
                        Label(videoReadyLabel, systemImage: "video.fill")
                    }
                    Button("Camera") { capturingCamera = true }
                    Button("Photo Library") { pickingPhotos = true }
                    Text(allowsLongVideo
                         ? "Videos can be up to 10 minutes."
                         : "Videos can be up to 30 seconds.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
                Section("Caption") {
                    TextField("Add a caption", text: $caption, axis: .vertical)
                        .lineLimit(1...3)
                }
                Section("Text") {
                    TextField("Write a little more…", text: $bodyText, axis: .vertical)
                        .lineLimit(2...6)
                }
            }
            .navigationTitle("New Post")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Share") {
                        Task { await publish() }
                    }
                    .disabled(mediaData == nil || publishing)
                }
            }
            .photosPicker(isPresented: $pickingPhotos, selection: $photoItem, matching: .any(of: [.images, .videos]))
            .onChange(of: photoItem) { _, item in
                guard let item else { return }
                Task { await importItem(item) }
            }
            .fullScreenCover(isPresented: $capturingCamera) {
                CommunityCameraCaptureView(
                    maxDuration: maxVideoSeconds,
                    onCapture: { data, mime, name, image, capturedDuration, poster in
                        capturingCamera = false
                        applyMedia(
                            data: data,
                            mimeType: mime,
                            filename: name,
                            preview: image,
                            durationMs: capturedDuration,
                            poster: poster
                        )
                    },
                    onCancel: { capturingCamera = false }
                )
                .ignoresSafeArea()
            }
            .alert("Couldn't share that", isPresented: Binding(
                get: { errorMessage != nil },
                set: { if !$0 { errorMessage = nil } }
            )) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(errorMessage ?? "")
            }
        }
    }

    private var allowsLongVideo: Bool {
        let role = (session.user?.role ?? "").lowercased()
        return ["admin", "instructor", "chief_instructor", "supervisor"].contains(role)
    }

    private var maxVideoSeconds: TimeInterval {
        allowsLongVideo ? 600 : 30
    }

    private var maxVideoBytes: Int {
        allowsLongVideo ? 200 * 1024 * 1024 : 50 * 1024 * 1024
    }

    private var videoReadyLabel: String {
        if durationMs > 0 {
            return "Video ready to share · \(max(1, durationMs / 1000))s"
        }
        return "Video ready to share"
    }

    private func importItem(_ item: PhotosPickerItem) async {
        let isVideo = item.supportedContentTypes.contains { $0.conforms(to: .movie) }
        guard let data = try? await item.loadTransferable(type: Data.self) else { return }
        if isVideo {
            let duration = await CommunityVideo.durationMs(from: data)
            let posterImage = await CommunityVideo.posterImage(from: data)
            let poster = posterImage?.jpegData(compressionQuality: 0.82)
            applyMedia(
                data: data,
                mimeType: "video/quicktime",
                filename: "video.mov",
                preview: posterImage,
                durationMs: duration,
                poster: poster
            )
        } else {
            applyMedia(data: data, mimeType: "image/jpeg", filename: "photo.jpg", preview: UIImage(data: data), durationMs: 0)
        }
    }

    private func applyMedia(data: Data, mimeType: String, filename: String, preview: UIImage?, durationMs: Int, poster: Data? = nil) {
        if mimeType.hasPrefix("video/") {
            if data.count > maxVideoBytes {
                errorMessage = "That video is too large to share."
                return
            }
            if durationMs > Int(maxVideoSeconds * 1000) {
                errorMessage = allowsLongVideo
                    ? "Keep Community videos to 10 minutes."
                    : "Keep Community videos to 30 seconds."
                return
            }
        }
        mediaData = data
        self.mimeType = mimeType
        self.filename = filename
        self.preview = preview
        self.durationMs = durationMs
        posterData = mimeType.hasPrefix("video/") ? poster : nil
    }

    private func publish() async {
        guard let mediaData else { return }
        publishing = true
        defer { publishing = false }
        if let post = await session.publishCommunityPost(
            caption: caption,
            body: bodyText,
            data: mediaData,
            filename: filename,
            mimeType: mimeType,
            durationMs: mimeType.hasPrefix("video/") ? max(durationMs, 1) : 0,
            poster: posterData
        ) {
            onPosted(post)
            dismiss()
        } else if let error = session.actionError {
            errorMessage = error
        }
    }
}

enum CommunityVideo {
    private static let posterCache = NSCache<NSString, UIImage>()

    static func durationMs(from data: Data) async -> Int {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString + ".mov")
        do {
            try data.write(to: url, options: .atomic)
            defer { try? FileManager.default.removeItem(at: url) }
            return try await durationMs(url: url)
        } catch {
            return 0
        }
    }

    static func durationMs(url: URL) async throws -> Int {
        let asset = AVURLAsset(url: url)
        let duration = try await asset.load(.duration)
        let seconds = CMTimeGetSeconds(duration)
        guard seconds.isFinite, seconds > 0 else { return 0 }
        return Int((seconds * 1000).rounded())
    }

    static func posterImage(from data: Data) async -> UIImage? {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString + ".mov")
        do {
            try data.write(to: url, options: .atomic)
            defer { try? FileManager.default.removeItem(at: url) }
            return try await posterImage(url: url)
        } catch {
            return nil
        }
    }

    static func posterImage(url: URL) async throws -> UIImage? {
        let asset = AVURLAsset(url: url)
        let generator = AVAssetImageGenerator(asset: asset)
        generator.appliesPreferredTrackTransform = true
        generator.maximumSize = CGSize(width: 1080, height: 1920)
        let cgImage = try await generator.image(at: CMTime(seconds: 0.05, preferredTimescale: 600)).image
        return UIImage(cgImage: cgImage)
    }

    static func cachedPoster(mediaUUID: String, url: URL) async -> UIImage? {
        let key = mediaUUID as NSString
        if let cached = posterCache.object(forKey: key) {
            return cached
        }
        guard let image = try? await posterImage(url: url) else { return nil }
        posterCache.setObject(image, forKey: key)
        return image
    }
}

struct CommunityMediaView: View {
    let media: CommunityMediaDTO?
    var autoplays = false
    var playbackEnabled = true

    var body: some View {
        if let media, media.getURL != nil || media.posterURL != nil {
            if media.kind == "video" {
                CommunityVerticalVideoView(media: media, autoplays: autoplays, playbackEnabled: playbackEnabled)
            } else if let urlString = media.getURL, let url = URL(string: urlString) {
                AsyncImage(url: url) { phase in
                    switch phase {
                    case .success(let image):
                        image.resizable().scaledToFit()
                    case .failure:
                        IPCATheme.Colors.navyElevated.frame(height: 180)
                    default:
                        ProgressView().tint(IPCATheme.Colors.ipcaBlue).frame(maxWidth: .infinity).frame(height: 180)
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: IPCATheme.Radius.large, style: .continuous))
            }
        }
    }
}

struct CommunityVerticalVideoView: View {
    let media: CommunityMediaDTO
    var autoplays = false
    var playbackEnabled = true
    @AppStorage("ipca.community.videoAudioOn") private var audioOn = false
    @State private var player: AVPlayer?
    @State private var isPlaying = false
    @State private var generatedPoster: UIImage?
    @State private var endObserver: NSObjectProtocol?

    var body: some View {
        ZStack(alignment: .topTrailing) {
            ZStack {
                IPCATheme.Colors.navyElevated
                posterLayer
                if let player {
                    CommunityPlayerLayerView(player: player)
                }
            }
            .onTapGesture { togglePlayback() }
            audioButton
                .padding(10)
        }
        .aspectRatio(CGSize(width: 9, height: 16), contentMode: .fit)
        .clipped()
        .clipShape(RoundedRectangle(cornerRadius: IPCATheme.Radius.large, style: .continuous))
        .task {
            if generatedPoster == nil, media.posterURL == nil, let urlString = media.getURL, let url = URL(string: urlString) {
                generatedPoster = await CommunityVideo.cachedPoster(mediaUUID: media.mediaUUID, url: url)
            }
            if autoplays {
                play()
            }
        }
        .onChange(of: audioOn) { _, on in
            applyAudio(on)
        }
        .onChange(of: playbackEnabled) { _, enabled in
            if !enabled {
                pause(teardown: true)
            }
        }
        .onDisappear {
            pause(teardown: true)
        }
        .accessibilityAddTraits(.startsMediaSession)
        .accessibilityLabel("Community video")
        .accessibilityHint(isPlaying ? "Plays automatically. Double tap to pause." : "Double tap to play.")
    }

    private var audioButton: some View {
        Button {
            audioOn.toggle()
        } label: {
            Image(systemName: audioOn ? "speaker.wave.2.fill" : "speaker.slash.fill")
                .font(.system(size: 13, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: 32, height: 32)
                .background(.black.opacity(0.48), in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(audioOn ? "Turn audio off" : "Turn audio on")
    }

    @ViewBuilder
    private var posterLayer: some View {
        if let generatedPoster {
            Image(uiImage: generatedPoster)
                .resizable()
                .scaledToFill()
                .frame(minWidth: 0, maxWidth: .infinity, minHeight: 0, maxHeight: .infinity)
                .clipped()
        } else if let urlString = media.posterURL, let url = URL(string: urlString) {
            AsyncImage(url: url) { phase in
                if case .success(let image) = phase {
                    image.resizable()
                        .scaledToFill()
                        .frame(minWidth: 0, maxWidth: .infinity, minHeight: 0, maxHeight: .infinity)
                        .clipped()
                }
            }
        }
    }

    private func togglePlayback() {
        if isPlaying {
            pause(teardown: false)
        } else {
            play()
        }
    }

    private func play() {
        guard playbackEnabled else { return }
        ensurePlayer()
        applyAudio(audioOn)
        player?.play()
        isPlaying = true
    }

    private func pause(teardown: Bool) {
        player?.pause()
        isPlaying = false
        if teardown {
            if let endObserver {
                NotificationCenter.default.removeObserver(endObserver)
                self.endObserver = nil
            }
            player?.replaceCurrentItem(with: nil)
            player = nil
        }
    }

    private func ensurePlayer() {
        if player != nil { return }
        guard let urlString = media.getURL, let url = URL(string: urlString) else { return }
        let item = AVPlayerItem(url: url)
        let created = AVPlayer(playerItem: item)
        created.actionAtItemEnd = .none
        created.isMuted = !audioOn
        player = created
        if let endObserver {
            NotificationCenter.default.removeObserver(endObserver)
        }
        endObserver = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemDidPlayToEndTime,
            object: item,
            queue: .main
        ) { [created] _ in
            created.seek(to: .zero)
            created.play()
        }
    }

    private func applyAudio(_ on: Bool) {
        player?.isMuted = !on
        guard on else { return }
        do {
            try AVAudioSession.sharedInstance().setCategory(.playback, mode: .moviePlayback, options: [.mixWithOthers])
            try AVAudioSession.sharedInstance().setActive(true)
        } catch {
            return
        }
    }
}

private struct CommunityPlayerLayerView: UIViewRepresentable {
    let player: AVPlayer

    func makeUIView(context: Context) -> CommunityPlayerUIView {
        let view = CommunityPlayerUIView()
        view.player = player
        return view
    }

    func updateUIView(_ uiView: CommunityPlayerUIView, context: Context) {
        uiView.player = player
    }
}

private final class CommunityPlayerUIView: UIView {
    override class var layerClass: AnyClass { AVPlayerLayer.self }

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = .black
        isUserInteractionEnabled = false
        (layer as? AVPlayerLayer)?.videoGravity = .resizeAspectFill
    }

    required init?(coder: NSCoder) {
        super.init(coder: coder)
        backgroundColor = .black
        isUserInteractionEnabled = false
        (layer as? AVPlayerLayer)?.videoGravity = .resizeAspectFill
    }

    var player: AVPlayer? {
        get { (layer as? AVPlayerLayer)?.player }
        set {
            let playerLayer = layer as? AVPlayerLayer
            playerLayer?.player = newValue
            playerLayer?.videoGravity = .resizeAspectFill
        }
    }
}

struct CommunityPostText: View {
    let caption: String
    let bodyText: String
    var light: Bool
    @State private var expanded = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            if !caption.isEmpty {
                Text(caption)
                    .font(light ? .body.weight(.semibold) : .title3.weight(.semibold))
                    .foregroundStyle(light ? IPCATheme.Colors.lightText : IPCATheme.Colors.textPrimary)
            }
            if !bodyText.isEmpty {
                Text(bodyText)
                    .font(.subheadline)
                    .foregroundStyle(light ? IPCATheme.Colors.lightSecondary : IPCATheme.Colors.textSecondary)
                    .lineLimit(expanded ? nil : 3)
                if showsMore, !expanded {
                    Button("more") {
                        withAnimation(.easeInOut(duration: 0.15)) {
                            expanded = true
                        }
                    }
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                    .buttonStyle(.plain)
                    .accessibilityLabel("more")
                }
            }
        }
    }

    private var showsMore: Bool {
        bodyText.count > 140 || bodyText.components(separatedBy: .newlines).count > 3
    }
}

enum CommunityTime {
    static func short(_ utc: String) -> String {
        let formats = [
            "yyyy-MM-dd HH:mm:ss.SSS",
            "yyyy-MM-dd HH:mm:ss",
            "yyyy-MM-dd'T'HH:mm:ss.SSSX",
            "yyyy-MM-dd'T'HH:mm:ssX"
        ]
        let parser = DateFormatter()
        parser.locale = Locale(identifier: "en_US_POSIX")
        parser.timeZone = TimeZone(secondsFromGMT: 0)
        for format in formats {
            parser.dateFormat = format
            if let date = parser.date(from: utc) {
                return date.formatted(.relative(presentation: .named))
            }
        }
        return utc
    }
}

struct CommunityCameraCaptureView: UIViewControllerRepresentable {
    var maxDuration: TimeInterval = 30
    var onCapture: (Data, String, String, UIImage?, Int, Data?) -> Void
    var onCancel: () -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(onCapture: onCapture, onCancel: onCancel)
    }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.mediaTypes = ["public.image", "public.movie"]
        picker.videoMaximumDuration = maxDuration
        picker.videoQuality = .typeMedium
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ uiViewController: UIImagePickerController, context: Context) {}

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let onCapture: (Data, String, String, UIImage?, Int, Data?) -> Void
        let onCancel: () -> Void

        init(onCapture: @escaping (Data, String, String, UIImage?, Int, Data?) -> Void, onCancel: @escaping () -> Void) {
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
            if let image = info[.originalImage] as? UIImage, let data = image.jpegData(compressionQuality: 0.85) {
                onCapture(data, "image/jpeg", "photo.jpg", image, 0, nil)
                return
            }
            if let url = info[.mediaURL] as? URL, let data = try? Data(contentsOf: url) {
                Task {
                    let duration = (try? await CommunityVideo.durationMs(url: url)) ?? 0
                    let posterImage = try? await CommunityVideo.posterImage(url: url)
                    let poster = posterImage?.jpegData(compressionQuality: 0.82)
                    await MainActor.run {
                        onCapture(data, "video/quicktime", "video.mov", posterImage, duration, poster)
                    }
                }
                return
            }
            onCancel()
        }
    }
}
