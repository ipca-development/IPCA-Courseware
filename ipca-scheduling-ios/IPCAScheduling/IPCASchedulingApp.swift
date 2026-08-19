import SwiftUI

@main
struct IPCASchedulingApp: App {
    @StateObject private var session = SchedulingSession()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .task { await session.start() }
                .onChange(of: scenePhase) { _, phase in
                    if phase == .active {
                        Task { await session.refreshOnForeground() }
                    }
                }
                .tint(IPCAColors.navy)
        }
    }
}
