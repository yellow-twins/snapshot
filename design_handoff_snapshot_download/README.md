# Handoff: Snapshot — Backend Download Module

## Overview
This is the UI for **Pillar A** of the `yellow-twins/snapshot` TYPO3 extension: the hardened
backend module that lets a developer with backend access **pull a copy of the current
environment's database and/or fileadmin to their local machine** over HTTP.

The module is a single content pane (rendered inside the normal TYPO3 v14 backend chrome —
module menu and login are provided by TYPO3, **not** part of this design). It handles the
whole download flow: pick sources → prepare (server-side export/scrub/archive) → download
via single-use, expiring links.

## About the Design Files
The files in this bundle are **design references created in HTML** — a prototype showing the
intended look and behavior. They are **not production code to copy directly**.

The task is to **recreate this design inside the extension's real environment**: TYPO3 v14
backend module using **Fluid templates** (`Resources/Private/Templates/Backend/`), a PHP
backend controller (`Classes/Backend/Controller/`), and TYPO3's backend asset/CSS conventions.
The interactive flow (prepare progress, countdown, single-use consumption) should be wired to
the real backend endpoints (`DatabaseDumpService`, `FileadminSyncService`, `ScrubbingService`,
the download-token security layer). Where the prototype fakes timing/values, replace with real
server state. Use TYPO3's own backend CSS variables and components (`typo3/cms-backend`) rather
than the inline styles here where an equivalent exists.

## Fidelity
**High-fidelity (hifi).** Final colors, typography, spacing, layout, and interaction states are
all intended as shown. Recreate pixel-close, but prefer native TYPO3 backend components
(buttons, callouts, cards, docheader) where they match — the inline styling here mirrors the
TYPO3 v14 look and is a fallback, not a mandate to avoid the design system.

---

## Screens / Views
Single view, one content pane, three phases driven by client state (`phase = idle | preparing | ready`).

### Docheader (always visible, sticky top)
- Height 53px, white bg (`#fff`), 1px bottom border `#d7d9dc`, horizontal padding 20px, flex space-between.
- **Left:** 30×30 rounded (7px) icon tile, bg `#fff6ec`, database (cylinder) icon in `#e6790a`.
  Next to it: title **"Snapshot"** (700, 16px, letter-spacing −.01em) with subtitle
  **"Environment sync"** (11.5px, `#6c7178`).
- **Right:** two 34×34 icon buttons (border `#cfd2d6`, radius 6px, white; hover bg `#f0f1f3`):
  reload icon, help (?) icon.

### Content container
- `max-width: 900px`, centered, padding `28px 20px 56px`. Page bg `#f4f5f7`.
- **Page heading:** "Download a snapshot" (22px, 700, letter-spacing −.02em) + muted intro
  paragraph (`#6c7178`, max-width 640px): "Pull a fresh copy of this environment's database and
  fileadmin to your local machine. Choose one, the other, or both."

### Protections strip (always visible)
3-column grid, 1px gaps on a `#e0e2e5` background giving hairline dividers, outer radius 9px.
Each cell (bg `#fbfbfc`, padding `12px 14px`, green `#1e7a34` icon + label + sub-label):
- **GDPR anonymized** — "personal data scrubbed" (shield-check icon)
- **Secrets scrubbed** — ".env, keys, hashes removed" (lock icon)
- **Single-use link** — "expires after download" (clock icon)

### Phase 1 — Idle (selection)
- Section label "SELECT WHAT TO INCLUDE" (12px, 700, uppercase, letter-spacing .06em, `#8a8f95`).
- **Two selectable cards**, 2-col grid, 14px gap. Each: padding 18px, white bg, radius 10px,
  2px border. **Unselected:** border `#e0e2e5`, bg `#fff`. **Selected:** border = accent, bg `#fffaf3`.
  Click toggles selection (each independent; either/both allowed). Transitions 150ms.
  - Top row: 38×38 rounded (9px) icon tile + a 24px round check badge (bg = accent, white check,
    opacity 1 when selected / 0 when not).
    - **Database** card: icon tile bg `#eef4fb`, icon `#0b71b8` (cylinder). Title "Database" (700, 16px),
      "≈ 248 MB compressed export" (`#6c7178`). Two pill chips (11.5px, `#565b61`, bg `#f0f1f3`, radius 20px):
      "database:export", "cache · logs · sessions excluded".
    - **Fileadmin** card: icon tile bg `#fff6ec`, icon `#e6790a` (folder). Title "Fileadmin",
      "≈ 3.2 GB archive". Chips: "rsync", "_processed_ · _temp_ excluded".
