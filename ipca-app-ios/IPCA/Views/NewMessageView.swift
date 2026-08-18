import SwiftUI

struct NewMessageView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    var compactPath: Binding<NavigationPath>? = nil
    @State private var query = ""
    @State private var groupName = ""
    @State private var selected: Set<String> = []
    @State private var isGroup = false
    @State private var isOpening = false
    @State private var errorMessage: String?

    var body: some View {
        List {
            if session.capabilities.groupsEnabled {
                Toggle("New Group", isOn: $isGroup)
                    .tint(IPCATheme.Colors.ipcaBlue)
                    .listRowBackground(IPCATheme.Colors.navySurface)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
            }
            if isGroup {
                TextField("Group name", text: $groupName)
                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                    .listRowBackground(IPCATheme.Colors.navySurface)
            }
            Section {
                ForEach(visiblePeople) { person in
                    Button {
                        if isGroup {
                            if selected.contains(person.uuid) {
                                selected.remove(person.uuid)
                            } else {
                                selected.insert(person.uuid)
                            }
                        } else {
                            Task { await openDirect(person) }
                        }
                    } label: {
                        HStack(spacing: IPCATheme.Spacing.sm) {
                            IPCAAvatar(
                                name: person.name,
                                photoPath: person.photoPath,
                                serverURL: session.serverURLString,
                                size: 40
                            )
                            VStack(alignment: .leading, spacing: 2) {
                                Text(person.name)
                                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                                IPCARolePill(role: person.role)
                            }
                            Spacer()
                            if isGroup && selected.contains(person.uuid) {
                                Image(systemName: "checkmark.circle.fill")
                                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                            }
                        }
                    }
                    .disabled(isOpening)
                    .listRowBackground(IPCATheme.Colors.navySurface)
                }
            } header: {
                Text("People")
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
            }
        }
        .ipcaListChrome()
        .navigationTitle("New Message")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyBase, for: .navigationBar)
        .searchable(text: $query, prompt: "Search people")
        .overlay {
            if isOpening {
                ProgressView()
                    .tint(IPCATheme.Colors.ipcaBlue)
            }
        }
        .onChange(of: query) {
            Task { await session.searchPeople(query) }
        }
        .task {
            await session.searchPeople("")
        }
        .alert("Couldn't start that conversation", isPresented: Binding(
            get: { errorMessage != nil },
            set: { if !$0 { errorMessage = nil } }
        )) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(errorMessage ?? "")
        }
        .toolbar {
            if isGroup {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Create") {
                        Task { await openGroup() }
                    }
                    .disabled(groupName.trimmingCharacters(in: .whitespaces).isEmpty || selected.isEmpty || isOpening)
                }
            }
        }
    }

    private var visiblePeople: [PublicUser] {
        session.people.filter { !$0.uuid.isEmpty }
    }

    private func openDirect(_ person: PublicUser) async {
        guard !isOpening else { return }
        isOpening = true
        defer { isOpening = false }
        if await session.openDirect(with: person) {
            revealOpenedConversation()
        } else {
            errorMessage = session.actionError ?? "Couldn't start that conversation."
        }
    }

    private func openGroup() async {
        guard !isOpening else { return }
        isOpening = true
        defer { isOpening = false }
        let members = session.people.filter { selected.contains($0.uuid) }
        if await session.createGroup(title: groupName, members: members) {
            revealOpenedConversation()
        } else {
            errorMessage = session.actionError ?? "Couldn't create that group."
        }
    }

    private func revealOpenedConversation() {
        guard let uuid = session.selectedConversationUUID else {
            dismiss()
            return
        }
        if var path = compactPath?.wrappedValue, !path.isEmpty {
            path.removeLast()
            path.append(uuid)
            compactPath?.wrappedValue = path
        } else {
            dismiss()
        }
    }
}
