import SwiftUI

struct MessagesCompose: Hashable {}

struct MessagesRootView: View {
    @Environment(\.horizontalSizeClass) private var sizeClass
    @EnvironmentObject private var session: AppSession
    @State private var path = NavigationPath()

    var body: some View {
        Group {
            if sizeClass == .regular {
                NavigationSplitView {
                    ConversationListView()
                } detail: {
                    if let uuid = session.selectedConversationUUID {
                        ConversationView(conversationUUID: uuid)
                    } else {
                        ContentUnavailableView("Messages", systemImage: "bubble.left.and.bubble.right", description: Text("Select a conversation"))
                    }
                }
            } else {
                NavigationStack(path: $path) {
                    ConversationListView(compactPath: $path)
                        .navigationDestination(for: MessagesCompose.self) { _ in
                            NewMessageView(compactPath: $path)
                        }
                        .navigationDestination(for: String.self) { uuid in
                            ConversationView(conversationUUID: uuid)
                        }
                }
            }
        }
        .onChange(of: session.pendingConversationUUID) { _, uuid in
            guard let uuid else { return }
            if sizeClass == .regular {
                session.pendingConversationUUID = nil
                return
            }
            path = NavigationPath()
            path.append(uuid)
            session.pendingConversationUUID = nil
        }
    }
}
