import SwiftUI

enum CVROperationalPalette {
    static let background = Color(red: 0.005, green: 0.02, blue: 0.045)
    static let cardBackground = Color(red: 0.025, green: 0.085, blue: 0.155)
    static let cardBorder = Color(red: 0.12, green: 0.34, blue: 0.56).opacity(0.55)
    static let primaryBlue = Color(red: 0.12, green: 0.47, blue: 0.92)
    static let secondaryBlue = Color(red: 0.37, green: 0.64, blue: 1.0)
    static let textPrimary = Color.white.opacity(0.92)
    static let textSecondary = Color.white.opacity(0.62)
    static let success = Color(red: 0.25, green: 0.82, blue: 0.32)
    static let standby = Color(red: 0.96, green: 0.67, blue: 0.20)
    static let warning = Color(red: 1.0, green: 0.50, blue: 0.12)
    static let critical = Color(red: 0.96, green: 0.18, blue: 0.16)
}

struct CVROperationalMetrics {
    var size: CGSize

    var isCompact: Bool { size.height < 790 }
    var spacing: CGFloat { isCompact ? 8 : 10 }
    var outerHorizontalPadding: CGFloat { 14 }
    var outerVerticalPadding: CGFloat { isCompact ? 8 : 12 }
    var cardPadding: CGFloat { isCompact ? 11 : 13 }
    var logoHeight: CGFloat { isCompact ? 42 : 48 }
    var headerHeight: CGFloat { isCompact ? 58 : 66 }
    var headerCenterX: CGFloat { size.width * 0.50 }
    var aircraftTextBlockWidth: CGFloat { size.width * 0.34 }
    var headerTextLeadingSpacing: CGFloat { 18 }
    var headerTextHorizontalOffset: CGFloat { -2 }
    var headerTextVerticalOffset: CGFloat { 0 }
    var aircraftRegistrationFontSize: CGFloat { isCompact ? 25 : 28 }
    var aircraftRegistrationTracking: CGFloat { 3.0 }
    var unitIdentifierFontSize: CGFloat { isCompact ? 11 : 12 }
    var unitIdentifierTracking: CGFloat { 4.0 }
    var statusFontSize: CGFloat { isCompact ? 24 : 27 }
    var timerFontSize: CGFloat { isCompact ? 32 : 38 }
    var tileIconSize: CGFloat { isCompact ? 20 : 23 }
    var tileHeight: CGFloat { isCompact ? 88 : 98 }
    var primaryHeight: CGFloat { isCompact ? 112 : 128 }
}

struct CVROperationalHeaderView: View {
    var aircraftRegistration: String
    var unitIdentifier: String
    var metrics: CVROperationalMetrics
    var onLogoTap: () -> Void

    var body: some View {
        ZStack(alignment: .leading) {
            Button(action: onLogoTap) {
                Image("ipca_cvr_logo_official")
                    .renderingMode(.original)
                    .resizable()
                    .scaledToFit()
                    .frame(height: metrics.logoHeight)
                    .accessibilityIdentifier("ipcaOfficialLogo")
                    .accessibilityLabel("IPCA logo")
            }
            .buttonStyle(.plain)
            .frame(height: metrics.headerHeight, alignment: .center)

            Rectangle()
                .fill(CVROperationalPalette.cardBorder)
                .frame(width: 1, height: metrics.logoHeight)
                .position(x: metrics.headerCenterX, y: metrics.headerHeight / 2)

            VStack(alignment: .trailing, spacing: metrics.isCompact ? 0 : 1) {
                Text(aircraftRegistration)
                    .font(.system(size: metrics.aircraftRegistrationFontSize, weight: .bold, design: .rounded))
                    .tracking(metrics.aircraftRegistrationTracking)
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                    .allowsTightening(true)
                    .multilineTextAlignment(.trailing)
                Text(unitIdentifier)
                    .font(.system(size: metrics.unitIdentifierFontSize, weight: .semibold, design: .rounded))
                    .tracking(metrics.unitIdentifierTracking)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
                    .multilineTextAlignment(.trailing)
            }
            .frame(width: metrics.aircraftTextBlockWidth, alignment: .trailing)
            .position(
                x: metrics.headerCenterX + metrics.headerTextLeadingSpacing + metrics.aircraftTextBlockWidth / 2 + metrics.headerTextHorizontalOffset,
                y: metrics.headerHeight / 2 + metrics.headerTextVerticalOffset
            )
        }
        .frame(height: metrics.headerHeight)
        .accessibilityElement(children: .combine)
        .accessibilityLabel("Aircraft \(aircraftRegistration), \(unitIdentifier)")
    }
}

