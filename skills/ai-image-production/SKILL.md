---
name: ai-image-production
description: >
  Produce marketing images and short image-to-video clips with generative models
  so they stay consistent, cheap and specific. Covers routing each job to the model
  family that wins that task, keeping a real product faithful with reference editing
  (and when a LoRA is still worth training), a manifest-driven LOCK -> SIGNATURE ->
  REPLICATE pipeline for one subject across many scenes and formats, positive
  prompting, generate-once-composite-locally, automatic provider fallback, the
  scroll-driven film hero built from real photos, and adapting one artwork to every
  ad placement ratio. Use when generating product shots, people or lifestyle images,
  article covers, ad creatives in several ratios, image-to-video clips or a
  scroll-scrubbed website hero, or when outputs drift, mangle a label, look plastic,
  or cost too much per approved image. Not for UI or web page design, not for
  medium-first art direction of cards, posters and greeting graphics (that is
  art-directed-graphic), and not for editing recorded video footage.
---

# AI image production

## Why this exists

Generative models are cheap per call and expensive per approved image. The money and
the client's patience leak through the same few holes every time: the wrong model for
the task (faces that read as plastic, a product label rebuilt from memory and
misspelled), prompts that summon the very thing they try to exclude, text baked into
the art so every copy change means a new generation, a provider outage that ships a
page without its image, and one square artwork letterboxed into five placements. This
skill is the routing, consistency, pipeline and cost discipline that closes those
holes. For what a designed graphic should look like (medium, surface, palette,
layout), use [art-directed-graphic](../art-directed-graphic/SKILL.md). This skill
decides which model makes it, how it stays consistent and what it costs.

Model names, endpoints, parameters and prices below are as of 2026. They change fast;
re-check the provider docs before trusting a parameter name.

## Non-negotiable rules

1. **Route by task.** No single model wins. Pick from the routing table, not from habit.
2. **A real subject comes from real photos.** When a specific product or person must
   appear, condition a reference-editing model on 2-4 real photos. Never let a model
   reconstruct a label, logo or face from a text description.
3. **Describe what is there, never what isn't.** Naming an unwanted thing, even inside
   "no ...", puts it in the frame. The one tolerated negative is "no writing of any kind".
4. **Generate clean, composite locally.** No headline, logo, price or UI copy in the
   generation. Add them afterwards: HTML + headless Chrome for typography, Pillow for
   raster operations. Copy revisions must cost nothing.
5. **Never put a brand name you do not own in a prompt.** Text-strong models letter it
   onto the product. They do the same with a LoRA trigger word, so check for it.
6. **Never skip an image because a provider failed.** Fall back automatically to a second
   provider with the same output contract (same filenames, same sizes after crop).
7. **Image-to-video prompts describe only what changes:** motion, camera, and how it ends.
8. **One master, many formats.** Adapt the approved artwork to each ratio. Never ship
   black letterbox bars, never regenerate per placement.
9. **Look at every output at full size** before calling it done. Correct dimensions and
   file sizes are necessary, not sufficient.
10. **Count cost per approved final**, not per call. Log it per job.
11. **Show the client two or three options, never five.**
12. **Alt text carries the focus keyword; the image title never repeats it.**

## Routing by task (as of 2026)

"Own test" rows come from side-by-side runs on real jobs. "Research" rows are a
second-hand consensus from vendor and creator write-ups; treat them as a starting
point, not a benchmark. Detail and parameters: [model-routing.md](model-routing.md).

| Task | Route to | Avoid | Basis |
|---|---|---|---|
| Photoreal people, faces | Seedream 4 text-to-image (most editorial), Nano Banana (warm film look), Imagen 4 (clean, more stock) | FLUX 1.1 Pro Ultra: faces read waxy | own test |
| A real product in a new scene | Nano Banana Pro edit with 2-4 real photos, no training | LoRA on a text-strong base (FLUX.2, Qwen): improvises the product, invents label text | own test |
| One subject across many scenes and formats | LOCK -> SIGNATURE -> REPLICATE pipeline ([asset-replication.md](asset-replication.md)) | Prompting each asset from scratch | own test |
| Premium hero with a different aesthetic | FLUX.2 edit with the locked master as reference | A FLUX.2 LoRA for the product (improvises it) | own test, experimental |
| Model + scene beauty AND exact product | Hybrid: strong model for person and scene, then edit or composite the real product in | Asking one LoRA to do both | derived from own test |
| Seasonal and social art (no people, no specific product) | FLUX 1.1 Pro Ultra finals, art direction per art-directed-graphic | Recraft V3: garbled pseudo-text, ignores reserved space | own test |
| Flat-vector article covers in a series | FLUX dev or FLUX.2 pro with the brand palette as hex values in the prompt | Prompts without a palette (series looks random) | own test |
| Wrong object count in an otherwise good image | FLUX Kontext max edit ("after the edit there must be exactly N ...") + a 4x upscaler | Rerolling the whole image | own test |
| Remove baked-in text or overlay panel from a flattened photo | Nano Banana edit, n=2, pick the best | Cropping (rarely clears a full-width panel) | own test |
| Landscape still -> 9:16 with real scene continuation | Nano Banana edit with `aspect_ratio: "9:16"`, keep original intact, extend above and below | Centre-cropping (loses the subject) | own test |
| Animate a real photo | Kling v3 pro image-to-video (preserves identity, layout, text) | Seedance 2.0 for routine work: rated higher, about 3x the price | own use + vendor docs |
| Start and end-locked camera moves | Kling v3 pro and Veo 3.1 fast first-last-frame; render hard moves on both, pick | Trusting one model on push-ins | own test |
| Primary provider down or locked | OpenAI gpt-image-1, automatic | Logging a blocker and publishing without the image | own incident |
| Text that must live inside the image | Composite it locally. If it truly must be generated: GPT Image 2, Nano Banana Pro, Ideogram | Generating non-Latin or right-to-left text in-model (garbled in own tests) | research + own test |
| Recurring human face, hundreds of assets | Hybrid: reference character sheet -> face-lock adapter or light LoRA -> face-swap backstop | Reference editing alone at extreme angles and long chains | research |

