import SwiftUI

enum IPCAColors {
    static let navy = Color(hex: 0x16233C)
    static let navyDeep = Color(hex: 0x0C172B)
    static let blue = Color(hex: 0x2D6CDF)
    static let blueBright = Color(hex: 0x5592F2)
    static let background = Color(hex: 0xF4F2ED)
    static let surface = Color.white
    static let surfaceMuted = Color(hex: 0xECEFF4)
    static let text = Color(hex: 0x152139)
    static let textSecondary = Color(hex: 0x637084)
    static let separator = Color(hex: 0xDDE2EA)
    static let success = Color(hex: 0x25865F)
    static let warning = Color(hex: 0xB56B12)
    static let warningSurface = Color(hex: 0xFFF5E5)
    static let destructive = Color(hex: 0xC84747)

    static let brandGradient = LinearGradient(
        colors: [navy, Color(hex: 0x203C6B)],
        startPoint: .topLeading,
        endPoint: .bottomTrailing
    )

    static let actionGradient = LinearGradient(
        colors: [blue, Color(hex: 0x2052AD)],
        startPoint: .topLeading,
        endPoint: .bottomTrailing
    )
}

enum IPCASpacing {
    static let xSmall: CGFloat = 4
    static let small: CGFloat = 8
    static let medium: CGFloat = 12
    static let standard: CGFloat = 16
    static let large: CGFloat = 20
    static let xLarge: CGFloat = 28
    static let screen: CGFloat = 18
}

enum IPCARadius {
    static let small: CGFloat = 10
    static let medium: CGFloat = 14
    static let card: CGFloat = 20
    static let large: CGFloat = 28
}

extension Color {
    init(hex: UInt32, alpha: Double = 1) {
        self.init(
            .sRGB,
            red: Double((hex >> 16) & 0xff) / 255,
            green: Double((hex >> 8) & 0xff) / 255,
            blue: Double(hex & 0xff) / 255,
            opacity: alpha
        )
    }
}

struct IPCACardModifier: ViewModifier {
    var highlighted = false

    func body(content: Content) -> some View {
        content
            .background(
                RoundedRectangle(cornerRadius: IPCARadius.card, style: .continuous)
                    .fill(highlighted ? AnyShapeStyle(IPCAColors.brandGradient) : AnyShapeStyle(IPCAColors.surface))
                    .shadow(
                        color: IPCAColors.navy.opacity(highlighted ? 0.16 : 0.07),
                        radius: highlighted ? 18 : 10,
                        y: highlighted ? 9 : 5
                    )
            )
    }
}

extension View {
    func ipcaCard(highlighted: Bool = false) -> some View {
        modifier(IPCACardModifier(highlighted: highlighted))
    }
}

struct BrandHeader<Accessory: View>: View {
    let eyebrow: String?
    let title: String
    let subtitle: String?
    @ViewBuilder let accessory: Accessory

    init(
        eyebrow: String? = nil,
        title: String,
        subtitle: String? = nil,
        @ViewBuilder accessory: () -> Accessory
    ) {
        self.eyebrow = eyebrow
        self.title = title
        self.subtitle = subtitle
        self.accessory = accessory()
    }

    var body: some View {
        HStack(alignment: .center, spacing: IPCASpacing.standard) {
            VStack(alignment: .leading, spacing: 5) {
                if let eyebrow {
                    Text(eyebrow.uppercased())
                        .font(.caption.weight(.semibold))
                        .tracking(1.2)
                        .foregroundStyle(.white.opacity(0.65))
                }
                Text(title)
                    .font(.system(.title2, design: .rounded, weight: .bold))
                    .foregroundStyle(.white)
                if let subtitle {
                    Text(subtitle)
                        .font(.subheadline)
                        .foregroundStyle(.white.opacity(0.72))
                }
            }
            Spacer(minLength: 8)
            accessory
        }
        .padding(.horizontal, IPCASpacing.screen)
        .padding(.top, 10)
        .padding(.bottom, 22)
        .background(IPCAColors.brandGradient)
    }
}

