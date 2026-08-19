import SwiftUI

struct ScheduleView: View {
    @EnvironmentObject private var session: SchedulingSession
    @Binding var showFilters: Bool
    @State private var showDatePicker = false

    var body: some View {
        ZStack {
            IPCAColors.background.ignoresSafeArea()
            ScrollView {
                LazyVStack(alignment: .leading, spacing: IPCASpacing.large) {
                    if session.isShowingCachedData || !session.connectivity.isOnline {
                        OfflineBanner(
                            lastUpdated: session.lastUpdated,
                            isOffline: !session.connectivity.isOnline
                        )
                        .clipShape(RoundedRectangle(cornerRadius: IPCARadius.medium))
                    }

                    calendarControl

                    VStack(alignment: .leading, spacing: IPCASpacing.medium) {
                        HStack {
                            Text(session.clock.longDate(session.selectedDate))
                                .font(.system(.headline, design: .rounded, weight: .bold))
                                .foregroundStyle(IPCAColors.text)
                            Spacer()
                            if session.isRefreshing {
                                ProgressView()
                                    .controlSize(.small)
                                    .tint(IPCAColors.navy)
                                    .accessibilityLabel("Refreshing schedule")
                            }
                        }

                        agenda
                    }
                }
                .padding(.horizontal, IPCASpacing.screen)
                .padding(.vertical, IPCASpacing.large)
            }
            .refreshable { await session.refresh(force: true) }
        }
        .safeAreaInset(edge: .top, spacing: 0) {
            BrandHeader(eyebrow: "SCHEDULE", title: session.clock.monthTitle(session.selectedDate)) {
                HStack(spacing: 8) {
                    Button {
                        session.goToToday()
                    } label: {
                        Text("Today")
                            .font(.subheadline.weight(.semibold))
                            .padding(.horizontal, 12)
                            .frame(height: 38)
                            .background(.white.opacity(0.12), in: Capsule())
                    }
                    .accessibilityHint("Selects today's date")

                    if session.isStaffExperience {
                        Button {
                            showFilters = true
                        } label: {
                            Image(systemName: session.filters.isEmpty
                                  ? "line.3.horizontal.decrease"
                                  : "line.3.horizontal.decrease.circle.fill")
                                .frame(width: 38, height: 38)
                                .background(.white.opacity(0.12), in: Circle())
                        }
                        .accessibilityLabel(session.filters.isEmpty ? "Filters" : "Filters applied")
                    }
                }
                .foregroundStyle(.white)
            }
        }
        .toolbar(.hidden, for: .navigationBar)
        .sheet(isPresented: $showDatePicker) {
            NavigationStack {
                DatePicker(
                    "Select date",
                    selection: $session.selectedDate,
                    displayedComponents: .date
                )
                .datePickerStyle(.graphical)
                .tint(IPCAColors.navy)
                .padding()
                .navigationTitle("Choose a Date")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Done") { showDatePicker = false }
                    }
                }
            }
            .presentationDetents([.medium])
        }
    }

    private var calendarControl: some View {
        VStack(spacing: IPCASpacing.medium) {
            HStack {
                Button {
                    session.moveWeek(by: -1)
                } label: {
                    Image(systemName: "chevron.left")
                        .frame(width: 44, height: 44)
                }
                .accessibilityLabel("Previous week")

                Spacer()
                Button {
                    showDatePicker = true
                } label: {
                    HStack(spacing: 7) {
                        Text(weekRange)
                            .font(.subheadline.weight(.semibold))
                        Image(systemName: "chevron.down")
                            .font(.caption.weight(.bold))
                    }
                }
                .accessibilityHint("Opens the month date picker")
                Spacer()

                Button {
                    session.moveWeek(by: 1)
                } label: {
                    Image(systemName: "chevron.right")
                        .frame(width: 44, height: 44)
                }
                .accessibilityLabel("Next week")
            }
            .foregroundStyle(IPCAColors.navy)

            WeekDateStrip(
                dates: session.clock.week(containing: session.selectedDate),
                selectedDate: session.selectedDate,
                todayKey: session.todayKey,
                clock: session.clock,
                select: session.selectDate
            )
        }
        .padding(IPCASpacing.medium)
        .ipcaCard()
    }

    @ViewBuilder
    private var agenda: some View {
        if session.selectedDayReservations.isEmpty && session.isRefreshing {
            LoadingScheduleView()
        } else if session.selectedDayReservations.isEmpty {
            VStack(spacing: 12) {
                Image(systemName: "calendar")
                    .font(.system(size: 30, weight: .medium))
                    .foregroundStyle(IPCAColors.blue)
                Text("Nothing scheduled")
                    .font(.headline)
                    .foregroundStyle(IPCAColors.text)
                Text("This day is clear.")
                    .font(.subheadline)
                    .foregroundStyle(IPCAColors.textSecondary)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 34)
            .ipcaCard()
        } else {
            ForEach(session.selectedDayReservations) { reservation in
                NavigationLink(value: reservation) {
                    HStack(alignment: .top, spacing: IPCASpacing.medium) {
                        VStack(spacing: 2) {
                            Text(session.clock.time(reservation.startLocal))
                                .font(.subheadline.weight(.bold))
                            Text(session.clock.time(reservation.endLocal))
                                .font(.caption)
                                .foregroundStyle(IPCAColors.textSecondary)
                        }
                        .frame(width: 72, alignment: .leading)

                        RoundedRectangle(cornerRadius: 2)
                            .fill(statusColor(reservation.status))
                            .frame(width: 4)

                        VStack(alignment: .leading, spacing: 6) {
                            Text(reservation.title)
                                .font(.headline)
                                .foregroundStyle(IPCAColors.text)
                            HStack(spacing: 10) {
                                Text(reservation.aircraft.registration)
                                if let crew = reservation.crewSummary { Text(crew).lineLimit(1) }
                            }
                            .font(.subheadline)
                            .foregroundStyle(IPCAColors.textSecondary)
                        }
                        Spacer(minLength: 0)
                        Image(systemName: "chevron.right")
                            .font(.caption.weight(.bold))
                            .foregroundStyle(IPCAColors.textSecondary.opacity(0.6))
                    }
                    .padding(IPCASpacing.standard)
                    .frame(minHeight: 92)
                    .ipcaCard()
                    .accessibilityElement(children: .combine)
                }
                .buttonStyle(.plain)
            }
        }
    }

    private var weekRange: String {
        let week = session.clock.week(containing: session.selectedDate)
        guard let first = week.first, let last = week.last else { return "" }
        let firstText = session.clock.monthDay(first)
        let lastText = session.clock.monthDay(last)
        return "\(firstText) – \(lastText)"
    }

    private func statusColor(_ status: String) -> Color {
        switch status.lowercased() {
        case "active", "claimed": IPCAColors.blue
        case "completed": IPCAColors.success
        case "cancelled": IPCAColors.destructive
        default: IPCAColors.navy
        }
    }
}
