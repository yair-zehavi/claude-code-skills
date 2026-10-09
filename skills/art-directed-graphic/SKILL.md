---
name: art-directed-graphic
description: >
  Produce a client-facing graphic that does NOT look AI-generated - holiday and
  greeting cards, social posts, print flyers, posters, invitations, announcement
  graphics. Use whenever a client needs a designed image rather than a stock render,
  or when a previous attempt was rejected as "looks AI" / "generic" / "on the face".
  Covers the medium-first prompting method, the generate-clean-then-composite
  pipeline, the direction library, and one-artwork-many-formats layout.
  NOT for UI/web pages or video.
---

# Art-directed graphic

## Why this exists

Nearly every AI holiday/greeting graphic dies the same death, and it is diagnosable,
not a matter of taste. The failure signature:

- soft pastel gradient wash background
- a symmetrical, centred photoreal still life
- glossy specular highlights (honey, glass, fruit)
- everything smooth, evenly lit, no surface, no edges, no ink
- typography parked ON TOP of a finished render rather than designed into it

That combination is what everyone else's post looks like this week. The client sees
"AI" before they see the idea.

**The fix is not a better render. It is a different medium.** Move to something with
a physical surface - torn paper fibre, screen-print halftone, ink misregistration,
canvas weave, carved linocut marks, thread, glaze crazing. Diffusion models render
those extremely well, and they are exactly what slop lacks. Surface is the tell.

## The method, in four moves

### 1. Name a medium, not a subject

A prompt that describes *what is in the picture* gets slop. A prompt that describes
*what the picture is physically made of* gets a design. Every prompt must name:

- **the medium** ("a four-colour silkscreen on aged uncoated paper", "a handmade
  cut-paper collage photographed flat under raking north daylight")
- **its imperfections** - this is the load-bearing part. Ink misregistration with a
  1mm offset. Torn paper fibre at every edge. Dry-brush drag and gouache pooling.
  Visible canvas weave. Pencil under-drawing showing through. Hand-cutting asymmetry.
- **a strict palette, counted** ("exactly four inks: crimson, warm gold, deep ink
  navy, and the cream of the paper"). Say the number. Unbounded palettes go muddy.
- **a lighting/finish rule** ("completely flat lighting, no gradients, no
  photographic realism" or "soft raking light so the cut paper lifts and casts
  delicate shadows")

See `directions.md` for the library of media that work, what to name in each, and
where each one fails.

### 2. Generate the art CLEAN - no text, no logo

Both are composited afterwards, always:

- **Non-Latin scripts garble in-model** (Hebrew, Arabic and others). Even when a model gets Latin right,
  it will not get the client's font, tracking or line breaks.
- **A generated logo reads pasted-on** every single time. Key the real file.

Instead, reserve space in the prompt, positively phrased:

> "The upper third of the image is left deliberately quiet and empty as reserved
> space for typography that will be added later. The image is purely pictorial and
> contains no writing, letters or numerals of any kind."

If the model puts a dark plane in the reserved zone anyway, do not fix it with a
scrim - the scrim turns it into a grey smear. Add an explicit **composition rule**
and regenerate:

> "COMPOSITION RULE: every painted plane, collaged paper and contour line sits
> entirely within the lower two thirds. The whole upper third is one single
> unbroken field of plain warm cream painted ground, running uninterrupted from
> the left edge to the right edge and up to the top edge."

### 3. Composite typography locally, in HTML + headless Chrome

Not Pillow. Chrome gives real RTL, real font shaping, real flexbox layout, and the
whole lockup becomes free to iterate - the expensive generation happens once, copy
and layout revisions cost nothing. This matters because clients revise copy 4-5 times.

### 4. One artwork, many formats

Design a print master and crop the social sizes from it, or generate one square
"plate" and let both formats draw on it. Never design the same graphic twice.

## Layout system that works

| element | rule |
|---|---|
| eyebrow | small, tracked, faint. "{Client} wishes you" |
| headline | display face, the client's accent colour, the largest thing on the page |
| rule | thin gold/accent line with a centre dot, closing the lockup |
| body (print only) | 2 short lines, in a footer band |
| sign-off | one line, accent colour |
| logo | **small, corner or centred in the footer, ONCE.** Never on an object in the art, never a top banner, never duplicated |
| footer band | solid panel at the foot of the card, **sampled from the artwork's own paper tone** so there is no seam |

Print geometry: A5 is 148×210mm. With 3mm bleed all round that is 154×216mm, which
is 1819×2551px at 300dpi. Nothing important within ~8mm of the trim edge. Ship a
bleed PDF and a trimmed PDF; ask the printer which they want and whether they want
crop marks (some trade printers prefer no marks).

Closest generated ratio to A5 is 2:3 - crop the rest with `object-fit: cover`.

## Hard-won gotchas

- **Check your image provider's exact endpoint and parameters.** Some models take
  `aspect_ratio` plus a resolution tier and silently ignore `image_size`. High-resolution
  generations can take a minute or more, so make generation resumable.
- **Board-style prompts come back with a white margin** around the "photographed"
  artwork. Auto-crop to the painted ground (detect the warm-vs-neutral edge) before use.
- **Headless Chrome is OOM-killed** on 1819×2551 pages when renders overlap, and
  silently leaves a stale PNG. Retry each screenshot up to 3×.
- **Logo keying**: key the white paper out by luminance, keep the real colours for
  light grounds; make a flat gold/white monochrome version for dark grounds.
- **Negatives summon the subject.** Never write "no candlesticks" - describe the
  scene positively instead. The one safe negative is "no writing of any kind",
  which Gemini-class models honour.
- **No em-dashes anywhere**, in any language. No exclamation marks on greeting cards (a style choice, adjust to the client).
- **Palette discipline beats everything.** If a direction comes back muddy or
  collapsed into one drab tone, it is a regenerate, not a colour-correct.

## Getting the brand INTO the art, not onto it

The difference between a design and a stock image with a logo: the brand idea should
be inside the picture, so the graphic is not logo-swappable.

Before prompting, extract from the client:
1. **What the logo mark actually depicts** (for example a globe with meridian
   arcs: a globe or meridian motif can recur inside the artwork)
2. **The client's world-objects** (cargo ship, aeroplane, crates, route arcs)
3. **A directional idea** (for example routes that always CONVERGE on one place and
   never radiate out, because the copy angle is "coming home")
4. **The brand colour, as an accent only** - the occasion's palette leads

Best-in-class device: hide the brand objects inside ornament so finding them is a
reward for looking (a ship and aeroplane concealed in papercut filigree).

## Suggested job layout

Keep one folder per job with four small pieces so a revision costs nothing:

- `prompts.py`: the prompts, with the reasoning in the docstring
- `gen.py`: image-generation calls, resumable (skips art that already exists)
- `build.py`: copy, palette and layout per direction, writes HTML
- `render.sh`: headless Chrome screenshots with retry

## Per-client taste

If the client has a taste-calibration note, read it before prompting. After every
round append what was liked, what was struck out, and the reason.
