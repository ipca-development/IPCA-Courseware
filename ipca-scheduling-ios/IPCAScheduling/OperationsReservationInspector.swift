import SwiftUI

struct OperationsReservationInspector: View {
    @EnvironmentObject private var session: SchedulingSession

    let reservation: SchedulerReservation
    let expanded: Bool
    let close: () -> Void
    let toggleExpanded: () -> Void

    @State private var current: SchedulerReservation
    @State private var warnings: [SchedulerWarning]

    init(
        reservation: SchedulerReservation,
        expanded: Bool,
        close: @escaping () -> Void,
        toggleExpanded: @escaping () -> Void
    ) {
        self.reservation = reservation
        self.expanded = expanded
        self.close = close
        self.toggleExpanded = toggleExpanded
        _current = State(initialValue: reservation)
        _warnings = State(initialValue: [])
    }

    var body: some View {
        VStack(spacing: 0) {
            header
            Rectangle().fill(OperationsStyle.line).frame(height: 1)

            ScrollView {
                LazyVStack(alignment: .leading, spacing: 14) {
                    scheduleSummary
                    propertyGroup(primaryFacts)

                    if !warnings.isEmpty {
                        warningSection
                    }
                    if !current.notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                        notesSection
                    }
                    if expanded {
                        expandedSections
                    }
                }
                .padding(16)
            }

            actionArea
        }
        .background(Color.white)
        .overlay(alignment: .leading) {
            Rectangle().fill(OperationsStyle.line).frame(width: 1)
        }
        .task(id: reservation.id) {
            current = reservation
            warnings = session.detailWarnings[reservation.id] ?? []
            let response = await session.detail(for: reservation)
            guard !Task.isCancelled else { return }
            current = response.reservation
            warnings = response.validation?.warnings ?? warnings
        }
        .accessibilityElement(children: .contain)
        .accessibilityLabel(expanded ? "Expanded reservation details" : "Reservation inspector")
    }

    private var header: some View {
        VStack(alignment: .leading, spacing: 9) {
            HStack(alignment: .top, spacing: 10) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(current.reservationTypeLabel.uppercased())
                        .font(.system(size: 9, weight: .semibold))
                        .tracking(0.8)
                        .foregroundStyle(OperationsStyle.muted)
                    Text(current.title)
                        .font(.system(size: 17, weight: .bold))
                        .foregroundStyle(OperationsStyle.ink)
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 4)
                Button(action: close) {
                    Image(systemName: "xmark")
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(OperationsStyle.ink)
                        .frame(width: 28, height: 28)
                        .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .keyboardShortcut(.cancelAction)
                .accessibilityLabel("Close inspector")
            }

            HStack(spacing: 8) {
                Text(current.status.replacingOccurrences(of: "_", with: " ").capitalized)
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundStyle(statusColor)
                    .padding(.horizontal, 8)
                    .frame(height: 22)
                    .background(statusColor.opacity(0.09), in: RoundedRectangle(cornerRadius: 6))
                Text(current.aircraft.registration)
                    .font(.system(size: 12, weight: .bold))
                    .foregroundStyle(OperationsStyle.ink)
                if current.lock.locked {
                    Image(systemName: "lock.fill")
                        .font(.system(size: 9))
                        .foregroundStyle(OperationsStyle.muted)
                        .accessibilityLabel("Locked")
                }
            }
        }
        .padding(16)
    }

    private var scheduleSummary: some View {
        HStack(spacing: 5) {
            Text(compactDate)
            Text("·").foregroundStyle(OperationsStyle.muted.opacity(0.65))
            Text(session.clock.timeRange(start: current.startLocal, end: current.endLocal))
            Text("·").foregroundStyle(OperationsStyle.muted.opacity(0.65))
            Text(durationText)
        }
        .font(.system(size: 10, weight: .medium))
        .foregroundStyle(OperationsStyle.muted)
        .lineLimit(1)
        .minimumScaleFactor(0.82)
        .accessibilityElement(children: .combine)
    }

    private var primaryFacts: [InspectorFact] {
        var facts: [InspectorFact] = []
        if let aircraftType = current.aircraft.aircraftType ?? current.aircraft.displayName,
           !aircraftType.isEmpty {
            facts.append(InspectorFact(label: "Aircraft Type", value: aircraftType))
        }
        facts.append(
            InspectorFact(
                label: "Student",
                value: studentNames.isEmpty ? "—" : studentNames.joined(separator: ", ")
            )
        )
        facts.append(
            InspectorFact(
                label: "Instructor",
                value: instructorNames.isEmpty ? "—" : instructorNames.joined(separator: ", ")
            )
        )
        facts.append(
            InspectorFact(
                label: "Training",
                value: current.mission?.code
                    ?? current.reservationTypeLabel
            )
        )
        facts.append(
            InspectorFact(
                label: "Route",
                value: current.route.airportChain.isEmpty
                    ? "—"
                    : current.route.airportChain.joined(separator: " → ")
            )
        )
        if let cohort = current.cohort?.name, !cohort.isEmpty {
            facts.append(InspectorFact(label: "Cohort", value: cohort))
        }
        return facts
    }

    private func propertyGroup(_ facts: [InspectorFact]) -> some View {
        VStack(spacing: 0) {
            ForEach(facts) { fact in
                HStack(alignment: .firstTextBaseline, spacing: 12) {
                    Text(fact.label)
                        .font(.system(size: 10, weight: .medium))
                        .foregroundStyle(OperationsStyle.muted)
                        .frame(width: expanded ? 96 : 78, alignment: .leading)
                    Text(fact.value)
                        .font(.system(size: 11, weight: .semibold))
                        .foregroundStyle(OperationsStyle.ink)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 9)
            }
        }
        .background(Color(hex: 0xF7F8FA))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(OperationsStyle.line)
        )
    }

    private var warningSection: some View {
        inspectorSection("Attention") {
            VStack(alignment: .leading, spacing: 8) {
                ForEach(warnings) { warning in
                    HStack(alignment: .top, spacing: 8) {
                        Image(systemName: "exclamationmark.triangle.fill")
                            .font(.system(size: 10))
                            .foregroundStyle(IPCAColors.warning)
                            .frame(width: 14)
                        Text(warning.message)
                            .font(.system(size: 10, weight: .medium))
                            .foregroundStyle(Color(hex: 0x8B5412))
                            .fixedSize(horizontal: false, vertical: true)
                    }
                }
            }
            .padding(12)
            .background(IPCAColors.warningSurface)
            .clipShape(RoundedRectangle(cornerRadius: 9))
            .overlay(
                RoundedRectangle(cornerRadius: 9)
                    .stroke(IPCAColors.warning.opacity(0.28))
            )
        }
    }

    private var notesSection: some View {
        inspectorSection("Notes") {
            Text(current.notes)
                .font(.system(size: 10))
                .foregroundStyle(OperationsStyle.ink)
                .fixedSize(horizontal: false, vertical: true)
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(Color(hex: 0xF7F8FA))
                .clipShape(RoundedRectangle(cornerRadius: 9))
                .overlay(
                    RoundedRectangle(cornerRadius: 9)
                        .stroke(OperationsStyle.line)
                )
        }
    }

    @ViewBuilder
    private var expandedSections: some View {
        if !current.crew.isEmpty {
            inspectorSection("Crew Details") {
                propertyGroup(
                    current.crew.map { member in
                        let metadata = [
                            member.role.replacingOccurrences(of: "_", with: " ").capitalized,
                            member.pilotFunction,
                            member.isPIC == true ? "PIC" : nil
                        ]
                        .compactMap { $0 }
                        .joined(separator: " · ")
                        return InspectorFact(
                            label: metadata,
                            value: member.personName
                        )
                    }
                )
            }
        }

        if !current.route.legs.isEmpty {
            inspectorSection("Route Details") {
                propertyGroup(
                    current.route.legs.map { leg in
                        InspectorFact(
                            label: "Leg \(leg.sequenceNumber)",
                            value: "\(leg.originAirport) → \(leg.destinationAirport)"
                        )
                    }
                )
            }
        }

        if current.lock.locked || evidenceSummary != nil {
            inspectorSection("Operational State") {
                propertyGroup(operationalFacts)
            }
        }

        inspectorSection("Canonical Metadata") {
            propertyGroup([
                InspectorFact(label: "Type", value: current.reservationTypeLabel),
                InspectorFact(label: "Updated", value: current.updatedAt),
                InspectorFact(label: "Reservation ID", value: current.reservationUUID)
            ])
        }
    }

    private func inspectorSection<Content: View>(
        _ title: String,
        @ViewBuilder content: () -> Content
    ) -> some View {
        VStack(alignment: .leading, spacing: 7) {
            Text(title.uppercased())
                .font(.system(size: 9, weight: .semibold))
                .tracking(0.75)
                .foregroundStyle(OperationsStyle.muted)
            content()
        }
    }

    private var actionArea: some View {
        VStack(spacing: 0) {
            Rectangle().fill(OperationsStyle.line).frame(height: 1)
            Button(action: toggleExpanded) {
                Label(
                    expanded ? "Compact View" : "Full Details",
                    systemImage: expanded ? "arrow.right" : "arrow.left"
                )
                .font(.system(size: 11, weight: .semibold))
                .frame(maxWidth: .infinity)
                .frame(height: 38)
            }
            .buttonStyle(.plain)
            .foregroundStyle(.white)
            .background(OperationsStyle.ink, in: RoundedRectangle(cornerRadius: 8))
            .padding(12)
        }
        .background(Color.white)
    }

    private var studentNames: [String] {
        crewNames(role: "student")
    }

    private var instructorNames: [String] {
        crewNames(role: "instructor")
    }

    private func crewNames(role: String) -> [String] {
        current.crew.compactMap { member in
            member.role.lowercased().contains(role) ? member.personName : nil
        }
    }

    private var compactDate: String {
        guard let date = session.clock.date(fromLocal: current.startLocal) else {
            return current.localDateKey
        }
        return date.formatted(
            .dateTime
                .weekday(.abbreviated)
                .month(.abbreviated)
                .day()
                .locale(Locale(identifier: "en_US"))
        )
    }

    private var durationText: String {
        guard let start = session.clock.date(fromLocal: current.startLocal),
              let end = session.clock.date(fromLocal: current.endLocal) else {
            return "Scheduled"
        }
        let minutes = max(0, Int(end.timeIntervalSince(start) / 60))
        let hours = minutes / 60
        let remainder = minutes % 60
        if hours > 0 {
            return remainder == 0 ? "\(hours) hr" : "\(hours) hr \(remainder) min"
        }
        return "\(minutes) min"
    }

    private var statusColor: Color {
        switch current.status.lowercased() {
        case "claimed", "active": IPCAColors.blue
        case "completed": IPCAColors.success
        case "cancelled": IPCAColors.destructive
        default: OperationsStyle.ink
        }
    }

    private var evidenceSummary: String? {
        guard let evidence = current.evidence else { return nil }
        let values = [
            evidence.hasDispatch == true ? "Dispatch" : nil,
            evidence.hasRecording == true ? "Recording" : nil,
            evidence.hasClosure == true ? "Closure" : nil,
            evidence.hasDebrief == true ? "Debrief" : nil
        ].compactMap { $0 }
        return values.isEmpty ? nil : values.joined(separator: ", ")
    }

    private var operationalFacts: [InspectorFact] {
        var facts: [InspectorFact] = []
        if current.lock.locked {
            facts.append(
                InspectorFact(
                    label: "Lock",
                    value: current.lock.reason?
                        .replacingOccurrences(of: "_", with: " ")
                        .capitalized ?? "Locked"
                )
            )
        }
        if let evidenceSummary {
            facts.append(InspectorFact(label: "Evidence", value: evidenceSummary))
        }
        return facts
    }
}

private struct InspectorFact: Identifiable {
    let label: String
    let value: String

    var id: String { "\(label)-\(value)" }
}
