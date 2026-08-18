import SwiftUI

struct ProfileEditView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var profile: ProfileDetails?
    @State private var options = ProfileOptions()
    @State private var errorMessage: String?
    @State private var isLoading = true
    @State private var isSaving = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                if isLoading {
                    ProgressView()
                        .tint(.white)
                        .frame(maxWidth: .infinity)
                        .padding(.top, IPCATheme.Spacing.xl)
                } else if profile != nil {
                    section("Name") {
                        ProfileTextField(title: "First Name", text: binding(\.firstName, in: $profile))
                        ProfileTextField(title: "Last Name", text: binding(\.lastName, in: $profile))
                        ProfileTextField(title: "Login Email", text: .constant(profile?.email ?? ""), locked: true)
                    }
                    section("Address") {
                        ProfileTextField(title: "Street", text: binding(\.streetAddress, in: $profile))
                        ProfileTextField(title: "Number", text: binding(\.streetNumber, in: $profile))
                        ProfileTextField(title: "ZIP / Postal Code", text: binding(\.zipCode, in: $profile))
                        ProfileTextField(title: "City", text: binding(\.city, in: $profile))
                        ProfileTextField(title: "State / Region", text: binding(\.stateRegion, in: $profile))
                        ProfileOptionPicker(title: "Country", value: binding(\.countryCode, in: $profile), options: options.country)
                    }
                    section("Contact") {
                        ProfileTextField(title: "Cellphone", text: binding(\.cellphone, in: $profile), keyboard: .phonePad)
                        ProfileTextField(title: "Secondary Email", text: binding(\.secondaryEmail, in: $profile), keyboard: .emailAddress)
                    }
                    section("Identity") {
                        ProfileTextField(title: "Date of Birth", text: binding(\.dateOfBirth, in: $profile), placeholder: "YYYY-MM-DD")
                        ProfileTextField(title: "Place of Birth", text: binding(\.placeOfBirth, in: $profile))
                        ProfileTextField(title: "Nationality", text: binding(\.nationality, in: $profile))
                        ProfileTextField(title: "ID / Passport Number", text: binding(\.idPassportNumber, in: $profile))
                        ProfileOptionPicker(title: "Gender", value: binding(\.gender, in: $profile), options: options.gender)
                        ProfileOptionPicker(title: "Marital Status", value: binding(\.maritalStatus, in: $profile), options: options.maritalStatus)
                    }
                    section("Appearance") {
                        ProfileOptionPicker(title: "Hair Color", value: binding(\.hairColor, in: $profile), options: options.hairColor)
                        ProfileOptionPicker(title: "Eye Color", value: binding(\.eyeColor, in: $profile), options: options.eyeColor)
                        ProfileTextField(title: "Weight (kg)", text: binding(\.weightKg, in: $profile), keyboard: .decimalPad)
                        ProfileTextField(title: "Height (cm)", text: binding(\.heightCm, in: $profile), keyboard: .decimalPad)
                    }
                    if let errorMessage {
                        Text(errorMessage)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.destructive)
                    }
                    Button {
                        Task { await save() }
                    } label: {
                        Group {
                            if isSaving {
                                ProgressView().tint(.white)
                            } else {
                                Text("Save Profile")
                                    .font(.headline)
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                    }
                    .foregroundStyle(.white)
                    .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                    .disabled(isSaving)
                    .padding(.bottom, IPCATheme.Spacing.xl)
                }
            }
            .padding(.horizontal, IPCATheme.Spacing.screen)
            .padding(.top, IPCATheme.Spacing.md)
        }
        .background(IPCABackground())
        .navigationTitle("Edit Profile")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyPrimary, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .task { await load() }
    }

    private func binding(_ keyPath: WritableKeyPath<ProfileDetails, String>, in profile: Binding<ProfileDetails?>) -> Binding<String> {
        Binding(
            get: { profile.wrappedValue?[keyPath: keyPath] ?? "" },
            set: { profile.wrappedValue?[keyPath: keyPath] = $0 }
        )
    }

    private func section<Content: View>(_ title: String, @ViewBuilder content: () -> Content) -> some View {
        VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
            Text(title.uppercased())
                .font(.caption.weight(.semibold))
                .tracking(0.8)
                .foregroundStyle(IPCATheme.Colors.textTertiary)
            content()
        }
    }

    private func load() async {
        isLoading = true
        do {
            let envelope = try await session.loadProfile()
            profile = envelope.profile
            options = envelope.options
            errorMessage = nil
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't load your profile."
        }
        isLoading = false
    }

    private func save() async {
        guard let profile else { return }
        isSaving = true
        do {
            try await session.savePersonalProfile(profile)
            dismiss()
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't save your profile."
        }
        isSaving = false
    }
}

