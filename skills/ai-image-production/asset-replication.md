# Asset replication: LOCK -> SIGNATURE -> REPLICATE

A manifest-driven pipeline that turns real photos of ONE subject (a product or a
person) into many subject-consistent assets across scenes and formats, without training
a LoRA. Validated in August 2026 on a glass bottle with a printed text label: the label
stayed legible and identical through every stage.

Use it when one subject must appear in more than a handful of assets (a launch set, a
campaign in several placements, a product page plus social). For a single image, one
reference edit is enough.

## Shooting the references

The source photos set the quality ceiling. Glare, softness and one repeated angle
produce mush that no model fixes.

- **Camera:** a recent phone at full resolution (48 MP class), standard photographic
  style. Shoot 4:3 and crop to square later; never shoot 1:1 in camera.
- **Light:** one continuous key light at about 5000-5600 K, locked, diffused through a
  bounce or a sheet. Glass and glossy packaging throw glare that hides the label.
- **Focus:** lock focus and exposure on the label. Tack-sharp beats high resolution.
- **Coverage, rigid product:** 20-35 frames. Vary everything except the subject: angle
  (front, both three-quarters, profile, high, low, a macro of the label and the cap),
  background (neutral, wood, stone, dark, light, never the same clutter twice) and
  light direction. Keep the product state identical in every frame (cap on, same fill
  level, same label).
- **Coverage, person:** a turnaround (front, three-quarter left and right, profile,
  back) plus an expression, a full-body and a detail crop, all under consistent light.
- **Prep (needed for LoRA training, harmless otherwise):** bake the EXIF rotation into
  the pixels (trainers ignore EXIF), convert to sRGB, cap the long edge around 2048 px,
  name files sequentially, one `.txt` caption per image. Drop any soft or glary frame.

For the replication pipeline, pick the best 2-4 of these, covering different angles.

## The three stages

1. **LOCK** (Nano Banana Pro edit, $0.15 per image as of 2026): real photos -> one
   canonical **master** of the subject on a plain neutral background. This is the
   reliable anchor every later stage references.
2. **SIGNATURE** (optional, FLUX.2 edit): the master goes into a *different* model for
   its aesthetic, producing a premium hero. Because it inherits the locked subject, it
   did not hallucinate the label in testing the way a FLUX.2 LoRA did. Experimental;
   check it for subject drift.
3. **REPLICATE** (per asset): re-anchor on the master plus the real references and
   render the subject into each scene and format. Two routes:
   - `nano`: maximum fidelity, because it copies pixels from the master. The format goes
     into the prompt ("square 1:1", "vertical 9:16 with empty space at top for text").
   - `flux2`: when you need an exact pixel size (`image_size: {width, height}`). Accepts
     at most 4 references.
   - Optional finish with `fal-ai/clarity-upscaler` at low creativity (0.2) and full
     resemblance (1.0), so the upscaler does not invent detail on the label.

Reference caps (as of 2026): Nano Banana Pro edit 14 images, FLUX.2 edit 4.

## Manifest

```json
{
  "subject": "amber glass dropper bottle with a white screw cap and a cream paper label printed in dark green",
  "refs": ["<abs-path>/ref-front.jpg", "<abs-path>/ref-three-quarter.jpg", "<abs-path>/ref-label-macro.jpg"],
  "out_dir": "<abs-path>/run-01",
  "lock": {
    "prompt": "Using the exact {subject} shown in the reference images (identical shape, materials, colours and printed label), produce one clean canonical product photo on a plain neutral light-grey seamless background, centred, soft even studio lighting, sharp focus. Keep the label exactly as shown."
  },
  "signature": {
    "enabled": true,
    "image_size": "portrait_4_3",
    "prompt": "Using the {subject} from the references (keep it identical), create a premium editorial hero image with dramatic directional lighting and rich shadow, shallow depth of field. Keep the product and its label exactly as shown."
  },
  "assets": [
    {"name": "marble-square", "model": "nano", "format": "square 1:1",
     "prompt": "Place the exact {subject} on a white marble surface with a soft shadow, bright airy still life, soft daylight. Keep the product and label identical."},
    {"name": "story-vertical", "model": "nano", "format": "vertical 9:16 with empty space at the top for a headline",
     "prompt": "Place the exact {subject} on warm sand at sunset, warm rim light, generous quiet sky above. Keep the product and label identical."},
    {"name": "banner-wide", "model": "flux2", "image_size": {"width": 1344, "height": 768}, "upscale": false,
     "prompt": "The exact {subject} on a dark stone surface, wide cinematic banner, dramatic side light, copy space on the right. Keep the product and label identical."}
  ]
}
```

