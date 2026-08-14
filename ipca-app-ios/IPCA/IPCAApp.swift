import SwiftUI

@main
struct IPCAApp: App {
    @StateObject private var session = AppSession()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(session)
                .environment(\.managedObjectContext, session.persistence.viewContext)
                .task {
                    if ProcessInfo.processInfo.arguments.contains("-IPCALiveBase") {
                        await LiveAppValidation.runIfRequested(session: session)
                    } else {
                        await session.restoreIfPossible()
                    }
                }
        }
    }
}
