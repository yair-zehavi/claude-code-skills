# Formats, placements and export

Generate once, then produce every size locally. Nothing on this page calls a model
except the optional outpaint and text-removal edits.

## 1. One artwork, every ad placement ratio

An ad set usually runs in feed (1:1 or 4:5), stories and reels (9:16). Designers and
clients often supply only a square.

**Build the adapted versions at ad-creation time, not after someone notices.**

- A square letterboxed into a 9:16 story shows black bars and looks broken and cheap.
- Automatic placement adaptation is often switched off on boosted-post creatives, so
  nothing fixes it for you.
- Retrofitting is worse than doing it up front: on a boosted existing post the only API
  route to a per-placement image is rebuilding the creative, which drops the post's
  social proof and sends it back to review. If that is the situation, hand the sized file
  to whoever runs the account to drop into the placement panel.
- Say in the handoff that a native export from the designer is still ideal (the copy can
  use the full height) and offer to request it.

**The adaptation that worked: blurred cover fill + centred sharp original.**

- Canvas at the target ratio.
- Background: the same artwork scaled to *cover* the canvas, Gaussian blur around 48,
  darkened to about 0.45 brightness. It reads as one continuous branded frame.
- Foreground: the sharp original fitted inside, centred, with the edges that meet the
  fill feathered (about 48 px) so there is no seam.
- Logo, headline and CTA must sit inside the placement's safe zone.

```python
from PIL import Image, ImageEnhance, ImageFilter

def adapt(src_path, out_path, target=(1080, 1920), blur=48, darken=0.45, feather=48):
    src = Image.open(src_path).convert("RGB")
    W, H = target
    # background: cover-scaled, blurred, darkened
    s = max(W / src.width, H / src.height)
    bg = src.resize((round(src.width * s), round(src.height * s)), Image.LANCZOS)
    x0, y0 = (bg.width - W) // 2, (bg.height - H) // 2
    bg = bg.crop((x0, y0, x0 + W, y0 + H)).filter(ImageFilter.GaussianBlur(blur))
    bg = ImageEnhance.Brightness(bg).enhance(darken)
    # foreground: contain-scaled, centred, feathered where it meets the fill
    f = min(W / src.width, H / src.height)
    fg = src.resize((round(src.width * f), round(src.height * f)), Image.LANCZOS)
    mask = Image.new("L", fg.size, 255)
    vertical = fg.height < H              # fill above and below -> feather top and bottom
    for k in range(feather):
        a = round(255 * k / feather)
        if vertical:
            mask.paste(a, (0, k, fg.width, k + 1))
            mask.paste(a, (0, fg.height - 1 - k, fg.width, fg.height - k))
        else:
            mask.paste(a, (k, 0, k + 1, fg.height))
            mask.paste(a, (fg.width - 1 - k, 0, fg.width - k, fg.height))
    bg.paste(fg, ((W - fg.width) // 2, (H - fg.height) // 2), mask)
    bg.save(out_path, quality=92)

adapt("<square-artwork>.png", "<artwork>-9x16.jpg", target=(1080, 1920))
adapt("<square-artwork>.png", "<artwork>-4x5.jpg", target=(1080, 1350))
```

**When the scene itself should continue** (a photo rather than a designed graphic),
outpaint instead: an edit model with `aspect_ratio: "9:16"` and "keep the original image
intact and continue the scene above and below". Check faces and any printed text after.

For video, the same principle holds: recompose for 9:16 (blurred fill behind, the
important crop enlarged), never letterbox.

## 2. Resizing a flattened poster: rebuild it in HTML

"Make this poster 1080x1080, same text and design" is not a generative job. Models
butcher right-to-left and non-Latin text. Rebuild the poster as an HTML/CSS document and
render it with headless Chrome: pixel-accurate text, full layout control, and every
later size is a CSS change.

- Fonts from Google Fonts and icons from a cdnjs-hosted icon set both load fine in
  headless Chrome. Sample brand colours from the source image; take the logo from the
  brand assets, not from the poster.
- Render at 2x and downscale with Pillow for crisp text:

```bash
"$CHROME" --headless=new --disable-gpu --hide-scrollbars \
  --force-device-scale-factor=2 --window-size=1080,1080 \
  --virtual-time-budget=12000 --run-all-compositor-stages-before-draw \
  --user-data-dir="$(mktemp -d)" \
  --screenshot=out_2x.png "file://$PWD/poster.html"
```

```python
from PIL import Image
Image.open("out_2x.png").resize((1080, 1080), Image.LANCZOS).save("poster-1080.png")
```

- `--virtual-time-budget` is what gives webfonts and icon fonts time to load before
  capture. Without it the screenshot has fallback fonts.
- Use a fresh `--user-data-dir` on every run, or Chrome dies on a profile lock.
- A shared browser-automation session is often already in use by another task; plain
  headless Chrome is the reliable fallback.
- In an RTL document a flex row of icons renders right to left and reverses the visual
  order. Put `direction: ltr` on that strip to keep the source order.
- **Photo with baked-in text or a translucent panel that no crop avoids:** remove it with
  an edit model (prompt in [prompt-rules.md](prompt-rules.md#9-edit-prompts-that-worked)),
  generate 2, pick one, and check that small printed text on the subject survived.

For large print-size pages, headless Chrome can be killed for memory and leave a stale
PNG; [art-directed-graphic](../art-directed-graphic/SKILL.md) covers the retry.

## 3. Print

- Build at the exact trim plus bleed at 300 DPI. Example: 159 x 215 mm net with 1 mm bleed
  all round is 161 x 217 mm, which is 1902 x 2563 px.
- Art runs full bleed; logos and contact details go on an opaque footer band.
- Save the PNG with `dpi=(300, 300)` and the PDF with `resolution=300`.
- Revision rounds (headline changes, a second language) are a local recomposite: minutes,
  no generation cost.
- Send the client ONE cleanly named PDF, without version numbers in the name.

## 4. Article covers

- Every article gets a cover. Blog cards without one show grey placeholders and the
  whole blog looks broken.
- Recipe: flat-vector illustration, the client palette as hex values, "no writing of any
  kind", wide landscape. Inspect every cover for pseudo-text and regenerate if any
  appears; do not assume the prompt held.
- The fallback provider returns 1536x1024 (as of 2026). Centre-crop to 16:9 (1536x864) before export,
  and look for English UI words on screens and devices.
- Export:
  - WebP, quality around 82, 1024x576, target under 100 KB, named `<article-slug>-cover.webp`
  - if the host cannot serve WebP: JPEG quality around 84 at 1280x720
- Set it as the featured image at publish time, with alt text (below).

## 5. Alt text and image title

- **Alt carries the focus keyword.** For an article cover, the alt is the focus keyword
  phrase. For an in-body or hero image, open with the keyword, then a short visual
  description.
- **The image title describes the image and never repeats the keyword.** The title is a
  hover tooltip that search engines give little weight, so a keyword placed there instead
  of the alt is wasted.

| Field | Example for the keyword "garden irrigation planner" |
|---|---|
| alt (in-body) | garden irrigation planner: a gardener checks the watering schedule on a tablet beside raised vegetable beds |
| title | Gardener checking a tablet beside raised vegetable beds |
| alt (missing the keyword, wrong) | a gardener checks the watering schedule on a tablet |

- On WordPress, set both fields through the REST API (`POST /wp-json/wp/v2/media/<id>`
  with `alt_text` and `title`) at upload. Patch existing images that miss it the same way.
