import AVKit
import SwiftUI

struct TrainingVideosView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    @ObservedObject private var downloads = TrainingVideoDownloadManager.shared
    @State private var videos: [TrainingVideoDTO] = []
    @State private var nextCursor: Int?
    @State private var selectedVideoUUID: String?
    @State private var loading = true
    @State private var loadFailed = false

    var body: some View {
        Group {
            if sizeClass == .regular {
                HStack(spacing: 0) {
                    feed
                        .frame(width: 400)
                    Divider().background(IPCATheme.Colors.separator)
                    NavigationStack {
                        if let selectedVideo {
                            TrainingVideoDetailView(video: selectedVideo, onChange: replace)
                        } else {
                            ContentUnavailableView("Videos", systemImage: "play.rectangle", description: Text("Choose a training video."))
                        }
                    }
                    .id(selectedVideoUUID ?? "empty")
                }
            } else {
                feed
            }
        }
        .task { await reload() }
    }

    private var groupedVideos: [(title: String, videos: [TrainingVideoDTO])] {
        let order = ["Private Pilot", "Instrument", "Commercial", "CFI", "Systems"]
        let grouped = Dictionary(grouping: videos) { video in
            video.category.isEmpty ? "Videos" : video.category
        }
        return grouped.keys.sorted { left, right in
            let leftRank = order.firstIndex(of: left) ?? (left == "Uncategorized" || left == "Videos" ? 90 : 50)
            let rightRank = order.firstIndex(of: right) ?? (right == "Uncategorized" || right == "Videos" ? 90 : 50)
            if leftRank != rightRank { return leftRank < rightRank }
            return left < right
        }.map { ($0, grouped[$0] ?? []) }
    }

    private var selectedVideo: TrainingVideoDTO? {
        videos.first { $0.videoUUID == selectedVideoUUID }
    }

    private var feed: some View {
        NavigationStack {
            VStack(spacing: 0) {
                IPCARootHeader(title: "Videos", subtitle: "Watch online or save for offline") {
                    EmptyView()
                }
                Group {
                    if loading && videos.isEmpty {
                        ProgressView()
                            .tint(IPCATheme.Colors.ipcaBlue)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if loadFailed && videos.isEmpty {
                        ContentUnavailableView("Couldn't load videos", systemImage: "play.rectangle", description: Text("Pull to try again."))
                    } else if videos.isEmpty {
                        ContentUnavailableView("No training videos", systemImage: "play.rectangle")
                    } else {
                        ScrollView {
                            LazyVStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                                ForEach(groupedVideos, id: \.title) { group in
                                    if groupedVideos.count > 1 {
                                        Text(group.title)
                                            .font(.headline)
                                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                                            .padding(.top, 4)
                                    }
                                    ForEach(group.videos) { video in
                                        TrainingVideoCard(video: video) {
                                            selectedVideoUUID = video.videoUUID
                                        }
                                    }
                                }
                                if nextCursor != nil {
                                    ProgressView()
                                        .task { await loadMore() }
                                }
                            }
                            .padding(IPCATheme.Spacing.md)
                        }
                    }
                }
            }
            .background(IPCABackground())
            .navigationDestination(for: String.self) { uuid in
                if let video = videos.first(where: { $0.videoUUID == uuid }) {
                    TrainingVideoDetailView(video: video, onChange: replace)
                }
            }
        }
    }

    private func replace(_ video: TrainingVideoDTO) {
        if let index = videos.firstIndex(where: { $0.videoUUID == video.videoUUID }) {
            videos[index] = video
        }
        selectedVideoUUID = video.videoUUID
    }

    private func reload() async {
        loading = true
        await session.flushTrainingVideoProgress()
        if let result = await session.loadTrainingVideoFeed() {
            videos = result.videos
            nextCursor = result.nextCursor
            selectedVideoUUID = selectedVideoUUID ?? result.videos.first?.videoUUID
            loadFailed = false
        } else if videos.isEmpty {
            let owner = session.user?.uuid ?? ""
            videos = downloads.downloadedVideos(for: owner)
            selectedVideoUUID = selectedVideoUUID ?? videos.first?.videoUUID
            loadFailed = videos.isEmpty
        }
        loading = false
    }

    private func loadMore() async {
        guard let cursor = nextCursor else { return }
        guard let result = await session.loadTrainingVideoFeed(cursor: cursor) else { return }
        videos.append(contentsOf: result.videos.filter { video in !videos.contains(where: { $0.videoUUID == video.videoUUID }) })
        nextCursor = result.nextCursor
    }
}

