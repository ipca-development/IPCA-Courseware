import SwiftUI
import UIKit

enum IPCATheme {
    enum Colors {
        static let navyBase = Color(hex: 0x071B35)
        static let navyPrimary = Color(hex: 0x0B2345)
        static let navyElevated = Color(hex: 0x102D55)
        static let navySurface = Color(hex: 0x0C2748)
        static let incomingBubble = Color(hex: 0x102B4C)

        static let backgroundTop = Color(hex: 0x07182F)
        static let backgroundMid = Color(hex: 0x081F3D)
        static let backgroundBottom = Color(hex: 0x06172C)

        static let ipcaBlue = Color(hex: 0x0A84FF)
        static let ipcaBlueBright = Color(hex: 0x159CFF)
        static let ipcaBlueDeep = Color(hex: 0x0755C9)
        static let avatarStart = Color(hex: 0x09275A)
        static let avatarMid = Color(hex: 0x075BCB)

        static let textPrimary = Color(hex: 0xF7F9FC)
        static let textSecondary = Color(hex: 0xA8B3C7)
        static let textTertiary = Color(hex: 0x73829B)

        static let lightCard = Color(hex: 0xF8FAFD)
        static let lightText = Color(hex: 0x071B35)
        static let lightSecondary = Color(hex: 0x6F7C91)

        static let separator = Color(hex: 0x467DBE, alpha: 0.20)
        static let warning = Color(hex: 0xFF9F0A)
        static let destructive = Color(hex: 0xFF4D55)
        static let success = Color(hex: 0x32D583)
    }

    enum Spacing {
        static let xxs: CGFloat = 4
        static let xs: CGFloat = 8
        static let sm: CGFloat = 12
        static let md: CGFloat = 16
        static let lg: CGFloat = 20
        static let xl: CGFloat = 24
        static let xxl: CGFloat = 32
        static let screen: CGFloat = 16
    }

    enum Radius {
        static let small: CGFloat = 8
        static let medium: CGFloat = 12
        static let card: CGFloat = 16
        static let large: CGFloat = 20
        static let pill: CGFloat = 22
    }

    static var backgroundGradient: LinearGradient {
        LinearGradient(
            colors: [Colors.backgroundTop, Colors.backgroundMid, Colors.backgroundBottom],
            startPoint: .top,
            endPoint: .bottom
        )
    }

    static var interactiveGradient: LinearGradient {
        LinearGradient(
            colors: [Colors.ipcaBlueDeep, Colors.ipcaBlue, Colors.ipcaBlueBright],
            startPoint: .topLeading,
            endPoint: .bottomTrailing
        )
    }

    static var outgoingBubbleGradient: LinearGradient {
        LinearGradient(
            colors: [Colors.ipcaBlueDeep, Colors.ipcaBlue],
            startPoint: .topLeading,
            endPoint: .bottomTrailing
        )
    }

    static var avatarGradient: LinearGradient {
        LinearGradient(
            colors: [Colors.avatarStart, Colors.avatarMid, Colors.ipcaBlue],
            startPoint: .topLeading,
            endPoint: .bottomTrailing
        )
    }

    static var iconTileGradient: LinearGradient {
        LinearGradient(
            colors: [Color(hex: 0x09275A), Colors.ipcaBlue],
            startPoint: .topLeading,
            endPoint: .bottomTrailing
        )
    }

    static func formattedRole(_ role: String) -> String {
        let trimmed = role.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return "" }
        return trimmed.replacingOccurrences(of: "_", with: " ").localizedCapitalized
    }

    static func initials(from name: String) -> String {
        let parts = name.split(separator: " ").prefix(2)
        let letters = parts.compactMap { $0.first }.map(String.init)
        let value = letters.joined().uppercased()
        return value.isEmpty ? "IP" : value
    }

    static func photoURL(path: String, serverURL: String) -> URL? {
        let trimmed = path.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }
        if trimmed.hasPrefix("http://") || trimmed.hasPrefix("https://") {
            return URL(string: trimmed)
        }
        guard let base = URL(string: serverURL) else { return nil }
        if trimmed.hasPrefix("/") {
            return URL(string: trimmed, relativeTo: base)?.absoluteURL
        }
        return base.appendingPathComponent(trimmed)
    }

    static func conversationTimestamp(_ date: Date) -> String {
        let calendar = Calendar.current
        if calendar.isDateInToday(date) {
            return date.formatted(date: .omitted, time: .shortened)
        }
        if calendar.isDateInYesterday(date) {
            return "Yesterday"
        }
        if calendar.isDate(date, equalTo: Date(), toGranularity: .year) {
            return date.formatted(.dateTime.month(.abbreviated).day())
        }
        return date.formatted(date: .abbreviated, time: .omitted)
    }
}

