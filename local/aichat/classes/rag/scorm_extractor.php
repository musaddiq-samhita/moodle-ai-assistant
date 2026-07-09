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

            // Tier A - per-format parsers (added in later slices). Stubbed for now.
            $segments = self::parse($format, $ctx);
            if (!empty($segments)) {
                // Assembly of Tier A chunks arrives with the parser slices.
                return self::assemble_chunks($segments, $cm, $course);
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
     * Per-format parser dispatch. Stubbed in slice 1 (returns no segments);
     * the rise/storyline/generic parsers are filled in by later slices.
     *
     * @param string $format
     * @param \context_module $ctx
     * @return array Segment list (empty for now).
     */
    protected static function parse(string $format, \context_module $ctx): array {
        return [];
    }

    /**
     * Tier A chunk assembly. Introduced with the first parser slice.
     *
     * @param array $segments
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return array
     */
    protected static function assemble_chunks(array $segments, \cm_info $cm, \stdClass $course): array {
        return [];
    }

    /**
     * Tier B: build one chunk from the activity + package/SCO titles in the DB.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @return array|null A chunk dict, or null if no usable titles exist.
     */
    protected static function titles_tier(\cm_info $cm, \stdClass $course): ?array {
        global $DB;

        $scoes = $DB->get_records('scorm_scoes', ['scorm' => $cm->instance], 'id ASC', 'id, title');
        $titles = [];
        foreach ($scoes as $sco) {
            $t = trim((string) $sco->title);
            if ($t !== '' && !in_array($t, $titles, true)) {
                $titles[] = $t;
            }
        }
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
        return $name !== null ? trim((string) $name) : '';
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
