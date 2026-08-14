import CoreData
import SwiftUI

struct ConversationListView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    var compactPath: Binding<NavigationPath>? = nil
    @FetchRequest(
        sortDescriptors: [
            NSSortDescriptor(key: "lastMessageAt", ascending: false),
            NSSortDescriptor(key: "conversationUUID", ascending: true)
        ]
    )
    private var conversations: FetchedResults<ConversationEntity>

    var body: some View {
        List {
            if conversations.isEmpty {
                ContentUnavailableView("No Messages", systemImage: "bubble.left", description: Text("Start a conversation with someone at IPCA."))
            } else {
                ForEach(conversations, id: \.conversationUUID) { conversation in
                    if sizeClass == .regular {
                        Button {
                            session.selectedConversationUUID = conversation.conversationUUID
                        } label: {
                            ConversationRow(conversation: conversation)
                        }
                        .buttonStyle(.plain)
                    } else {
                        NavigationLink(value: conversation.conversationUUID) {
                            ConversationRow(conversation: conversation)
                        }
                    }
                }
            }
        }
        .listStyle(.plain)
        .navigationTitle("Messages")
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                if let compactPath {
                    Button {
                        compactPath.wrappedValue.append(MessagesCompose())
                    } label: {
                        Image(systemName: "square.and.pencil")
                    }
                    .accessibilityLabel("New Message")
                } else {
                    NavigationLink {
                        NewMessageView()
                    } label: {
                        Image(systemName: "square.and.pencil")
                    }
                    .accessibilityLabel("New Message")
                }
            }
        }
        .onAppear {
            if session.selectedConversationUUID == nil {
                session.selectedConversationUUID = conversations.first?.conversationUUID
            }
        }
    }
}

struct ConversationRow: View {
    @ObservedObject var conversation: ConversationEntity

    var body: some View {
        HStack(spacing: 12) {
            InitialsView(name: conversation.title)
            VStack(alignment: .leading, spacing: 2) {
                HStack {
                    Text(conversation.title.isEmpty ? "Conversation" : conversation.title)
                        .font(.headline)
                        .lineLimit(1)
                    Spacer()
                    if let date = conversation.lastMessageAt {
                        Text(date, style: .time)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }
                HStack {
                    Text(conversation.preview)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                    Spacer()
                    if conversation.unreadCount > 0 {
                        Text(conversation.unreadCount > 99 ? "99+" : "\(conversation.unreadCount)")
                            .font(.caption2.weight(.bold))
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.accentColor, in: Capsule())
                            .foregroundStyle(.white)
                            .accessibilityLabel("\(conversation.unreadCount) unread")
                    }
                }
            }
        }
        .padding(.vertical, 4)
    }
}

struct InitialsView: View {
    let name: String

    var body: some View {
        Text(initials)
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(.white)
            .frame(width: 40, height: 40)
            .background(Color.accentColor.opacity(0.85), in: Circle())
            .accessibilityHidden(true)
    }

    private var initials: String {
        let parts = name.split(separator: " ").prefix(2)
        let letters = parts.compactMap { $0.first }.map(String.init)
        return letters.joined().uppercased()
    }
}