private extension Color {
    init(hex: UInt32, alpha: Double = 1) {
        let r = Double((hex >> 16) & 0xFF) / 255
        let g = Double((hex >> 8) & 0xFF) / 255
        let b = Double(hex & 0xFF) / 255
        self.init(.sRGB, red: r, green: g, blue: b, opacity: alpha)
    }
}

struct IPCABackground: View {
    var body: some View {
        IPCATheme.backgroundGradient
            .ignoresSafeArea()
    }
}

struct IPCALogo: View {
    var height: CGFloat = 32
    var lockup: Bool = false

    var body: some View {
        Image(uiImage: logoImage)
            .resizable()
            .interpolation(.high)
            .scaledToFit()
            .frame(height: height)
            .accessibilityLabel("IPCA")
    }

    private var logoImage: UIImage {
        let name = lockup ? "IPCALogoLockup" : "IPCALogoWhite"
        return (UIImage(named: name) ?? UIImage()).withRenderingMode(.alwaysOriginal)
    }
}

struct IPCARootHeader<Trailing: View>: View {
    var title: String
    var subtitle: String
    var trailing: Trailing

    init(title: String, subtitle: String, @ViewBuilder trailing: () -> Trailing) {
        self.title = title
        self.subtitle = subtitle
        self.trailing = trailing()
    }

    var body: some View {
        HStack(alignment: .top, spacing: IPCATheme.Spacing.sm) {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.xs) {
                IPCALogo(height: 34)
                Text(title)
                    .font(.largeTitle.bold())
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .minimumScaleFactor(0.8)
                    .lineLimit(1)
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
            }
            Spacer(minLength: IPCATheme.Spacing.sm)
            trailing
                .padding(.top, 2)
        }
        .padding(.horizontal, IPCATheme.Spacing.screen)
        .padding(.top, IPCATheme.Spacing.xs)
        .padding(.bottom, IPCATheme.Spacing.sm)
    }
}

extension IPCARootHeader where Trailing == EmptyView {
    init(title: String, subtitle: String) {
        self.init(title: title, subtitle: subtitle) { EmptyView() }
    }
}

struct IPCAGradientCircleButton: View {
    var systemImage: String
    var accessibilityLabel: String
    var size: CGFloat = 44
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: systemImage)
                .font(.system(size: size * 0.38, weight: .semibold))
                .foregroundStyle(.white)
                .frame(width: size, height: size)
                .background(IPCATheme.interactiveGradient, in: Circle())
                .shadow(color: IPCATheme.Colors.ipcaBlue.opacity(0.35), radius: 8, y: 3)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(accessibilityLabel)
    }
}

struct IPCAAvatar: View {
    var name: String
    var photoPath: String = ""
    var serverURL: String = ""
    var systemImage: String? = nil
    var size: CGFloat = 44

    var body: some View {
        ZStack {
            Circle().fill(IPCATheme.avatarGradient)
            if let url = IPCATheme.photoURL(path: photoPath, serverURL: serverURL) {
                AsyncImage(url: url) { phase in
                    switch phase {
                    case .success(let image):
                        image
                            .resizable()
                            .scaledToFill()
                    default:
                        avatarContent
                    }
                }
            } else {
                avatarContent
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
        .overlay(Circle().stroke(IPCATheme.Colors.separator, lineWidth: 0.5))
        .accessibilityHidden(true)
    }

    @ViewBuilder
    private var avatarContent: some View {
        if let systemImage {
            Image(systemName: systemImage)
                .font(.system(size: size * 0.38, weight: .semibold))
                .foregroundStyle(.white)
        } else {
            Text(IPCATheme.initials(from: name))
                .font(.system(size: size * 0.34, weight: .semibold))
                .foregroundStyle(.white)
        }
    }
}

struct IPCAIconTile: View {
    var systemImage: String
    var size: CGFloat = 36
    var foreground: Color = .white

    var body: some View {
        Image(systemName: systemImage)
            .font(.system(size: size * 0.42, weight: .semibold))
            .foregroundStyle(foreground)
            .frame(width: size, height: size)
            .background(IPCATheme.iconTileGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.small, style: .continuous))
            .accessibilityHidden(true)
    }
}

struct IPCASearchField: View {
    @Binding var text: String
    var placeholder: String
    var onFilter: (() -> Void)? = nil

