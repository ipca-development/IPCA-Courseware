import SwiftUI

struct TodayView: View {
    @EnvironmentObject private var session: SchedulingSession

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

                    content
                }
                .padding(.horizontal, IPCASpacing.screen)
                .padding(.vertical, IPCASpacing.large)
            }
            .refreshable { await session.refresh(force: true) }
        }
        .safeAreaInset(edge: .top, spacing: 0) {
            BrandHeader(
                eyebrow: "IPCA",
                title: greeting,
                subtitle: session.clock.longDate(session.now)
            ) {
                Image("IPCALogoWhite")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 42, height: 42)
                    .accessibilityLabel("IPCA")
            }
        }
        .toolbar(.hidden, for: .navigationBar)
    }

    @ViewBuilder
    private var content: some View {
        if session.reservations.isEmpty && session.isRefreshing {
            LoadingScheduleView()
        } else if session.reservations.isEmpty, let error = session.errorMessage {
            ScheduleErrorView(message: error) { await session.refresh(force: true) }
        } else if session.todaySections.isEmpty {
            EmptyScheduleView(
                nextReservation: session.nextUpcomingReservation,
                clock: session.clock
            )
        } else {
            ForEach(session.todaySections, id: \.0) { section, reservations in
                VStack(alignment: .leading, spacing: IPCASpacing.medium) {
                    Text(section.rawValue.uppercased())
                        .font(.caption.weight(.bold))
                        .tracking(1.2)
                        .foregroundStyle(section == .next ? IPCAColors.blue : IPCAColors.textSecondary)

                    ForEach(reservations) { reservation in
                        NavigationLink(value: reservation) {
                            ReservationCard(
                                reservation: reservation,
                                clock: session.clock,
                                isNext: section == .next,
                                now: session.now
                            )
                            .opacity(section == .completed ? 0.72 : 1)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private var greeting: String {
        let hour = session.clock.calendar.component(.hour, from: session.now)
        let salutation: String
        switch hour {
        case 5 ..< 12: salutation = "Good morning"
        case 12 ..< 18: salutation = "Good afternoon"
        default: salutation = "Good evening"
        }
        let firstName = session.user?.name.split(separator: " ").first.map(String.init) ?? "there"
        return "\(salutation), \(firstName)"
    }
}
