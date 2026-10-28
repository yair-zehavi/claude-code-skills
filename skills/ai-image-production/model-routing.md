# Model routing, benchmarks and costs

Everything here is as of mid to late 2026 and dated where it matters. Endpoint names
are fal.ai paths unless stated. Prices are list prices observed at the time of the run;
re-check before quoting them to anyone.

Two kinds of evidence, kept apart on purpose:

- **Own tests:** side-by-side runs on real jobs and internal tests, judged by a person
  who rejected outputs for concrete reasons.
- **Research consensus:** a web sweep of creator and vendor write-ups (August 2026).
  Community forums were not reachable, many sources are SEO content, and consistency
  percentages quoted there are marketing, not benchmarks. Use it to pick candidates,
  then test.

## 1. Photoreal people (own test, July 2026)

Job: a seasonal social graphic with a couple in it. FLUX 1.1 Pro Ultra
(`fal-ai/flux-pro/v1.1-ultra`) was rejected: "humans don't look real enough", skin
reads waxy. Ranked replacements, all tested the same day:

| Rank | Endpoint | Character | Size parameter |
|---|---|---|---|
| 1 | `fal-ai/bytedance/seedream/v4/text-to-image` | Most editorial and photographic: real skin, freckles, backlit hair | `image_size: {width, height}` |
| 2 | `fal-ai/nano-banana` (Gemini 2.5 Flash Image) | Warmest, most romantic, film look | `aspect_ratio: "3:4"` |
| 3 | `fal-ai/imagen4/preview` (Imagen 4) | Clean and bright, real but more "stock" | `aspect_ratio` |

