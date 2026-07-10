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
    public static function extract_chunks(\cm_info $cm, \stdClass $course, array $existingrows = []): array {
        // Containment: any failure extracting one SCORM package degrades to a lower
        // tier (or nothing) - it must never abort indexing of the whole course.
        try {
            $ctx = \context_module::instance($cm->id);
            $format = self::detect_format($ctx);

            // Tier A - full package prose via the per-format parser.
            $segments = self::parse($format, $ctx);
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
        } catch (\Exception $e) {
            debugging(
                "local_aichat scorm_extractor: cmid {$cm->id} extraction failed, skipping ("
                    . $e->getMessage() . ')',
                DEBUG_DEVELOPER
            );
            return [];
        }
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
     * @return array Segment list.
     */
    protected static function parse(string $format, \context_module $ctx): array {
        switch ($format) {
            case 'rise':
                return self::parse_rise($ctx);
            case 'storyline':
                return self::parse_storyline($ctx);
            // 'generic', 'cmi5' parsers land in later slices.
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
     * @return array Segment list (empty on failure -> Tier B fallback).
     */
    protected static function parse_storyline(\context_module $ctx): array {
        $data = self::read_gpd($ctx, '/html5/data/js/', 'data.js');
        if (!is_array($data) || empty($data['scenes'])) {
            debugging('local_aichat scorm_extractor: Storyline data.js unreadable, falling back to titles',
                DEBUG_DEVELOPER);
            return [];
        }
        $sectionmap = self::storyline_section_map($ctx);

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
        $package = self::package_title($cm);
        $section = self::section_name($cm, $course);
        $chunks = [];
        // Chunk titles must be unique within an activity (they carry the DB unique
        // index): disambiguate segments that share a title (e.g. two Storyline
        // slides both called "Introduction").
        $seentitles = [];

        foreach ($segments as $seg) {
            $prose = trim((string) $seg['prose']);
            if ($prose === '') {
                continue;
            }
            $lesson = trim((string) $seg['lesson_title']);
            $header = self::header_block($cm->name, $package, $section, $lesson, $seg['section_title'] ?? null);
            // Cap the breadcrumb so the disambiguator and any "(part n/total)" suffix
            // always survive the 255-char chunk_title limit (which the unique index needs).
            $basetitle = \core_text::substr(
                $cm->name . ' › ' . $package . ($lesson !== '' ? ' › ' . $lesson : ''),
                0, self::TITLE_BREADCRUMB_MAX
            );
            if (isset($seentitles[$basetitle])) {
                $basetitle .= ' [' . (++$seentitles[$basetitle]) . ']';
            } else {
                $seentitles[$basetitle] = 1;
            }
            $chunks = array_merge($chunks, self::chunk_with_header($header, $prose, $cm->id, $basetitle));
        }
        return $chunks;
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