extension BrandHeader where Accessory == EmptyView {
    init(eyebrow: String? = nil, title: String, subtitle: String? = nil) {
        self.init(eyebrow: eyebrow, title: title, subtitle: subtitle) { EmptyView() }
    }
}

struct StatusBadge: View {
    let status: String
    var locked = false

    private var label: String {
        status.replacingOccurrences(of: "_", with: " ").capitalized
    }

    private var color: Color {
        switch status.lowercased() {
        case "active", "claimed": IPCAColors.blue
        case "completed": IPCAColors.success
        case "cancelled": IPCAColors.destructive
        default: IPCAColors.navy
        }
    }

    var body: some View {
        HStack(spacing: 5) {
            if locked { Image(systemName: "lock.fill").font(.caption2) }
            Text(label)
        }
        .font(.caption.weight(.semibold))
        .foregroundStyle(color)
        .padding(.horizontal, 10)
        .padding(.vertical, 6)
        .background(color.opacity(0.1), in: Capsule())
        .accessibilityElement(children: .combine)
    }
}

struct OfflineBanner: View {
    let lastUpdated: Date?
    let isOffline: Bool

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: isOffline ? "wifi.slash" : "clock.arrow.circlepath")
            Text(message)
                .font(.footnote.weight(.medium))
            Spacer()
        }
        .foregroundStyle(IPCAColors.warning)
        .padding(.horizontal, IPCASpacing.standard)
        .padding(.vertical, 10)
        .background(IPCAColors.warningSurface)
        .accessibilityElement(children: .combine)
    }

    private var message: String {
        guard let lastUpdated else { return "Offline · Showing saved schedule" }
        let time = lastUpdated.formatted(date: .omitted, time: .shortened)
        return "\(isOffline ? "Offline" : "Saved schedule") · Last updated \(time)"
    }
}

struct LoadingScheduleView: View {
    var body: some View {
        VStack(spacing: 14) {
            ForEach(0 ..< 3, id: \.self) { index in
                RoundedRectangle(cornerRadius: IPCARadius.card)
                    .fill(IPCAColors.surface)
                    .frame(height: index == 0 ? 150 : 112)
                    .overlay(alignment: .leading) {
                        VStack(alignment: .leading, spacing: 10) {
                            RoundedRectangle(cornerRadius: 4)
                                .fill(IPCAColors.surfaceMuted)
                                .frame(width: 110, height: 16)
                            RoundedRectangle(cornerRadius: 4)
                                .fill(IPCAColors.surfaceMuted)
                                .frame(width: 190, height: 22)
                            RoundedRectangle(cornerRadius: 4)
                                .fill(IPCAColors.surfaceMuted)
                                .frame(width: 145, height: 14)
                        }
                        .padding()
                    }
            }
        }
        .redacted(reason: .placeholder)
        .accessibilityLabel("Loading schedule")
    }
}

struct ScheduleErrorView: View {
    let message: String
    let retry: () async -> Void

    var body: some View {
        VStack(spacing: IPCASpacing.standard) {
            Image(systemName: "wifi.exclamationmark")
                .font(.system(size: 34, weight: .medium))
                .foregroundStyle(IPCAColors.blue)
            Text("Couldn't update the schedule")
                .font(.headline)
                .foregroundStyle(IPCAColors.text)
            Text(message)
                .font(.subheadline)
                .multilineTextAlignment(.center)
                .foregroundStyle(IPCAColors.textSecondary)
            Button("Try Again") { Task { await retry() } }
                .buttonStyle(.borderedProminent)
                .tint(IPCAColors.navy)
        }
        .padding(IPCASpacing.xLarge)
        .frame(maxWidth: .infinity)
        .ipcaCard()
    }
}
