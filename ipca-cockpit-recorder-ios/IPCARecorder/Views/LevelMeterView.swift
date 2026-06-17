import SwiftUI

struct LevelMeterView: View {
    var level: Float

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .leading) {
                RoundedRectangle(cornerRadius: 8)
                    .fill(.gray.opacity(0.2))
                RoundedRectangle(cornerRadius: 8)
                    .fill(level > 0.75 ? .orange : .green)
                    .frame(width: max(4, proxy.size.width * CGFloat(level)))
            }
        }
        .frame(height: 18)
        .accessibilityLabel("Audio level")
        .accessibilityValue("\(Int(level * 100)) percent")
    }
}
