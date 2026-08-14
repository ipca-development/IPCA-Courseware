import SwiftUI

struct MessagesRootView: View {
    @Environment(\.horizontalSizeClass) private var sizeClass
    @EnvironmentObject private var session: AppSession

    var body: some View {
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
            NavigationStack {
                ConversationListView()
            }
        }
    }
}
