# local_aichat tests

Unit/integration tests for the plugin. Test classes are added alongside the feature commit that
introduces the code they exercise (see `FINAL-PLAN-v2` commit sequence), so no test references a
class that does not yet exist.

## Running

Moodle PHPUnit requires a one-time test-environment init against a **separate** test database
(never the live DB):

```bash
# from the Moodle root, once:
php admin/tool/phpunit/cli/init.php

# then run just this plugin's suite:
vendor/bin/phpunit --testsuite local_aichat_testsuite
# or a single class:
vendor/bin/phpunit local/aichat/tests/scorm_video_mapping_test.php
```

On the current dev box a PHPUnit test environment is **not** provisioned (it is a production-like
instance). Until one exists, every changed PHP file is gated with `php -l` on the real PHP 7.4
runtime, and behaviour is verified end-to-end via the Commit 10 pilot on course 6.

## Fixtures (`tests/fixtures/`)

Minimized Articulate Storyline `data.js` payloads; shapes confirmed live on cmid 135 (Storyline 3.70):
video assets live in `assetLib` (`videoType: "mp4"`, `url: "story_content/…mp4"`) and are linked to
slides via `slideMap.slideRefs[*].id` (= `"sceneId.slideId"`) + `assetIds`, independently repeated in
each slide model at `slideLayers[*].objects[*].data.videodata.assetId`.

- `storyline_min.json` — agreement cases: a narrated video, a silent video, a video shared by two slides,
  an unmapped asset (in `assetLib` but no `slideRef`), and a decorative image (must be ignored).
- `storyline_conflict.json` — top-level map and slide-model disagree for one slide → titled fallback + log.

Silent vs. corrupt MP4 outcomes are binary-media behaviours and are covered by the transcription-client
contract tests (mocked HTTP: 200-blank → `empty`, 400 "could not be decoded" → `undecodable`), not by
these JSON fixtures.

## Planned coverage (by commit)
- C4 client contract: exact OpenAI URL, Bearer auth, multipart fields, boundary delegated to cURL,
  200→success, 200-blank→empty, 400→undecodable, 429→retryable, 401/403→config_blocked, redacted logs.
- C5 mapping: multi-video slide, shared video, unmapped, conflict, path-safety rejections, `slideRefs[*].id` parsing.
- C6 gate matrix (only all-true + provider=openai transcribes); foreground lazy-index zero-call;
  failure preservation (per-video degrade; package-level exception keeps existing rows).
- C7 cache idempotency + concurrent-lock single call; fingerprint OFF→ON / stable / retry-due / ON→OFF /
  model-change invalidation / replace-silent-with-narrated.
- C8 queued-rebuild response schema + dashboard `:manage` authorization.