struct CVROperationalStatusCard: View {
    var title: String
    var subtitle: String
    var iconName: String
    var color: Color
    var value: String?
    var caption: String?
    var metrics: CVROperationalMetrics

    var body: some View {
        VStack(spacing: metrics.isCompact ? 5 : 7) {
            HStack(spacing: 8) {
                Image(systemName: iconName)
                    .foregroundStyle(color)
                Text(title)
                    .font(.system(size: metrics.statusFontSize, weight: .bold, design: .rounded))
                    .foregroundStyle(color)
                    .lineLimit(1)
                    .minimumScaleFactor(0.82)
                    .allowsTightening(true)
            }
            Text(subtitle)
                .font(.caption.weight(.semibold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .lineLimit(1)
                .allowsTightening(true)
            if let value {
                Text(value)
                    .font(.system(size: metrics.timerFontSize, weight: .bold, design: .monospaced))
                    .monospacedDigit()
                    .foregroundStyle(CVROperationalPalette.textPrimary)
                    .lineLimit(1)
                    .minimumScaleFactor(0.85)
            }
            if let caption {
                Text(caption)
                    .font(.caption2.weight(.bold))
                    .tracking(1.4)
                    .foregroundStyle(CVROperationalPalette.secondaryBlue)
                    .lineLimit(1)
            }
        }
        .padding(metrics.cardPadding)
        .frame(maxWidth: .infinity, minHeight: metrics.primaryHeight)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

struct CVROperationalTile: View {
    var title: String
    var iconName: String
    var value: String
    var color: Color
    var metrics: CVROperationalMetrics

    var body: some View {
        VStack(spacing: 5) {
            Image(systemName: iconName)
                .font(.system(size: metrics.tileIconSize, weight: .semibold))
                .foregroundStyle(CVROperationalPalette.secondaryBlue)
                .frame(height: metrics.tileIconSize + 2)
            Text(title)
                .font(.caption2.weight(.bold))
                .foregroundStyle(CVROperationalPalette.textSecondary)
                .lineLimit(1)
                .frame(height: 13)
            Text(value)
                .font(.caption.weight(.bold))
                .foregroundStyle(color)
                .lineLimit(2)
                .multilineTextAlignment(.center)
                .minimumScaleFactor(0.82)
                .frame(height: 32, alignment: .top)
        }
        .padding(.horizontal, 6)
        .padding(.vertical, 8)
        .frame(maxWidth: .infinity, minHeight: metrics.tileHeight, maxHeight: metrics.tileHeight)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 16))
        .overlay(RoundedRectangle(cornerRadius: 16).stroke(CVROperationalPalette.cardBorder, lineWidth: 1))
    }
}

struct CVROperationalWarningCard: View {
    var title: String
    var message: String
    var iconName: String
    var color: Color

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: iconName)
                .font(.title3.weight(.bold))
                .foregroundStyle(color)
            VStack(alignment: .leading, spacing: 2) {
                Text(title)
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(color)
                    .lineLimit(1)
                Text(message)
                    .font(.caption)
                    .foregroundStyle(CVROperationalPalette.textSecondary)
                    .lineLimit(2)
                    .minimumScaleFactor(0.86)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 10)
        .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 18))
        .overlay(RoundedRectangle(cornerRadius: 18).stroke(color.opacity(0.75), lineWidth: 1))
    }
}

struct CVROperationalActionButton: View {
    var title: String
    var subtitle: String?
    var color: Color
    var action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 2) {
                Text(title)
                    .font(.subheadline.weight(.bold))
                    .tracking(0.8)
                    .lineLimit(1)
                if let subtitle {
                    Text(subtitle)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(CVROperationalPalette.textSecondary)
                        .lineLimit(1)
                }
            }
            .foregroundStyle(color)
            .frame(maxWidth: .infinity, minHeight: 50)
            .background(CVROperationalPalette.cardBackground, in: RoundedRectangle(cornerRadius: 17))
            .overlay(RoundedRectangle(cornerRadius: 17).stroke(color.opacity(0.75), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .contentShape(RoundedRectangle(cornerRadius: 17))
    }
}
