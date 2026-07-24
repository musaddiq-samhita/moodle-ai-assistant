<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Chat - SCORM content extractor.
 *
 * Extracts text from SCORM packages for RAG indexing, with a tiered fallback:
 *   Tier A - full package prose (per authoring tool; added in later slices)
 *   Tier B - SCO / organization titles from the DB (no file parse)
 *   Tier C - activity name + intro (the plugin's original behaviour)
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Tiered text extraction for mod_scorm activities.
 */
class scorm_extractor {

    /** @var int Max characters per chunk (matches content_extractor::MAX_CHUNK_CHARS). */
    private const MAX_CHUNK_CHARS = 6000;

    /** @var int Max characters kept from the breadcrumb before title suffixes are appended. */
    private const TITLE_BREADCRUMB_MAX = 200;

    /** @var int Default floor: drop a segment whose prose is shorter than this. */
    private const DEFAULT_MIN_PROSE_CHARS = 40;

    /** @var int Default target size when merging consecutive within-section segments. */
    private const DEFAULT_MERGE_TARGET_CHARS = 2000;

    /** @var string[] Articulate Rise block keys whose string values are prose. */
    private const RISE_TEXT_KEYS = [
        'heading', 'paragraph', 'title', 'caption', 'subtitle', 'text', 'description',
    ];

    /** @var string[] Rise keys whose subtrees are config/asset noise (or secrets) - pruned.
     * Any key ending in "image" is also pruned (see collect_rise_text). */
    private const RISE_SKIP_KEYS = [
        'media', 'settings', 'metadata', 'globalBlockId', 'id', 'icon', 'color',
        'fonts', 'theme', 'author', 'authors', 'aiTutorConfig', 'aiLearnerCourseApiKey',
    ];

    /** @var string[] Storyline slide-model keys whose subtrees are config/asset/state noise. */
    private const STORYLINE_SKIP_KEYS = [
        'audiolib', 'imagelib', 'imagedata', 'base64', 'pngfb', 'html5data', 'assetId',
        'animations', 'tweens', 'events', 'actionGroups', 'actions', 'vectorData', 'path',
        'data', 'states',
    ];

    /** @var string[] Storyline keys that may carry authored notes-pane text (untested path). */
    private const STORYLINE_NOTE_KEYS = ['notes', 'notesData', 'slideNotes'];

    /**
     * Extract indexable chunks for a single SCORM activity.
     *
     * Returns an array of chunk dicts, each:
     *   ['chunk_type' => 'scorm', 'chunk_id' => cmid, 'chunk_title' => ..., 'content_text' => ...]
     *
     * Slice 1: authoring-tool detection + Tier B (SCO titles) / Tier C (name+intro).
     * The per-format parsers are stubbed to return no segments, so every package
     * currently lands on Tier B (or Tier C when it has no SCO titles).
     *
     * @param \cm_info $cm The SCORM course module.
     * @param \stdClass $course The course record.
     * @param array $existingrows Existing embedding rows for this course (used by the
     *        pre-parse skip in a later slice; unused here).
     * @return array List of chunk dicts.
     */
    public static function extract_chunks(\cm_info $cm, \stdClass $course, array $existingrows = [],
            bool $allowtranscription = false): array {
        // Containment: a failure here must not abort indexing of the whole
        // course, but it also must NOT silently return zero chunks - that would
        // let index_course delete this package's existing embeddings. Instead we
        // throw a typed exception so the indexer preserves the prior rows.
        try {
            // Resolve the full transcription gate once (plugin+global+course+flag+provider).
            $transcribe = self::transcription_allowed($cm, $allowtranscription);

            // The change fingerprint folds in the transcription policy when
            // transcription is on, so toggling it (or changing provider/model)
            // forces a reparse that adds or removes transcript text.
            $fingerprint = self::package_fingerprint($cm);
            if ($fingerprint !== '' && $transcribe) {
                $fingerprint = sha1($fingerprint . '|transcription:v1:on:' . transcription_cache::policy_hash());
            }

            // Pre-parse skip: if the package is unchanged since it was last indexed,
            // re-emit its stored chunks verbatim. When transcription is on, only skip
            // if every eligible video already has a settled cache entry (else reparse
            // to fill in / retry the missing transcripts).
            if ($fingerprint !== '') {
                $stored = self::stored_chunks($cm->id, $fingerprint, $existingrows);
                if ($stored !== null
                        && (!$transcribe || self::videos_cache_complete(\context_module::instance($cm->id)))) {
                    self::log_tier($cm, 'skip', 'unchanged package, ' . count($stored) . ' chunk(s)');
                    return $stored;
                }
            }

            $chunks = self::build_chunks($cm, $course, $transcribe);
            return self::tag_source_hash($chunks, $fingerprint);
        } catch (\Exception $e) {
            debugging(
                "local_aichat scorm_extractor: cmid {$cm->id} extraction failed, preserving prior index ("
                    . $e->getMessage() . ')',
                DEBUG_DEVELOPER
            );
            throw new extraction_failed_exception('scorm', (int) $cm->id, $e->getMessage());
        }
    }

    /**
     * Whether SCORM video transcription is permitted for this activity right now.
     *
     * Requires ALL of: the plugin enabled, the global transcription switch, the
     * course opt-in, the caller's background/maintenance flag, and the OpenAI
     * provider (the only certified transcription provider in Phase 1).
     *
     * @param \cm_info $cm
     * @param bool $allowtranscription The caller's execution flag.
     * @return bool
     */
    protected static function transcription_allowed(\cm_info $cm, bool $allowtranscription): bool {
        if (!$allowtranscription) {
            return false;
        }
        if (!get_config('local_aichat', 'enabled') || !get_config('local_aichat', 'enable_transcription')) {
            return false;
        }
        if (\local_aichat\azure_openai_client::get_provider() !== 'openai') {
            return false;
        }
        global $CFG;
        require_once($CFG->dirroot . '/local/aichat/lib.php');
        $coursesettings = \local_aichat_get_course_settings((int) $cm->course);
        return !empty($coursesettings->enable_transcription);
    }

    /**
     * Run the tiered extraction (detect -> parse -> assemble, else Tier B / C).
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @param bool $allowtranscription Whether transcription may run (threaded to the parser).
     * @return array Chunk dicts.
     */
    private static function build_chunks(\cm_info $cm, \stdClass $course, bool $allowtranscription = false): array {
        $ctx = \context_module::instance($cm->id);
        $format = self::detect_format($ctx);

        // Tier A - full package prose via the per-format parser.
        $segments = self::parse($format, $ctx, $allowtranscription);
        if (!empty($segments)) {
            $chunks = self::assemble_chunks($segments, $cm, $course);
            if (!empty($chunks)) {
                self::log_tier($cm, 'A', $format . ', ' . count($chunks) . ' chunk(s)');
                return $chunks;
            }
        }

        // Tier B - SCO / organization titles straight from the DB (no file parse).
        $titlechunk = self::titles_tier($cm, $course);
        if ($titlechunk !== null) {
            self::log_tier($cm, 'B', 'no parser segments (format=' . $format . ')');
            return [$titlechunk];
        }

        // Tier C - fall back to the activity name + intro.
        self::log_tier($cm, 'C', 'no SCO titles');
        return self::name_intro_tier($cm);
    }

    /**
     * The package's change fingerprint - the SCORM sha1hash (populated by Moodle
     * on upload, changes only on re-upload). Empty string if unavailable.
     *
     * @param \cm_info $cm
     * @return string
     */
    private static function package_fingerprint(\cm_info $cm): string {
        global $DB;
        $hash = $DB->get_field('scorm', 'sha1hash', ['id' => $cm->instance]);
        return $hash ? (string) $hash : '';
    }

    /**
     * The activity's already-stored chunks, but only if every one carries the
     * current fingerprint (i.e. the package is unchanged). Null otherwise, which
     * signals a full re-parse.
     *
     * @param int $cmid
     * @param string $fingerprint
     * @param array $existingrows Existing embedding rows for the course.
     * @return array|null
     */
    private static function stored_chunks(int $cmid, string $fingerprint, array $existingrows): ?array {
        $chunks = [];
        foreach ($existingrows as $row) {
            if ($row->chunk_type !== 'scorm' || (int) $row->chunk_id !== $cmid) {
                continue;
            }
            if (($row->source_hash ?? '') !== $fingerprint) {
                return null;
            }
            $chunks[] = [
                'chunk_type'   => 'scorm',
                'chunk_id'     => $cmid,
                'chunk_title'  => $row->chunk_title,
                'content_text' => $row->content_text,
                'source_hash'  => $fingerprint,
            ];
        }
        return !empty($chunks) ? $chunks : null;
    }

    /**
     * Stamp every chunk with the source fingerprint so a later reindex can skip
     * re-parsing the package when it is unchanged.
     *
     * @param array $chunks
     * @param string $fingerprint
     * @return array
     */
    private static function tag_source_hash(array $chunks, string $fingerprint): array {
        if ($fingerprint === '') {
            return $chunks;
        }
        foreach ($chunks as &$chunk) {
            $chunk['source_hash'] = $fingerprint;
        }
        unset($chunk);
        return $chunks;
    }

    /**
     * Build the Tier B "titles only" content for the live current-page block.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return array|null {title, type, content} or null if no titles are available.
     */
    public static function current_page_titles(\cm_info $cm, \stdClass $course): ?array {
        $chunk = self::titles_tier($cm, $course);
        if ($chunk === null) {
            return null;
        }
        return [
            'title'   => $cm->name,
            'type'    => 'scorm',
            'content' => $chunk['content_text'],
        ];
    }

    /**
     * Detect the authoring tool from the unzipped package's file list.
     *
     * I/O-free: inspects file paths only, never reads bytes. Most-specific first,
     * because Rise/Storyline packages also contain imsmanifest.xml.
     *
     * @param \context_module $ctx
     * @return string One of 'rise', 'storyline', 'generic', 'cmi5', 'unknown'.
     */
    public static function detect_format(\context_module $ctx): string {
        $fs = get_file_storage();
        $files = $fs->get_area_files($ctx->id, 'mod_scorm', 'content', 0, 'filepath, filename', false);

        $haspath = function(string $needle) use ($files): bool {
            foreach ($files as $file) {
                $rel = ltrim($file->get_filepath(), '/') . $file->get_filename();
                if (strpos($rel, $needle) !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($haspath('cmi5.xml')) {
            return 'cmi5';
        }
        if ($haspath('scormcontent/lib/rise/')) {
            return 'rise';
        }
        if ($haspath('story_content/') || $haspath('story.html')) {
            return 'storyline';
        }
        if ($haspath('imsmanifest.xml')) {
            return 'generic';
        }
        return 'unknown';
    }

    /**
     * Per-format parser dispatch. Each parser returns a list of segments:
     *   ['lesson_title' => string, 'prose' => string, 'section_title' => ?string]
     * An empty result makes the caller fall back to Tier B titles.
     *
     * @param string $format
     * @param \context_module $ctx
     * @param bool $allowtranscription Whether transcription may run (Storyline only in Phase 1).
     * @return array Segment list.
     */
    protected static function parse(string $format, \context_module $ctx, bool $allowtranscription = false): array {
        switch ($format) {
            case 'rise':
                return self::parse_rise($ctx);
            case 'storyline':
                return self::parse_storyline($ctx, $allowtranscription);
            case 'generic':
                return self::parse_generic($ctx);
            case 'cmi5':
                return self::parse_cmi5($ctx);
            default:
                return [];
        }
    }

    /**
     * Parse an Articulate Rise package: the course model is embedded in
     * scormcontent/index.html as base64-encoded JSON inside deserialize("...").
     * One segment per lesson.
     *
     * @param \context_module $ctx
     * @return array Segment list (empty on any failure -> Tier B fallback).
     */
    protected static function parse_rise(\context_module $ctx): array {
        $fs = get_file_storage();
        $file = $fs->get_file($ctx->id, 'mod_scorm', 'content', 0, '/scormcontent/', 'index.html');
        if (!$file) {
            return [];
        }
        $html = $file->get_content();
        if (!preg_match('/deserialize\("([A-Za-z0-9+\/=]+)"\)/', $html, $m)) {
            return [];
        }
        $raw = base64_decode($m[1], true);
        $model = ($raw !== false) ? json_decode($raw, true) : null;
        if (!is_array($model) || empty($model['course']['lessons'])) {
            // Not base64-JSON (e.g. an unrecognised/compressed Rise variant) - degrade to titles.
            debugging('local_aichat scorm_extractor: Rise course model not base64-JSON, falling back to titles',
                DEBUG_DEVELOPER);
            return [];
        }

        $segments = [];
        foreach ($model['course']['lessons'] as $lesson) {
            $parts = [];
            self::collect_rise_text($lesson['items'] ?? [], $parts);
            // Drop consecutive duplicate runs (Rise repeats some headings across nested items).
            $deduped = [];
            $prev = null;
            foreach ($parts as $p) {
                if ($p !== $prev) {
                    $deduped[] = $p;
                }
                $prev = $p;
            }
            $segments[] = [
                'lesson_title'  => trim((string) ($lesson['title'] ?? '')),
                'prose'         => trim(implode("\n\n", $deduped)),
                'section_title' => null,
            ];
        }
        return $segments;
    }

    /**
     * Recursively collect prose from a Rise block tree: pull string values of the
     * known text keys, prune the known noise/secret subtrees, recurse the rest.
     *
     * @param mixed $node
     * @param array $out Collected text lines (by reference).
     */
    private static function collect_rise_text($node, array &$out): void {
        if (!is_array($node)) {
            return;
        }
        // A JSON object (associative) vs a JSON array (sequential).
        if (array_keys($node) !== range(0, count($node) - 1)) {
            foreach ($node as $key => $value) {
                // Prune config/secret keys and any "*Image" asset key (URLs/alt text).
                if (in_array($key, self::RISE_SKIP_KEYS, true)
                        || substr(strtolower((string) $key), -5) === 'image') {
                    continue;
                }
                if (in_array($key, self::RISE_TEXT_KEYS, true) && is_string($value) && trim($value) !== '') {
                    $text = trim(html_to_text($value, 0, false));
                    if ($text !== '') {
                        $out[] = $text;
                    }
                } else {
                    self::collect_rise_text($value, $out);
                }
            }
        } else {
            foreach ($node as $item) {
                self::collect_rise_text($item, $out);
            }
        }
    }

    /**
     * Parse an Articulate Storyline package. Storyline HTML5 output is a manifest
     * (html5/data/js/data.js) plus one file per slide, each wrapping JSON in
     * window.globalProvideData('<tag>', '<js-string>'). One segment per slide.
     *
     * @param \context_module $ctx
     * @param bool $allowtranscription Whether video transcription may run (wired in a later slice).
     * @return array Segment list (empty on failure -> Tier B fallback).
     */
    protected static function parse_storyline(\context_module $ctx, bool $allowtranscription = false): array {
        $data = self::read_gpd($ctx, '/html5/data/js/', 'data.js');
        if (!is_array($data) || empty($data['scenes'])) {
            debugging('local_aichat scorm_extractor: Storyline data.js unreadable, falling back to titles',
                DEBUG_DEVELOPER);
            return [];
        }
        $sectionmap = self::storyline_section_map($ctx);

        // When transcription is permitted, transcribe each eligible video once
        // (cache-aware) and group the resulting text by owning slide key.
        $transcriptsbyslide = [];
        if ($allowtranscription) {
            $discovered = 0;
            $withtext = 0;
            foreach (self::storyline_eligible_videos($ctx) as $v) {
                $discovered++;
                $text = self::transcribe_video($ctx, $v);
                if ($text === false) {
                    // Run-level config block (e.g. auth failure / open circuit):
                    // stop calling the provider; index the slide text we have.
                    break;
                }
                if ($text !== null && trim($text) !== '') {
                    $withtext++;
                    $transcriptsbyslide[$v['scenekey']][] = trim($text);
                }
            }
            if ($discovered > 0) {
                debugging('local_aichat transcription: cmid ' . $ctx->instanceid . ' - '
                    . $discovered . ' video(s) discovered, ' . $withtext . ' with usable transcript '
                    . '(remainder silent/undecodable/failed - see local_aichat_transcriptions).',
                    DEBUG_DEVELOPER);
            }
        }

        // Content scenes in presentation order (skip player message scenes).
        $scenes = [];
        foreach ($data['scenes'] as $scene) {
            if (empty($scene['isMessageScene'])) {
                $scenes[] = $scene;
            }
        }
        usort($scenes, function($a, $b) {
            return ($a['sceneNumber'] ?? 0) <=> ($b['sceneNumber'] ?? 0);
        });

        $segments = [];
        foreach ($scenes as $scene) {
            $slides = $scene['slides'] ?? [];
            usort($slides, function($a, $b) {
                return ($a['slideNumberInScene'] ?? 0) <=> ($b['slideNumberInScene'] ?? 0);
            });
            foreach ($slides as $slide) {
                if (array_key_exists('includeInSlideCounts', $slide) && !$slide['includeInSlideCounts']) {
                    continue;
                }
                $url = ltrim((string) ($slide['html5url'] ?? ''), '/');
                if ($url === '') {
                    continue;
                }
                $slideid = pathinfo($url, PATHINFO_FILENAME);
                $model = self::read_gpd($ctx, '/' . trim(dirname($url), '/') . '/', basename($url));
                $prose = is_array($model) ? self::slide_prose($model) : '';

                // Append any video transcript(s) for this slide.
                $scenekey = (string) ($scene['id'] ?? '') . '.' . (string) ($slide['id'] ?? '');
                if (!empty($transcriptsbyslide[$scenekey])) {
                    $labelled = [];
                    $multi = count($transcriptsbyslide[$scenekey]) > 1;
                    foreach ($transcriptsbyslide[$scenekey] as $i => $text) {
                        $label = $multi ? ('Video transcript ' . ($i + 1) . ':') : 'Video transcript:';
                        $labelled[] = $label . "\n" . $text;
                    }
                    $prose = trim($prose . "\n\n" . implode("\n\n", $labelled));
                }

                $segments[] = [
                    'lesson_title'  => trim((string) ($slide['title'] ?? '')),
                    'prose'         => $prose,
                    'section_title' => $sectionmap[$slideid] ?? null,
                ];
            }
        }
        return $segments;
    }

    /**
     * Collect readable prose from one Storyline slide model: on-slide text runs
     * plus any authored notes (untested field path).
     *
     * @param array $model
     * @return string
     */
    protected static function slide_prose(array $model): string {
        $parts = [];
        foreach ($model['slideLayers'] ?? [] as $layer) {
            self::collect_storyline_text($layer['objects'] ?? [], $parts);
        }
        // Notes pane (often the fullest narration) - untested path, included when present.
        self::collect_storyline_notes($model, $parts);

        // Drop consecutive duplicate runs (hover/visited state text, repeated headings).
        $deduped = [];
        $prev = null;
        foreach ($parts as $p) {
            if ($p !== $prev) {
                $deduped[] = $p;
            }
            $prev = $p;
        }
        return trim(implode("\n", $deduped));
    }

    /**
     * Recursively collect Storyline on-slide text (string values under 'text' keys),
     * pruning config/asset/state subtrees.
     *
     * @param mixed $node
     * @param array $out (by reference)
     */
    private static function collect_storyline_text($node, array &$out): void {
        if (!is_array($node)) {
            return;
        }
        if (array_keys($node) !== range(0, count($node) - 1)) {
            foreach ($node as $key => $value) {
                if (in_array($key, self::STORYLINE_SKIP_KEYS, true)) {
                    continue;
                }
                if ($key === 'text' && is_string($value) && trim($value) !== '') {
                    $text = self::clean_storyline_text($value);
                    if ($text !== '') {
                        $out[] = $text;
                    }
                } else {
                    self::collect_storyline_text($value, $out);
                }
            }
        } else {
            foreach ($node as $item) {
                self::collect_storyline_text($item, $out);
            }
        }
    }

    /**
     * Find authored notes anywhere in the slide model and collect their text.
     * The exact field is unverified (no notes-bearing specimen was available), so
     * this scans the known note keys and degrades silently when absent.
     *
     * @param mixed $node
     * @param array $out (by reference)
     */
    private static function collect_storyline_notes($node, array &$out): void {
        if (!is_array($node)) {
            return;
        }
        if (array_keys($node) !== range(0, count($node) - 1)) {
            foreach ($node as $key => $value) {
                if (in_array($key, self::STORYLINE_SKIP_KEYS, true)) {
                    continue;
                }
                if (in_array($key, self::STORYLINE_NOTE_KEYS, true)) {
                    if (is_string($value)) {
                        $text = self::clean_storyline_text($value);
                        if ($text !== '') {
                            $out[] = $text;
                        }
                    } else {
                        self::collect_storyline_text($value, $out);
                    }
                } else {
                    self::collect_storyline_notes($value, $out);
                }
            }
        } else {
            foreach ($node as $item) {
                self::collect_storyline_notes($item, $out);
            }
        }
    }

    // ------------------------------------------------------------------
    // Storyline video -> slide mapping (pure helpers; no API calls).
    // ------------------------------------------------------------------

    /**
     * Index Storyline video assets from data.js: assetLib[*] where videoType=mp4.
     *
     * @param array $data Decoded data.js payload.
     * @return array int asset id => relative url (e.g. 'story_content/video_x.mp4')
     */
    public static function storyline_video_assets(array $data): array {
        $out = [];
        foreach (($data['assetLib'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['videoType'] ?? '') !== 'mp4') {
                continue;
            }
            if (!isset($asset['id'], $asset['url'])) {
                continue;
            }
            $out[(int) $asset['id']] = (string) $asset['url'];
        }
        return $out;
    }

    /**
     * Map each slide key to its referenced video asset ids from the top-level
     * slideMap. The slide key is slideRefs[*].id, e.g. 'sceneId.slideId'.
     *
     * @param array $data Decoded data.js payload.
     * @return array string slide key => int[] asset ids
     */
    public static function storyline_slide_video_map(array $data): array {
        $out = [];
        foreach (($data['slideMap']['slideRefs'] ?? []) as $ref) {
            if (!is_array($ref) || !isset($ref['id'])) {
                continue;
            }
            $ids = [];
            foreach (($ref['assetIds'] ?? []) as $aid) {
                if (is_int($aid) || (is_string($aid) && ctype_digit($aid))) {
                    $ids[] = (int) $aid;
                }
            }
            if (!empty($ids)) {
                $out[(string) $ref['id']] = array_values(array_unique($ids));
            }
        }
        return $out;
    }

    /**
     * Collect video asset ids from a slide model's data.videodata.assetId nodes.
     *
     * A dedicated traversal is required because collect_storyline_text() prunes
     * the 'assetId' subtree via STORYLINE_SKIP_KEYS.
     *
     * @param array $model Decoded slide model.
     * @return int[] asset ids
     */
    public static function storyline_model_video_ids(array $model): array {
        $ids = [];
        self::collect_videodata_assetids($model, $ids);
        return array_values(array_unique($ids));
    }

    /**
     * Recursively collect data.videodata.assetId values.
     *
     * @param mixed $node
     * @param array $out (by reference)
     */
    private static function collect_videodata_assetids($node, array &$out): void {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['videodata']) && is_array($node['videodata']) && isset($node['videodata']['assetId'])) {
            $aid = $node['videodata']['assetId'];
            if (is_int($aid) || (is_string($aid) && ctype_digit($aid))) {
                $out[] = (int) $aid;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collect_videodata_assetids($value, $out);
            }
        }
    }

    /**
     * Decide which video asset ids to attach to a slide, given the two
     * independent mappings (top-level slideMap and the slide model).
     *
     * both     - the mappings agree (share ids); attach the intersection.
     * single   - only one source has ids; accept them (log a warning upstream).
     * conflict - both have ids but disagree entirely; attach nothing (fallback).
     * none     - no video on this slide.
     *
     * @param int[] $topids Ids from the top-level slideMap.
     * @param int[] $modelids Ids from the slide model.
     * @return array {attach: int[], sources: string}
     */
    public static function classify_slide_videos(array $topids, array $modelids): array {
        $top = array_values(array_unique(array_map('intval', $topids)));
        $model = array_values(array_unique(array_map('intval', $modelids)));
        sort($top);
        sort($model);

        if (empty($top) && empty($model)) {
            return ['attach' => [], 'sources' => 'none'];
        }
        if (empty($top) || empty($model)) {
            return ['attach' => !empty($top) ? $top : $model, 'sources' => 'single'];
        }
        $both = array_values(array_intersect($top, $model));
        if (!empty($both)) {
            return ['attach' => $both, 'sources' => 'both'];
        }
        return ['attach' => [], 'sources' => 'conflict'];
    }

    /**
     * Validate and split a Storyline asset URL into a Moodle filearea path.
     *
     * Rejects absolute URLs, schemes/hosts, NUL bytes, backslashes, traversal,
     * anything outside story_content/, and non-mp4 files.
     *
     * @param string $url Relative asset url from data.js.
     * @return array|null {filepath, filename} or null if invalid.
     */
    public static function storyline_asset_relpath(string $url) {
        $url = trim($url);
        if ($url === '' || strpos($url, '://') !== false || strpos($url, "\0") !== false
                || strpos($url, '\\') !== false) {
            return null;
        }
        $url = ltrim($url, '/');
        if (strpos($url, '..') !== false) {
            return null;
        }
        if (stripos($url, 'story_content/') !== 0) {
            return null;
        }
        if (strtolower((string) pathinfo($url, PATHINFO_EXTENSION)) !== 'mp4') {
            return null;
        }
        return [
            'filepath' => '/' . trim(dirname($url), '/') . '/',
            'filename' => basename($url),
        ];
    }

    /**
     * Enumerate the eligible video candidates for a SCORM package: every video
     * that maps (agreement or single-source) to an indexed slide, resolved to a
     * Moodle file + contenthash. Used for transcript injection and for the
     * cache-completeness check on the unchanged-package skip.
     *
     * @param \context_module $ctx
     * @return array list of {scenekey, slidetitle, assetid, filepath, filename, contenthash, sources}
     */
    protected static function storyline_eligible_videos(\context_module $ctx): array {
        $data = self::read_gpd($ctx, '/html5/data/js/', 'data.js');
        if (!is_array($data) || empty($data['scenes'])) {
            return [];
        }
        $assets = self::storyline_video_assets($data);
        $slidemap = self::storyline_slide_video_map($data);
        $fs = get_file_storage();

        $out = [];
        foreach ($data['scenes'] as $scene) {
            if (!empty($scene['isMessageScene'])) {
                continue;
            }
            foreach (($scene['slides'] ?? []) as $slide) {
                if (array_key_exists('includeInSlideCounts', $slide) && !$slide['includeInSlideCounts']) {
                    continue;
                }
                $scenekey = (string) ($scene['id'] ?? '') . '.' . (string) ($slide['id'] ?? '');
                $topids = $slidemap[$scenekey] ?? [];
                $url = ltrim((string) ($slide['html5url'] ?? ''), '/');
                $model = $url !== '' ? self::read_gpd($ctx, '/' . trim(dirname($url), '/') . '/', basename($url)) : null;
                $modelids = is_array($model) ? self::storyline_model_video_ids($model) : [];
                $plan = self::classify_slide_videos($topids, $modelids);
                if ($plan['sources'] === 'conflict') {
                    debugging('local_aichat scorm_extractor: video mapping conflict on slide ' . $scenekey,
                        DEBUG_DEVELOPER);
                }
                foreach ($plan['attach'] as $assetid) {
                    if (!isset($assets[$assetid])) {
                        continue;
                    }
                    $rel = self::storyline_asset_relpath($assets[$assetid]);
                    if ($rel === null) {
                        continue;
                    }
                    $file = $fs->get_file($ctx->id, 'mod_scorm', 'content', 0, $rel['filepath'], $rel['filename']);
                    if (!$file) {
                        continue;
                    }
                    if ($plan['sources'] === 'single') {
                        debugging('local_aichat scorm_extractor: single-source video mapping on slide '
                            . $scenekey . ' asset ' . $assetid, DEBUG_DEVELOPER);
                    }
                    $out[] = [
                        'scenekey'    => $scenekey,
                        'slidetitle'  => trim((string) ($slide['title'] ?? '')),
                        'assetid'     => $assetid,
                        'filepath'    => $rel['filepath'],
                        'filename'    => $rel['filename'],
                        'contenthash' => $file->get_contenthash(),
                        'sources'     => $plan['sources'],
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Transcribe one eligible video (cache-aware). Returns the transcript text on
     * success, or null (skip) for silent/undecodable/empty/failed/blocked videos.
     *
     * @param \context_module $ctx
     * @param array $v One entry from storyline_eligible_videos().
     * @return string|false|null Transcript text on success; null to skip this
     *         video; false to signal a run-level config block (stop the batch).
     */
    protected static function transcribe_video(\context_module $ctx, array $v) {
        global $CFG;
        $hash = $v['contenthash'];

        $cached = transcription_cache::lookup($hash);
        if (!transcription_cache::should_attempt($cached)) {
            return transcription_cache::usable_transcript($cached);
        }

        // Serialize per unique video so cron + a manual rebuild cannot upload the
        // same file twice; re-check the cache inside the lock.
        $lock = index_lock::transcription($hash, 30);
        if (!$lock) {
            return transcription_cache::usable_transcript(transcription_cache::lookup($hash));
        }

        try {
            $cached = transcription_cache::lookup($hash);
            if (!transcription_cache::should_attempt($cached)) {
                return transcription_cache::usable_transcript($cached);
            }

            $file = get_file_storage()->get_file($ctx->id, 'mod_scorm', 'content', 0, $v['filepath'], $v['filename']);
            if (!$file) {
                return null;
            }
            // Copy + transport share one try/finally so a partial temp file from a
            // failed copy is always removed. transcribe() never throws; a copy
            // failure is contained here and skips just this video.
            $tmp = $CFG->tempdir . '/aichat_tx_' . uniqid('', true) . '.mp4';
            $outcome = null;
            try {
                $file->copy_content_to($tmp);
                $outcome = \local_aichat\azure_openai_client::transcribe($tmp, $v['filename']);
            } catch (\Throwable $e) {
                $outcome = null;
            } finally {
                @unlink($tmp);
            }
            if ($outcome === null) {
                return null;
            }

            $status = $outcome['status'];
            // Provider/config problems are run-level: do NOT cache per file, and
            // signal the caller to stop attempting the rest of the batch.
            if ($status === \local_aichat\azure_openai_client::TRANSCRIBE_CONFIG_BLOCKED) {
                return false;
            }

            $data = [
                'transcript' => $outcome['transcript'],
                'language'   => $outcome['language'],
                'provider'   => $outcome['provider'],
                'model'      => $outcome['model'],
                'lasterror'  => $outcome['lasterror'],
            ];
            if ($status === transcription_cache::STATUS_RETRYABLE) {
                $attempt = ($cached ? (int) $cached->attemptcount : 0) + 1;
                $data['retryafter'] = transcription_cache::next_retry_after($attempt, $outcome['retryafterheader']);
            }
            transcription_cache::store($hash, $status, $data);

            return $status === transcription_cache::STATUS_SUCCESS ? $outcome['transcript'] : null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether every eligible video for a package already has an actionable-free
     * cache entry (success/empty/undecodable/permanent, or a retryable not yet
     * due). Used to decide if the unchanged-package skip is safe when
     * transcription is enabled.
     *
     * @param \context_module $ctx
     * @return bool
     */
    protected static function videos_cache_complete(\context_module $ctx): bool {
        foreach (self::storyline_eligible_videos($ctx) as $v) {
            if (transcription_cache::should_attempt(transcription_cache::lookup($v['contenthash']))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Map slide id -> section title from the Storyline frame nav outline (best effort).
     *
     * @param \context_module $ctx
     * @return array slideid => section title
     */
    protected static function storyline_section_map(\context_module $ctx): array {
        $frame = self::read_gpd($ctx, '/html5/data/js/', 'frame.js');
        $map = [];
        if (!is_array($frame)) {
            return $map;
        }
        $links = $frame['navData']['outline']['links'] ?? [];
        foreach ($links as $root) {
            self::walk_storyline_outline($root, self::outline_title($root), $map);
        }
        return $map;
    }

    /**
     * Recursively walk the frame outline, assigning each slide its grouping section.
     *
     * @param array $node
     * @param string $section
     * @param array $map (by reference)
     */
    private static function walk_storyline_outline(array $node, string $section, array &$map): void {
        $sid = (string) ($node['slideid'] ?? '');
        if ($sid !== '') {
            $parts = explode('.', $sid);
            $map[end($parts)] = $section;
        }
        $kids = $node['links'] ?? [];
        // A node with children acts as its children's section heading.
        $childsection = !empty($kids) ? self::outline_title($node) : $section;
        foreach ($kids as $child) {
            self::walk_storyline_outline($child, $childsection, $map);
        }
    }

    /**
     * The display title of a frame-outline node.
     *
     * @param array $node
     * @return string
     */
    private static function outline_title(array $node): string {
        $title = (string) ($node['displaytext'] ?? ($node['slidetitle'] ?? ''));
        return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Read a Storyline globalProvideData file and return its decoded JSON payload.
     *
     * @param \context_module $ctx
     * @param string $filepath File area path (e.g. '/html5/data/js/').
     * @param string $filename
     * @return array|null Decoded payload, or null if missing/unparseable.
     */
    protected static function read_gpd(\context_module $ctx, string $filepath, string $filename): ?array {
        $fs = get_file_storage();
        $file = $fs->get_file($ctx->id, 'mod_scorm', 'content', 0, $filepath, $filename);
        if (!$file) {
            return null;
        }
        $doc = $file->get_content();
        // Strip a UTF-8 BOM if present.
        $doc = preg_replace('/^\xEF\xBB\xBF/', '', $doc);
        if (!preg_match("/globalProvideData\\(\\s*'[^']*'\\s*,\\s*'(.*)'\\s*\\)\\s*;?\\s*\$/s", $doc, $m)) {
            return null;
        }
        $decoded = json_decode(self::js_unescape($m[1]), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decode a JavaScript single-quoted string literal's escape sequences.
     *
     * Byte-oriented on purpose: `\` (0x5C) never appears inside a UTF-8 multibyte
     * sequence, so raw multibyte content passes through untouched while only the
     * ASCII escape sequences are rewritten.
     *
     * @param string $s
     * @return string
     */
    private static function js_unescape(string $s): string {
        $simple = [
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
            'v' => "\v", '0' => "\0", "'" => "'", '"' => '"', '\\' => '\\', '/' => '/',
        ];
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                $nx = $s[$i + 1];
                if ($nx === 'u') {
                    $hex = substr($s, $i + 2, 4);
                    $cp = hexdec($hex);
                    // Combine a UTF-16 surrogate pair (😀) into one character.
                    if ($cp >= 0xD800 && $cp <= 0xDBFF
                            && $i + 11 < $len && $s[$i + 6] === '\\' && $s[$i + 7] === 'u') {
                        $ch = json_decode('"\u' . $hex . '\u' . substr($s, $i + 8, 4) . '"');
                        $out .= is_string($ch) ? $ch : '';
                        $i += 11;
                        continue;
                    }
                    // Reuse json_decode to turn \uXXXX into its UTF-8 character.
                    $ch = json_decode('"\u' . $hex . '"');
                    $out .= is_string($ch) ? $ch : '';
                    $i += 5;
                    continue;
                }
                if ($nx === 'x') {
                    $out .= chr(hexdec(substr($s, $i + 2, 2)));
                    $i += 3;
                    continue;
                }
                $out .= $simple[$nx] ?? $nx;
                $i += 1;
                continue;
            }
            $out .= $s[$i];
        }
        return $out;
    }

    /**
     * Strip HTML and Storyline escapes from a text run.
     *
     * @param string $s
     * @return string
     */
    private static function clean_storyline_text(string $s): string {
        // Storyline escapes a literal percent (its variable delimiter) as ^%^.
        $s = str_replace('^%^', '%', $s);
        return trim(html_to_text($s, 0, false));
    }

    /**
     * Parse a generic (hand-authored) SCORM 1.2/2004 package: walk the default
     * organization's <item> tree in document order, resolve each leaf item's
     * resource href to its HTML page, and strip it to text. One segment per item.
     *
     * NOTE: no hand-authored generic SCORM specimen exists on this deployment
     * (all packages are Rise/Storyline), so the content-recovery step is
     * spec-conformant but unproven end-to-end; the manifest parsing is validated.
     *
     * @param \context_module $ctx
     * @return array Segment list (empty on failure -> Tier B fallback).
     */
    protected static function parse_generic(\context_module $ctx): array {
        $fs = get_file_storage();
        $manifest = $fs->get_file($ctx->id, 'mod_scorm', 'content', 0, '/', 'imsmanifest.xml');
        if (!$manifest) {
            return [];
        }
        $items = self::generic_manifest_items($manifest->get_content());
        return self::segments_from_html_items($ctx, $items);
    }

    /**
     * Parse a cmi5 package uploaded through mod_scorm: walk cmi5.xml, resolving
     * each assignable unit's <url> to its launch HTML. Untested (no specimen).
     *
     * @param \context_module $ctx
     * @return array Segment list (empty on failure -> Tier B fallback).
     */
    protected static function parse_cmi5(\context_module $ctx): array {
        $fs = get_file_storage();
        $file = $fs->get_file($ctx->id, 'mod_scorm', 'content', 0, '/', 'cmi5.xml');
        if (!$file) {
            return [];
        }
        $dom = self::load_xml($file->get_content());
        if (!$dom) {
            return [];
        }
        $roots = (new \DOMXPath($dom))->query("//*[local-name()='courseStructure']");
        if (!$roots->length) {
            return [];
        }
        $items = [];
        self::walk_cmi5($roots->item(0), [], $items);
        return self::segments_from_html_items($ctx, $items);
    }

    /**
     * Shared back half of the HTML-based parsers: read each item's href page,
     * strip repeated chrome, and emit segments. Returns [] if no page has prose.
     *
     * @param \context_module $ctx
     * @param array $items Ordered [{title, section, href}].
     * @return array Segment list.
     */
    private static function segments_from_html_items(\context_module $ctx, array $items): array {
        if (empty($items)) {
            return [];
        }
        $pages = [];
        foreach ($items as $item) {
            $href = (string) $item['href'];
            // Skip external URLs (no local file / SSRF) and known player-shell pages.
            if ($href === '' || preg_match('#^https?://#i', $href) || self::is_driver_shell($href)) {
                continue;
            }
            $text = self::read_html_text($ctx, $href);
            // Skip essentially-empty pages (< 30 non-whitespace chars) - a player
            // shell or chrome-only page that slipped past the filename filter.
            if ($text === null || \core_text::strlen(preg_replace('/\s+/u', '', $text)) < 30) {
                continue;
            }
            $pages[] = ['title' => $item['title'], 'section' => $item['section'], 'text' => $text];
        }
        $pages = self::strip_repeated_lines($pages);

        $segments = [];
        $hasprose = false;
        foreach ($pages as $page) {
            $text = trim($page['text']);
            if ($text === '') {
                continue;
            }
            $hasprose = true;
            $segments[] = [
                'lesson_title'  => $page['title'],
                'prose'         => $text,
                'section_title' => $page['section'],
            ];
        }
        // No readable prose anywhere -> let the extractor fall back to Tier B titles.
        return $hasprose ? $segments : [];
    }

    /**
     * Parse an imsmanifest.xml (as a string) into an ordered list of content items.
     * Namespace-agnostic (matches on local-name), so SCORM 1.2 and 2004 both work.
     * Kept string-in / array-out so the manifest walk is testable without a package.
     *
     * @param string $xmlcontent
     * @return array Ordered list of ['title' => string, 'section' => ?string, 'href' => string].
     */
    protected static function generic_manifest_items(string $xmlcontent): array {
        $dom = self::load_xml($xmlcontent);
        if (!$dom) {
            return [];
        }
        $xpath = new \DOMXPath($dom);

        $xmlns = 'http://www.w3.org/XML/1998/namespace';
        // An xml:base on <resources> applies to every <resource> href beneath it.
        $resnodes = $xpath->query("//*[local-name()='resources']");
        $resourcesbase = $resnodes->length ? trim($resnodes->item(0)->getAttributeNS($xmlns, 'base'), '/') : '';

        // Index resources by identifier.
        $resources = [];
        foreach ($xpath->query("//*[local-name()='resource']") as $res) {
            $rid = $res->getAttribute('identifier');
            if ($rid === '') {
                continue;
            }
            // Combine the resources-level base with any resource-level base.
            $base = $resourcesbase;
            $rbase = trim($res->getAttributeNS($xmlns, 'base'), '/');
            if ($rbase !== '') {
                $base = ($base !== '') ? $base . '/' . $rbase : $rbase;
            }
            $resources[$rid] = [
                'href'      => $res->getAttribute('href'),
                'scormtype' => strtolower(self::dom_attr_ci($res, 'scormtype')),
                'xmlbase'   => $base,
            ];
        }

        // Pick the default organization (else the first).
        $org = null;
        $orgsnodes = $xpath->query("//*[local-name()='organizations']");
        $orglist = $xpath->query("//*[local-name()='organization']");
        $default = $orgsnodes->length ? $orgsnodes->item(0)->getAttribute('default') : '';
        foreach ($orglist as $candidate) {
            if ($default !== '' && $candidate->getAttribute('identifier') === $default) {
                $org = $candidate;
                break;
            }
        }
        if (!$org && $orglist->length) {
            $org = $orglist->item(0);
        }
        if (!$org) {
            return [];
        }

        $items = [];
        self::walk_generic_items($org, [], $resources, $items);
        return $items;
    }

    /**
     * Depth-first walk of <item> descendants in document order, resolving leaf
     * items to their content href and threading grouping-item titles as sections.
     *
     * @param \DOMElement $parent
     * @param array $sectionstack
     * @param array $resources id => [href, scormtype, xmlbase]
     * @param array $items (by reference)
     */
    private static function walk_generic_items(\DOMElement $parent, array $sectionstack,
            array $resources, array &$items): void {
        foreach ($parent->childNodes as $child) {
            if (!($child instanceof \DOMElement) || strcasecmp($child->localName, 'item') !== 0) {
                continue;
            }
            $title = self::dom_child_text($child, 'title');
            $idref = $child->getAttribute('identifierref');

            if ($idref !== '' && isset($resources[$idref])) {
                $res = $resources[$idref];
                if ($res['href'] !== '' && in_array($res['scormtype'], ['sco', 'asset', ''], true)) {
                    $items[] = [
                        'title'   => $title,
                        'section' => !empty($sectionstack) ? end($sectionstack) : null,
                        'href'    => self::resolve_href($res),
                    ];
                }
            }

            // A grouping item (no resource) contributes its title as its children's section.
            $childstack = $sectionstack;
            if ($idref === '' && $title !== '') {
                $childstack[] = $title;
            }
            self::walk_generic_items($child, $childstack, $resources, $items);
        }
    }

    /**
     * Depth-first walk of a cmi5 courseStructure: <au> are content units,
     * <block> group them (their title becomes the section).
     *
     * @param \DOMElement $parent
     * @param array $sectionstack
     * @param array $items (by reference)
     */
    private static function walk_cmi5(\DOMElement $parent, array $sectionstack, array &$items): void {
        foreach ($parent->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            $name = strtolower($child->localName);
            if ($name === 'au') {
                $items[] = [
                    'title'   => self::cmi5_title($child),
                    'section' => !empty($sectionstack) ? end($sectionstack) : null,
                    'href'    => trim(self::dom_child_text($child, 'url')),
                ];
            } else if ($name === 'block') {
                $stack = $sectionstack;
                $blocktitle = self::cmi5_title($child);
                if ($blocktitle !== '') {
                    $stack[] = $blocktitle;
                }
                self::walk_cmi5($child, $stack, $items);
            }
        }
    }

    /**
     * The text of a cmi5 element's <title><langstring> (first language).
     *
     * @param \DOMElement $el
     * @return string
     */
    private static function cmi5_title(\DOMElement $el): string {
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && strcasecmp($child->localName, 'title') === 0) {
                foreach ($child->childNodes as $ls) {
                    if ($ls instanceof \DOMElement && strcasecmp($ls->localName, 'langstring') === 0) {
                        return trim($ls->textContent);
                    }
                }
                return trim($child->textContent);
            }
        }
        return '';
    }

    /**
     * Read a package HTML file and strip it to plain text, or null if missing.
     *
     * @param \context_module $ctx
     * @param string $href Package-relative path.
     * @return string|null
     */
    private static function read_html_text(\context_module $ctx, string $href): ?string {
        $href = ltrim($href, '/');
        if ($href === '') {
            return null;
        }
        $dir = dirname($href);
        $filepath = ($dir === '.' || $dir === '') ? '/' : '/' . trim($dir, '/') . '/';
        $fs = get_file_storage();
        $file = $fs->get_file($ctx->id, 'mod_scorm', 'content', 0, $filepath, basename($href));
        if (!$file) {
            return null;
        }
        // html_to_text($html, $width, $dolinks): width=0 = no hard wrap,
        // dolinks=false = drop the "[1] http://..." link-reference tails.
        return trim(html_to_text($file->get_content(), 0, false));
    }

    /**
     * Normalise a resource href: URL-decode, drop query/fragment, apply xml:base.
     *
     * @param array $res
     * @return string
     */
    private static function resolve_href(array $res): string {
        $href = preg_replace('/[?#].*$/', '', urldecode((string) $res['href']));
        if (!empty($res['xmlbase'])) {
            $href = trim($res['xmlbase'], '/') . '/' . ltrim($href, '/');
        }
        return $href;
    }

    /**
     * Whether an href is a known SCORM player/driver shell page (no learner content).
     *
     * @param string $href
     * @return bool
     */
    private static function is_driver_shell(string $href): bool {
        $lc = strtolower($href);
        // Path fragments (a whole directory of player/driver code).
        foreach (['scormdriver/', '/lms/'] as $fragment) {
            if (strpos($lc, $fragment) !== false) {
                return true;
            }
        }
        // Exact shell filenames - matched on the basename so a real content page
        // like "myblank.html" or "success-story.html" is NOT caught.
        $shellfiles = [
            'indexapi.html', 'index_lms.html', 'story.html', 'analytics-frame.html',
            'goodbye.html', 'blank.html', 'aicccomm.html', 'preloadintegrity.js', 'configuration.js',
        ];
        return in_array(basename($lc), $shellfiles, true);
    }

    /**
     * Drop lines that repeat across most pages (nav/header/footer chrome). Cheap
     * intra-package pass; cross-chunk dedup is the volume ticket's job.
     *
     * @param array $pages
     * @return array
     */
    private static function strip_repeated_lines(array $pages): array {
        // Nav/header/footer lines recurring across >= 60% of pages; keep >= 30 chars.
        return self::drop_frequent_lines($pages, 'text', 0.6, null, 30);
    }

    /**
     * Load XML safely (no network, no external entities), or null on parse error.
     *
     * @param string $xml
     * @return \DOMDocument|null
     */
    private static function load_xml(string $xml): ?\DOMDocument {
        if (trim($xml) === '') {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $dom : null;
    }

    /**
     * First direct-child element with the given local name, as trimmed text.
     *
     * @param \DOMElement $el
     * @param string $localname
     * @return string
     */
    private static function dom_child_text(\DOMElement $el, string $localname): string {
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && strcasecmp($child->localName, $localname) === 0) {
                return trim($child->textContent);
            }
        }
        return '';
    }

    /**
     * An element's attribute value matched by local name (namespace-agnostic).
     *
     * @param \DOMElement $el
     * @param string $localname
     * @return string
     */
    private static function dom_attr_ci(\DOMElement $el, string $localname): string {
        if ($el->hasAttributes()) {
            foreach ($el->attributes as $attr) {
                if (strcasecmp($attr->localName, $localname) === 0) {
                    return (string) $attr->nodeValue;
                }
            }
        }
        return '';
    }

    /**
     * Assemble Tier A chunks from parser segments: one chunk per segment
     * (full Activity/Package/Section/Lesson header + prose), oversized prose
     * split on paragraph boundaries with the header re-prepended to each part.
     * (The dedup/floor/merge volume pipeline is added in a later slice.)
     *
     * @param array $segments
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return array Chunk dicts.
     */
    protected static function assemble_chunks(array $segments, \cm_info $cm, \stdClass $course): array {
        // Volume pipeline: dedup boilerplate -> fill sections -> drop thin segments
        // -> merge within section -> (chunk_with_header applies the split safety net).
        $segments = self::dedup_boilerplate($segments);
        $segments = self::forward_fill_sections($segments);

        $minprose = self::int_setting('scorm_min_prose_chars', self::DEFAULT_MIN_PROSE_CHARS);
        $kept = [];
        foreach ($segments as $seg) {
            if (\core_text::strlen(trim((string) $seg['prose'])) >= $minprose) {
                $kept[] = $seg;
            }
        }

        $target = self::int_setting('scorm_merge_target_chars', self::DEFAULT_MERGE_TARGET_CHARS);
        $groups = self::merge_segments($kept, $target);

        $package = self::package_title($cm);
        $section = self::section_name($cm, $course);
        $chunks = [];
        // Chunk titles must be unique within an activity (they carry the DB unique
        // index): disambiguate groups that share a title.
        $seentitles = [];

        foreach ($groups as $group) {
            $body = self::group_body($group['segs']);
            if ($body === '') {
                continue;
            }
            $lessonlabel = self::group_lesson_label($group['segs']);
            $header = self::header_block($cm->name, $package, $section, $lessonlabel, $group['section']);
            // Cap the breadcrumb so the disambiguator and any "(part n/total)" suffix
            // always survive the 255-char chunk_title limit (which the unique index needs).
            $basetitle = \core_text::substr(
                $cm->name . ' › ' . $package . ($lessonlabel !== '' ? ' › ' . $lessonlabel : ''),
                0, self::TITLE_BREADCRUMB_MAX
            );
            if (isset($seentitles[$basetitle])) {
                $basetitle .= ' [' . (++$seentitles[$basetitle]) . ']';
            } else {
                $seentitles[$basetitle] = 1;
            }
            $chunks = array_merge($chunks, self::chunk_with_header($header, $body, $cm->id, $basetitle));
        }
        return $chunks;
    }

    /**
     * Drop boilerplate chrome: short lines (<= 60 chars) that recur across >= 50%
     * of the package's segments (nav/quiz/footer text). Language-agnostic.
     *
     * @param array $segments
     * @return array
     */
    private static function dedup_boilerplate(array $segments): array {
        // Short lines (<= 60 chars) recurring across >= 50% of segments are chrome.
        return self::drop_frequent_lines($segments, 'prose', 0.5, 60, null);
    }

    /**
     * Drop lines that recur across a ratio of items (nav/header/footer chrome),
     * shared by the segment dedup and the generic-parser page dedup.
     *
     * @param array $items List of associative rows.
     * @param string $key The text field on each row.
     * @param float $ratio Drop a line present in >= ceil(ratio * count) items.
     * @param int|null $maxlen Only consider lines up to this length (null = any).
     * @param int|null $minkeep If set, keep a row's original text when stripping
     *        would leave fewer than this many characters.
     * @return array
     */
    private static function drop_frequent_lines(array $items, string $key, float $ratio,
            ?int $maxlen, ?int $minkeep): array {
        $total = count($items);
        if ($total < 2) {
            return $items;
        }
        $linecount = [];
        foreach ($items as $item) {
            $seen = [];
            foreach (preg_split('/\n/', (string) $item[$key]) as $line) {
                $t = trim($line);
                if ($t === '' || ($maxlen !== null && \core_text::strlen($t) > $maxlen) || isset($seen[$t])) {
                    continue;
                }
                $seen[$t] = true;
                $linecount[$t] = ($linecount[$t] ?? 0) + 1;
            }
        }
        $threshold = max(2, (int) ceil($ratio * $total));
        $drop = [];
        foreach ($linecount as $line => $count) {
            if ($count >= $threshold) {
                $drop[$line] = true;
            }
        }
        if (empty($drop)) {
            return $items;
        }
        foreach ($items as &$item) {
            $kept = [];
            foreach (preg_split('/\n/', (string) $item[$key]) as $line) {
                $t = trim($line);
                if (($maxlen === null || \core_text::strlen($t) <= $maxlen) && isset($drop[$t])) {
                    continue;
                }
                $kept[] = $line;
            }
            $stripped = trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $kept)));
            if ($minkeep === null || \core_text::strlen($stripped) >= $minkeep) {
                $item[$key] = $stripped;
            }
        }
        unset($item);
        return $items;
    }

    /**
     * Forward-fill empty section titles from the preceding segment, so slides
     * that sit between two section headings merge with their section instead of
     * fragmenting the within-section merge. Segments before the first heading
     * stay unsectioned.
     *
     * @param array $segments
     * @return array
     */
    private static function forward_fill_sections(array $segments): array {
        $last = null;
        foreach ($segments as &$seg) {
            if (!empty($seg['section_title'])) {
                $last = $seg['section_title'];
            } else if ($last !== null) {
                $seg['section_title'] = $last;
            }
        }
        unset($seg);
        return $segments;
    }

    /**
     * Group consecutive segments that share a section, packing prose up to the
     * merge target. A segment larger than the target stands alone (and is split
     * later by chunk_with_header).
     *
     * @param array $segments
     * @param int $target
     * @return array List of ['section' => ?string, 'segs' => array].
     */
    private static function merge_segments(array $segments, int $target): array {
        $groups = [];
        $current = null;
        foreach ($segments as $seg) {
            $len = \core_text::strlen(trim((string) $seg['prose']));
            $section = $seg['section_title'] ?? null;
            if ($current !== null && $current['section'] === $section
                    && ($current['len'] + $len) <= $target) {
                $current['segs'][] = $seg;
                $current['len'] += $len;
            } else {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = ['section' => $section, 'segs' => [$seg], 'len' => $len];
            }
        }
        if ($current !== null) {
            $groups[] = $current;
        }
        return $groups;
    }

    /**
     * The body text for a merged group: a single segment's prose, or each
     * segment's title + prose stacked so per-lesson titles stay in the chunk.
     *
     * @param array $segs
     * @return string
     */
    private static function group_body(array $segs): string {
        if (count($segs) === 1) {
            return trim((string) $segs[0]['prose']);
        }
        $parts = [];
        foreach ($segs as $seg) {
            $title = trim((string) $seg['lesson_title']);
            $prose = trim((string) $seg['prose']);
            if ($prose === '') {
                continue;
            }
            $parts[] = ($title !== '') ? $title . "\n" . $prose : $prose;
        }
        return trim(implode("\n\n", $parts));
    }

    /**
     * The "Lesson" label for a group: the single title, or "first … last".
     *
     * @param array $segs
     * @return string
     */
    private static function group_lesson_label(array $segs): string {
        $titles = [];
        foreach ($segs as $seg) {
            $t = trim((string) $seg['lesson_title']);
            if ($t !== '') {
                $titles[] = $t;
            }
        }
        if (empty($titles)) {
            return '';
        }
        if (count($titles) === 1) {
            return $titles[0];
        }
        return $titles[0] . ' … ' . end($titles);
    }

    /**
     * Read a positive-integer admin setting, falling back to a default.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    private static function int_setting(string $name, int $default): int {
        $val = get_config('local_aichat', $name);
        return ($val !== false && (int) $val > 0) ? (int) $val : $default;
    }

    /**
     * Build the 4-line header block that leads every Tier A chunk.
     *
     * @param string $activity
     * @param string $package
     * @param string $section Moodle course section name ('' to omit).
     * @param string $lesson
     * @param string|null $sectiontitle Package-internal grouping, prepended to the lesson line.
     * @return string
     */
    protected static function header_block(string $activity, string $package, string $section,
            string $lesson, ?string $sectiontitle): string {
        $lines = [
            'Activity: ' . $activity,
            'Package: ' . $package,
        ];
        if ($section !== '') {
            $lines[] = 'Section: ' . $section;
        }
        $lessonline = ($sectiontitle !== null && $sectiontitle !== '') ? $sectiontitle . ' › ' . $lesson : $lesson;
        if ($lessonline !== '') {
            $lines[] = 'Lesson: ' . $lessonline;
        }
        return implode("\n", $lines);
    }

    /**
     * Produce one or more chunks for a header + prose pair, splitting the prose
     * on paragraph boundaries when the combined text exceeds MAX_CHUNK_CHARS and
     * re-prepending the header to each part.
     *
     * @param string $header
     * @param string $prose
     * @param int $cmid
     * @param string $basetitle
     * @return array Chunk dicts.
     */
    private static function chunk_with_header(string $header, string $prose, int $cmid, string $basetitle): array {
        $make = function(string $body, string $title) use ($header, $cmid): array {
            return [
                'chunk_type'   => 'scorm',
                'chunk_id'     => $cmid,
                'chunk_title'  => \core_text::substr($title, 0, 255),
                'content_text' => $header . "\n\n" . $body,
            ];
        };

        if (\core_text::strlen($header . "\n\n" . $prose) <= self::MAX_CHUNK_CHARS) {
            return [$make($prose, $basetitle)];
        }

        $budget = self::MAX_CHUNK_CHARS - \core_text::strlen($header) - 2;
        $parts = self::split_prose($prose, $budget);

        $chunks = [];
        $total = count($parts);
        foreach ($parts as $i => $part) {
            // $basetitle is already capped at TITLE_BREADCRUMB_MAX, so this suffix survives.
            $chunks[] = $make($part, $basetitle . ' (part ' . ($i + 1) . '/' . $total . ')');
        }
        return $chunks;
    }

    /**
     * Split prose into parts each within $budget characters, packing on paragraph
     * boundaries and hard-splitting any single paragraph that alone exceeds $budget.
     *
     * @param string $prose
     * @param int $budget Max characters per part.
     * @return string[]
     */
    private static function split_prose(string $prose, int $budget): array {
        if ($budget < 1) {
            $budget = self::MAX_CHUNK_CHARS;
        }
        // Break oversized single paragraphs down first so no unit exceeds the budget.
        $units = [];
        foreach (preg_split('/\n{2,}/', $prose) as $para) {
            $len = \core_text::strlen($para);
            if ($len <= $budget) {
                $units[] = $para;
            } else {
                for ($offset = 0; $offset < $len; $offset += $budget) {
                    $units[] = \core_text::substr($para, $offset, $budget);
                }
            }
        }
        // Pack units into parts up to the budget.
        $parts = [];
        $current = '';
        foreach ($units as $unit) {
            if ($current !== '' && \core_text::strlen($current . "\n\n" . $unit) > $budget) {
                $parts[] = $current;
                $current = $unit;
            } else {
                $current = ($current === '') ? $unit : $current . "\n\n" . $unit;
            }
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /**
     * Tier B: build one chunk from the activity + package/SCO titles in the DB.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return array|null A chunk dict, or null if no usable titles exist.
     */
    protected static function titles_tier(\cm_info $cm, \stdClass $course): ?array {
        $titles = self::sco_titles($cm);
        if (empty($titles)) {
            return null;
        }

        $package = $titles[0];
        $section = self::section_name($cm, $course);

        $lines = [
            'Activity: ' . $cm->name,
            'Package: ' . $package,
        ];
        if ($section !== '') {
            $lines[] = 'Section: ' . $section;
        }
        // Any further distinct SCO titles beyond the package title.
        foreach (array_slice($titles, 1) as $extra) {
            $lines[] = $extra;
        }
        $content = implode("\n", $lines);

        return [
            'chunk_type'  => 'scorm',
            'chunk_id'    => $cm->id,
            'chunk_title' => \core_text::substr($cm->name . ' › ' . $package, 0, 255),
            'content_text' => $content,
        ];
    }

    /**
     * Distinct, non-empty SCO / organization titles for the package, in SCO order.
     *
     * @param \cm_info $cm
     * @return string[]
     */
    protected static function sco_titles(\cm_info $cm): array {
        global $DB;

        $scoes = $DB->get_records('scorm_scoes', ['scorm' => $cm->instance], 'id ASC', 'id, title');
        $titles = [];
        foreach ($scoes as $sco) {
            $t = trim((string) $sco->title);
            if ($t !== '' && !in_array($t, $titles, true)) {
                $titles[] = $t;
            }
        }
        return $titles;
    }

    /**
     * The package's display title (first SCO/organization title, else the activity name).
     *
     * @param \cm_info $cm
     * @return string
     */
    protected static function package_title(\cm_info $cm): string {
        $titles = self::sco_titles($cm);
        return !empty($titles) ? $titles[0] : $cm->name;
    }

    /**
     * Tier C: the original behaviour - activity name + intro.
     *
     * @param \cm_info $cm
     * @return array List with one chunk, or empty if there is nothing to index.
     */
    protected static function name_intro_tier(\cm_info $cm): array {
        global $DB;

        $parts = [$cm->name];
        $record = $DB->get_record('scorm', ['id' => $cm->instance], 'intro');
        if ($record && !empty($record->intro)) {
            $parts[] = html_to_text($record->intro, 0, false);
        }
        $content = trim(implode("\n", $parts));
        if ($content === '') {
            return [];
        }
        return [[
            'chunk_type'  => 'scorm',
            'chunk_id'    => $cm->id,
            'chunk_title' => \core_text::substr($cm->name, 0, 255),
            'content_text' => $content,
        ]];
    }

    /**
     * Resolve the Moodle course section name the activity sits in.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return string Section name, or '' if unavailable.
     */
    protected static function section_name(\cm_info $cm, \stdClass $course): string {
        $name = get_section_name($course, $cm->sectionnum);
        if ($name === null) {
            return '';
        }
        // get_section_name() returns format_string() output (HTML-encoded); decode
        // entities so the header text embedded for RAG is clean ("&" not "&amp;").
        return trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Emit a developer-debug line recording which fidelity tier a package reached.
     *
     * @param \cm_info $cm
     * @param string $tier
     * @param string $reason
     */
    protected static function log_tier(\cm_info $cm, string $tier, string $reason): void {
        debugging(
            "local_aichat scorm_extractor: cmid {$cm->id} -> tier {$tier} ({$reason})",
            DEBUG_DEVELOPER
        );
    }
}
