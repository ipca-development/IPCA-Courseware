import SwiftUI

enum IPCAReaderTheme {
    static let accent = Color(red: 0.06, green: 0.15, blue: 0.27)
    static let shelfBackground = Color(red: 0.92, green: 0.92, blue: 0.94)
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
