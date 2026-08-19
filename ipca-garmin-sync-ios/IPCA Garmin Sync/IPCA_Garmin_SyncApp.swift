import SwiftUI

@main
struct IPCAGarminSyncApp: App {
    @StateObject private var model = SyncViewModel()

    var body: some Scene {
        WindowGroup {
            ContentView(model: model)
                .task { await model.recoverOnLaunch() }
        }
    }
}
