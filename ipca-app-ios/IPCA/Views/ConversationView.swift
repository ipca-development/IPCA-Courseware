import CoreData
import SwiftUI

struct ConversationView: View {
    let conversationUUID: String
    @EnvironmentObject private var session: AppSession
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var draft = ""
    @FocusState private var composerFocused: Bool

    @FetchRequest private var messages: FetchedResults<MessageEntity>
    @FetchRequest private var conversations: FetchedResults<ConversationEntity>
    @FetchRequest private var members: FetchedResults<MemberEntity>

    init(conversationUUID: String) {
        self.conversationUUID = conversationUUID
        _messages = FetchRequest(
            sortDescriptors: [
                NSSortDescriptor(key: "seq", ascending: true),
                NSSortDescriptor(key: "createdAt", ascending: true)
            ],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
        _conversations = FetchRequest(
            sortDescriptors: [],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
        _members = FetchRequest(
            sortDescriptors: [NSSortDescriptor(key: "name", ascending: true)],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
    }

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: 8) {
                    ForEach(orderedMessages, id: \.clientID) { message in
                        MessageBubble(message: message)
                            .id(message.clientID)
                    }
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
            }
            .onChange(of: orderedMessages.count) {
                if let last = orderedMessages.last {
                    proxy.scrollTo(last.clientID, anchor: .bottom)
                }
            }
            .onAppear {
                if let last = orderedMessages.last {
                    proxy.scrollTo(last.clientID, anchor: .bottom)
                    let maxSeq = orderedMessages.map(\.seq).max() ?? 0
                    if maxSeq > 0 {
                        Task {
                            await session.markRead(conversationUUID: conversationUUID, seq: Int(maxSeq))
                        }
                    }
                }
            }
        }
        .safeAreaInset(edge: .bottom) {
            ComposerBar(text: $draft, focused: $composerFocused) {
                let body = draft
                draft = ""
                Task { await session.send(conversationUUID: conversationUUID, body: body) }
            }
        }
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .onAppear {
            session.selectedConversationUUID = conversationUUID
        }
    }

    private var title: String {
        conversations.first?.title ?? "Messages"
    }

    private var orderedMessages: [MessageEntity] {
        let confirmed = messages.filter { $0.seq > 0 }.sorted { $0.seq < $1.seq }
        let pending = messages.filter { $0.seq == 0 }.sorted { $0.createdAt < $1.createdAt }
        return confirmed + pending
    }
}

struct MessageBubble: View {
    @ObservedObject var message: MessageEntity
    @EnvironmentObject private var session: AppSession

    var body: some View {
        HStack {
            if message.isFromMe { Spacer(minLength: 48) }
            VStack(alignment: message.isFromMe ? .trailing : .leading, spacing: 4) {
                Text(message.body)
                    .padding(.horizontal, 12)
                    .padding(.vertical, 8)
                    .background(message.isFromMe ? Color.accentColor : Color(.systemGray5), in: RoundedRectangle(cornerRadius: 18))
                    .foregroundStyle(message.isFromMe ? Color.white : Color.primary)
                caption
            }
            if !message.isFromMe { Spacer(minLength: 48) }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilityText)
    }

    @ViewBuilder
    private var caption: some View {
        switch LocalMessageState(rawValue: message.localState) {
        case .queued, .sending:
            Text("Sending…")
                .font(.caption2)
                .foregroundStyle(.secondary)
        case .failed:
            Button("Not Delivered — Tap to Retry") {
                Task { await session.retry(clientID: message.clientID) }
            }
            .font(.caption2)
            .foregroundStyle(.red)
        case .serverReceived, .none:
            EmptyView()
        }
    }

    private var accessibilityText: String {
        var text = message.body
        if message.localState == LocalMessageState.sending.rawValue || message.localState == LocalMessageState.queued.rawValue {
            text += ", Sending"
        }
        if message.localState == LocalMessageState.failed.rawValue {
            text += ", Not delivered"
        }
        return text
    }
}

struct ComposerBar: View {
    @Binding var text: String
    var focused: FocusState<Bool>.Binding
    var onSend: () -> Void

    var body: some View {
        HStack(alignment: .bottom, spacing: 8) {
            TextField("Message", text: $text, axis: .vertical)
                .textFieldStyle(.plain)
                .lineLimit(1...6)
                .focused(focused)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .background(Color(.systemGray6), in: RoundedRectangle(cornerRadius: 18))
            Button(action: onSend) {
                Image(systemName: "arrow.up.circle.fill")
                    .font(.system(size: 28))
            }
            .disabled(text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            .accessibilityLabel("Send")
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(.bar)
    }
}
