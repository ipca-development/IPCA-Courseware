import SwiftUI

@main
struct IPCAApp: App {
    @UIApplicationDelegateAdaptor(IPCAAppDelegate.self) private var appDelegate
    @StateObject private var session = AppSession()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .environment(\.managedObjectContext, session.persistence.viewContext)
                .onAppear {
                    appDelegate.session = session
                }
                .onOpenURL { session.handleOpenURL($0) }
                .task {
                    appDelegate.session = session
                    if ProcessInfo.processInfo.arguments.contains("-IPCALiveBase") {
                        await LiveAppValidation.runIfRequested(session: session)
                    } else {
                        await session.restoreIfPossible()
                    }
                }
        }
    }
}