Also learned: FLUX rendered plants huge relative to the people. State realistic
proportions explicitly. Prompt cues are in [prompt-rules.md](prompt-rules.md#5-photoreal-people).

## 2. Product consistency: LoRA vs reference editing (own test, August 2026)

Job: reproduce one physical product (a glass bottle with a cap, a printed pattern and a
text label) consistently across many generated assets with people and scenes. Dataset:
23 phone photos shot per [asset-replication.md](asset-replication.md#shooting-the-references).

| Approach | Product fidelity | People and scene | Label text |
|---|---|---|---|
| Flux.1-dev LoRA | Best of the LoRAs: kept the real cap, clear liquid, exact print | Waxy, glossy skin | Wrong |
| Qwen-Image LoRA | Simplified the print, wrong liquid colour | Good people | Hallucinated |
| FLUX.2-dev LoRA | Improvised and over-embellished; cap right in one shot, wrong in the next | Best: magazine-grade | Hallucinated |
| Nano Banana Pro edit, 3 real photos, no training | Best overall: real cap, clear glass, exact geometric print and floral | Matched FLUX.2 | Rendered the REAL label text every LoRA got wrong |

**Core finding (in this benchmark):** the stronger the base model, the better the people and the scene, and
the worse the object fidelity. The text-strong models (Qwen, FLUX.2) lettered text onto
the bottle that was never on it: the LoRA trigger word, and even a famous fashion
house's name that had appeared in a prompt.

**Why reference editing wins here:** it copies pixels from the real photo instead of
reconstructing the object from learned weights. It became the default for
product-in-scene after this test.

**Hybrid for maximum beauty plus exact product:** let FLUX.2 (or Nano Banana Pro) make
the person, scene and light, then inpaint or composite the accurate product (a Flux.1
render or the real product photo) through an edit model.

## 3. When a LoRA is still worth training

Reference editing covers most commercial jobs (the research sweep put it at roughly 95
percent). Train only for:

- many assets (hundreds) of one identity, or novel poses and angles the reference photos
  do not cover
- brand IP the base model has never seen and cannot approximate
- a rights-owned recurring likeness at maximum consistency, or when you must own the weights
- frame-perfect serialized content
- a niche style the base cannot prompt into
- tight full-body, profile or hands control
- a fully local, no-API requirement

Decision table:

| Situation | Do this |
|---|---|
| Product or person into a scene, one-off or short batch | Reference edit (Nano Banana Pro), no training |
| A style (editorial, golden hour) | The base knows it, just prompt |
| Recurring face, a few hero shots | Nano Banana Pro from 3-6 reference angles |
| Recurring face across dozens to hundreds of assets | Hybrid stack (below) |
| Unseen brand IP, owned likeness, frame-perfect series | Train |

**To lock an object inside a LoRA** (when you do train): strip captions down to the bare
`<trigger> <product type>`, retrain around 1800 steps, and generate at LoRA scale around
1.3. That trades scene quality for fidelity. On the Flux.1 trainer, `create_masks: true`
down-weights the background.

**Trainers and inference endpoints (August 2026):**

| Family | Train | Infer | Notes |
|---|---|---|---|
| Flux.1-dev | `fal-ai/flux-lora-fast-training`, about $2 per run, scales with steps | `fal-ai/flux-lora`, $0.035/MP | Lead the prompt with the trigger; `lora_scale` 0.9-1.1 |
| Qwen-Image | `fal-ai/qwen-image-trainer` (`image_data_url`, `steps`, `trigger_phrase`), about $0.002/step, minimum 250 | `fal-ai/qwen-image`, `loras` path + scale 0-4, $0.02/MP | |
| FLUX.2-dev | `fal-ai/flux-2-trainer` (`image_data_url`, `steps`, `default_caption`) | `fal-ai/flux-2/lora`, `loras` path + scale 0-2, $0.021/MP | |
| Flux Pro finetune | Standalone trainer gone ("Application not found") | Finetuned inference endpoints still answer, but there is no public way to mint a `finetune_id` | Do not plan on it |

**Human consistency at volume (research consensus):** reference models drift at
profile, back and extreme angles, with intense expressions, along long edit chains and
in multi-character scenes, and trend toward a smooth "same face". Studios that need
volume run a hybrid: a reference-built multi-angle character sheet as the identity
anchor -> a face-lock adapter or a light LoRA (or a hosted identity service) to hold it
at volume -> a face-swap pass as a backstop for drift. Counter the smooth-skin drift with
explicit skin-texture prompting.

## 4. Other own-test results

- **Seasonal art finals:** FLUX 1.1 Pro Ultra at $0.06 per image produced client-ready
  art. Recraft V3 ($0.04) rendered garbled fake text and ignored reserved negative space.
  No LoRA was needed for multi-style seasonal work; revisit only if one signature style
  becomes a recurring monthly ask.
- **Object miscount:** FLUX miscounted objects. A FLUX Kontext max edit ("after the edit
  there must be exactly N ...") followed by a 4x upscale (AuraSR) fixed it faster than
  rerolling.
- **Flat-vector article covers:** `fal-ai/flux/dev` at `landscape_16_9` with the client
  palette in the prompt. Pseudo-text still appears sometimes; inspect every cover.
- **General default:** FLUX.2 pro (`fal-ai/flux-2-pro`), about $0.03/MP (about $0.045
  for a 1080p image).
- **Image-to-video from real photos (September 2026):** Kling v3 is the working pick;
  the provider documents it as preserving identity, layout and text details, and it held
  up on a real-photo reel once the prompts described only the change. Seedance 2.0 rates
  higher on leaderboards but costs about 3x Kling v3 pro; not tested side by side.
- **Start and end-locked moves (October 2026):** Kling turned a push-in into a
  cross-dissolve; Veo 3.1 fast did a real push-in. Render the hard moves on both.

## 5. Research consensus: best model by task (August 2026, second-hand)

| Job | Pick | Backup |
|---|---|---|
| Photoreal product | FLUX.2 Pro, Seedream 4.5 | GPT Image 2 (labels), Nano Banana Pro (material realism) |
| People, beauty | Nano Banana Pro, Imagen 4 Ultra | FLUX.2 (texture) |
| Editorial, aesthetic | Midjourney V7/V8 | Nano Banana Pro |
| Text in image | GPT Image 2, Nano Banana Pro | Ideogram 3.0, Qwen (open) |
| Editing, compositing | Nano Banana Pro, GPT Image 2 | Seedream 5 |
| Upscale, finish | Magnific, Topaz | clarity-upscaler, SUPIR |

The pattern professionals describe: generate on a frontier model -> fix with a chat or
mask edit (never regenerate for a local defect) -> upscale -> finish in an image editor
-> automate the repeatable parts. IP-Adapter style conditioning has been replaced by
natural-language reference editing; ControlNet survives for structure (pose, depth,
line art).

## 6. Endpoint parameter cheatsheet (as of 2026)

| Endpoint | Key parameters | Gotcha |
|---|---|---|
| `fal-ai/nano-banana-pro/edit` | `prompt`, `image_urls` (up to 14), `num_images`, `output_format`, `aspect_ratio`, `resolution` (1K/2K/4K) | 4K costs 2x. No pixel-size field; ask for the format in the prompt or via `aspect_ratio` |
| `fal-ai/nano-banana-2/edit` | as above | Cheaper and faster than Pro |
| `fal-ai/nano-banana/edit`, `fal-ai/nano-banana` | `prompt`, `image_urls`, `aspect_ratio` | Ignores `image_size` |
| `fal-ai/flux-2/edit` | `prompt`, `image_urls` (max 4), `image_size` (preset or `{width, height}`), `num_inference_steps` | Trim references to 4 |
| `fal-ai/flux-pro/v1.1-ultra` | `prompt`, `aspect_ratio` | Ignores `image_size` |
| `fal-ai/bytedance/seedream/v4/text-to-image` | `image_size: {width, height}` or presets such as `auto_2K` | |
| `fal-ai/clarity-upscaler` | `image_url`, `upscale_factor`, `creativity`, `resemblance` | Use low creativity (0.2) and resemblance 1.0 so it does not invent detail on a product |
| `fal-ai/kling-video/v3/pro/image-to-video` | `start_image_url`, `end_image_url`, `duration` (3-15 s), `generate_audio`, `cfg_scale` (default 0.5), `multi_prompt`, `elements` | Not `image_url`. Aspect ratio comes from the start image; the `aspect_ratio` field is ignored |
| `fal-ai/veo3.1/fast/first-last-frame-to-video` | `first_frame_url`, `last_frame_url`, `duration` ("4s"/"6s"/"8s"), `resolution: "1080p"`, `aspect_ratio`, `generate_audio` | Different field names from Kling |
| OpenAI `gpt-image-1` (fallback) | `model`, `prompt`, `size` (1024x1024, 1536x1024, 1024x1536), `quality`, `n` | Fixed sizes; crop to the target ratio. Returns base64 |

Transport notes:

- Short image calls can go to the synchronous endpoint (`https://fal.run/<model>`).
  Video jobs run for minutes: submit to the queue (`https://queue.fal.run/<model>`), poll the
  returned `status_url`, then fetch `response_url`.
- Local files go in as base64 data URIs. A payload with several data URIs is too big for
  a command-line argument; write it to a temp file and post the file.
- High-resolution generations can take a minute or more. Make batch scripts resumable.

## 7. Cost per approved final

Measure the job, not the call: total generation spend divided by the images the client
approved. Rerolls, rejected directions and test renders all count. Observed figures:

| Job | Observed |
|---|---|
| Two client-approved seasonal social graphics | about $0.22 in total: 3 FLUX 1.1 Pro Ultra ($0.06 each) + 1 Recraft V3 ($0.04). Four rounds of typography revisions cost $0 because they ran locally |
| Product-in-scene via reference editing | $0.15 per image on Nano Banana Pro edit list price (4K doubles it), no training cost |
| Product via LoRA | about $2 per training run plus the dataset shoot, then $0.02-0.035/MP per image. In the benchmark, all three LoRAs lost to reference editing on fidelity |
| Scroll-driven film hero, 37 s | about $7 for keyframes plus start/end-locked segments |
| Image-to-video | Kling v3 pro $0.112/s with audio off, standard $0.084/s, 4K $0.42/s; Seedance 2.0 about $0.30/s; Wan 2.2 $0.04-0.08/s |
| Article cover on the fallback | list estimate $0.04-0.17 per image depending on size and quality |

The lever that moves this number most is not the model price. It is keeping text and
logos out of the generation so revisions cost nothing.

## 8. Compliance

- Training on a real person's likeness needs their consent.
- Some models (Nano Banana) embed an invisible SynthID watermark, and some providers
  attach a signed C2PA manifest. EU AI Act AI-content labeling applies from August 2026.
- Keep C2PA and provenance metadata through the finish, everywhere and especially for EU
  delivery. Do not strip it to avoid a platform's "AI info" label; settle the disclosure
  question with the account owner instead.
- A Pillow re-save or a headless Chrome screenshot writes a new file without the
  original's metadata. If provenance has to survive local compositing, re-attach it at
  the final export (the open-source C2PA tooling can record the generated original as an
  ingredient).
