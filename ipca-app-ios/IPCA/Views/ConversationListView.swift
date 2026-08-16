import CoreData
import SwiftUI

private enum ConversationFilter: String, CaseIterable, Identifiable {
    case all = "All"
    case unread = "Unread"
    case direct = "Direct"
    case groups = "Groups"

    var id: String { rawValue }
}

struct ConversationListView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    var compactPath: Binding<NavigationPath>? = nil
    @State private var showingCompose = false
    @State private var searchText = ""
    @State private var filter: ConversationFilter = .all
    @FetchRequest(
        sortDescriptors: [
            NSSortDescriptor(key: "lastMessageAt", ascending: false),
            NSSortDescriptor(key: "conversationUUID", ascending: true)
        ]
    )
    private var conversations: FetchedResults<ConversationEntity>

    var body: some View {
        VStack(spacing: 0) {
            IPCARootHeader(title: "Messages", subtitle: "Stay connected with IPCA") {
                IPCAGradientCircleButton(systemImage: "square.and.pencil", accessibilityLabel: "New Message", action: openCompose)
            }
            if !session.isOnline {
                Text("Waiting for network. Unsent messages stay queued.")
                    .font(.footnote)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, IPCATheme.Spacing.xs)
                    .background(IPCATheme.Colors.navySurface)
            }
            VStack(spacing: IPCATheme.Spacing.sm) {
                IPCASearchField(text: $searchText, placeholder: "Search messages...")
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: IPCATheme.Spacing.xs) {
                        ForEach(ConversationFilter.allCases) { item in
                            IPCAFilterChip(
                                title: item.rawValue,
                                count: count(for: item),
                                selected: filter == item,
                                action: { filter = item }
                            )
                        }
                    }
                }
            }
            .padding(.horizontal, IPCATheme.Spacing.screen)
            .padding(.bottom, IPCATheme.Spacing.sm)

            List {
                if session.capabilities.systemMessagesEnabled {
                    if sizeClass == .regular {
                        Button {
                            session.showingActions = true
                            session.selectedConversationUUID = nil
                        } label: {
                            NeedsAttentionRow(count: session.needsActionCount)
                        }
                        .buttonStyle(.plain)
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                    } else if let compactPath {
                        Button {
                            compactPath.wrappedValue.append(MessagesActions())
                        } label: {
                            NeedsAttentionRow(count: session.needsActionCount)
                        }
                        .buttonStyle(.plain)
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                    } else {
                        NavigationLink {
                            ActionsView()
                        } label: {
                            NeedsAttentionRow(count: session.needsActionCount)
                        }
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                    }
                }
                if visibleConversations.isEmpty {
                    ContentUnavailableView(
                        "No Messages",
                        systemImage: "bubble.left",
                        description: Text(emptyDescription)
                    )
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                    .listRowBackground(Color.clear)
                    .listRowSeparator(.hidden)
                } else {
                    ForEach(visibleConversations, id: \.conversationUUID) { conversation in
                        if sizeClass == .regular {
                            Button {
                                session.showingActions = false
                                session.selectedConversationUUID = conversation.conversationUUID
                            } label: {
                                ConversationRow(conversation: conversation)
                            }
                            .buttonStyle(.plain)
                            .listRowBackground(isSelected(conversation) ? IPCATheme.Colors.navyElevated.opacity(0.7) : Color.clear)
                            .listRowSeparatorTint(IPCATheme.Colors.separator)
                        } else if let compactPath {
                            Button {
                                compactPath.wrappedValue.append(conversation.conversationUUID)
                            } label: {
                                ConversationRow(conversation: conversation)
                            }
                            .buttonStyle(.plain)
                            .listRowBackground(Color.clear)
                            .listRowSeparatorTint(IPCATheme.Colors.separator)
                        } else {
                            NavigationLink(value: conversation.conversationUUID) {
                                ConversationRow(conversation: conversation)
                            }
                            .listRowBackground(Color.clear)
                            .listRowSeparatorTint(IPCATheme.Colors.separator)
                        }
                    }
                }
            }
            .ipcaListChrome()
        }
        .background(IPCABackground())
        .toolbar(.hidden, for: .navigationBar)
        .sheet(isPresented: $showingCompose) {
            NavigationStack {
                NewMessageView()
            }
        }
        .onAppear {
            if session.selectedConversationUUID == nil && !session.showingActions {
                session.selectedConversationUUID = conversations.first?.conversationUUID
            }
        }
    }

    private var visibleConversations: [ConversationEntity] {
        conversations.filter { conversation in
            switch filter {
            case .all:
                break
            case .unread:
                if conversation.unreadCount <= 0 { return false }
            case .direct:
                if conversation.conversationType != "direct" { return false }
            case .groups:
                if conversation.conversationType != "group" { return false }
            }
            let query = searchText.trimmingCharacters(in: .whitespacesAndNewlines)
            guard !query.isEmpty else { return true }
            return conversation.title.localizedCaseInsensitiveContains(query)
                || conversation.preview.localizedCaseInsensitiveContains(query)
        }
    }

    private var emptyDescription: String {
        if !searchText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            return "No conversations match that search."
        }
        if filter != .all {
            return "Nothing in this filter yet."
        }
        return "Start a conversation with someone at IPCA."
    }

    private func count(for filter: ConversationFilter) -> Int {
        switch filter {
        case .all:
            return conversations.count
        case .unread:
            return conversations.filter { $0.unreadCount > 0 }.count
        case .direct:
            return conversations.filter { $0.conversationType == "direct" }.count
        case .groups:
            return conversations.filter { $0.conversationType == "group" }.count
        }
    }

    private func openCompose() {
        if let compactPath {
            compactPath.wrappedValue.append(MessagesCompose())
        } else if sizeClass == .regular {
            showingCompose = true
        } else {
            showingCompose = true
        }
    }

    private func isSelected(_ conversation: ConversationEntity) -> Bool {
        !session.showingActions && session.selectedConversationUUID == conversation.conversationUUID
    }
}

