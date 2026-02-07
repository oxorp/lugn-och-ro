# Legal Constraints

> GDPR, the Supreme Court ruling, and what PlatsIndex can and cannot do.

## Overview

The platform operates under strict legal constraints, particularly around criminal data and individual privacy. Understanding these constraints is critical for anyone extending the system.

## Swedish Supreme Court Ruling (February 2025)

In February 2025, the Swedish Supreme Court ruled that bulk collection of court judgments for searchable databases violates **GDPR Article 10** (criminal conviction data processing). This shut down services like Lexbase, Verifiera, and Acta Publica.

**Impact on PlatsIndex**: None, as long as we maintain the aggregate-only approach.

## Design Rules

1. **NO** searchable databases linking individual names to criminal convictions
2. **NO** individual-level personal data as model features
3. **ALL** data inputs must be aggregate-level statistics
4. **NO** individual-level personal data is stored or processed

## The `foreign_background_pct` Decision

The `foreign_background_pct` indicator exists in the database but has:
- `direction = neutral`
- `weight = 0.00`
- Excluded from scoring entirely

This is intentional. While it's legitimate SCB aggregate data, using ethnic composition as a scoring factor would:
- Risk discrimination claims
- Draw regulatory scrutiny from IMY (Swedish privacy authority)
- Create PR and ethical concerns

The value is stored for disaggregation model validation only — it is NOT used in any disaggregation formula.

## Client-Facing Output Rules

User-facing output must express measurable socioeconomic indicators only:

:white_check_mark: **Allowed**: "Elevated Risk: declining school performance, rising crime trend, high estimated debt rate"

:x: **Not Allowed**: Individual names, ethnic counts, or religious building proximity as named features

## Data Source Policy

- **No restriction on source type**: Government, commercial, and open-source data are all valid
- **Restriction is on individual-level data**: Only aggregate statistics
- **GDPR Article 10**: Specifically constrains criminal conviction data linked to individuals

## Related

- [Data Sources Overview](/data-sources/)
- [Methodology Overview](/methodology/)
