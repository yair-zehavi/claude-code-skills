# Scroll-driven film hero

A website hero where scrolling drives a continuous first-person "walk-through" film of
the client's own spaces: real photos -> AI keyframes -> start and end-locked
image-to-video segments -> WebP frames painted on a canvas. Every step is agent-drivable.
Observed cost: about $7 for a 37 second film (October 2026).

## The bar

- A continuous POV camera move that the scroll scrubs, not a slideshow. A reference
  build of this kind ships around 450 WebP frames at 1920x1080, about 100 KB each, plus a
  separate mobile set, drawn to a canvas.
- Depth-parallax on still photos was rejected as "just close-ups". It also melts thin
  lattice structures (racking, railings, shelving) when the depth map is sharp; if you
  use it at all, blur the depth map heavily.

## Pipeline

### 1. Keyframes from the client's real photos

- Use an edit model conditioned on the client's REAL photos (`fal-ai/nano-banana-pro/edit`,
  `aspect_ratio: "16:9"`) so every space in the film is their own project, not a stock
  interior. Fallback chain: Nano Banana Pro edit -> Nano Banana edit. For a keyframe
  with no reference (an extreme close-up of an object): Nano Banana Pro text-to-image ->
  FLUX.2 pro.
- Start each keyframe prompt from the photo: "Keep this exact <space>, camera angle,
  <fixtures>. <What changes>." Then append one shared look string (lens, light, grade,
  16:9) to every keyframe so the film reads as one shoot.
