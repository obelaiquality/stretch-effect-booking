# Design review: The Stretch Effect booking demo

Reviewed on 9 November 2026 against the live demo
(https://obelaiquality.github.io/stretch-effect-booking/). Every page was captured with headless
Chrome at 1280 px and 390 px, signed in as a client and as the owner (about 100 screens).
The captures are in `research/design/shots/` (not committed).

## Sources

| Repo | Stars (9 Nov 2026) | What it gave this review | Distilled rules |
| --- | --- | --- | --- |
| [nextlevelbuilder/ui-ux-pro-max-skill](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill) | 147k | UX rules by priority (accessibility, touch, states, forms, motion), product rows for booking, spa, yoga and fitness apps | `research/design/ui-ux-pro-max.md` (45 rules) |
| [VoltAgent/awesome-design-md](https://github.com/VoltAgent/awesome-design-md) | 126k | The DESIGN.md format, and design systems of Nike, Ferrari, Linear, Spotify, Oura and Cal.com | `research/design/awesome-design-md.md` (53 rules) |
| [Leonxlnx/taste-skill](https://github.com/Leonxlnx/taste-skill) | 112k | Layout, hierarchy, motion and copy craft, and the full "AI slop" banned list | `research/design/taste-skill.md` (63 rules) |

Rule references below use the short names **UUPM**, **DMD** (with the site) and **TASTE**,
plus the rule number in the distilled file.

## Verdict

The demo has a strong, correct brand base. The black, red and silver palette, the Anton
headlines and the Cinzel wordmark read as The Stretch Effect. The core flows work on phone
and desktop. It does not look like a generic AI template.

It is not yet premium. Five issues hold it back:

1. **Red is everywhere, so it means nothing.** Red is on headlines, eyebrows, buttons, booked
   calendar blocks, negative numbers, badges and warning boxes. Up to four red buttons show
   in one view.
2. **No people.** His Instagram is built on photos of Ivan stretching clients. The site has
   only type and a drawn figure.
3. **Cinzel is used for small labels and data.** At 12 px with wide tracking it is hard to read.
   Red Cinzel eyebrows also fail contrast.
4. **The parts do not form one system.** The site has two package card designs, three date
   formats, five wordings for the main button, and a muddy disabled state.
5. **Missing states.** Loading shows a blank box. Empty and closed days look the same.
   The owner's week calendar does not work on a phone.

## Top 12 fixes, in order

| # | Fix | Severity | Effort |
| --- | --- | --- | --- |
| 1 | Write a red whitelist. Red is only for the primary button, the selected slot, focus, the offer and the logo. Show one filled red button per view. | High | S |
| 2 | Get 6 to 10 real photos from Ivan, with client consent. Use one art-directed hero photo and a small studio gallery. | High | M (needs Ivan) |
| 3 | Limit Cinzel to the wordmark and the slogans. Set eyebrows, labels and day names in Barlow 600, 13 px, uppercase, 0.08em tracking, in silver. | High | S |
| 4 | Fix contrast. Red text on black is 3.4:1; use a lighter red such as `#ff4d63` (6.1:1) for small red text. Borders and input outlines are 1.4 to 1.7:1; inputs need 3:1. | High | S |
| 5 | Add loading skeletons for each page and for route changes. Empty states need a message and an action. | High | M |
| 6 | Owner week calendar on phones: switch to a one-day view with a day strip, like the client booking page. | High | M |
| 7 | One wording for the main action everywhere: "Book a session". | Medium | S |
| 8 | One date format: "Tue 10 Nov · 07:30" (no zero before the day, no year unless needed). | Medium | S |
| 9 | Booking page: group slots into Morning, Afternoon and Evening. Show "Closed" and "Fully booked" as different states. Show the client's own booking in the grid. | Medium | M |
| 10 | Remove the slogan subtitle under every app page title, the red full stop after owner titles, and the "Owner" eyebrow. | Medium | S |
| 11 | Disabled buttons: neutral grey fill and grey text, not 45% opacity red. | Medium | S |
| 12 | Write a DESIGN.md (format in section 9) so later work keeps the system. | Medium | M |

## What works (keep)

- **Brand match.** The palette, the Anton headlines with red second lines, the brush-stroke offer
  and the Cinzel wordmark lockup match his posters. The faceted figure reads clearly.
- **Live slots on the landing page.** Real free times in the hero are the right pattern
  (DMD Cal.com rule 43). They make the page useful at once.
- **The credits number.** The large Anton "4" with a small label is the Ferrari spec-cell
  pattern (DMD 19). It is the right focal point for the dashboard.
- **Mobile booking.** The day strip with a 3-column slot grid and a sticky Continue bar is a
  good phone pattern.
- **Owner client list on mobile** turns into cards, not a squeezed table (UUPM 33).
- **Copy discipline.** No invented reviews, no stats strip, no banned slop words
  (TASTE 46 to 58). The booking confirmation shows date, time, length, cost and the cancel rule
  (UUPM 17).
- **Sharp corners** (2 to 6 px) match the brand and the Ferrari and Nike references (DMD 14).
- **Reduced motion** is respected, and motion is short and limited (UUPM 24, TASTE 25).

## Findings by area

Severity: **H** high, **M** medium, **L** low.

### 1. Visual system

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| S1 | **H** Red is on 12 kinds of element: headline lines, the tagline, eyebrows, filled buttons, the header button, the slot-card button, booked calendar blocks, negative ledger numbers, low-balance badges, the "today" card, the kill-switch box and the Owner badge. The landing hero shows 4 filled red buttons (header, hero, slot card, offer). | DMD Linear 28, Spotify 32, Nike 4; TASTE 20, 23; UUPM 18 | Red whitelist: primary button, selected slot, focus ring, the offer, the logo, one key word in a heading. Everything else uses white, silver or the semantic colours. Booked blocks use white or silver with a red left edge. Negative numbers use neutral text. |
| S2 | **H** Cinzel at 12 px with 0.32em tracking is used for eyebrows, card titles, KPI labels, day names, the week range, table-like labels and even tool names ("Running search_clients"). A serif with wide tracking at small sizes is hard to read, and it is on data. | DMD Oura 37 (serif for feeling, sans for facts); TASTE 13, 14, 16 | Cinzel only for the wordmark, the footer slogan and the closing slogan. Small labels in Barlow 600, 13 px. Tool names in a monospace font. |
| S3 | **H** Contrast. Red `#c8102e` text on `#0a0a0b` is 3.36:1, and on panels 3.15:1. That fails 4.5:1 for small text (eyebrows, "Live better." in the footer, "10:40" time label). `--red-hi` is 4.12:1, which also fails. Borders (`--line`) are 1.39:1, and input outlines (`--line-2`) are 1.75:1. | UUPM 1, 2, 3; WCAG 1.4.3 and 1.4.11 | Add a `--red-text` token (`#ff4d63`, 6.1:1) for small red text. Raise input borders to at least `#6a6a72` (3.7:1 on ink, 3.5:1 on panels). Keep `#c8102e` for fills (white on red is 5.3:1, which passes). |
| S4 | **M** No type scale. Headings are set page by page: hero about 112 px, section h2 56 px, page titles 64 px, card titles 20 px, eyebrows 12 px, notes 12 to 13 px. Several texts are under 13 px. | DMD format gap 2; TASTE 14, 17; UUPM 28 | Fix one scale: display 96/64/48 (desktop/tablet/phone), h2 48, h3 24, body 16/1.55, small 14, label 13. Nothing under 13 px. |
| S5 | **M** The surface ladder is too flat. Ink `#0a0a0b`, panel `#131315` and panel-2 `#1b1b1e` are close. Slot buttons use ink inside a panel, so they read as holes, not buttons. | DMD Linear 23, 25; Spotify 31 | Four steps, for example `#0b0b0c`, `#141416`, `#1c1c1f`, `#26262a`. Controls always sit one step above their container. Add a 1 px top highlight (white at 4%) on raised panels. |
| S6 | **M** Two systems for one thing. Packages are big-number cards on the landing page and Anton-title price cards on the client "Load sessions" page. Badges are on everything (status, "Client", "You", counts). | TASTE 22; UUPM 31 | One package card recipe used on both pages. Badges only for status. Use plain text for the actor ("by you", "by the client"). |
| S7 | **M** Disabled buttons are red at 45% opacity, which shows as dark maroon ("Continue", "Apply", "Save changes"). They look like an error or a pressed state. | UUPM checklist "disabled state clear" | Disabled = panel-2 fill, muted text, no red. |
| S8 | **L** Date formats differ: "Mon 9 Nov", "Mon 09 Nov", "03 Nov 2026", "Tue 03 Nov · 17:30". | UUPM 40; TASTE 22 | One formatter: "Tue 3 Nov · 17:30". Add the year only for other years. |
| S9 | **L** The wordmark shows four times on the landing page (header, hero mark, closing band, footer). | TASTE 22, 63 | Remove the closing wordmark band. Put the closing call to action on a photo instead. |

### 2. Landing page

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| L1 | **H** No photography. His Instagram is built on real photos of Ivan stretching clients. The site has none, so it feels like a poster, not a studio. The spa product row says a gallery is required. | TASTE 40, 64; DMD Nike 8, Oura 42; UUPM product row #32 | One art-directed hero photo (16:9 desktop, 4:5 phone) with the headline over a dark gradient, and a 4 to 6 photo strip. Use his photos only, with client consent. |
| L2 | **H** The hero has 3 filled red buttons, plus the header button. The hero button says "Book your session", the header says "Book a session", the closing says "Book your first session", and the cards say "Choose". | DMD Nike 4; TASTE 23, 44; UUPM 18, 42 | One filled red button per view. "See all times" becomes a text link. One wording: "Book a session". |
| L3 | **M** The faceted figure behind the slot card is cut off at the top and right, at 16% opacity. It looks like an accident. | TASTE 61 (slivers and fragments at an edge) | Remove it when a photo is in the hero, or place it whole. |
| L4 | **M** "Don't just stretch. Feel the Stretch Effect." is a short strip section of about 150 px. | TASTE 6, 52 | Move the line into the hero or into the comparison heading. |
| L5 | **M** "Popular choice" on the middle pack is a claim with no data behind it. It is also the "Most popular on the middle plan" tell. | TASTE 50, 56; BUILD-SPEC claims policy | Remove it, or put "Lowest price per session" on the 10-pack, which is a fact. |
| L6 | **M** No proof. There are no reviews and no "as seen at" logos. The claims policy correctly forbids invented ones. | UUPM 41, 43 | Ask Ivan for permission to quote 2 or 3 real Google or Instagram reviews with first name and initial. |
| L7 | **M** The "Run the studio from ChatGPT" section is a pitch to Ivan on a page for his clients. Its mock chat has Allow and Deny "buttons" that do nothing. | TASTE 63 (fake UI from styled divs); UUPM 23 | Keep it for the demo pitch. On the live site, move it to the owner area or remove it. Make the mock clearly a picture (no button styling). |
| L8 | **L** Every section uses the same template: eyebrow, heading, then a block of cards. | TASTE 56, 63 | Drop the eyebrows that repeat the heading ("Sessions" above "Load sessions"). Vary at least two section layouts. |
| L9 | **L** The steps "01 02 03" are `--line-2` on panel (1.64:1). | UUPM 1 | Decorative, so allowed, but raise them to silver at 40% or make them smaller and readable. |
| L10 | **L** The closing band is centred (wordmark, slogan, button). The rest of the page is left-aligned. | TASTE 5 | Left-align, or replace it with a photo band (see S9). |
| L11 | **L** On a phone the hero buttons are not full width. | TASTE 37 | Full-width buttons under 640 px. |

### 3. Sign-in and sign-up

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| A1 | **H** A signed-in user can open `/login`. The page then shows the client navigation and "Sign out" while someone signs in as the owner. | UUPM 34 (navigation consistency) | If a user is signed in, `/login` redirects to their home, or shows "You are signed in as Thandi" with Sign out. |
| A2 | **M** No route without a password (magic link or Google sign-in) for clients. UUPM rates this Critical under WCAG 2.2 accessible authentication. Paste and password managers do work. | UUPM 9 | Phase 2: an emailed sign-in link for clients. Keep the password and authenticator code for the owner. |
| A3 | **M** The consent checkbox is the native grey box, unlike every other control. Password fields have no show/hide toggle. | TASTE 22; UUPM 13 | A styled checkbox that matches the working-hours checkboxes. A show/hide button on password fields. |
| A4 | **L** The 6-digit code field has a red border before the user types, so it looks like an error. | UUPM 14 | Red border only on focus or error. |

### 4. Client booking flow

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| B1 | **H** Booking takes three actions: pick a slot, Continue, then Confirm in a dialog. The sticky Continue bar repeats the dialog. | UUPM 15, 17; DMD Cal.com 43 | Tap a slot to open the confirmation at once. Remove the Continue bar. |
| B2 | **M** Long columns of identical buttons (up to 24 slots a day). On desktop the grid scrolls inside the page, so the page has two scrollbars, and buttons are cut in half at the edge. | TASTE 1, 59; UUPM 33 | Group into Morning, Afternoon and Evening, with a "+ more" if needed. No inner scroll: let the page scroll. |
| B3 | **M** "No times" is shown for a closed Sunday, a fully booked day and a day inside the minimum notice. These are different situations. | UUPM 6, 20 | "Closed", "Fully booked" and "Too soon to book" as separate texts, each with the next free day as a link. |
| B4 | **M** The client's own upcoming booking is not shown in the week grid. | UUPM 17 | Mark it in the grid ("Your session"). |
| B5 | **M** Selection is shown only by a red fill. | UUPM 6 (not colour alone) | Add a check icon and `aria-pressed`. |
| B6 | **L** The success screen has no "Add to my calendar" (.ics) and no studio address or directions. | UUPM 17 (reminder) | Add both. |

### 5. Client dashboard and packs

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| D1 | **M** The progress bar under "Sessions left" has 5 segments, but there is no maximum of 5. It suggests a target that does not exist. | TASTE 63 (filled progress bars as visuals) | Remove the bar, or show progress in the current pack ("3 of 5 used"). |
| D2 | **M** "Load more" next to "Book a session" reads like "show more items". | TASTE 44 | "Buy sessions". |
| D3 | **M** Recent activity repeats itself: the title "Session booked", then the note "Session booked". The date is when the client clicked, not the date of the session. | TASTE 45 | One line: "Booked Wed 18 Nov · 17:30" with "−1" right-aligned in neutral text. |
| D4 | **M** Packs page: 3 filled red buttons side by side. The cards are different heights, so the buttons do not line up. "Example pricing for the demo" is styled like a red section heading. | TASTE 3, 23; UUPM 18 | One recommended pack with the red button and two outline buttons. Equal card heights with buttons on one line. The demo price note as a small grey badge. |
| D5 | **L** A lock icon is next to "Pay by EFT or card at the studio". A lock suggests security, not payment. | UUPM 7 | Use a card or cash icon, or no icon. |
| D6 | **L** Every app page repeats a slogan under its title ("Get stretched. Move better. Live better."). | TASTE 45, 54 | Remove the slogans from app pages. Use the space for a useful line, such as the studio address on the booking page. |

### 6. Owner area

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| O1 | **H** On a phone the owner week calendar squeezes 7 columns into 330 px. Names are cut ("Joh") and the free-time outlines overlap. | DMD format gap 6 (collapse rules); UUPM 33 | A day view with a day strip under 768 px, as on the client booking page. |
| O2 | **M** In the week calendar, free time is drawn as large outlined boxes that overlap bookings. Bookings are solid red blocks with only a first name. The free time competes with the real content. | DMD Linear 28; UUPM 6, 37 | Free time as a faint fill, no border. Bookings as silver blocks with a red left edge, name and time. Google busy time stays hatched, with its label. |
| O3 | **M** The "Today" page shows the date as small grey text at the right. The page title has a red full stop, and the eyebrow says "Owner" on every page (the header already shows an Owner badge). | TASTE 17, 56 | Title "Monday 9 November" or "Today · Mon 9 Nov" in the heading. Remove the red full stop and the "Owner" eyebrow on all owner pages. |
| O4 | **M** KPIs have no context. "14 booked" alone does not say if that is good. | UUPM 36 | Add the change from last week ("+3 on last week") in small text. |
| O5 | **M** "Next 7 days" uses 4 lines of 11 to 12 px text per day. On a phone the 7 cards are 44 px wide. | TASTE 14; UUPM 28 | One row per day: name, a bar of booked and free hours, and the two numbers. Make each day a link to the calendar. |
| O6 | **M** On the client page, "Book for Aisha" is a large empty panel with one button. The left column is crowded. | TASTE 6, 8 | Put the actions (adjust sessions, book, mark paid) in one action column. Give the wide column to bookings and history. |
| O7 | **L** History rows put the status badge right after the date, so the badges form a ragged column. | TASTE 3 | A fixed column for status. |
| O8 | **L** The session history table repeats "What" and "Note" ("Session booked / Session booked"). | TASTE 45 | Show the note only when it adds something. |

### 7. ChatGPT page

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| C1 | **M** Tool names use Cinzel mixed case ("Running search_clients"). Code in a decorative serif is hard to read. | DMD Vercel 51; S2 | Monospace for tool names and JSON. |
| C2 | **M** The heading is doubled: eyebrow "Simulation", then the Cinzel title "Your ChatGPT (simulation)". The six prompt chips are large boxes with long text. | TASTE 56 | One heading. Chips as compact 2-line buttons in a scrolling row on phones. |
| C3 | **L** The empty conversation box is 160 px with only "Pick a prompt above to start." | UUPM 20 | Show the first exchange by default, or make the box short until it is used. |
| C4 | **L** The kill-switch note uses a red fill and a red edge, like an error. It is information. | S1 | Neutral panel with a shield icon. |

### 8. Go-live page, mobile menu and shared shell

| ID | Finding | Rule | Fix |
| --- | --- | --- | --- |
| G1 | **M** The voice changes: the intro talks to Ivan ("your own hosting"), and the steps talk about him ("his hosting", "Ivan books"). | TASTE 45 | Second person throughout. |
| G2 | **M** Step text lines are up to about 150 characters wide on desktop. | TASTE 14; UUPM 28 | Max width of about 68 characters for running text. |
| G3 | **M** The mobile menu opens with no backdrop. The page shows directly under the menu, and a tap outside does not close it. | UUPM 34 | A full-height panel or a dark scrim, close on tap outside and on Escape, and lock page scroll. |
| G4 | **M** Route loading shows an empty area with no heading. On a slow phone the page looks broken for a second. | UUPM 19, 21 | A skeleton that matches each page layout (title bar, two cards). |
| G5 | **L** "Go live" is in the main navigation for clients. It is a pitch page for Ivan. | UUPM 34 | Keep it for the demo. Remove it from the live site. |
| G6 | **L** The footer slogan wraps on phones and leaves "Better." alone on a line. | TASTE 19 | `text-wrap: balance`, or a non-breaking space in "Live better.". |

## Where the repos disagree with the brand

The brand comes from the owner and is fixed. These repo defaults are rejected on purpose:

- UUPM lists dark mode as an anti-pattern for spa products, and its booking row suggests blue
  and green. The Stretch Effect is black, red and silver. Keep the brand, and meet the contrast
  rules instead (S3).
- Taste-skill calls dark pages "tech only", bans tracked small caps and prefers sentence case.
  The posters use uppercase Anton and tracked Cinzel. Keep uppercase for display and the
  wordmark, and apply the small-text rules to labels (S2).
- The Yoga and Spa rows suggest calm, rounded, organic styling. The brand is high-energy and
  sharp. Take their structure (gallery, proof, story), not their look.

## What a DESIGN.md for this site must add

BUILD-SPEC section 5 has the fonts, colour tokens, radius, spacing, motion summary and
banned list. The DESIGN.md format (VoltAgent/awesome-design-md) also needs:

1. Front matter with tokens (`colors`, `typography`, `spacing`, `rounded`, `elevation`,
   `motion`, `components`), referenced in the prose as `{colors.red}`.
2. A type scale table per role (size, weight, line height, tracking, phone size).
3. Colour roles with contrast per surface, and the red whitelist (S1).
4. A surface ladder and elevation table. Shadows only on dialogs and menus (DMD Spotify 33).
5. Component specs with all states: buttons, inputs, slot chip (free, selected, yours, closed,
   full), credits figure, package card, booking row, status badge, table, dialog, toast,
   mobile menu, demo banner.
6. Layout: grid, section rhythm (96 / 64 / 48 px), breakpoints, and collapse rules (week
   views become day views under 768 px).
7. Imagery rules for photos, the faceted mark and the brush stroke, including crops per
   breakpoint.
8. Voice and microcopy: one main action wording, date and money formats, and empty, error
   and cancel messages.
9. A Do's list next to the banned list, an agent prompt guide, and a Known Gaps list.
