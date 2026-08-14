import SwiftUI

struct NewMessageView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var query = ""
    @State private var groupName = ""
    @State private var selected: Set<String> = []
    @State private var isGroup = false

    var body: some View {
        List {
            if session.capabilities.groupsEnabled {
                Toggle("New Group", isOn: $isGroup)
            }
            if isGroup {
                TextField("Group name", text: $groupName)
            }
            Section("People") {
                ForEach(session.people) { person in
                    Button {
                        if isGroup {
                            if selected.contains(person.uuid) {
                                selected.remove(person.uuid)
                            } else {
                                selected.insert(person.uuid)
                            }
                        } else {
                            Task {
                                await session.openDirect(with: person)
                                dismiss()
                            }
                        }
                    } label: {
                        HStack {
                            VStack(alignment: .leading) {
                                Text(person.name)
                                    .foregroundStyle(.primary)
                                Text(person.role.replacingOccurrences(of: "_", with: " "))
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            if isGroup && selected.contains(person.uuid) {
                                Image(systemName: "checkmark.circle.fill")
                            }
                        }
                    }
                }
            }
        }
        .navigationTitle("New Message")
        .searchable(text: $query)
        .onChange(of: query) {
            Task { await session.searchPeople(query) }
        }
        .task {
            await session.searchPeople("")
        }
        .toolbar {
            if isGroup {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Create") {
                        Task {
                            let members = session.people.filter { selected.contains($0.uuid) }
                            await session.createGroup(title: groupName, members: members)
                            dismiss()
                        }
                    }
                    .disabled(groupName.trimmingCharacters(in: .whitespaces).isEmpty || selected.isEmpty)
                }
            }
        }
    }
}
