# IPCA Courseware Modules

This document defines the modular architecture of the IPCA Courseware platform.

Modules allow the platform to support:

• multiple organizations
• different subscription tiers
• scalable SaaS deployment
• selective feature activation

Each module can be enabled or disabled per organization.

---

# Core Modules

These modules form the core platform.

## Core System
Status: Live

Responsibilities:

• authentication
• roles
• navigation
• system policies
• AI integration
• storage integration
• email infrastructure
• system logging

Key components:

users  
system_policy_definitions  
system_policy_values  
training_progression_events  

---

# Training Modules

## Theory Training
Status: Live (primary focus)

Responsibilities:

• theory curriculum
• slide player
• summaries
• progress tests
• remediation workflow
• instructor intervention

Core structure:

Program  
Course  
Lesson  
Slide  
Summary  
Progress Test  

Key tables:

programs  
courses  
lessons  
slides  
lesson_summaries  
progress_tests_v2  
student_required_actions  
lesson_activity  

---

## Flight Training
Status: Planned

Future training hierarchy:

Program  
Stage  
Phase  
Scenario  
Exercise  

Capabilities:

• exercise tracking
• instructor evaluation
• competency mapping
• simulator sessions
• cockpit recordings
• AI debriefing

Future tables:

training_programs  
training_stages  
training_phases  
training_scenarios  
training_exercises  
exercise_evaluations  
training_sessions  

---

# Scheduling Module

Status: Planned

The scheduler will be an optimization engine.

Resources managed:

• students
• instructors
• aircraft
• simulators
• briefing rooms
• classrooms
• Vision Pro sessions
• weather constraints
• maintenance constraints

Goals:

• maximize training throughput
• minimize idle resources
• recover quickly from disruptions

---

# Safety Management Module

Status: Planned

Capabilities:

• safety bulletins
• student safety acknowledgement tracking
• safety occurrence reports
• investigation workflow
• safety trend analysis

Student permissions:

• read safety bulletins
• acknowledge notices
• submit safety reports
• view own reports

Instructor/Admin permissions:

• investigate reports
• assign severity
• close reports
• trend analysis

---

# Compliance Monitoring Module

Status: Planned

Capabilities:

• regulatory compliance tracking
• certification audit support
• training compliance monitoring
• regulatory mapping

Supports:

• FAA
• EASA
• ICAO
• internal SOP compliance

---

# Operations Module

Status: Planned

Capabilities:

• student lifecycle management
• entry screening
• contracts
• student ledger
• training tool requirements
• access key distribution
• document downloads

---

# Maintenance Module

Status: Planned

Capabilities:

• aircraft maintenance tracking
• inspection scheduling
• maintenance alerts
• aircraft availability constraints

---

# AI Platform Module

Status: Partial

AI capabilities include:

• theory instruction
• progress test generation
• spoken test evaluation
• AI debriefing
• training progress analysis
• safety analysis
• scheduling optimization

AI systems must remain modular and policy-governed.

---

# SaaS Architecture Goal

The platform must support multi-organization deployment.

Future architecture will support:

• organization-specific modules
• branding customization
• policy overrides
• AI usage tracking
• scalable onboarding

Future supporting tables:

organizations  
organization_settings  
organization_modules  
organization_branding  
ai_usage_logs  

---

# Strategic Platform Goal

IPCA Courseware is intended to become a global aviation training operating system for training organizations.

The platform vision is comparable to:

ForeFlight / Garmin Pilot

—but designed for training organizations rather than individual pilots.