---
version: 1
name: The Stretch Effect
description: Booking site for an assisted stretch therapy studio in Brackenfell, Cape Town. Dark, sharp, athletic. Black, silver and white carry the page; red is scarce and always means "act" or "selected".
colors:
  ink: "#0b0b0c"          # page canvas (never pure black)
  panel: "#141416"        # level 1: cards, sections
  panel-2: "#1c1c1f"      # level 2: controls and raised items inside a card, hover
  panel-3: "#26262a"      # level 3: menus, dialogs, selected rows
  line: "#2a2a2f"         # decorative dividers and card edges (not a control boundary)
  edge: "#6a6a72"         # control boundaries: inputs, outline buttons, slot chips (3.7:1 on ink, 3.5:1 on panel)
  white: "#f4f4f2"        # primary text
  silver: "#c9ccd2"       # secondary text, labels, eyebrows (11.5:1 on panel)
  muted: "#8d8d95"        # meta text only: dates, hints (5.6:1 on panel)
  red: "#c8102e"          # FILLS only: primary button, selected slot, offer brush, logo. White on red 5.3:1
  red-hi: "#e01a36"       # hover of red fills
  red-text: "#ff4d63"     # red TEXT at small sizes: errors, destructive labels (6.1:1 on ink)
  ok: "#3fa66b"
  warn: "#d9a23a"
typography:
  display: { family: Anton, case: upper, weight: 400, line-height: 0.95 }
  body: { family: Barlow, weights: [400, 500, 600] }
  brand: { family: Cinzel, use: "wordmark and the two slogans only" }
  scale:
    display: "clamp(48px, 8vw, 96px)"   # landing hero only
    page-title: "clamp(40px, 5.5vw, 64px)"
    h2: "clamp(32px, 4vw, 48px)"
    h3: "22px / 1.25, Barlow 600"
    figure: "clamp(64px, 10vw, 96px) Anton"  # credits left, KPI hero numbers
    body: "16px / 1.55"
    small: "14px / 1.5"
    label: "13px / 1.3, Barlow 600, uppercase, 0.08em tracking, silver"
    minimum: "13px — nothing smaller"
spacing: [4, 8, 12, 16, 24, 32, 48, 64, 96, 128]
rounded: { button: 2px, input: 4px, card: 6px, badge: 2px }
motion: { fast: 150ms, medium: 250ms, ease: "cubic-bezier(0.2, 0.7, 0.2, 1)", press: "translateY(1px)" }
---

# The Stretch Effect — DESIGN.md

How the site must look. BUILD-SPEC.md says what it does; this file says how it looks.
Tokens live in `web/src/styles/tokens.css` and match the front matter. Components use tokens,
never raw hex.

## 1. Atmosphere

Black studio, silver figure, one red signal. The brand comes from Ivan's posters: heavy
condensed capitals, a thin Roman wordmark, a faceted silver figure. The site is calm and
confident. White and silver do the work. Red appears where the user acts or has chosen.

## 2. Colour roles

**The red whitelist.** Red may appear only here:

1. The **one** filled primary button in a view (`.btn-red`). All other buttons are outline or text.
2. A **selected** state: the selected slot, the active nav underline, the active day in a day strip.
3. The **focus ring** (`--red-hi`, 2px, offset 2px).
4. The **50% off** offer brush on the landing page.
5. The **logo** and the red rules beside "EFFECT".
6. **One** red line or word in a display heading on the landing hero only.
7. **Errors and destructive actions** as `--red-text` text or a red outline, never a red fill.

Not red: eyebrows, labels, badges, card top edges, booked calendar blocks, negative numbers,
counts, information boxes, the Owner badge, checkboxes, KPI numbers, page-title punctuation.

**Semantic colours**: `ok` for confirmed/completed/paid, `warn` for pending/low, `red-text`
for errors and "0 sessions". A state is never colour alone: add a word or an icon.

**Surfaces**: ink → panel → panel-2 → panel-3. A control sits one level above its container
(slot chips on a panel are panel-2). Raised panels get a 1px top highlight
(`inset 0 1px 0 rgba(255,255,255,0.04)`). Shadows only on dialogs and menus
(`0 12px 32px rgba(0,0,0,0.5)`).

**Contrast**: body text 4.5:1, large text 3:1, control boundaries 3:1 (`edge`). Checked values
are in the front matter. `line` is for decoration only.

## 3. Typography

