import SwiftUI

enum IPCATheme {
    static let navy = Color(red: 0.02, green: 0.09, blue: 0.25)
    static let blue = Color(red: 0.02, green: 0.22, blue: 0.55)
    static let brightBlue = Color(red: 0.05, green: 0.42, blue: 0.95)
    static let lightBlue = Color(red: 0.82, green: 0.91, blue: 1.0)
    static let pageBackground = Color(red: 0.94, green: 0.97, blue: 1.0)
    static let cardBackground = Color.white.opacity(0.94)
    static let secondaryText = Color(red: 0.29, green: 0.36, blue: 0.47)
    static let success = Color(red: 0.0, green: 0.56, blue: 0.30)
    static let warning = Color(red: 0.86, green: 0.50, blue: 0.05)
    static let danger = Color(red: 0.78, green: 0.08, blue: 0.10)
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
        VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 8) {
                Image(systemName: systemImage)
                    .foregroundStyle(IPCATheme.brightBlue)
                    .frame(width: 26, height: 26)
                    .background(IPCATheme.lightBlue.opacity(0.72), in: Circle())
                Text(title)
                    .font(.headline.weight(.bold))
                    .foregroundStyle(IPCATheme.navy)
            }
            content
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .foregroundStyle(IPCATheme.navy)
        .background(IPCATheme.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .shadow(color: IPCATheme.navy.opacity(0.08), radius: 12, y: 6)
    }
}