private struct TrainingVideoCard: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    @ObservedObject private var downloads = TrainingVideoDownloadManager.shared
    let video: TrainingVideoDTO
    var onSelect: () -> Void

    var body: some View {
        Group {
            if sizeClass == .regular {
                content
                    .contentShape(Rectangle())
                    .onTapGesture(perform: onSelect)
            } else {
                NavigationLink(value: video.videoUUID) {
                    content
                }
                .buttonStyle(.plain)
            }
        }
    }

    private var content: some View {
        VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
            ZStack(alignment: .topTrailing) {
                TrainingVideoPoster(url: video.posterURL)
                HStack(alignment: .top) {
                    TrainingVideoWatchBadge(video: video)
                        .padding(10)
                    Spacer()
                    offlineButton
                        .padding(10)
                }
            }
            Text(video.title)
                .font(.headline)
                .foregroundStyle(IPCATheme.Colors.textPrimary)
            if !video.category.isEmpty {
                Text(video.category)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
            }
            if !video.description.isEmpty {
                Text(video.description)
                    .font(.subheadline)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                    .lineLimit(3)
            }
            HStack(spacing: 16) {
                Label("\(video.viewCount)", systemImage: "eye")
                Label("\(video.likeCount)", systemImage: video.liked ? "heart.fill" : "heart")
                    .foregroundStyle(video.liked ? Color.red : IPCATheme.Colors.textSecondary)
                    .accessibilityLabel("Like")
                Label("\(video.commentCount)", systemImage: "bubble.right")
                    .accessibilityLabel("Comment")
            }
            .font(.subheadline.weight(.medium))
            .foregroundStyle(IPCATheme.Colors.textSecondary)
        }
        .padding(IPCATheme.Spacing.sm)
        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
    }

    private var ownerUUID: String { session.user?.uuid ?? "" }

    @ViewBuilder
    private var offlineButton: some View {
        if downloads.isDownloaded(video.videoUUID, ownerUserUUID: ownerUUID) {
            Image(systemName: "checkmark.circle.fill")
                .foregroundStyle(.white, IPCATheme.Colors.ipcaBlue)
                .font(.title2)
                .padding(5)
                .background(.black.opacity(0.55), in: Circle())
                .accessibilityLabel("Saved offline")
        } else if let value = downloads.progress[video.videoUUID] {
            ZStack {
                Circle().fill(.black.opacity(0.6))
                ProgressView(value: value)
                    .progressViewStyle(.circular)
                    .tint(.white)
            }
            .frame(width: 36, height: 36)
            .accessibilityLabel("Downloading \(Int(value * 100)) percent")
        } else if downloads.failures[video.videoUUID] != nil {
            Button {
                requestDownload()
            } label: {
                Image(systemName: "exclamationmark.circle.fill")
                    .foregroundStyle(.white, Color.red)
                    .font(.title2)
                    .padding(5)
                    .background(.black.opacity(0.55), in: Circle())
            }
            .accessibilityLabel("Retry download")
        } else {
            Button(action: requestDownload) {
                Image(systemName: "arrow.down.circle.fill")
                    .foregroundStyle(.white, IPCATheme.Colors.ipcaBlue)
                    .font(.title2)
                    .padding(5)
                    .background(.black.opacity(0.55), in: Circle())
            }
            .accessibilityLabel("View offline")
        }
    }

    private func requestDownload() {
        Task {
            guard let entitlement = await session.loadTrainingVideoPlayback(video.videoUUID, download: true) else { return }
            downloads.start(video: video, entitlement: entitlement, ownerUserUUID: ownerUUID)
        }
    }
}