- Anton: display headings, page titles, the credits figure, big dates. Uppercase. Never body.
- Barlow: everything else, including labels, eyebrows, buttons, tables and numbers
  (`font-variant-numeric: tabular-nums` for times, prices, counts).
- Cinzel: the wordmark, the footer slogan and the closing slogan. Never at under 16px,
  never for data, labels or code.
- Monospace (`ui-monospace, SFMono-Regular, Menlo`): tool names, JSON, references.
- Eyebrow (`.eyebrow`): Barlow 600, 13px, uppercase, 0.08em tracking, silver. Use an eyebrow
  only when it adds context the heading does not have.
- Nothing under 13px. Running text max 68 characters per line (`max-width: 68ch`).

## 4. Components and states

| Component | Rule |
| --- | --- |
| Primary button `.btn-red` | Red fill, white uppercase Barlow 600 15px. One per view. Hover `red-hi`, press translateY(1px). |
| Outline button `.btn-ghost` | Transparent, `edge` border, white text. Hover border white. |
| Text button `.btn-quiet` | Silver text, underline on hover. |
| Disabled (all buttons) | panel-2 fill, muted text, `line` border, no red, cursor not-allowed. |
| Destructive | Outline button with `red-text` label and `red-text` border on hover. Placed apart from the primary action. |
| Input / select | ink fill, `edge` border, 4px radius, 48px high, label above. Focus: red-hi border. Error: red-text border + message under the field. |
| Checkbox | 20px, `edge` border; checked = white fill with ink check mark. |
| Slot chip | panel-2 fill, `edge` border, white tabular time. Hover: white border. Selected: red fill, white text, check icon, `aria-pressed="true"`. Yours: silver fill, ink text, "Yours". Closed / full / too soon: a text line, not a chip. |
| Badge | 2px radius, 13px Barlow 600 uppercase, 1px border. Status only (confirmed, pending, completed, no-show, cancelled). |
| Card | panel fill, `line` border, 6px radius, 24px padding, top highlight. No coloured top edge. |
| Credits figure | Anton `figure` size, white, label under it (`.label`). |
| Booked block (owner calendar) | panel-3 fill, white name + time, 3px red left edge. Google busy: hatched, label shown. Free: no border, faint panel-2 fill. |
| Dialog | panel-3, 6px radius, shadow, focus trap, Escape closes. |
| Mobile menu | Full-width panel under the header, dark scrim over the page, closes on scrim tap, Escape and route change, page scroll locked. |
| Skeleton | panel-2 blocks with a slow shimmer (none with reduced motion). Every page and every route shows its skeleton while loading. |
| Empty state | One sentence + one action button. |

## 5. Layout

- Max width 1200px, gutter 16px (phone) / 32px (≥768px). One left edge for header, sections, footer.
- Section rhythm: 96px desktop, 64px tablet, 48px phone.
- Phone (<768px): one column, full-width primary buttons, week views become a day strip +
  one day list (client booking AND owner calendar).
- Text left-aligned. No centred sections.

## 6. Imagery

- Photos: real photos of Ivan and the studio only, with client consent. Hero crop 16:9 desktop,
  4:5 phone, dark gradient under text. Until photos exist, show no photo slots at all
  (`web/src/content/photos.ts` is empty).
- The faceted figure appears whole, never cut by the viewport or another element.
- The brush stroke appears once (the offer).

## 7. Voice and microcopy

- Main action: **"Book a session"** everywhere (header, hero, dashboard, empty states).
- Buying: **"Buy sessions"** (page title "Load sessions" is fine).
- Dates: **"Tue 3 Nov · 17:30"**. Day without leading zero. Year only when not this year.
  Use `formatDay`, `formatTime`, `formatDayTime` from `web/src/lib/format.ts`.
- Money: "R2,500". Times 24-hour.
- No slogans as subtitles on app pages. Slogans live on the landing page and footer.
- No invented claims ("popular", "most booked", counts). Facts only ("Lowest price per session").

## 8. Do

- Let white type on black carry the hierarchy; save red for the moment of action.
- Show real data (live slots) instead of illustrations.
- Keep one system: one card, one button set, one badge, one date format.

## 9. Don't

- More than one filled red button in a view.
- Red for decoration, counts, labels, headings in the app, or information boxes.
- Cinzel at small sizes or for data.
- Blank loading screens.
- Fake interactive UI (buttons that do nothing) outside the labelled simulation.

## 10. Known gaps

- No photos yet (needs Ivan, with consent).
- No real client reviews yet (needs permission).