- `subject` is written once and substituted into every prompt via `{subject}`.
- `refs`: 2-4 clean real photos; more angles give better consistency.
- Outputs: `00-master.jpg`, `01-signature.jpg`, `asset-NN-<name>.jpg`, and an
  `_outputs.json` summary listing every file.

## Runner sketch

Generic Python using the `fal-client` package, with the key in `$FAL_KEY`.

```python
import json, pathlib, sys, urllib.request
import fal_client  # reads FAL_KEY from the environment

def call(endpoint, args):
    r = fal_client.subscribe(endpoint, arguments=args)
    return r["images"][0]["url"] if "images" in r else r["image"]["url"]

def nano(prompt, refs):
    return call("fal-ai/nano-banana-pro/edit",
                {"prompt": prompt, "image_urls": refs[:14], "num_images": 1, "output_format": "jpeg"})

def flux2(prompt, refs, size):
    return call("fal-ai/flux-2/edit",
                {"prompt": prompt, "image_urls": refs[:4], "image_size": size,
                 "num_images": 1, "output_format": "jpeg"})

def upscale(url):
    return call("fal-ai/clarity-upscaler",
                {"image_url": url, "upscale_factor": 2, "creativity": 0.2, "resemblance": 1.0})

def run(manifest_path):
    m = json.loads(pathlib.Path(manifest_path).read_text())
    out = pathlib.Path(m["out_dir"]); out.mkdir(parents=True, exist_ok=True)
    fill = lambda p: p.format(subject=m["subject"])
    refs = [fal_client.upload_file(p) for p in m["refs"]]
    done = {}

    mpath = out / "00-master.jpg"
    if mpath.exists():                                                    # resume on the SAME master
        master = fal_client.upload_file(str(mpath))
    else:
        master = nano(fill(m["lock"]["prompt"]), refs)                    # LOCK
        save(master, mpath)
    done["master"] = str(mpath)
    anchor = [master] + refs                                              # master first

    sig = m.get("signature", {})
    if sig.get("enabled"):                                                # SIGNATURE
        spath = out / "01-signature.jpg"
        if not spath.exists():
            save(flux2(fill(sig["prompt"]), anchor, sig.get("image_size", "portrait_4_3")), spath)
        done["signature"] = str(spath)

    done["assets"] = []
    for i, a in enumerate(m["assets"], 1):                                # REPLICATE
        dest = out / f"asset-{i:02d}-{a['name']}.jpg"
        if dest.exists():                                                 # resumable
            done["assets"].append(str(dest)); continue
        p = fill(a["prompt"])
        if a.get("model") == "flux2":
            url = flux2(p, anchor, a.get("image_size", "square_hd"))
        else:
            url = nano(p + (f" Composition/aspect: {a['format']}." if a.get("format") else ""), anchor)
        if a.get("upscale"):
            url = upscale(url)
        done["assets"].append(save(url, dest))

    (out / "_outputs.json").write_text(json.dumps(done, indent=2))

def save(url, dest):
    urllib.request.urlretrieve(url, dest)
    return str(dest)

if __name__ == "__main__":
    run(sys.argv[1])
```

Add the provider fallback from [SKILL.md](SKILL.md#provider-fallback-shape) around
`call` if the run must not stop on an outage: for edits, Nano Banana Pro edit -> Nano
Banana edit.

## Checks per stage

- **Master:** compare with the best reference photo at full size, letter by letter. If
  the master is wrong, stop; every later asset inherits the error.
- **Signature:** the most likely place for drift (cap shape, print simplification).
  Reject rather than fix downstream.
- **Each asset:** label, geometry, material; the format asked for; the reserved text
  space actually empty.
- **Upscaled assets:** zoom on the label; an upscaler with too much creativity rewrites
  small text.

## Why not a LoRA here

Reference editing copies pixels from the real photo, so the real label survives, with
no training, no dataset prep beyond 2-4 good photos, and cents per asset. Train a LoRA
only for volume in the hundreds, novel angles the photos do not cover, or when the
weights must be owned (see [model-routing.md](model-routing.md#3-when-a-lora-is-still-worth-training)).
