# Interface and interaction

## What a person can now do faster

**Ctrl+K opens everything.** One box searches members, trainers, classes and
payments, and doubles as a command list — switch theme, print, sign out. Results
are scoped by role in SQL, so a trainer searching a name only ever gets their own
roster back. Arrow keys move, Enter opens, Escape closes.

**Keyboard shortcuts** for the things done twenty times a day: `/` jumps to the
filter box, `n` opens the add dialog on whatever screen you are on, `g` then a
letter navigates, `t` flips the theme, `?` lists them all.

**Every table sorts, filters and pages itself.** No PHP changed — the interaction
layer finds each table, makes the headers clickable, adds pagination past 25 rows,
and wires any existing filter box to keep the count in step. Numbers, dates and
text each sort correctly.

**Bulk actions on members.** Tick several, then set status, reassign a trainer, or
send them all the same message. The confirm step names how many records are about
to change.

## Choices worth explaining

**Light theme, not just dark.** The gym floor is bright and phones get used in
daylight. The palette inverts to white while keeping the frosted panels and the
red accent, and the choice is applied before first paint from a tiny inline
script — so the page never flashes the wrong colour scheme.

**Compact density.** Front-desk staff scanning a long member list want more rows
per screen; a manager reading a dashboard does not. One toggle, remembered.

**Tables become cards on a phone.** Below 620px each row stacks into a labelled
card using the column headings, so nothing needs horizontal scrolling. Modals
become bottom sheets, because a dialog pinned to the middle of a phone screen is
hard to reach with a thumb.

**Bottom tab bar on mobile.** Four destinations per role, chosen by what each one
actually opens most. The sidebar still exists behind a menu button with a scrim.

## Accessibility

A skip link, visible focus rings on every interactive element, focus trapped
inside open dialogs and returned to where it came from on close, `aria-live` on
toasts, labelled checkboxes and icon buttons, keyboard-operable sort headers, and
`prefers-reduced-motion` honoured throughout — the drifting light field stops
entirely.

## Feedback and safety

Toasts replace silent redirects, with a progress line showing the dismiss timer
that pauses on hover. A thin bar at the top of the window shows navigation in
flight. `window.confirm` is replaced by a themed dialog that names the
consequence. Submit buttons disable for a moment after a click so a double-tap
cannot double-post, and leaving a half-filled dialog warns first.

## New screens

**Check-in station (`kiosk.php`)** — a full-screen tablet page for the entrance.
Large keypad, one action: keying a member number toggles them in or out, so staff
never pick between two buttons under pressure. It accepts a barcode scanner on the
same path, shows who is currently on the floor, and refuses expired memberships
with wording the member can read.

**Announcements (`admin/announcements.php`)** — one message to a whole group.
Audiences are queries, not lists: everyone, members, trainers, active members only,
anyone carrying a balance, or anyone not seen in three weeks. Sent broadcasts show
their open rate. The whole send is one prepared statement inside one transaction.

**Member card (`member/id-card.php`)** — a printable card with the member number
and a deterministic bar pattern, plus the branch list. Tapping the number copies it.

## Installing

Nothing new to import. The two new asset files are picked up automatically and
versioned by modification time, so browsers refresh them only when they change.