struct ConversationRow: View {
    @ObservedObject var conversation: ConversationEntity

    private var isOfficial: Bool {
        conversation.conversationType == "system" || conversation.conversationType == "announcement"
    }

    private var unread: Bool {
        conversation.unreadCount > 0
    }

    var body: some View {
        HStack(spacing: IPCATheme.Spacing.sm) {
            if isOfficial {
                IPCAAvatar(name: conversation.title, systemImage: "megaphone.fill", size: 48)
            } else if conversation.conversationType == "group" {
                IPCAAvatar(name: conversation.title, systemImage: "person.3.fill", size: 48)
            } else {
                IPCAAvatar(name: conversation.title, size: 48)
            }
            VStack(alignment: .leading, spacing: 3) {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(conversation.title.isEmpty ? "Conversation" : conversation.title)
                        .font(.body.weight(unread ? .bold : .semibold))
                        .foregroundStyle(IPCATheme.Colors.textPrimary)
                        .lineLimit(1)
                    if conversation.conversationType == "group" {
                        IPCAStatusBadge(text: "Group", tone: .info)
                    } else if isOfficial {
                        IPCAStatusBadge(text: "Official", tone: .info)
                    }
                    Spacer(minLength: 8)
                    if let date = conversation.lastMessageAt {
                        Text(IPCATheme.conversationTimestamp(date))
                            .font(.caption)
                            .foregroundStyle(unread ? IPCATheme.Colors.ipcaBlue : IPCATheme.Colors.textTertiary)
                    }
                }
                HStack(alignment: .top) {
                    Text(conversation.preview)
                        .font(.subheadline)
                        .foregroundStyle(unread ? IPCATheme.Colors.textSecondary : IPCATheme.Colors.textTertiary)
                        .lineLimit(1)
                    Spacer()
                    IPCAUnreadBadge(count: Int(conversation.unreadCount))
                }
            }
        }
        .padding(.vertical, 6)
        .contentShape(Rectangle())
    }
}

struct NeedsAttentionRow: View {
    var count: Int

    var body: some View {
        HStack(spacing: IPCATheme.Spacing.sm) {
            IPCAIconTile(
                systemImage: count > 0 ? "exclamationmark.triangle.fill" : "checkmark.circle.fill",
                foreground: count > 0 ? IPCATheme.Colors.warning : IPCATheme.Colors.success
            )
            VStack(alignment: .leading, spacing: 3) {
                Text("Needs Attention")
                    .font(.body.weight(.semibold))
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                Text(count == 0 ? "You're all caught up" : attentionCopy)
                    .font(.subheadline)
                    .foregroundStyle(IPCATheme.Colors.textSecondary)
                    .lineLimit(2)
            }
            Spacer()
            if count > 0 {
                IPCAUnreadBadge(count: count)
                    .accessibilityLabel("\(count) needing attention")
            }
            Image(systemName: "chevron.right")
                .font(.footnote.weight(.semibold))
                .foregroundStyle(IPCATheme.Colors.textTertiary)
        }
        .padding(IPCATheme.Spacing.sm)
        .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous))
        .overlay(alignment: .leading) {
            if count > 0 {
                RoundedRectangle(cornerRadius: 2)
                    .fill(IPCATheme.Colors.warning)
                    .frame(width: 3)
                    .padding(.vertical, 10)
            }
        }
        .overlay(
            RoundedRectangle(cornerRadius: IPCATheme.Radius.card, style: .continuous)
                .stroke(IPCATheme.Colors.separator, lineWidth: 1)
        )
        .padding(.vertical, 4)
        .contentShape(Rectangle())
    }

    private var attentionCopy: String {
        if count == 1 {
            return "1 official message requires your response"
        }
        return "\(count) official messages require your response"
    }
}
