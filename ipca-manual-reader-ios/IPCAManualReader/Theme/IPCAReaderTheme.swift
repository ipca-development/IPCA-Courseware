import SwiftUI

enum IPCAReaderTheme {
    static let navy = Color(red: 22 / 255, green: 35 / 255, blue: 60 / 255)
    static let navyLight = Color(red: 35 / 255, green: 54 / 255, blue: 88 / 255)
    static let accent = navy
    static let shelfBackground = Color(red: 248 / 255, green: 249 / 255, blue: 251 / 255)
    static let cardBackground = Color.white
    static let divider = Color.black.opacity(0.08)
    static let muted = Color.secondary
}

struct ManualPageScale {
    static func scale(for zoom: ReaderZoomMode, containerSize: CGSize) -> CGFloat {
        let pageW = ManualPageLayout.width
        let pageH = ManualPageLayout.height
        guard containerSize.width > 0, containerSize.height > 0 else { return 1 }

        switch zoom {
        case .fitWidth:
            return containerSize.width / pageW
        case .fitPage:
            return min(containerSize.width / pageW, containerSize.height / pageH)
        case .percent75:
            return 0.75
        case .percent100:
            return 1
        case .percent125:
            return 1.25
        }
    }
}
