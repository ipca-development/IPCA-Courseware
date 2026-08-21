import SwiftUI

final class SchedulingAppDelegate: NSObject, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        supportedInterfaceOrientationsFor window: UIWindow?
    ) -> UIInterfaceOrientationMask {
        let arguments = ProcessInfo.processInfo.arguments
        let isWorkstationPreview = arguments.contains("--ui-preview")
            && arguments.contains { $0.hasPrefix("workstation-") }
            && !arguments.contains("workstation-portrait")
            && !arguments.contains("workstation-narrow")
        if isWorkstationPreview && UIDevice.current.userInterfaceIdiom == .pad {
            return .landscape
        }
        return UIDevice.current.userInterfaceIdiom == .pad ? .all : .portrait
    }
}

@main
struct IPCASchedulingApp: App {
    @UIApplicationDelegateAdaptor(SchedulingAppDelegate.self) private var appDelegate
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