- **Action bar:** white card, border `#e0e2e5`, radius 10px, padding `16px 18px`, flex space-between.
  - Left: summary (600) + size sub-line (12.5px `#6c7178`). Values depend on selection:
    both → "Database + Fileadmin" / "≈ 3.4 GB total"; DB only → "Database only" / "≈ 248 MB";
    fileadmin only → "Fileadmin only" / "≈ 3.2 GB"; none → "Nothing selected" / "Pick at least one source".
  - Right: **Prepare download** primary button (accent bg, white, radius 7px, padding `10px 20px`,
    600, download icon; hover `filter: brightness(.93)`). Disabled look when nothing selected
    (opacity .45, cursor not-allowed).

### Phase 2 — Preparing
White card, border `#e0e2e5`, radius 12px, padding 24px.
- Spinner (accent, 850ms linear rotation) + "Preparing snapshot…" (700, 16px). Sub-line
  (13px `#6c7178`, indented): "This runs server-side. Keep this tab open."
- **Progress bar:** 8px track `#eceef0`, radius 20px; fill = accent, width = progress %, 500ms ease.
- **Step list**, rows 9px vertical padding. Steps are built from selection:
  1. "Exporting database (database:export)" — only if DB selected
  2. "Anonymizing data & scrubbing secrets" — always
  3. "Archiving fileadmin (rsync)" — only if fileadmin selected
  4. "Signing single-use download token" — always
  - Each row status marker (22px): **done** = green check on `#eaf6ec` circle; **active** = spinning
    ring (`#7a7f85`); **pending** = 8px grey dot `#cfd2d6`. Active label 600 `#1a1c1e`, done label
    `#1a1c1e`, pending label `#a5a9ad`.

### Phase 3 — Ready
- **Success callout:** bg `#eaf6ec`, border `#c5e6cd`, radius 10px, padding `14px 18px`. 28px green
  (`#1e7a34`) round check + "Snapshot ready" (700, 15px, `#155625`) + "Anonymized and scrubbed.
  Your download is armed below." (12.5px `#3d6b48`).
- **Expiry bar:** bg `#fdf6e6`, border `#ecd9a8`, radius 10px, padding `11px 16px`. Clock icon +
  text (13px `#6b5410`): "Each link is single-use and expires in **MM:SS**. It stops working after
  one download." The countdown value is bold, tabular-nums, colored `#b8860b` (amber) and switches
  to `#b23c2e` (red) when under 60s. When the countdown hits 0 → expired state: "**Links expired.**
  Prepare a new snapshot to download again."
- **Artifact rows** (one per selected source), white card, border `#e0e2e5`, radius 11px, padding
  `16px 18px`, flex, 14px gap:
  - 40×40 rounded (9px) icon tile (DB: bg `#eef4fb`/`#0b71b8`; fileadmin: bg `#fff6ec`/`#e6790a`).
  - Middle: title ("Database dump" / "Fileadmin archive", 700, 15px) + size (grey `· 248 MB` / `· 3.2 GB`).
    Second line = **masked token path**, monospace 12px `#91959b`, ellipsized:
    `snapshot/dl/a7f3••••••••••/database.sql.gz` (never expose the real token in markup until issued).
  - Right action, three mutually exclusive states:
    - **available:** "Download" button (white, border `#cfd2d6`, radius 7px, 600, download icon).
    - **consumed** (after click): green "Downloaded · link used" with check (no button).
    - **dead** (after expiry, un-downloaded): grey "Expired".
- **Combined archive row** (only when BOTH selected): bg `#fbfbfc`, **dashed** border `#cfd2d6`,
  radius 11px. Grey box/archive icon tile. "Combined archive · 3.4 GB" + "Database + fileadmin in
  one `.zip`". Right: **Download all** primary button (accent). Same available/consumed/expired
  states. Downloading the combined archive marks all artifacts consumed.
- **Footer:** top border `#e6e8ea`, flex space-between. Left: "Generated just now · scrubbed · this
  action is audit-logged." (12px `#91959b`). Right: **Start over** button (white, border `#cfd2d6`,
  reload icon) → returns to idle, preserving the source selection.

---

## Interactions & Behavior
- **Card toggle:** click either source card to select/deselect. Independent booleans.
- **Prepare:** disabled unless ≥1 source selected. Transitions idle → preparing. In the prototype
  each step advances every 850ms and the bar fills proportionally; **in production drive this from
  real server progress** (SSE/polling on the export job).
- **Prepare → Ready** after the last step (prototype: +450ms). Starts the expiry countdown.
- **Countdown:** 1s tick from `expiryMinutes × 60` down to 0. At 0 → all links become "Expired".
  Amber → red under 60s.
