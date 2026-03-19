# First Account Setup Journey

Status: internal UX and onboarding copy note  
Date: 2026-03-19

See also:

- `F24_fresh_install_start_screens_and_first_run_onboarding.md`
- `ACTIVATION_FUNNEL.md`
- `AGENCY_FIRST_STRATEGY.md`

## Purpose

This note defines the setup user journey from fresh install to the first completed managed account.

It is intentionally narrow.

It does **not** cover:

- first worker connection
- first authenticated machine request
- governed write setup
- approvals
- Target Sets
- workflow kits

The goal is to make the first account step feel obvious, fast, and controlled.

## Journey Goal

The operator should be able to move from:

- "I installed the plugin"

to:

- "I created my first managed account and I know what to do next"

without having to understand the full system first.

## Primary User

- technical agency operator
- developer or technical lead
- support / delivery person who can create credentials and connect a worker

## Product Rule

Every screen in this journey must answer one question clearly:

- what should I do next?

The interface should not front-load:

- raw scope complexity
- governance concepts
- advanced diagnostics
- production operations detail

## Journey Overview

1. Plugin first open
2. Welcome / orientation
3. Choose account template
4. Confirm account setup
5. Account created / token reveal / next step

## Step 1: Plugin First Open

### Purpose

Set context fast and avoid the feeling of entering a monitoring console.

### User question

- What is this plugin for?
- Am I supposed to start in Status or Accounts?

### Screen goal

Make the first screen feel like a start surface, not an ops surface.

### Suggested headline

- `Connect your first machine account`

### Suggested body copy

- `Agents gives your Craft site a controlled way to work with external workers, automations, and AI runtimes.`
- `Start by creating one managed account. You can connect it to a worker once the account exists.`

### Suggested supporting bullets

- `Scoped machine accounts managed inside Craft`
- `A clear control surface for access, review, and status`
- `A safe starting point for external workers and automations`

### Primary CTA

- `Create first account`

### Secondary CTAs

- `How this works`
- `Skip for now`

### Notes

Do not show here:

- status proof grids
- webhook tools
- scope lists
- approvals language
- write-mode caveats

## Step 2: Welcome / Orientation

This can be the same screen as Step 1 if the layout supports it cleanly.

### Purpose

Give the operator enough confidence to continue without making them study the product.

### User question

- What am I creating?
- Is this a user account, API key, or something else?

### Suggested explanatory label

- `Managed account`

### Suggested body copy

- `A managed account is the machine identity your worker will use to connect to this site.`
- `Create one account first. You can tighten scopes and add more advanced controls later.`

### CTA hierarchy

Primary:

- `Create first account`

Secondary:

- `See example workflows`

Tertiary:

- `Read docs`

### Notes

The main job here is reducing category confusion.
The operator should understand that this is a machine actor inside a governed system.

## Step 3: Choose Account Template

### Purpose

Move the operator from concept to intent.

### User question

- What kind of account should I create first?

### Screen goal

Template-first setup.
Raw scopes stay secondary.

### Suggested heading

- `Choose what this account should do`

### Suggested body copy

- `Start with a template. You can review and refine access before creating the account.`

### Suggested default template order

1. `Read-only worker`
2. `Content review worker`
3. `Draft updates with approval`
4. `Custom account`

### Suggested card copy

#### Read-only worker

- `Best first setup`
- `Connect a worker that can inspect the site without changing content.`

CTA:

- `Use this template`

#### Content review worker

- `For quality checks and reporting`
- `Read entries and related site data for review or analysis workflows.`

CTA:

- `Use this template`

#### Draft updates with approval

- `For governed write workflows`
- `Prepare draft changes that still go through approval before anything is applied.`

CTA:

- `Use this template`

#### Custom account

- `Advanced`
- `Choose scopes manually if the default templates are not a fit.`

CTA:

- `Configure manually`

### Notes

If possible, the first template should be visually recommended.
For a fresh install, `Read-only worker` should usually be the calmest default.

## Step 4: Confirm Account Setup

### Purpose

Turn the chosen template into a concrete account without exposing unnecessary complexity.

### User question

- What do I need to fill in?
- What matters right now?

### Screen goal

Keep the form short and confidence-building.

### Suggested heading

- `Set up your account`

### Suggested intro copy

- `Give this account a clear name so you can recognize it later. The suggested access is already filled in from the template.`

### Suggested visible fields

- `Account name`
- `Description` or `Used for`
- `Suggested access`

### Suggested field help text

#### Account name

- `Use a name that describes the worker or workflow, for example “Content QA Worker” or “Weekly Reporting”.`

#### Description / Used for

- `Optional, but useful when several people manage the site.`

#### Suggested access

- `This template starts with a safe access set for the chosen job.`

### Advanced section label

- `Review advanced access`

### Advanced section helper copy

- `Open this only if you need to adjust scopes before creating the account.`

### Primary CTA

- `Create account`

### Secondary CTA

- `Back`

### Notes

Do not put raw scope checklists in the default visible form if a template was chosen.
Default view should show the intent, not the entire permission model.

## Step 5: Account Created

### Purpose

Make account creation feel like a completion moment and immediately hand off to the next action.

### User question

- Did it work?
- What should I do next?
- Where is the token?

### Screen goal

Celebrate completion quietly and immediately guide connection.

### Suggested success headline

- `Your first account is ready`

### Suggested body copy

- `The account was created successfully.`
- `Copy the token now or download the connection details. You can connect a worker as the next step.`

### Suggested status line

- `Account created. No worker connection has been seen yet.`

### Primary CTA

- `Copy token`

### Secondary CTAs

- `Download .env`
- `Open guide to create your first worker`
- `Back to Accounts`

### Suggested completion hint

- `The token is shown once. Save it now if you want to connect the worker immediately.`

### Notes

This screen should mark the end of the first-account journey.
It should not yet force:

- Status review
- webhook setup
- approval configuration
- Target Sets

Those belong to later stages or specific workflows.

## Step 6: First Account Completed State In Accounts

### Purpose

When the operator lands back in `Accounts`, the screen should reinforce progress instead of dropping them into a cold list.

### Suggested inline banner

- `Your first account is ready to connect.`

### Suggested supporting copy

- `Next, connect a worker or validate your first authenticated request.`

### Primary CTA

- `Open connection details`

### Secondary CTA

- `Guide to create your first worker`

### Optional tertiary CTA

- `Create another account`

### Notes

This state should make the product feel like it is moving forward.
The operator should not wonder whether account creation was the end of the setup story.

## Copy Tone Rules

Use:

- short declarative sentences
- concrete nouns
- confident verbs
- mild warmth

Avoid:

- enterprise setup language
- defensive warning copy on healthy fresh installs
- capability dumping
- long explanations of scopes and governance before they matter

## CTA Rules

### Step 1 / 2

Primary:

- `Create first account`

### Step 3

Primary per template card:

- `Use this template`

### Step 4

Primary:

- `Create account`

### Step 5

Primary:

- `Copy token`

### Step 6

Primary:

- `Open connection details`

Only one primary CTA should dominate each step.

## Acceptance Check

This journey is working if a new operator can:

1. understand what a managed account is
2. choose a sensible first template
3. create the account without studying scopes first
4. recognize the account-creation moment as a success
5. know the next action immediately after account creation

If the operator still needs to ask:

- `Where do I start?`
- `What is a managed account?`
- `Do I need to configure all this now?`

then the journey is still too dense.
