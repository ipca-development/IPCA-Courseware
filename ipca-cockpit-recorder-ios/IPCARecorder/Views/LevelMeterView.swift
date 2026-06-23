import SwiftUI

struct LevelMeterView: View {
    var level: Float
    var peakLevel: Float

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    RoundedRectangle(cornerRadius: 10)
                        .fill(IPCATheme.lightBlue.opacity(0.45))
                    RoundedRectangle(cornerRadius: 10)
                        .fill(level > 0.75 ? IPCATheme.warning : IPCATheme.success)
                        .frame(width: max(2, proxy.size.width * CGFloat(level)))
                    Rectangle()
                        .fill(IPCATheme.navy)
                        .frame(width: 3)
                        .offset(x: max(0, min(proxy.size.width - 3, proxy.size.width * CGFloat(peakLevel))))
                }
            }
            .frame(height: 28)

            HStack {
                Text("Quiet")
                Spacer()
                Text("Signal")
                Spacer()
                Text("Loud")
            }
            .font(.caption2)
            .foregroundStyle(IPCATheme.secondaryText)
        }
        .accessibilityLabel("Audio level")
        .accessibilityValue("\(Int(level * 100)) percent")
    }
}
