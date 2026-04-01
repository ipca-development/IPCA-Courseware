# CURRENT SSOT POINTER

This file identifies the currently authoritative version of the IPCA Courseware Single Source of Truth.

The SSOT defines the canonical architecture, workflow logic, and platform direction.

Developers, contributors, and AI assistants must always follow the CURRENT SSOT.

---

Current Version

IPCA_Courseware_SSOT_v1.6.md

Location

docs/ssot/IPCA_Courseware_SSOT_v1.6.md

---

Important Rule

Older SSOT versions must NEVER be overwritten or deleted.

They are historical records of architectural decisions.

Version history should look like:

docs/ssot/
  IPCA_Courseware_SSOT_v1.2.md
  IPCA_Courseware_SSOT_v1.3.md
  IPCA_Courseware_SSOT_v1.4.md
  IPCA_Courseware_SSOT_v1.5.md
  IPCA_Courseware_SSOT_v1.6.md
  CURRENT_SSOT.md

---

Development Rule

When architectural changes are introduced:

1. Update SSOT.
2. Create a new version file.
3. Update CURRENT_SSOT.md.
4. Commit to repository.

No code should diverge from SSOT rules.

---

Purpose

This file exists so both humans and AI systems can quickly determine the authoritative architecture reference.