    var body: some View {
        HStack(spacing: IPCATheme.Spacing.xs) {
            HStack(spacing: IPCATheme.Spacing.xs) {
                Image(systemName: "magnifyingglass")
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
                TextField(placeholder, text: $text)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                if !text.isEmpty {
                    Button {
                        text = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(IPCATheme.Colors.textTertiary)
                    }
                    .accessibilityLabel("Clear search")
                }
            }
            .padding(.horizontal, IPCATheme.Spacing.sm)
            .padding(.vertical, 10)
            .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                    .stroke(IPCATheme.Colors.separator, lineWidth: 1)
            )
            if let onFilter {
                Button(action: onFilter) {
                    Image(systemName: "line.3.horizontal.decrease")
                        .font(.system(size: 16, weight: .semibold))
                        .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                        .frame(width: 42, height: 42)
                        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                        .overlay(
                            RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                                .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                        )
                }
                .accessibilityLabel("Filter")
            }
        }
    }
}

struct IPCAFilterChip: View {
    var title: String
    var count: Int? = nil
    var selected: Bool
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Text(title)
                    .font(.subheadline.weight(.semibold))
                if let count {
                    Text("\(count)")
                        .font(.caption2.weight(.bold))
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(selected ? Color.white.opacity(0.22) : IPCATheme.Colors.navyElevated, in: Capsule())
                }
            }
            .foregroundStyle(selected ? Color.white : IPCATheme.Colors.textSecondary)
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background {
                if selected {
                    Capsule().fill(IPCATheme.interactiveGradient)
                } else {
                    Capsule().fill(IPCATheme.Colors.navySurface)
                }
            }
            .overlay(
                Capsule().stroke(selected ? Color.clear : IPCATheme.Colors.separator, lineWidth: 1)
            )
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(selected ? .isSelected : [])
    }
}

struct IPCASectionHeader: View {
    var title: String
    var accessory: String? = nil

    var body: some View {
        HStack {
            Text(title.uppercased())
                .font(.caption.weight(.semibold))
                .tracking(0.8)
                .foregroundStyle(IPCATheme.Colors.textTertiary)
            Spacer()
            if let accessory {
                Text(accessory)
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
            }
        }
        .padding(.horizontal, IPCATheme.Spacing.screen)
        .padding(.top, IPCATheme.Spacing.md)
        .padding(.bottom, IPCATheme.Spacing.xs)
    }
}

struct IPCAStatusBadge: View {
    var text: String
    var tone: Tone

    enum Tone {
        case info, attention, urgent, success, muted

        var foreground: Color {
            switch self {
            case .info: return IPCATheme.Colors.ipcaBlue
            case .attention: return IPCATheme.Colors.warning
            case .urgent: return IPCATheme.Colors.destructive
            case .success: return IPCATheme.Colors.success
            case .muted: return IPCATheme.Colors.textSecondary
            }
        }
    }

    var body: some View {
        Text(text)
            .font(.caption2.weight(.bold))
            .foregroundStyle(tone.foreground)
            .padding(.horizontal, 8)
            .padding(.vertical, 4)
            .background(tone.foreground.opacity(0.16), in: Capsule())
    }
}

struct IPCAUnreadBadge: View {
    var count: Int

    var body: some View {
        if count > 0 {
            Text(count > 99 ? "99+" : "\(count)")
                .font(.caption2.weight(.bold))
                .foregroundStyle(.white)
                .padding(.horizontal, 7)
                .padding(.vertical, 3)
                .background(IPCATheme.Colors.ipcaBlue, in: Capsule())
                .accessibilityLabel("\(count) unread")
        }
    }
}

struct IPCATabBar: View {
    @Binding var selection: AppTab
    var communityEnabled: Bool
    var trainingEnabled: Bool
    var trainingVideosEnabled: Bool
    var messagesBadge: Int

