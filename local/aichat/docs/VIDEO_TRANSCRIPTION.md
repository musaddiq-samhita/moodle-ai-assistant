# SCORM video transcription (Phase 1)

The assistant can transcribe the audio of **narrated videos inside SCORM packages**
and add the text to its RAG knowledge base, so learners can ask about content that
previously existed only in a video. Off by default; opt-in per course.

## What it does / does not do

- **Does:** transcribe narrated videos (those with an audio track) via OpenAI
  Whisper (`whisper-1`), attach the transcript to the owning slide's content, and
  index it like any other slide text.
- **Does not:** transcribe silent videos (motion graphics / screen recordings with
  no audio track). Those return a provider "could not be decoded" response, are
  cached as `undecodable`, and skipped — they carry no spoken content.
- **Provider:** OpenAI only in Phase 1. With any other provider, transcription is a
  no-op (returns `config_blocked`, nothing is cached).
- **Formats:** Articulate Storyline packages (videos under `story_content/`). Rise /
  generic SCORM video mapping is not implemented in Phase 1.

## Enabling it (two switches — both required)

1. **Site:** *Site administration → Plugins → Local plugins → AI Chat* →
   **Enable SCORM video transcription** (`local_aichat/enable_transcription`), and set
   **Transcription model** (default `whisper-1`).
2. **Course:** *Course → AI Chat settings* → **Enable video transcription**.

After enabling a course, queue one rebuild (dashboard → *Rebuild index*, or the
`local_aichat_rebuild_index` web service). A brand-new course with no embeddings is
not auto-discovered by the daily task until it has an index, so the first rebuild
must be triggered manually.

## How it runs

- Transcription only happens during **background maintenance**: the `reindex_courses`
  scheduled task and the `rebuild_course_index` ad-hoc task (queued by the dashboard
  / web service). It **never** runs on a learner's chat request.
- Both background paths run as a site admin so availability-restricted activities
  stay visible (otherwise their embeddings would be deleted).
- Each unique video (by file `contenthash`) is transcribed **once per policy** and
  cached in `local_aichat_transcriptions`. Re-runs reuse the cache — no repeat API
  calls. A cold rebuild runs as a background task so it cannot hit a web timeout.

## Cost

Whisper is billed per minute of audio (`whisper-1` ≈ $0.006/min at time of writing);
silent/undecodable videos are billed $0. A typical course is a few minutes of audio
(cents), one-time, then cached.

## Cache, invalidation & retention

- Cache key is `(contenthash, policyhash)` where `policyhash = sha1(provider | model
  | recipe-version)`. Changing the provider, model, or bumping
  `transcription_cache::RECIPE_VERSION` invalidates cached results so they recompute.
- Transient failures (`retryable_error`) are retried with backoff; auth/config
  problems (`config_blocked`) are never cached as permanent, so fixing configuration
  and rebuilding recovers automatically.
- **Admin invalidation:** `\local_aichat\rag\transcription_cache::invalidate()` clears
  all cached transcripts (or `invalidate($contenthash)` for one video); the next
  rebuild recomputes them.
- **Retention:** cache rows are keyed by content hash and are not tied to a course,
  so they survive package replacement and are reused across courses. There is no
  automatic cleanup in Phase 1; a future task may delete rows whose content hash is
  no longer referenced by any `mod_scorm/content` file.

## Data / privacy

Enabling transcription for a course sends that course's SCORM video **audio** to the
AI provider. This is disclosed in the plugin's privacy metadata
(`privacy:metadata:aitranscription`). Obtain content-owner / legal approval before
enabling institution-owned or licensed course media.