struct EmergencyContactsView: View {
    @EnvironmentObject private var session: AppSession
    @Environment(\.dismiss) private var dismiss
    @State private var contacts: [EmergencyContact] = []
    @State private var options: [ProfileOption] = []
    @State private var errorMessage: String?
    @State private var isLoading = true
    @State private var isSaving = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: IPCATheme.Spacing.md) {
                if isLoading {
                    ProgressView()
                        .tint(.white)
                        .frame(maxWidth: .infinity)
                        .padding(.top, IPCATheme.Spacing.xl)
                } else {
                    ForEach($contacts) { $contact in
                        VStack(alignment: .leading, spacing: IPCATheme.Spacing.sm) {
                            Text(contact.sortOrder == 1 ? "PRIMARY CONTACT" : "SECONDARY CONTACT")
                                .font(.caption.weight(.semibold))
                                .tracking(0.8)
                                .foregroundStyle(IPCATheme.Colors.textTertiary)
                            ProfileTextField(title: "Name", text: $contact.contactName)
                            ProfileOptionPicker(title: "Relationship", value: $contact.relationship, options: options)
                            ProfileTextField(title: "Phone", text: $contact.phone, keyboard: .phonePad)
                        }
                    }
                    if let errorMessage {
                        Text(errorMessage)
                            .font(.footnote)
                            .foregroundStyle(IPCATheme.Colors.destructive)
                    }
                    Button {
                        Task { await save() }
                    } label: {
                        Group {
                            if isSaving {
                                ProgressView().tint(.white)
                            } else {
                                Text("Save Contacts")
                                    .font(.headline)
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                    }
                    .foregroundStyle(.white)
                    .background(IPCATheme.interactiveGradient, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                    .disabled(isSaving)
                    .padding(.bottom, IPCATheme.Spacing.xl)
                }
            }
            .padding(.horizontal, IPCATheme.Spacing.screen)
            .padding(.top, IPCATheme.Spacing.md)
        }
        .background(IPCABackground())
        .navigationTitle("Emergency Contacts")
        .navigationBarTitleDisplayMode(.inline)
        .toolbarBackground(IPCATheme.Colors.navyPrimary, for: .navigationBar)
        .toolbarColorScheme(.dark, for: .navigationBar)
        .task { await load() }
    }

    private func load() async {
        isLoading = true
        do {
            let envelope = try await session.loadProfile()
            contacts = envelope.emergencyContacts
            options = envelope.options.relationship
            errorMessage = nil
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't load emergency contacts."
        }
        isLoading = false
    }

    private func save() async {
        isSaving = true
        do {
            try await session.saveEmergencyContacts(contacts)
            dismiss()
        } catch let error as APIClientError {
            errorMessage = error.errorDescription
        } catch {
            errorMessage = "Couldn't save emergency contacts."
        }
        isSaving = false
    }
}

struct ProfileTextField: View {
    var title: String
    @Binding var text: String
    var placeholder: String = ""
    var keyboard: UIKeyboardType = .default
    var locked: Bool = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.footnote.weight(.semibold))
                .foregroundStyle(IPCATheme.Colors.textSecondary)
            TextField(placeholder.isEmpty ? title : placeholder, text: $text)
                .keyboardType(keyboard)
                .textInputAutocapitalization(keyboard == .emailAddress ? .never : .words)
                .autocorrectionDisabled(keyboard == .emailAddress)
                .disabled(locked)
                .padding(IPCATheme.Spacing.sm)
                .foregroundStyle(locked ? IPCATheme.Colors.textTertiary : IPCATheme.Colors.textPrimary)
                .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                        .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                )
        }
    }
}

struct ProfileOptionPicker: View {
    var title: String
    @Binding var value: String
    var options: [ProfileOption]

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title)
                .font(.footnote.weight(.semibold))
                .foregroundStyle(IPCATheme.Colors.textSecondary)
            Menu {
                ForEach(options) { option in
                    Button(option.label) { value = option.value }
                }
            } label: {
                HStack {
                    Text(selectedLabel)
                        .foregroundStyle(value.isEmpty ? IPCATheme.Colors.textTertiary : IPCATheme.Colors.textPrimary)
                    Spacer()
                    Image(systemName: "chevron.up.chevron.down")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(IPCATheme.Colors.textTertiary)
                }
                .padding(IPCATheme.Spacing.sm)
                .background(IPCATheme.Colors.navySurface, in: RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous))
                .overlay(
                    RoundedRectangle(cornerRadius: IPCATheme.Radius.medium, style: .continuous)
                        .stroke(IPCATheme.Colors.separator, lineWidth: 1)
                )
            }
        }
    }

    private var selectedLabel: String {
        options.first(where: { $0.value == value })?.label ?? title
    }
}
