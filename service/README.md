# Peak SEO brain

A Python program that makes **one careful improvement per run** to a WordPress
site running the Peak SEO plugin. It reads the site through the plugin's signed
API, writes the change with Claude using only the facts in the site's notes
file, and sends it as a proposal.

## What one run does

1. Reads the site (`/site`, `/pages`, `/proposals`).
2. Picks the single most valuable improvement, in this order:
   1. **Search titles and descriptions** that are missing or too long (Home first, then pages in the keyword map, then other pages, then posts).
   2. **Thin pages** (under `thin_word_count` words). Elementor pages get widget text edits; other pages get new body content.
   3. **New pages** for keyword-map rows marked `new: <slug>`.

   A page with a pending proposal is skipped, as is any page that got the same kind of proposal in the last `cooldown_days`.
3. Asks Claude (`claude-opus-5-5` by default) to write the change, with the whole notes file as the only source of facts. Claude returns structured JSON: the change, the facts it used, and any **open questions** for the owner.
4. Checks the answer (lengths, known widget ids, nothing empty), prints it, and sends it as a proposal.
5. **Approves it only if** the owner turned on central approval in the plugin **and** the plugin didn't mark it `needs_owner`. Otherwise it waits in the owner's dashboard.
6. Appends a line to the notes file's **Run log**, adds new **Open questions**, and prints the tokens used and the estimated cost.

`--dry-run` does steps 1–4 and prints the proposal without sending it or writing to the notes file. It still calls Claude, so it costs the same as a real run.

## Facts

The notes file (`SITE.md`) is the brain's only source of facts. It never invents reviews, prices, guarantees, licenses, years in business, service areas or services. When a fact would help but isn't in the notes, it writes the change without it and asks in "Open questions".

The plugin has its own owner-controlled list (**Peak SEO → Settings → Approved facts**). A change that mentions a service area, service, phone number or hours that's **not** on that list waits for the owner, whatever the notes say. Keep the two in step: the brain warns when the notes list a service area or service the plugin doesn't have.

## Setup

```bash
cd peak-seo-plugin
python -m venv service/.venv
service/.venv/Scripts/python -m pip install -r service/requirements.txt   # Windows
# service/.venv/bin/python -m pip install -r service/requirements.txt     # macOS/Linux
```

Set the two keys in the shell (never in a file):

```bash
export ANTHROPIC_API_KEY=sk-ant-...      # from console.anthropic.com
export PEAK_KEY=pk_...                    # WordPress → Peak SEO → Settings
```

Create a folder for each site:

```bash
service/.venv/Scripts/python service/peak_brain.py init service/sites/acme --site-url https://acme.com --name "Acme Plumbing"
```

Then fill in `service/sites/acme/SITE.md` (business facts, approved service areas and services, phone, hours, keyword map). Site folders are git-ignored because they hold client details. `config.json` names the environment variable holding the site key (`key_env`), so each site can use its own.

## Commands

```bash
python service/peak_brain.py plan service/sites/acme            # what the next run would pick (free, no Claude call)
python service/peak_brain.py run  service/sites/acme --dry-run  # write it with Claude, print it, send nothing
python service/peak_brain.py run  service/sites/acme            # write, send, approve if allowed, log
python service/peak_brain.py run  service/sites/acme --no-approve   # send but never approve
```

## config.json

| Key | Default | |
|---|---|---|
| `site_url` | | The WordPress site address |
| `key_env` | `PEAK_KEY` | Environment variable holding this site's key |
| `model` | `claude-opus-5-5` | Claude model |
| `effort` | `medium` | Claude effort: `low`, `medium`, `high`, `xhigh`, `max` |
| `thin_word_count` | `300` | Pages under this count as thin |
| `cooldown_days` | `30` | Don't repeat the same kind of change on a page within this many days |
| `notes_file` | `SITE.md` | Notes file name |

## Cost

Each run makes one Claude request. The run prints input/output tokens and an estimated dollar cost from the price table at the top of `peak_brain.py` (Claude Opus 5.5: $4 per million input tokens, $20 per million output tokens; thinking counts as output). If the model declines a request, the API retries it on a fallback model, and the cost line shows both models. The same line goes into the run log.