struct TrainingVideoDetailView: View {
    @EnvironmentObject private var session: AppSession
    @ObservedObject private var downloads = TrainingVideoDownloadManager.shared
    @State var video: TrainingVideoDTO
    let onChange: (TrainingVideoDTO) -> Void
    @State private var comments: [TrainingVideoCommentDTO] = []
    @State private var commentText = ""
    @State private var playbackURL: URL?
    @State private var showingPlayer = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                ZStack(alignment: .topTrailing) {
                    Button {
                        Task { await play() }
                    } label: {
                        ZStack {
                            TrainingVideoPoster(url: video.posterURL)
                            Image(systemName: "play.circle.fill")
                                .font(.system(size: 64))
                                .foregroundStyle(.white)
                                .shadow(radius: 8)
                        }
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel("Play")
                    HStack(alignment: .top) {
                        TrainingVideoWatchBadge(video: video)
                            .padding(12)
                        Spacer()
                        offlineButton
                            .padding(12)
                    }
                }
                Text(video.title)
                    .font(.title2.weight(.bold))
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                if !video.category.isEmpty {
                    Text(video.category)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                }
                if !video.description.isEmpty {
                    Text(video.description)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                }
                HStack(spacing: 16) {
                    Label("\(video.viewCount) views", systemImage: "eye")
                    Button {
                        Task { await toggleLike() }
                    } label: {
                        Label("\(video.likeCount)", systemImage: video.liked ? "heart.fill" : "heart")
                            .foregroundStyle(video.liked ? Color.red : IPCATheme.Colors.textSecondary)
                    }
                    .accessibilityLabel("Like")
                    Label("\(video.commentCount)", systemImage: "bubble.right")
                        .accessibilityLabel("Comment")
                }
                .font(.subheadline.weight(.medium))
                .foregroundStyle(IPCATheme.Colors.textSecondary)

                Text("Comments")
                    .font(.headline)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                ForEach(comments) { comment in
                    VStack(alignment: .leading, spacing: 4) {
                        Text(comment.author.name)
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(IPCATheme.Colors.textPrimary)
                        Text(comment.body)
                            .foregroundStyle(IPCATheme.Colors.textSecondary)
                    }
                    .padding(.vertical, 4)
                }
                HStack {
                    TextField("Comment", text: $commentText, axis: .vertical)
                        .textFieldStyle(.plain)
                        .lineLimit(1...4)
                        .padding(10)
                        .background(IPCATheme.Colors.navySurface, in: Capsule())
                    Button("Send") {
                        Task { await sendComment() }
                    }
                    .disabled(commentText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
            .padding(IPCATheme.Spacing.md)
        }
        .background(IPCABackground())
        .navigationTitle("Videos")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .fullScreenCover(isPresented: $showingPlayer) {
            if let playbackURL {
                TrainingVideoPlayer(url: playbackURL, video: video) { positionMs, durationMs in
                    Task {
                        if let updated = await session.reportTrainingVideoProgress(
                            videoUUID: video.videoUUID,
                            positionMs: positionMs,
                            durationMs: durationMs
                        ) {
                            video = updated
                            onChange(updated)
                        }
                    }
                }
                .ignoresSafeArea()
            }
        }
    }

    private var ownerUUID: String { session.user?.uuid ?? "" }

    @ViewBuilder
    private var offlineButton: some View {
        if downloads.isDownloaded(video.videoUUID, ownerUserUUID: ownerUUID) {
            Image(systemName: "checkmark.circle.fill")
                .foregroundStyle(.white, IPCATheme.Colors.ipcaBlue)
                .font(.title2)
                .padding(5)
                .background(.black.opacity(0.55), in: Circle())
                .accessibilityLabel("Saved offline")
        } else if downloads.progress[video.videoUUID] != nil {
            ProgressView()
                .tint(.white)
                .padding(8)
                .background(.black.opacity(0.55), in: Circle())
        } else {
            Button {
                Task { await download() }
            } label: {
                Image(systemName: "arrow.down.circle.fill")
                    .foregroundStyle(.white, IPCATheme.Colors.ipcaBlue)
                    .font(.title2)
                    .padding(5)
                    .background(.black.opacity(0.55), in: Circle())
            }
            .accessibilityLabel("View offline")
        }
    }

    private func load() async {
        if let fresh = await session.loadTrainingVideo(video.videoUUID) {
            video = fresh
            onChange(fresh)
        }
        comments = await session.loadTrainingVideoComments(video.videoUUID)
    }

    private func play() async {
        if let local = downloads.localURL(for: video, ownerUserUUID: ownerUUID) {
            playbackURL = local
            showingPlayer = true
            if let updated = await session.recordTrainingVideoView(video.videoUUID) {
                video = updated
                onChange(updated)
            }
            return
        }
        guard let entitlement = await session.loadTrainingVideoPlayback(video.videoUUID) else { return }
        if let updated = entitlement.video {
            video = updated
            onChange(updated)
        }
        if let url = URL(string: entitlement.streamURL ?? entitlement.url) {
            playbackURL = url
            showingPlayer = true
        }
    }

    private func download() async {
        guard let entitlement = await session.loadTrainingVideoPlayback(video.videoUUID, download: true) else { return }
        downloads.start(video: video, entitlement: entitlement, ownerUserUUID: ownerUUID)
    }

    private func toggleLike() async {
        if let updated = await session.toggleTrainingVideoLike(video) {
            video = updated
            onChange(updated)
        }
    }

    private func sendComment() async {
        let body = commentText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !body.isEmpty else { return }
        if let comment = await session.commentOnTrainingVideo(video.videoUUID, body: body) {
            comments.append(comment)
            commentText = ""
            if let updated = await session.loadTrainingVideo(video.videoUUID) {
                video = updated
                onChange(updated)
            }
        }
    }
}

private struct TrainingVideoWatchBadge: View {
    let video: TrainingVideoDTO

