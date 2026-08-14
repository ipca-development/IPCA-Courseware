import SwiftUI

struct MainShellView: View {
    @EnvironmentObject private var session: AppSession

    var body: some View {
        TabView {
            MessagesRootView()
                .tabItem {
                    Label("Messages", systemImage: "bubble.left.and.bubble.right")
                }
            if session.capabilities.communityEnabled {
                PlaceholderView(title: "Community", systemImage: "person.3")
                    .tabItem { Label("Community", systemImage: "person.3") }
            }
            if session.capabilities.trainingEnabled {
                PlaceholderView(title: "Training", systemImage: "airplane")
                    .tabItem { Label("Training", systemImage: "airplane") }
            }
            MeView()
                .tabItem {
                    Label("Me", systemImage: "person.crop.circle")
                }
        }
    }
}

struct PlaceholderView: View {
    let title: String
    let systemImage: String

    var body: some View {
        NavigationStack {
            ContentUnavailableView(title, systemImage: systemImage)
                .navigationTitle(title)
        }
    }
}
