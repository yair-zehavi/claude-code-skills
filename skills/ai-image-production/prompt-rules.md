# Prompt rules

For medium-first art direction of designed graphics (name the physical medium, its
imperfections, a counted palette), see
[art-directed-graphic](../art-directed-graphic/SKILL.md). The rules here are about
getting the right objects, people and motion into the frame.

## 1. Describe what is there; never name what you don't want

Diffusion models attend to every token in the prompt. Wrapping a word in "no",
"without" or "absolutely no" does not cancel it; it adds it.

Observed on a seasonal mood board (Seedream 4): unwanted seasonal decorations showed up
in every generation. Adding "absolutely no ..." for each of them made it worse. What
worked first time: delete every mention and fill the space with positive description.
An illustration of the same fix:

```
BAD:  cosy autumn harvest dinner table, warm home, absolutely no pumpkins,
      no jack-o'-lanterns, no fairy lights
GOOD: a long wooden table on a linen runner with a loaf of crusty bread, a bowl
      of red apples and a jug of cider, bare plaster wall and a bookshelf behind,
      stone tile floor, soft evening daylight
```

- The occasion word itself can carry the wrong prior. A generic "holiday dinner" pulls
  the most common holiday in the training data. Name the actual occasion or, better,
  the actual objects.
- The one negative that has held up in practice is "no writing of any kind" (or
  "contains no writing, letters or numerals"). Keep it; it is how you get clean art for
  local compositing.

## 2. Spell out specific objects

Models drift on anything specific. Asked for a pair of plain candlesticks, one model
returned ornate multi-branch candelabra. Give geometry, count and condition:

> a pair of simple old brass candlesticks, two separate single candlesticks side by
> side, plain and worn

Same for proportions: one model made plants tower over the people in the scene. State
scale ("vines at shoulder height, leaves the size of a hand").

When the count is still wrong on an otherwise good image, edit, do not reroll: a
subject-preserving edit model with "after the edit there must be exactly N <objects>",
then upscale.

## 3. Show the real moment, not the stock metaphor

Topic imagery defaults to the stock visual cliché. For a service business whose
customers are in the middle of a life event, models reach for the symbolic version:
an open road, a sunrise silhouette, puzzle pieces, a lightbulb, a chess piece. The
customer at that moment feels logistics, paperwork and decisions, not romance. The
image misframes the article from the first pixel.

Prompt the domestic, practical moment instead:

- a kitchen table with paperwork, a laptop, coffee and a manila document folder
- a room in transition: bare floor with sunlight slanting in, an empty wall, a single
  piece of furniture, a fresh-paint feel
- a window onto a street or view that sets the place without props
- hands doing the actual task: signing, sorting, comparing two documents

Ask what the reader is actually doing at that moment (forms, phone calls, a kitchen
table) and put that in the frame. If a brief arrives in cliché language, redirect it
gently toward the practical scene.

## 4. Logos and brand names

- Do not composite a logo onto a generated object. On a generated product surface it
  read as a sticker even with luminance-based ink blending and reduced opacity: the AI
  surface is soft and inconsistent so the logo edge looks too sharp, the lighting does
  not match, and generated surfaces are rarely flat so the perspective skews. Use
  unbranded objects; the brand belongs in the copy, the page title and the site schema.
  For a branded object hero, photograph the real object.
- Never name a brand you do not own ("a bottle like <famous brand>"). Describe shape,
  material and colour. Text-strong models letter the name onto the product.

## 5. Photoreal people

Route first (Seedream 4, Nano Banana, Imagen 4; see
[model-routing.md](model-routing.md#1-photoreal-people-own-test-july-2026)). Then push
documentary realism in positive terms:

> candid documentary photograph, real skin texture with visible pores and small
> imperfections, unretouched, natural window light, shot on a 50mm lens on a full-frame
> camera

- Keep the light plain. Fairy lights, a heavy colour grade and a vignette were the
  reason one set was rejected: "extra filters and lights make it look cheap".
- Reference-edited people trend toward smooth "same face" skin. Ask for skin texture
  explicitly.
- Generate the people clean and composite the type afterwards. For a non-Latin script
  in Pillow without a shaping engine, strings may need reversing; HTML + headless Chrome
  avoids that entirely.

## 6. Product and reference-edit prompts

- Describe the subject once and reuse the exact string everywhere (`{subject}` in a
  manifest). Variation in the description becomes variation in the product.
- Anchor every prompt on the references:

> Using the exact {subject} shown in the reference images (identical shape, materials,
> colours and printed label), produce one clean product photo on a plain neutral
> light-grey seamless background, centred, soft even studio lighting, sharp focus.

- For scenes, put the subject somewhere concrete and finish with the lock phrase:

> Place the exact {subject} on a white marble surface with a soft shadow, bright airy
> still life, soft daylight. Keep the product and label identical.

- Give 2-4 real angles; more angles give the model better 3D understanding.
- Ask for the format in words when the endpoint has no pixel-size field
  ("vertical 9:16 with empty space at the top for a headline").

## 7. Series consistency

- Put the brand palette in the prompt as named hex values ("<colour name> #RRGGBB as
  primary, <colour name> #RRGGBB as secondary, <colour name> #RRGGBB as highlight,
  <colour name> #RRGGBB background"). Covers in a series then look like one publication.
- Keep one style sentence and one "look" string (lens, light, grade, aspect) and append
  them to every prompt in the set. Example look string for a film:

> Photorealistic cinematic film still, full-frame cinema camera with a 24mm lens at eye
> level, smooth gimbal POV, soft early-morning daylight mixed with clean cool overhead
> LED light, subtle haze, rich but natural colour grade, crisp detail, 16:9 widescreen.

- Reserve type space positively ("the upper third is a quiet, empty field reserved for
  typography added later"). If a model still fills it, shifting the art in post and
  mirror-filling the vacated edge is cheaper than regenerating.

## 8. Image-to-video prompts: describe only what changes

The start image already encodes appearance, wardrobe, lighting, palette and
composition. Restating any of it creates competing instructions and is a leading cause
of faces morphing and text or logos garbling. It is the video form of rule 1.

```
BAD:  ...the printed logo stays perfectly sharp and unchanged,
      warm street lamp steady, she wears the blue coat...
GOOD: The handshake finishes and their hands part. She turns her head slightly
      toward the man beside her. Leaves stir overhead. Very slow dolly-in.
```

- Say how the motion ends, not only that it happens; it prevents floating, awkward stops.
- Tone motion down with "subtle" and "very slow". Treat the frame as a locked composition.
- Keep clips short. Drift grows with duration; compare the first and last frame of every
  clip.
- For start and end-locked segments, describe the camera path between the two frames and
  where it arrives ("pushes in until the carton and the tape fill the frame"). A short
  shared suffix such as "continuous single shot, smooth steady gimbal motion at walking
  pace" keeps segments matched.
- Readable text and packaging garble in motion. Keep it out of the clip and overlay it.

## 9. Edit prompts that worked

- **Strip baked-in text from a flattened image:** "remove all text and the coloured
  overlay panel, reconstruct the natural background, keep <subject> exactly unchanged".
  Generate 2, pick one, and check small printed text on the subject did not garble.
  Bonus: the edit model upscales to about 1024 px on the long side.
- **Outpaint to 9:16:** with an edit model and `aspect_ratio: "9:16"`, "keep the original
  image intact and continue the scene above and below". Preserves faces on real photos.
- **Fix a count:** "after the edit there must be exactly N <objects>", then a 4x upscale.
- **Carry an object between scenes:** pass the previous keyframe as a second reference
  and name the object ("the carton with the single strip of brand-coloured tape from the
  second image").