- **Carry a hero object between scenes** by giving the previous keyframe as a second
  reference and naming the object ("the carton with the single strip of brand-coloured tape
  from the second image").
- **Generate the next keyframe FROM the previous one.** If two consecutive keyframes show
  different-looking rooms, the clip between them morphs mid-glide.
- **To move the camera past an object,** reference the original photo, not the keyframe
  that contains the object. Edit models copy foreground objects from the reference.
- Cache every keyframe on disk and skip existing ones; generate independent keyframes
  in parallel.

### 2. Segments between consecutive keyframes

Each segment is locked to keyframe N as its start and N+1 as its end, so the joins are
exact. Generate all segments in parallel.

| Model (as of 2026) | Fields | Durations |
|---|---|---|
| `fal-ai/kling-video/v3/pro/image-to-video` | `start_image_url`, `end_image_url`, `duration`, `generate_audio: false` | 3-15 s |
| `fal-ai/veo3.1/fast/first-last-frame-to-video` | `first_frame_url`, `last_frame_url`, `duration` ("4s"/"6s"/"8s"), `resolution: "1080p"`, `aspect_ratio: "16:9"`, `generate_audio: false` | 4, 6, 8 s |

- Prompt only the camera path and where it arrives ("The camera pushes in smoothly
  toward the carton until the cardboard and the tape fill the frame"), plus a short
  shared suffix: "Continuous single shot, smooth steady gimbal motion at walking pace,
  photorealistic, consistent lighting."
- Kling turned a push-in into a cross-dissolve; Veo did a real push-in. Render the hard
  moves (push-ins, crane rises, going through doorways) on both and pick.
- Use the queue API (submit, poll the status URL, fetch the response URL) with a timeout
  per segment, and skip segments already on disk.
- Send keyframes as JPEG data URIs (quality around 92) to keep payloads small.

### 3. Join and export frames

```bash
# per segment: normalise fps and size; drop the FIRST frame of every segment after the
# first, because it repeats the previous segment's last frame (otherwise: a stall)
ffmpeg -y -i S2.mp4 -an -vf "fps=15,trim=start_frame=1,setpts=PTS-STARTPTS,scale=1920:1080:flags=lanczos,setsar=1" \
  -c:v libx264 -preset slow -crf 14 -pix_fmt yuv420p build/S2.mp4

# concat: the list file needs ABSOLUTE paths
printf "file '%s'\n" "$PWD"/build/S1.mp4 "$PWD"/build/S2.mp4 > build/list.txt
ffmpeg -y -f concat -safe 0 -i build/list.txt -c copy build/film.mp4

# frames out as PNG; encode WebP with Pillow if your ffmpeg build lacks libwebp
ffmpeg -y -i build/film.mp4 -vf scale=1920:1080 build/png/%04d.png
```

```python
from PIL import Image
im = Image.open(png).convert("RGB")
im.save(desktop / f"{png.stem}.webp", "WEBP", quality=72, method=5)
# mobile: centre 9:16 slice of the 16:9 frame, then resize
im.crop((656, 0, 1264, 1080)).resize((720, 1280), Image.LANCZOS) \
  .save(mobile / f"{png.stem}.webp", "WEBP", quality=70, method=5)
```

At 15 fps, record each segment's frame range while joining and write a manifest:

```json
{"count": 555, "fps": 15, "segments": [{"seg": "S1", "from": 0, "to": 89}, {"seg": "S2", "from": 90, "to": 164}]}
```

Chapter copy is then pinned to `[segment, fraction]` positions, so re-cutting the film
only needs a new manifest, not new page code.

### 4. The page: a canvas frame painter

```html
<link rel="preload" as="image" href="frames/desktop/0001.webp" media="(min-width: 641px)">
<link rel="preload" as="image" href="frames/mobile/0001.webp" media="(max-width: 640px)">
<section id="journey" style="height:600vh">
  <div class="stage" style="position:sticky;top:0;height:100svh;
       background:#000 url(frames/desktop/0001.webp) center/cover no-repeat">
    <canvas id="film" style="width:100%;height:100%"></canvas>
    <!-- chapter copy as real HTML, never baked into the film -->
  </div>
</section>
```

```js
const journey = document.getElementById("journey");
const canvas = document.getElementById("film");
const ctx = canvas.getContext("2d");
const reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;
const isMobile = () => innerWidth <= 640;
let frames = [], N = 0, P = 0, drawn = -1, dirty = true;

const progress = () => {
  const r = journey.getBoundingClientRect();
  return Math.min(1, Math.max(0, -r.top / (r.height - innerHeight)));
};

function resize() {
  const dpr = Math.min(devicePixelRatio || 1, 2);          // cap DPR at 2
  canvas.width = Math.round(canvas.clientWidth * dpr);
  canvas.height = Math.round(canvas.clientHeight * dpr);
}

function loadFrames() {                                    // coarse-to-fine
  const dir = isMobile() ? "frames/mobile" : "frames/desktop";
  const order = [];
  for (const step of [16, 8, 4, 2, 1])
    for (let i = 0; i < N; i += step) if (!order.includes(i)) order.push(i);
  let next = 0, inflight = 0;
  const pump = () => {
    while (inflight < 8 && next < order.length) {
      const i = order[next++], im = new Image();
      inflight++;
      im.decoding = "async";
      im.onload = () => { im.ok = true; inflight--; dirty = true; pump(); };
      im.onerror = () => { inflight--; pump(); };
      im.src = `${dir}/${String(i + 1).padStart(4, "0")}.webp`;
      frames[i] = im;
    }
  };
  pump();
}

function draw(i) {                                         // nearest loaded frame, cover-fit
  let img = null;
  for (let d = 0; d < N && !img; d++)
    img = frames[i - d]?.ok ? frames[i - d] : frames[i + d]?.ok ? frames[i + d] : null;
  if (!img) return false;
  const s = Math.max(canvas.width / img.naturalWidth, canvas.height / img.naturalHeight);
  const w = img.naturalWidth * s, h = img.naturalHeight * s;
  ctx.drawImage(img, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
  return true;
}

function tick() {
  const target = progress();
  P += (target - P) * 0.12;                                // eased scrub
  const i = Math.round(P * (N - 1));
  if (journey.getBoundingClientRect().bottom > 0 && (i !== drawn || dirty))
    if (draw(i)) { drawn = i; dirty = false; }
  requestAnimationFrame(tick);
}

fetch("frames/manifest.json").then(r => r.json()).then(man => {
  N = man.count;
  if (reduce || !ctx) return document.documentElement.classList.add("static");
  resize();
  addEventListener("resize", () => { resize(); dirty = true; });
  loadFrames();
  requestAnimationFrame(tick);
}).catch(() => document.documentElement.classList.add("static"));
```

- The first frame doubles as the CSS background poster, so the hero is never black
  while frames load.
- `prefers-reduced-motion` and any manifest failure fall back to a static layout with the
  chapter copy stacked over stills.
- Readable text, logos and packaging stay as HTML overlays. AI text garbles in the film.

## Checks

- Look at each keyframe at full size against the source photo before paying for
  segments.
- Scrub every segment: identity of the space, no mid-glide morph, the move the prompt
  asked for (not a dissolve).
- Frame count on disk matches the manifest for both desktop and mobile sets; no stall at
  any segment join.
- Total payload: print the desktop and mobile set sizes at export and keep them in the
  job log.
- Preview on a static folder with `noindex`, purge the page cache, and check the bare URL
  before sharing it with the client.
