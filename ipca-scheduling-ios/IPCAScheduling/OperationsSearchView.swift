import SwiftUI

struct OperationsSearchView: View {
    @EnvironmentObject private var session: SchedulingSession
    let selectReservation: (SchedulerReservation) -> Void
    let close: () -> Void

    @State private var query = ""
    @State private var serverResults: SchedulerSearchResults?
    @State private var isSearching = false

    var body: some View {
        NavigationStack {
            List {
                if !reservationMatches.isEmpty {
                    Section("Reservations") {
                        ForEach(reservationMatches.prefix(20)) { reservation in
                            Button {
                                selectReservation(reservation)
                                close()
                            } label: {
                                reservationResult(reservation)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }

                if let serverResults {
                    if !serverResults.aircraft.isEmpty {
                        resourceSection("Aircraft", items: serverResults.aircraft, icon: "airplane")
                    }
                    if !serverResults.person.isEmpty {
                        resourceSection("People", items: serverResults.person, icon: "person")
                    }
                    if !serverResults.mission.isEmpty {
                        resourceSection("Training", items: serverResults.mission, icon: "book.closed")
                    }
                }

                if !query.isEmpty && reservationMatches.isEmpty && noServerResults && !isSearching {
                    ContentUnavailableView.search(text: query)
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("Find on Schedule")
            .navigationBarTitleDisplayMode(.inline)
            .searchable(text: $query, prompt: "Aircraft, person, mission, reservation")
            .onSubmit(of: .search) { searchServer() }
            .onChange(of: query) { _, newValue in
                if newValue.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    serverResults = nil
                }
            }
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done", action: close)
                }
                if isSearching {
                    ToolbarItem(placement: .primaryAction) {
                        ProgressView().controlSize(.small)
                    }
                }
            }
        }
    }

    private var reservationMatches: [SchedulerReservation] {
        OperationsNavigator.search(
            reservations: session.reservations,
            query: query
        )
    }

    private var noServerResults: Bool {
        guard let serverResults else { return true }
        return serverResults.aircraft.isEmpty
            && serverResults.person.isEmpty
            && serverResults.mission.isEmpty
    }

    private func reservationResult(_ reservation: SchedulerReservation) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 4) {
                Text(reservation.title)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(IPCAColors.text)
                Text("\(reservation.aircraft.registration) · \(session.clock.timeRange(start: reservation.startLocal, end: reservation.endLocal))")
                    .font(.caption)
                    .foregroundStyle(IPCAColors.textSecondary)
                if let crew = reservation.crewSummary {
                    Text(crew)
                        .font(.caption)
                        .foregroundStyle(IPCAColors.textSecondary)
                        .lineLimit(1)
                }
            }
            Spacer()
            Image(systemName: "scope")
                .foregroundStyle(IPCAColors.blue)
                .accessibilityHidden(true)
        }
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityHint("Shows this reservation on the timeline")
    }

    private func resourceSection(
        _ title: String,
        items: [SchedulerResourceItem],
        icon: String
    ) -> some View {
        Section(title) {
            ForEach(items) { item in
                Label(item.label, systemImage: icon)
                    .foregroundStyle(IPCAColors.text)
            }
        }
    }

    private func searchServer() {
        isSearching = true
        Task {
            serverResults = await session.searchResources(query: query)
            isSearching = false
        }
    }
}
