import SwiftUI

@main
struct IPCAManualReaderApp: App {
    var body: some Scene {
        WindowGroup {
#if DEBUG
            if ProcessInfo.processInfo.arguments.contains("--pagination-self-test") {
                PaginationSelfTestView()
            } else {
                ContentView()
            }
#else
            ContentView()
#endif
        }
    }
}
