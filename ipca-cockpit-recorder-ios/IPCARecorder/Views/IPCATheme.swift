import SwiftUI

enum IPCATheme {
    static let navy = Color(red: 0.02, green: 0.09, blue: 0.25)
    static let blue = Color(red: 0.02, green: 0.22, blue: 0.55)
    static let brightBlue = Color(red: 0.05, green: 0.42, blue: 0.95)
    static let lightBlue = Color(red: 0.82, green: 0.91, blue: 1.0)
    static let pageBackground = Color(red: 0.94, green: 0.97, blue: 1.0)
    static let cardBackground = Color.white.opacity(0.94)
    static let success = Color(red: 0.0, green: 0.56, blue: 0.30)
    static let warning = Color(red: 0.86, green: 0.50, blue: 0.05)
    static let danger = Color(red: 0.78, green: 0.08, blue: 0.10)
}

struct IPCALogoMark: View {
    var compact = false

    var body: some View {
        HStack(spacing: compact ? 8 : 12) {
            ZStack {
                Circle()
                    .fill(.white.opacity(0.16))
                Circle()
                    .stroke(.white.opacity(0.75), lineWidth: 2)
                Text("I")
                    .font(.system(size: compact ? 17 : 24, weight: .heavy, design: .rounded))
                    .foregroundStyle(.white)
            }
            .frame(width: compact ? 34 : 46, height: compact ? 34 : 46)
            .shadow(color: .black.opacity(0.25), radius: 7, y: 4)

            VStack(alignment: .leading, spacing: compact ? 0 : 2) {
                Text("IPCA")
                    .font(.system(size: compact ? 22 : 32, weight: .heavy, design: .rounded))
                    .tracking(1.5)
                    .foregroundStyle(.white)
                if !compact {
                    Text("Cockpit Recorder")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white.opacity(0.82))
                        .textCase(.uppercase)
                        .tracking(1.1)
                }
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel("IPCA Cockpit Recorder")
    }
}

struct IPCAHeader: View {
    var title: String
    var subtitle: String
    var systemImage: String

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            HStack(alignment: .top) {
                IPCALogoMark()
                Spacer()
                Image(systemName: systemImage)
                    .font(.system(size: 30, weight: .semibold))
                    .foregroundStyle(.white.opacity(0.85))
                    .padding(12)
                    .background(.white.opacity(0.12), in: Circle())
            }

            VStack(alignment: .leading, spacing: 6) {
                Text(title)
                    .font(.largeTitle.weight(.bold))
                    .foregroundStyle(.white)
                Text(subtitle)
                    .font(.headline)
                    .foregroundStyle(.white.opacity(0.82))
            }
        }
        .padding(24)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            LinearGradient(
                colors: [IPCATheme.navy, IPCATheme.blue, IPCATheme.brightBlue.opacity(0.82)],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            ),
            in: RoundedRectangle(cornerRadius: 28)
        )
        .overlay(alignment: .bottomTrailing) {
            Circle()
                .fill(.white.opacity(0.10))
                .frame(width: 170, height: 170)
                .offset(x: 38, y: 58)
        }
        .clipShape(RoundedRectangle(cornerRadius: 28))
        .shadow(color: IPCATheme.navy.opacity(0.28), radius: 22, y: 12)
    }
}

struct IPCACard<Content: View>: View {
    var title: String
    var systemImage: String
    private var content: Content

    init(title: String, systemImage: String, @ViewBuilder content: () -> Content) {
        self.title = title
        self.systemImage = systemImage
        self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 10) {
                Image(systemName: systemImage)
                    .font(.headline)
                    .foregroundStyle(IPCATheme.brightBlue)
                    .frame(width: 30, height: 30)
                    .background(IPCATheme.lightBlue.opacity(0.72), in: Circle())
                Text(title)
                    .font(.headline.weight(.bold))
                    .foregroundStyle(IPCATheme.navy)
            }

            content
        }
        .padding(18)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(IPCATheme.cardBackground, in: RoundedRectangle(cornerRadius: 20))
        .overlay {
            RoundedRectangle(cornerRadius: 20)
                .stroke(.white.opacity(0.8), lineWidth: 1)
        }
        .shadow(color: IPCATheme.navy.opacity(0.08), radius: 18, y: 8)
    }
}

struct IPCAStatusPill: View {
    var text: String
    var color: Color

    var body: some View {
        Text(text)
            .font(.caption.weight(.bold))
            .foregroundStyle(color)
            .padding(.horizontal, 9)
            .padding(.vertical, 4)
            .background(color.opacity(0.12), in: Capsule())
    }
}
