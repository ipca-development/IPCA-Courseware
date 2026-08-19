import SwiftUI

struct FilterSheet: View {
    @EnvironmentObject private var session: SchedulingSession
    @Environment(\.dismiss) private var dismiss
    @State private var draft = ScheduleFilters.empty
    @State private var aircraft: [SchedulerResourceItem] = []
    @State private var people: [SchedulerResourceItem] = []

    var body: some View {
        NavigationStack {
            List {
                Section {
                    NavigationLink {
                        ResourcePicker(
                            title: "Aircraft",
                            allLabel: "All Aircraft",
                            items: aircraft,
                            selectedID: draft.aircraftID
                        ) { item in
                            draft.aircraftID = item?.id
                            draft.aircraftLabel = item?.label
                        }
                    } label: {
                        filterRow(
                            icon: "airplane",
                            title: "Aircraft",
                            value: draft.aircraftLabel ?? "All Aircraft"
                        )
                    }

                    NavigationLink {
                        ResourcePicker(
                            title: "Person",
                            allLabel: "Everyone",
                            items: people,
                            selectedID: draft.participantUserID
                        ) { item in
                            draft.participantUserID = item?.id
                            draft.participantLabel = item?.label
                        }
                    } label: {
                        filterRow(
                            icon: "person.2",
                            title: "Person",
                            value: draft.participantLabel ?? "Everyone"
                        )
                    }

                    Menu {
                        Button("All Cohorts") {
                            draft.cohortID = nil
                            draft.cohortLabel = nil
                        }
                        ForEach(cohortOptions, id: \.0) { id, name in
                            Button(name) {
                                draft.cohortID = id
                                draft.cohortLabel = name
                            }
                        }
                    } label: {
                        filterRow(
                            icon: "person.3",
                            title: "Cohort",
                            value: draft.cohortLabel ?? "All Cohorts"
                        )
                    }

                    Menu {
                        Button("All Types") { draft.reservationType = nil }
                        ForEach(typeOptions, id: \.0) { value, label in
                            Button(label) { draft.reservationType = value }
                        }
                    } label: {
                        filterRow(
                            icon: "square.grid.2x2",
                            title: "Reservation Type",
                            value: typeOptions.first(where: { $0.0 == draft.reservationType })?.1 ?? "All"
                        )
                    }
                }

                if !draft.isEmpty {
                    Section {
                        Button(role: .destructive) {
                            draft = .empty
                        } label: {
                            Label("Reset Filters", systemImage: "arrow.counterclockwise")
                        }
                    }
                }

                Section {
                    Text("Filters change this schedule view only. Your access remains controlled by IPCA.")
                        .font(.footnote)
                        .foregroundStyle(IPCAColors.textSecondary)
                }
            }
            .navigationTitle("Filters")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") {
                        Task {
                            await session.applyFilters(draft)
                            dismiss()
                        }
                    }
                    .fontWeight(.semibold)
                }
            }
            .task {
                draft = session.filters
                aircraft = await session.resourceOptions(type: "aircraft")
                people = await session.resourceOptions(type: "person")
            }
        }
        .presentationDetents([.medium, .large])
        .presentationDragIndicator(.visible)
    }

    private func filterRow(icon: String, title: String, value: String) -> some View {
        HStack(spacing: IPCASpacing.medium) {
            Image(systemName: icon)
                .foregroundStyle(IPCAColors.blue)
                .frame(width: 28)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).foregroundStyle(IPCAColors.text)
                Text(value)
                    .font(.subheadline)
                    .foregroundStyle(IPCAColors.textSecondary)
            }
        }
        .frame(minHeight: 48)
        .accessibilityElement(children: .combine)
    }

    private var cohortOptions: [(Int, String)] {
        var found: [Int: String] = [:]
        for reservation in session.reservations {
            if let id = reservation.cohort?.id, let name = reservation.cohort?.name, !name.isEmpty {
                found[id] = name
            }
        }
        return found.sorted { $0.value < $1.value }
    }

    private var typeOptions: [(String, String)] {
        var found: [String: String] = [:]
        for reservation in session.reservations {
            found[reservation.reservationType] = reservation.reservationTypeLabel
        }
        return found.sorted { $0.value < $1.value }
    }
}

private struct ResourcePicker: View {
    let title: String
    let allLabel: String
    let items: [SchedulerResourceItem]
    let selectedID: Int?
    let select: (SchedulerResourceItem?) -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var query = ""

    var body: some View {
        List {
            Button {
                select(nil)
                dismiss()
            } label: {
                selectionRow(label: allLabel, selected: selectedID == nil)
            }
            ForEach(filteredItems) { item in
                Button {
                    select(item)
                    dismiss()
                } label: {
                    selectionRow(label: item.label, selected: selectedID == item.id)
                }
            }
        }
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .searchable(text: $query, prompt: "Search")
    }

    private var filteredItems: [SchedulerResourceItem] {
        guard !query.isEmpty else { return items }
        return items.filter { $0.label.localizedCaseInsensitiveContains(query) }
    }

    private func selectionRow(label: String, selected: Bool) -> some View {
        HStack {
            Text(label).foregroundStyle(IPCAColors.text)
            Spacer()
            if selected {
                Image(systemName: "checkmark")
                    .fontWeight(.semibold)
                    .foregroundStyle(IPCAColors.blue)
            }
        }
        .contentShape(Rectangle())
    }
}