    var body: some View {
        if video.watchCompleted {
            Text("Watched")
                .font(.caption.weight(.bold))
                .foregroundStyle(.white)
                .padding(.horizontal, 10)
                .padding(.vertical, 5)
                .background(IPCATheme.Colors.ipcaBlue, in: Capsule())
                .accessibilityLabel("Watched in full")
        } else if video.watchPercent > 0 {
            HStack(spacing: 6) {
                ZStack {
                    Circle()
                        .stroke(.white.opacity(0.35), lineWidth: 3)
                    Circle()
                        .trim(from: 0, to: min(1, CGFloat(video.watchPercent) / 100))
                        .stroke(.white, style: StrokeStyle(lineWidth: 3, lineCap: .round))
                        .rotationEffect(.degrees(-90))
                }
                .frame(width: 16, height: 16)
                Text("\(video.watchPercent)%")
                    .font(.caption.weight(.bold))
                    .foregroundStyle(.white)
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 5)
            .background(.black.opacity(0.55), in: Capsule())
            .accessibilityLabel("\(video.watchPercent) percent watched")
        }
    }
}

private struct TrainingVideoPoster: View {
    let url: String

    var body: some View {
        Group {
            if let poster = URL(string: url) {
                AsyncImage(url: poster) { phase in
                    switch phase {
                    case .success(let image):
                        image.resizable().scaledToFill()
                    default:
                        placeholder
                    }
                }
            } else {
                placeholder
            }
        }
        .frame(maxWidth: .infinity)
        .frame(height: 220)
        .clipped()
        .background(IPCATheme.Colors.navyElevated)
        .clipShape(RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
    }

    private var placeholder: some View {
        ZStack {
            IPCATheme.Colors.navyElevated
            Image(systemName: "play.rectangle.fill")
                .font(.largeTitle)
                .foregroundStyle(IPCATheme.Colors.textTertiary)
        }
    }
}

private struct TrainingVideoPlayer: UIViewControllerRepresentable {
    let url: URL
    let video: TrainingVideoDTO
    let onProgress: (Int, Int) -> Void

    func makeCoordinator() -> Coordinator {
        Coordinator(video: video, onProgress: onProgress)
    }

    func makeUIViewController(context: Context) -> AVPlayerViewController {
        let controller = AVPlayerViewController()
        let asset = AVURLAsset(url: url)
        let item = AVPlayerItem(asset: asset)
        let player = AVPlayer(playerItem: item)
        player.automaticallyWaitsToMinimizeStalling = true
        controller.player = player
        controller.entersFullScreenWhenPlaybackBegins = true
        controller.exitsFullScreenWhenPlaybackEnds = true
        context.coordinator.attach(player)
        if !video.watchCompleted, video.resumePositionMs > 1000 {
            let resume = CMTime(milliseconds: video.resumePositionMs)
            player.seek(to: resume, toleranceBefore: .zero, toleranceAfter: .zero)
        }
        player.play()
        return controller
    }

    func updateUIViewController(_ controller: AVPlayerViewController, context: Context) {}

    static func dismantleUIViewController(_ controller: AVPlayerViewController, coordinator: Coordinator) {
        coordinator.report(force: true)
        coordinator.detach()
        controller.player?.pause()
        controller.player = nil
    }

    final class Coordinator {
        let video: TrainingVideoDTO
        let onProgress: (Int, Int) -> Void
        private var player: AVPlayer?
        private var timeObserver: Any?
        private var endObserver: NSObjectProtocol?
        private var lastSent = Date.distantPast

        init(video: TrainingVideoDTO, onProgress: @escaping (Int, Int) -> Void) {
            self.video = video
            self.onProgress = onProgress
        }

        func attach(_ player: AVPlayer) {
            self.player = player
            timeObserver = player.addPeriodicTimeObserver(
                forInterval: CMTime(seconds: 5, preferredTimescale: 600),
                queue: .main
            ) { [weak self] _ in
                self?.report(force: false)
            }
            endObserver = NotificationCenter.default.addObserver(
                forName: .AVPlayerItemDidPlayToEndTime,
                object: player.currentItem,
                queue: .main
            ) { [weak self] _ in
                self?.report(force: true)
            }
        }

        func report(force: Bool) {
            guard let player else { return }
            if !force, Date().timeIntervalSince(lastSent) < 4.5 {
                return
            }
            lastSent = Date()
            let position = Int((player.currentTime().seconds * 1000).rounded())
            let durationSeconds = player.currentItem?.duration.seconds ?? 0
            let duration = durationSeconds.isFinite ? Int((durationSeconds * 1000).rounded()) : video.durationMs
            if !force, position < 500, video.resumePositionMs > 1000, !video.watchCompleted {
                return
            }
            onProgress(max(0, position), max(0, duration))
        }

        func detach() {
            if let timeObserver, let player {
                player.removeTimeObserver(timeObserver)
            }
            if let endObserver {
                NotificationCenter.default.removeObserver(endObserver)
            }
            timeObserver = nil
            endObserver = nil
            player = nil
        }
    }
}

private extension CMTime {
    init(milliseconds: Int) {
        self.init(value: CMTimeValue(milliseconds), timescale: 1000)
    }
}
