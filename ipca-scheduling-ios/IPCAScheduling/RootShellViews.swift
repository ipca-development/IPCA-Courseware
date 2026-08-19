import SwiftUI

struct RootView: View {
    @EnvironmentObject private var session: SchedulingSession
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Group {
            switch session.launchState {
            case .launching:
                LaunchView()
            case .signedOut:
                LoginView()
            case .signedIn:
                MainTabView()
            }
        }
        .animation(reduceMotion ? nil : .easeInOut(duration: 0.2), value: session.launchState)
    }
}

private struct LaunchView: View {
    var body: some View {
        ZStack {
            IPCAColors.brandGradient.ignoresSafeArea()
            VStack(spacing: 20) {
                Image("IPCALogoLockup")
                    .resizable()
                    .scaledToFit()
                    .frame(width: 210)
                    .accessibilityLabel("IPCA")
                ProgressView()
                    .tint(.white)
                    .accessibilityLabel("Restoring your session")
            }
        }
    }
}

struct LoginView: View {
    @EnvironmentObject private var session: SchedulingSession
    @State private var email = ""
    @State private var password = ""
    @FocusState private var focusedField: Field?

    private enum Field { case email, password }

    var body: some View {
        ZStack {
            IPCAColors.brandGradient.ignoresSafeArea()
            Circle()
                .fill(IPCAColors.blue.opacity(0.2))
                .frame(width: 330, height: 330)
                .blur(radius: 1)
                .offset(x: 170, y: -330)
                .accessibilityHidden(true)

            ScrollView {
                VStack(spacing: 0) {
                    Spacer(minLength: 66)
                    Image("IPCALogoLockup")
                        .resizable()
                        .scaledToFit()
                        .frame(width: 228)
                        .accessibilityLabel("IPCA")

                    VStack(alignment: .leading, spacing: 8) {
                        Text("Your schedule, simplified.")
                            .font(.system(.title2, design: .rounded, weight: .bold))
                            .foregroundStyle(.white)
                        Text("See what’s next, wherever the day takes you.")
                            .font(.subheadline)
                            .foregroundStyle(.white.opacity(0.68))
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 42)

                    VStack(spacing: 14) {
                        loginField(
                            title: "Email",
                            icon: "envelope",
                            text: $email,
                            field: .email,
                            contentType: .emailAddress
                        )
                        .textInputAutocapitalization(.never)
                        .keyboardType(.emailAddress)
                        .submitLabel(.next)
                        .onSubmit { focusedField = .password }

                        SecureField("Password", text: $password)
                            .textContentType(.password)
                            .focused($focusedField, equals: .password)
                            .submitLabel(.go)
                            .onSubmit { signIn() }
                            .padding(.horizontal, 16)
                            .frame(height: 54)
                            .foregroundStyle(.white)
                            .background(.white.opacity(0.1), in: RoundedRectangle(cornerRadius: IPCARadius.medium))
                            .overlay(
                                RoundedRectangle(cornerRadius: IPCARadius.medium)
                                    .stroke(.white.opacity(0.12))
                            )

                        if let error = session.errorMessage {
                            Label(error, systemImage: "exclamationmark.circle.fill")
                                .font(.footnote)
                                .foregroundStyle(Color(hex: 0xFFD2D2))
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .accessibilityLabel("Sign in error. \(error)")
                        }

                        Button(action: signIn) {
                            HStack {
                                if session.isRefreshing {
                                    ProgressView().tint(.white)
                                }
                                Text(session.isRefreshing ? "Signing In…" : "Sign In")
                                    .font(.headline)
                            }
                            .frame(maxWidth: .infinity)
                            .frame(height: 54)
                            .foregroundStyle(.white)
                            .background(IPCAColors.actionGradient, in: RoundedRectangle(cornerRadius: IPCARadius.medium))
                        }
                        .disabled(session.isRefreshing)
                        .accessibilityHint("Signs in to your IPCA scheduling account")
                    }
                    .padding(.top, 28)
                    Spacer(minLength: 44)
                }
                .padding(.horizontal, 26)
                .frame(minHeight: UIScreen.main.bounds.height - 40)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .preferredColorScheme(.dark)
    }

    private func loginField(
        title: String,
        icon: String,
        text: Binding<String>,
        field: Field,
        contentType: UITextContentType
    ) -> some View {
        HStack(spacing: 12) {
            Image(systemName: icon)
                .foregroundStyle(.white.opacity(0.55))
                .frame(width: 20)
                .accessibilityHidden(true)
            TextField(title, text: text)
                .textContentType(contentType)
                .focused($focusedField, equals: field)
                .foregroundStyle(.white)
        }
        .padding(.horizontal, 16)
        .frame(height: 54)
        .background(.white.opacity(0.1), in: RoundedRectangle(cornerRadius: IPCARadius.medium))
        .overlay(
            RoundedRectangle(cornerRadius: IPCARadius.medium)
                .stroke(.white.opacity(0.12))
        )
    }

    private func signIn() {
        focusedField = nil
        Task { await session.signIn(email: email, password: password) }
    }
}

struct MainTabView: View {
    @EnvironmentObject private var session: SchedulingSession
    @State private var todayPath: [SchedulerReservation] = []
    @State private var schedulePath: [SchedulerReservation] = []
    @State private var showFilters = false

    var body: some View {
        TabView(selection: $session.selectedTab) {
            NavigationStack(path: $todayPath) {
                TodayView()
                    .navigationDestination(for: SchedulerReservation.self) { reservation in
                        ReservationDetailView(reservation: reservation)
                    }
            }
            .tabItem { Label("Today", systemImage: "calendar.badge.clock") }
            .tag(0)

            NavigationStack(path: $schedulePath) {
                ScheduleView(showFilters: $showFilters)
                    .navigationDestination(for: SchedulerReservation.self) { reservation in
                        ReservationDetailView(reservation: reservation)
                    }
            }
            .tabItem { Label("Schedule", systemImage: "calendar") }
            .tag(1)

            NavigationStack {
                MoreView()
            }
            .tabItem { Label("More", systemImage: "ellipsis.circle") }
            .tag(2)
        }
        .tint(IPCAColors.navy)
        .toolbarBackground(.visible, for: .tabBar)
        .toolbarBackground(.ultraThinMaterial, for: .tabBar)
        .sheet(isPresented: $showFilters) {
            FilterSheet()
                .environmentObject(session)
        }
        .onAppear {
            guard let preview = session.previewScreen else { return }
            if preview == .details, todayPath.isEmpty {
                todayPath.append(SchedulerFixtures.featuredReservation)
            }
            if preview == .filters { showFilters = true }
        }
    }
}
