import CoreData
import SwiftUI

struct GroupMembersView: View {
    let conversationUUID: String
    let title: String
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var showingAddPeople = false
    @State private var errorMessage: String?
    @State private var isSaving = false

    @FetchRequest private var members: FetchedResults<MemberEntity>

    init(conversationUUID: String, title: String) {
        self.conversationUUID = conversationUUID
        self.title = title
        _members = FetchRequest(
            sortDescriptors: [NSSortDescriptor(key: "name", ascending: true)],
            predicate: NSPredicate(format: "conversationUUID == %@", conversationUUID)
        )
    }

    var body: some View {
        List {
            Section {
                ForEach(members, id: \.userUUID) { member in
                    HStack(spacing: IPCATheme.Spacing.sm) {
                        IPCAAvatar(name: member.name, size: 40)
                        VStack(alignment: .leading, spacing: 4) {
                            HStack(spacing: 6) {
                                Text(member.name.isEmpty ? "Member" : member.name)
                                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                                if member.userUUID == session.user?.uuid {
                                    Text("You")
                                        .font(.caption2.weight(.semibold))
                                        .foregroundStyle(IPCATheme.Colors.textTertiary)
                                }
                            }
                            IPCARolePill(role: member.role)
                        }
                        Spacer()
                    }
                    .listRowBackground(IPCATheme.Colors.navySurface)
                }
                .onDelete(perform: session.capabilities.groupsEnabled ? remove : nil)
            } header: {
                Text(members.count == 1 ? "1 member" : "\(members.count) members")
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
            }
        }
        .ipcaListChrome()
        .navigationTitle(title.isEmpty ? "Group" : title)
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyBase, for: .navigationBar)
        .toolbar {
            ToolbarItem(placement: .cancellationAction) {
                Button("Done") { dismiss() }
            }
            if session.capabilities.groupsEnabled {
                ToolbarItem(placement: .primaryAction) {
                    Button {
                        showingAddPeople = true
                    } label: {
                        Image(systemName: "person.badge.plus")
                    }
                    .accessibilityLabel("Add people")
                    .disabled(isSaving)
                }
            }
        }
        .overlay {
            if isSaving {
                ProgressView()
                    .tint(IPCATheme.Colors.ipcaBlue)
            }
        }
        .alert("Couldn't update the group", isPresented: Binding(
            get: { errorMessage != nil },
            set: { if !$0 { errorMessage = nil } }
        )) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(errorMessage ?? "")
        }
        .sheet(isPresented: $showingAddPeople) {
            NavigationStack {
                GroupAddPeopleView(
                    existingUUIDs: Set(members.map(\.userUUID)),
                    onAdd: { people in
                        showingAddPeople = false
                        Task { await add(people) }
                    }
                )
                .environmentObject(session)
            }
        }
    }

    private func add(_ people: [PublicUser]) async {
        guard !people.isEmpty, !isSaving else { return }
        isSaving = true
        defer { isSaving = false }
        if await session.updateGroupMembers(conversationUUID: conversationUUID, add: people) {
            return
        }
        errorMessage = session.actionError ?? "Couldn't add those people."
    }

    private func remove(at offsets: IndexSet) {
        let uuids = offsets.compactMap { index -> String? in
            guard members.indices.contains(index) else { return nil }
            return members[index].userUUID
        }
        guard !uuids.isEmpty else { return }
        Task { await remove(uuids: uuids) }
    }

    private func remove(uuids: [String]) async {
        guard !uuids.isEmpty, !isSaving else { return }
        isSaving = true
        defer { isSaving = false }
        let left = uuids.contains(session.user?.uuid ?? "")
        if await session.updateGroupMembers(conversationUUID: conversationUUID, removeUUIDs: uuids) {
            if left {
                dismiss()
            }
            return
        }
        errorMessage = session.actionError ?? "Couldn't update the group."
    }
}

private struct GroupAddPeopleView: View {
    let existingUUIDs: Set<String>
    var onAdd: ([PublicUser]) -> Void
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    @State private var selected: Set<String> = []

    var body: some View {
        List {
            Section {
                ForEach(visiblePeople) { person in
                    Button {
                        if selected.contains(person.uuid) {
                            selected.remove(person.uuid)
                        } else {
                            selected.insert(person.uuid)
                        }
                    } label: {
                        HStack(spacing: IPCATheme.Spacing.sm) {
                            IPCAAvatar(
                                name: person.name,
                                photoPath: person.photoPath,
                                serverURL: session.serverURLString,
                                size: 40
                            )
                            VStack(alignment: .leading, spacing: 4) {
                                Text(person.name)
                                    .foregroundStyle(IPCATheme.Colors.textPrimary)
                                IPCARolePill(role: person.role)
                            }
                            Spacer()
                            if selected.contains(person.uuid) {
                                Image(systemName: "checkmark.circle.fill")
                                    .foregroundStyle(IPCATheme.Colors.ipcaBlue)
                            }
                        }
                    }
                    .listRowBackground(IPCATheme.Colors.navySurface)
                }
            } header: {
                Text("People")
                    .foregroundStyle(IPCATheme.Colors.textTertiary)
            }
        }
        .ipcaListChrome()
        .navigationTitle("Add People")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyBase, for: .navigationBar)
        .searchable(text: $query, prompt: "Search people")
        .onChange(of: query) {
            Task { await session.searchPeople(query) }
        }
        .task {
            await session.searchPeople("")
        }
        .toolbar {
            ToolbarItem(placement: .cancellationAction) {
                Button("Cancel") { dismiss() }
            }
            ToolbarItem(placement: .confirmationAction) {
                Button("Add") {
                    onAdd(session.people.filter { selected.contains($0.uuid) })
                }
                .disabled(selected.isEmpty)
            }
        }
    }

    private var visiblePeople: [PublicUser] {
        session.people.filter { !$0.uuid.isEmpty && !existingUUIDs.contains($0.uuid) }
    }
}