    var body: some View {
        HStack(spacing: 0) {
            tab(.messages, title: "Messages", systemImage: "bubble.left.and.bubble.right.fill", badge: messagesBadge)
            if communityEnabled {
                tab(.community, title: "Community", systemImage: "person.3.fill")
            }
            if trainingEnabled {
                tab(.training, title: "Training", systemImage: "graduationcap.fill")
            }
            if trainingVideosEnabled {
                tab(.trainingVideos, title: "Videos", systemImage: "play.rectangle.fill")
            }
            tab(.me, title: "Me", systemImage: "person.crop.circle.fill")
        }
        .padding(.top, 8)
        .padding(.bottom, 4)
        .background(
            IPCATheme.Colors.navyBase.opacity(0.96)
                .overlay(alignment: .top) {
                    Rectangle()
                        .fill(IPCATheme.Colors.separator)
                        .frame(height: 0.5)
                }
                .ignoresSafeArea(edges: .bottom)
        )
        .accessibilityElement(children: .contain)
    }

    private func tab(_ value: AppTab, title: String, systemImage: String, badge: Int = 0) -> some View {
        let selected = selection == value
        return Button {
            withAnimation(.easeInOut(duration: 0.18)) {
                selection = value
            }
        } label: {
            VStack(spacing: 4) {
                ZStack(alignment: .topTrailing) {
                    Image(systemName: systemImage)
                        .font(.system(size: 20, weight: .semibold))
                        .foregroundStyle(selected ? IPCATheme.Colors.ipcaBlue : IPCATheme.Colors.textTertiary)
                        .frame(height: 24)
                    if badge > 0 {
                        Text(badge > 99 ? "99+" : "\(badge)")
                            .font(.system(size: 9, weight: .bold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 4)
                            .padding(.vertical, 1)
                            .background(IPCATheme.Colors.destructive, in: Capsule())
                            .offset(x: 10, y: -6)
                    }
                }
                Text(title)
                    .font(.caption2.weight(selected ? .semibold : .regular))
                    .foregroundStyle(selected ? IPCATheme.Colors.ipcaBlue : IPCATheme.Colors.textTertiary)
                Circle()
                    .fill(selected ? IPCATheme.Colors.ipcaBlue : Color.clear)
                    .frame(width: 5, height: 5)
            }
            .frame(maxWidth: .infinity)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(title)
        .accessibilityAddTraits(selected ? .isSelected : [])
        .accessibilityValue(badge > 0 ? "\(badge) unread" : "")
    }
}

struct IPCASettingsRow: View {
    var icon: String
    var title: String
    var subtitle: String? = nil
    var value: String? = nil
    var valueColor: Color = IPCATheme.Colors.textSecondary
    var showsChevron: Bool = false

    var body: some View {
        HStack(spacing: IPCATheme.Spacing.sm) {
            IPCAIconTile(systemImage: icon)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.body.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                if let subtitle, !subtitle.isEmpty {
                    Text(subtitle)
                        .font(.footnote)
                        .foregroundStyle(IPCATheme.Colors.textSecondary)
                }
            }
            Spacer()
            if let value {
                Text(value)
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(valueColor)
            }
            if showsChevron {
                Image(systemName: "chevron.right")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
            }
        }
        .padding(.horizontal, IPCATheme.Spacing.md)
        .padding(.vertical, 12)
        .contentShape(Rectangle())
    }
}

struct IPCACircularProgress: View {
    var percent: Int
    var caption: String

    var body: some View {
        ZStack {
            Circle()
                .stroke(IPCATheme.Colors.navyElevated, lineWidth: 8)
            Circle()
                .trim(from: 0, to: CGFloat(min(max(percent, 0), 100)) / 100)
                .stroke(
                    IPCATheme.interactiveGradient,
                    style: StrokeStyle(lineWidth: 8, lineCap: .round)
                )
                .rotationEffect(.degrees(-90))
            VStack(spacing: 2) {
                Text("\(percent)%")
                    .font(.headline.weight(.bold))
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                Text(caption)
                    .font(.caption2)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
            }
        }
        .frame(width: 86, height: 86)
        .accessibilityLabel("\(percent) percent \(caption)")
    }
}

private struct IPCAHidesTabBarModifier: ViewModifier {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass

    func body(content: Content) -> some View {
        content
            .toolbar(.hidden, for: .tabBar)
            .onAppear {
                if sizeClass != .regular {
                    session.hidesTabBar = true
                }
            }
            .onDisappear {
                if sizeClass != .regular {
                    session.hidesTabBar = false
                }
            }
    }
}

extension View {
    func ipcaHidesTabBar() -> some View {
        modifier(IPCAHidesTabBarModifier())
    }

    func ipcaListChrome() -> some View {
        self
            .scrollContentBackground(.hidden)
            .listStyle(.plain)
            .background(IPCABackground())
    }
}