## Procedure

1. **Sort the job** against the routing table. If it is a designed graphic (card,
   poster, holiday post), run art-directed-graphic for the medium and layout, and use
   this skill for model choice, fallback and cost.
2. **Collect inputs before prompting:**
   - real photos of any specific subject from 2-4 different angles (Nano Banana Pro
     accepts up to 14). If they are glary, soft or all one angle, reshoot per
     [asset-replication.md](asset-replication.md#shooting-the-references)
   - brand palette as hex values
   - the full list of placements with exact pixel sizes (see
     [formats-and-placements.md](formats-and-placements.md))
   - the page's focus keyword, if the image goes on a web page
3. **Write the prompt** per [prompt-rules.md](prompt-rules.md):
   - positive, concrete objects; counts and geometry spelled out; realistic proportions
   - the subject described once, in a variable reused across every prompt in the set
   - one shared "look" string (lens, light, grade) appended to every prompt in a set
   - reserved empty space where type will go, and "no writing of any kind"
4. **Generate small.** n=2 per prompt through a wrapper that has the fallback built in
   (below). Make it resumable: skip outputs that already exist, so a timeout halfway
   through a batch does not repay for finished images. Keep prompts and parameters in a
   job file next to the outputs.
5. **Inspect every output at full size** (Verification below). For a local defect
   (count, a stray object, baked text), run a targeted edit; do not regenerate the whole
   image.
6. **Composite** headline, logo and CTA locally. Build every format from the one
   approved master: placement adaptation, outpaint, or crop.
7. **Export per placement:** exact dimensions, format and byte budget, alt text and
   title. For print, exact trim + bleed at 300 DPI.
8. **Log the job:** approved finals, total generation spend, cost per final, what was
   rejected and why. The rejection reasons become next job's prompt rules.

### Provider fallback shape

```python
def generate(prompt, out_prefix, aspect="16:9", n=2):
    try:
        images = primary_generate(prompt, aspect=aspect, n=n)    # e.g. fal, $FAL_KEY
        if not images:
            raise RuntimeError("empty result")
    except Exception as err:                                      # lock, outage, timeout, bad JSON
        log(f"primary failed: {err}; falling back")
        size = {"1:1": "1024x1024"}.get(aspect, "1536x1024" if is_landscape(aspect) else "1024x1536")
        images = fallback_generate(prompt, model="gpt-image-1", size=size, n=n)  # $OPENAI_API_KEY
    for i, img in enumerate(images, 1):
        save(img, f"{out_prefix}.{i}.png")                        # same names either way
```

- Model-specific options (seed, LoRA, `image_size` presets) are dropped on the fallback
  path. Anything downstream must not care which provider answered.
- The fallback returns fixed sizes (1536x1024 for landscape). Crop to the target ratio
  before export.
- Edit and keyframe jobs chain the same way inside a provider: Nano Banana Pro edit ->
  Nano Banana edit; text-to-image Nano Banana Pro -> FLUX.2 pro.
- Pass API keys through the environment or a config file on stdin. Never interpolate a
  key into a command string: it lands in shell history, process lists and error output.

## Verification

Do every check by opening the file and looking at it, not by inferring from the run log.

- **Full-size look at every output.** Faces, hands, edges, the reserved type zone.
  Downscaled contact sheets hide exactly the defects clients see.
- **Subject fidelity against the reference photo**, side by side: shape, cap or
  closure, material (clear vs tinted), print pattern, and every letter of the label.
  One wrong letter fails the image.
- **No unwanted text:** pseudo-letters, gibberish, a competitor's name, UI words on
  screens (the fallback model has added "PAY"-style labels when a prompt mentioned
  payments), the LoRA trigger word.
- **Counts, geometry and scale:** the number of objects asked for, the shape of
  specific objects, people vs props proportions.
- **Dimensions and bytes per placement:** read the actual pixel size of each exported
  file and compare with the placement list. Check the byte budget (for example under
  100 KB for a blog cover).
- **Safe zones:** logo, headline and CTA inside each placement's safe area after
  adaptation.
- **Image-to-video:** compare the first and last frame for identity drift; scrub the
  middle for morphing; check that the motion ends rather than floats.
- **Scroll film:** frame count matches the manifest, the mobile set exists, segment
  joins have no stall, and the reduced-motion fallback renders.
- **Web images:** alt text set with the keyword, title without it, file actually served
  at the live URL after cache purge.

## Gotchas (symptom -> cause -> fix)

- **"No X" in the prompt, X in every image** -> diffusion models attend to the tokens
  regardless of the negation -> delete every mention of X and describe what fills that
  space instead.
- **A seasonal table comes back decorated for the wrong occasion** -> generic occasion
  words ("holiday dinner") carry the most common occasion's prior -> name the actual
  objects on the table or the actual occasion.
- **Asked for two plain candlesticks, got an ornate candelabrum** -> models drift on
  specific objects -> spell out geometry and count ("two separate single candlesticks
  side by side, plain and worn").
- **Faces read plastic** -> model choice plus glossy studio lighting and heavy filters ->
  route to Seedream 4, Nano Banana or Imagen 4; prompt candid documentary, real skin
  texture, unretouched, 50mm full-frame; drop fairy lights, heavy grade and vignette.
- **Product label misspelled or replaced** -> a LoRA or text-to-image model rebuilt it
  from weights -> reference-edit from real photos, which copies the pixels.
- **A famous brand's name appears on the client's product** -> it was in the prompt
  ("a bottle like ...") -> never name it; describe shape and material.
- **Logo composited onto an AI-generated object looks like a sticker** -> soft AI texture,
  mismatched light, skewed perspective -> keep generated objects unbranded; for a
  branded object, start from a real photo of the real object.
- **Requested size ignored** -> some endpoints (Nano Banana, FLUX 1.1 Pro Ultra) ignore
  `image_size` -> pass `aspect_ratio`; Nano Banana Pro also takes `resolution`.
- **Image-to-video ignores the input image** -> Kling v3 expects `start_image_url`, not
  `image_url`; aspect ratio comes from the start image -> use the right field per model.
- **Faces morph and logos garble in the clip** -> the prompt restated what is already in
  the frame -> describe only the change; shorter clips; "subtle", "very slow".
- **Reserved type space filled anyway** -> model ignored the composition note -> shift
  the art in post (mirror-fill the vacated edge) before paying for a regenerate; for a
  painted plane in the zone, use art-directed-graphic's composition rule.
- **Primary provider locked on a billing top-up, page published without an image** ->
  the job logged a blocker instead of falling back -> automatic fallback, always.
- **Story placement shows black bars** -> only a square was supplied and automatic
  placement adaptation was off -> build the blurred-cover adaptation at ad creation.
- **Reference editing drifts at profile, back or extreme angles** -> the references do
  not cover them -> add those angles; at volume, a face-lock adapter or a LoRA.

More gotchas live in each reference file.

## When to stop and ask a human

- The client wants their logo on a generated object (a mug, a tote bag, a shop sign). Say up front it
  will read pasted-on; offer real photography or an unbranded generated scene.
- Reference editing still gets the label wrong after a corrected prompt and a full
  reference set. The fix is better photos or a real product shot, not more rerolls.
- A real person's likeness is involved. Training on someone's likeness needs their
  consent; settle that before generating.
- A LoRA looks necessary. Training costs a dataset shoot plus training runs; confirm the
  volume or the novel angles justify it first.
- Delivery is in the EU or a platform requires AI disclosure. Some models embed an
  invisible watermark and AI-content labeling rules apply. Keep provenance metadata
  (C2PA) through the finish in every case, never strip it to avoid a platform's AI
  label, and confirm the labeling with the account owner.
- An ad is a boosted existing post and one placement needs a new ratio. Rebuilding the
  live creative through the API drops its social proof and forces re-review; hand the
  sized file to the person who runs the account instead.
- Both providers fail. Stop and report; do not publish the page without its image.
- A placement adaptation crops the copy. A native export from the designer is better
  (text can use the full height); offer to request it.

## Reference files

- [model-routing.md](model-routing.md): own benchmarks, LoRA vs reference editing, when a
  LoRA is still worth it, endpoint parameter cheatsheet, observed costs.
- [prompt-rules.md](prompt-rules.md): positive description, object and scene rules,
  photoreal people, product prompts, image-to-video prompts, edit prompts.
- [asset-replication.md](asset-replication.md): shooting the references, the
  LOCK -> SIGNATURE -> REPLICATE pipeline, manifest schema, runner sketch.
- [scroll-film-hero.md](scroll-film-hero.md): real photos -> keyframes -> start/end-locked
  video -> WebP frames on a canvas, with the frame painter.
- [formats-and-placements.md](formats-and-placements.md): blurred-cover placement
  adaptation, poster resizing via HTML + headless Chrome, print sizing, cover export,
  alt text.