- **Download (per artifact):** single-use. In production this hits the token endpoint; the link is
  consumed server-side and the UI flips to "Downloaded · link used". The prototype just flips state.
- **Download all:** consumes/hides the combined + both individual links.
- **Start over:** clears timers, returns to idle, keeps selection.
- **Transitions:** border-color/background 150ms on cards; progress width 500ms ease; check-badge
  opacity 150ms; button hover `filter: brightness(.93)` / bg `#f0f1f3`.

## State Management
Client state:
- `db: bool`, `fileadmin: bool` — source selection.
- `phase: 'idle' | 'preparing' | 'ready'`.
- `stepLabels: string[]`, `active: number`, `progress: number` — prepare progress.
- `seconds: number`, `expired: bool` — expiry countdown.
- `dbDl: bool`, `faDl: bool`, `allDl: bool` — single-use consumption flags.

**Production data/endpoints to wire:**
- Real artifact sizes from a server size-preview (dry-run) before prepare.
- Prepare = kick off server job (DatabaseDumpService + ScrubbingService + FileadminSyncService);
  stream real step status.
- Ready = issue **single-use, expiring, non-guessable download token(s)** (the security-model
  requirement #9). Countdown mirrors real token TTL. Consumption is enforced server-side, UI just
  reflects it. The action is audit-logged (control #5) and gated by permission/IP-allowlist/MFA/
  step-up (controls #1–4) upstream of this view.

## Design Tokens
**Colors**
- Page bg `#f4f5f7`; surface `#ffffff`; subtle surface `#fbfbfc`.
- Borders `#e0e2e5` / `#d7d9dc` / `#cfd2d6`; footer divider `#e6e8ea`; track `#eceef0`.
- Text: primary `#1a1c1e`; muted `#6c7178`; faint `#8a8f95` / `#91959b`; disabled `#a5a9ad`.
- **Accent** (tweakable) — default in this handoff is **`#1a1c1e`** (dark/neutral). Original
  TYPO3-orange option `#ff8700`; blue option `#0b71b8`. Accent is used for: selected card
  border/check badge, progress fill, and primary buttons.
- DB icon: tile `#eef4fb`, glyph `#0b71b8`. Fileadmin icon: tile `#fff6ec`, glyph `#e6790a`.
- Success green `#1e7a34` (bg `#eaf6ec`, border `#c5e6cd`, dark text `#155625`/`#3d6b48`).
- Warning amber `#b8860b` (bg `#fdf6e6`, border `#ecd9a8`, text `#6b5410`); danger `#b23c2e`.
- Selected card bg tint `#fffaf3`; chip bg `#f0f1f3`, chip text `#565b61`.

**Typography** — `Source Sans 3` (Google Fonts, weights 400/600/700), fallback
`-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`. Base 14px / line-height 1.5.
Monospace (token paths): `ui-monospace, "SF Mono", Menlo, monospace`.
Scale: page title 22px/700; card title 16px/700; docheader title 16px/700; section label
12px/700 uppercase; body 13–14px; sub-labels/meta 11.5–12.5px.

**Radius** — buttons 6–7px; cards 10–12px; icon tiles 7–9px; pills/badges 20px/50%.
**Spacing** — container padding 28/20px; card padding 16–24px; grid gaps 14px; button padding
`9–10px × 16–20px`.
**Shadows** — none (flat, border-driven), matching TYPO3 v14 backend.

**Tweakable props** (declared on the component):
- `accent` (color) — default `#1a1c1e`. Options: `#ff8700`, `#0b71b8`, `#1a1c1e`.
- `expiryMinutes` (int, 1–60) — default 15. Link TTL / countdown start.
- `startPhase` (enum `idle|ready`) — default `idle`. For previewing the ready screen directly.

## Assets
- **Icons:** inline SVG, single-stroke (`stroke-width` 1.7–2.6, round caps/joins) — database
  cylinder, folder, shield-check, lock, clock, check, download arrow, reload, help (?), spinner
  ring, archive/box. No external icon dependency. In TYPO3, prefer the core Icon API / bootstrap
  icons where equivalents exist.
- **Fonts:** Source Sans 3 via Google Fonts (`@import`/`<link>`). TYPO3 backend already ships a
  Source Sans stack — reuse it instead of loading Google Fonts in production.
- No images/photography.

## Files
- `Snapshot Backend Module.dc.html` — the source design (template markup + logic class). **Read this
  for exact markup, inline styles, and the state machine.**
- `Snapshot Backend Module (standalone).html` — self-contained, offline-runnable build of the same
  design (open in any browser to click through the flow).
