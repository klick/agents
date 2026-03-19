# F24 Status Page Mode Split and Density Reduction

Date: 2026-03-19  
Status: Proposed design brief  
Branch: `feature/f24-status-mode-split`

## Summary

The current `Status` page is trying to serve three jobs at once:

- first-run setup
- day-to-day operational confidence
- incident investigation

That creates too much information density for the default state, especially when the environment is healthy and unused.

This brief keeps the existing design language and the large verdict line at the top, but simplifies everything that comes after it.

Core rule:

- the operator should understand what matters and what to do next without reading a readiness dossier

## Design Direction

Keep:

- the top verdict line and verdict treatment
- the current muted card/header language
- the existing CP-native table/card rhythm
- the idea that `Status` can expand into operational detail

Change:

- what is shown by default
- how much detail is visible before expansion
- the order of information
- the separation between setup, operations, and advanced tools

Do not change in this slice:

- the big verdict line at the top
- the overall page shell/layout language already established across Agents
- the underlying readiness model

## Product Model

Split the page conceptually into three states:

1. `Setup`
2. `Operational`
3. `Incident`

The same page can support all three, but the default content density must vary by state.

### `Setup`

Used when:

- runtime is healthy enough to begin
- first meaningful machine usage has not happened yet

Tone:

- optimistic
- guided
- low-density

### `Operational`

Used when:

- the environment has real activity
- there is no meaningful fault condition

Tone:

- calm
- confidence-oriented
- more detailed than setup, but still restrained

### `Incident`

Used when:

- something is actually degraded or blocked

Tone:

- direct
- action-oriented
- detail can increase because urgency justifies it

## Target Content Hierarchy

### Level 1: Verdict and next action

This stays at the top and remains dominant.

Show:

- existing large verdict line
- one short supporting sentence
- one primary next action based on the current state

Examples:

- `Create managed account`
- `Open account connection details`
- `Review blocked runtime setting`

### Level 2: Essential summary only

Replace the current dense multi-panel proof area with a much lighter default summary.

For `Setup`, show only 3 short answer blocks:

- `Runtime`
  - enabled / blocked
- `Accounts`
  - first account created / not created
- `Connection`
  - first successful machine use seen / not seen

Optional fourth block:

- `Webhooks`
  - optional / configured

Each block should answer:

- what is the state?
- is action needed?

Not:

- expose all sub-signals inline

### Level 3: Guided action panel

Below the essential summary, show a short state-appropriate guidance panel.

For `Setup`:

- `You are ready to connect your first worker.`
- compact checklist:
  - runtime enabled
  - first account created
  - first successful authenticated request

For `Operational`:

- `The environment is operating normally.`
- short note about where to inspect details if needed

For `Incident`:

- one short explanation of the blocking/degraded reason
- one or two direct remediation actions

### Level 4: Expandable system detail

Move the current proof-grid style detail behind disclosure.

Label examples:

- `View system details`
- `Open readiness details`

Expanded content can include:

- hard gates
- traffic / access
- delivery / webhooks
- integration / capacity
- accounts / policy
- confidence / observability

The content can stay structurally similar, but it should not dominate the first viewport.

### Level 5: Advanced utilities

Treat these as tools, not core content.

Group separately under a lower section such as:

- `Advanced tools`
- `Operational tools`

Include here:

- diagnostics bundle
- webhook probe
- webhook test sink

These should remain available, but should not sit directly in the main setup path.

## Remove / Keep / Defer

### Remove from default first viewport

- full proof card matrix
- action mapping table
- diagnostics bundle block as a primary surface
- webhook probe card
- webhook test sink card
- dense sub-signal copy under every category

These can remain in the page, but not in the default first-run view.

### Keep in the design system

- top verdict line
- muted header strip treatment
- current page shell and content width
- Craft-native buttons and disclosure patterns
- the ability to expose deeper operational detail when needed

### Defer for later

- major readiness model rewrites
- new telemetry taxonomy
- incident-specific secondary views or tabs
- heavy new visual treatments unrelated to the current Agents language

## Three Mockup States

### 1. Setup state: `Ready to Connect`

Purpose:

- help the operator start

Above the fold:

- big verdict line unchanged
- short explanatory sentence
- primary CTA: `Create managed account`
- 3–4 minimal summary blocks
- short guided checklist

Below the fold:

- optional `View system details`
- advanced tools collapsed or clearly separated

### 2. Operational state: `Ready`

Purpose:

- reassure and orient

Above the fold:

- big verdict line unchanged
- shorter supporting line
- small summary strip
- optional secondary CTA: `View system details`

Below the fold:

- expanded operational detail available
- advanced tools remain separate

### 3. Incident state: `Degraded` or `Blocked`

Purpose:

- explain the problem and route action

Above the fold:

- big verdict line unchanged
- one clear reason sentence
- one primary remediation CTA
- 2–3 issue cards only for affected domains

Below the fold:

- action mapping
- full detail and utilities

Only in incident mode should higher density be accepted by default.

## Visual and Interaction Rules

- Keep the page aligned with the current Agents design system.
- Do not introduce a radically new layout grammar.
- Use disclosure and state-based density reduction, not a wholly different visual language.
- Use one dominant CTA at the top.
- Keep summary language short and verdict-oriented.
- Advanced tools should feel available, not loud.

## Dependency Graph

```mermaid
graph TD
  T1[T1 Define Status state modes and content hierarchy]
  T2[T2 Create setup/operational/incident information model]
  T3[T3 Map current Status sections into remove/keep/defer buckets]
  T4[T4 Define collapsed vs expanded detail behavior]
  T5[T5 Define advanced-tools separation]
  T6[T6 Prepare mockup-ready screen briefs]

  T1 --> T2
  T1 --> T3
  T2 --> T4
  T3 --> T4
  T4 --> T5
  T2 --> T6
  T3 --> T6
  T5 --> T6
```

## Mockup Acceptance Criteria

A Status mockup is on the right path if:

- the large verdict line remains intact
- the first viewport feels materially calmer than today
- a healthy setup state no longer reads like a monitoring console
- the operator can identify the next action in under a few seconds
- advanced tools are still present but clearly secondary
- the screen remains visually consistent with current Agents cards, headers, and shell structure

## Assumptions

- this is a design-direction brief, not an implementation plan for the full readiness model
- current runtime and readiness logic largely stays in place for now
- the primary value is reducing perceived density and improving action clarity
- implementation should come later, after mockups are reviewed
