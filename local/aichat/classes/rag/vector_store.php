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
 * AI Chat - Vector store
 *
 * Manages course content embeddings in the database.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Vector store for course content embeddings.
 */
class vector_store {

    /**
     * Index (or re-index) all content for a course.
     *
     * @param int $courseid The course ID.
     * @param bool $force Force a full re-parse (a manual rebuild), bypassing the
     *        extractor's unchanged-source skip. Embeddings are still only rewritten
     *        when the resulting content actually changed.
     * @return array {indexed: int, skipped: int, deleted: int}
     */
    public static function index_course(int $courseid, bool $force = false, bool $allowtranscription = false): array {
        global $DB;

        // Serialize the whole read/extract/embed/write/delete cycle per course so
        // cron and a manual rebuild cannot race (double-embed or delete on a stale
        // snapshot). If another indexer holds the lock, skip this run.
        $lock = index_lock::course($courseid, 0);
        if (!$lock) {
            return ['indexed' => 0, 'skipped' => 0, 'deleted' => 0, 'locked' => true];
        }

        try {
            return self::index_course_locked($courseid, $force, $allowtranscription);
        } finally {
            $lock->release();
        }
    }

    /**
     * The body of index_course(), run while holding the course-index lock.
     *
     * @param int $courseid
     * @param bool $force
     * @param bool $allowtranscription
     * @return array {indexed, skipped, deleted}
     */
    private static function index_course_locked(int $courseid, bool $force, bool $allowtranscription): array {
        global $DB;

        $indexed = 0;
        $skipped = 0;
        $now = time();

        // Load existing embeddings first: the extractor uses them to skip re-parsing
        // unchanged sources (e.g. a SCORM package whose fingerprint is unchanged).
        // A forced rebuild passes no rows to the extractor, so it always re-parses.
        $existing = $DB->get_records('local_aichat_embeddings', ['courseid' => $courseid]);
        $failedsources = [];
        $chunks = content_extractor::extract_course_content(
            $courseid, $force ? [] : $existing, $allowtranscription, $failedsources);

        // Sources whose extraction failed this run: their existing rows must be
        // preserved (never treated as "removed" and deleted).
        $failedset = [];
        foreach ($failedsources as $fs) {
            $failedset[$fs['chunk_type'] . '::' . $fs['chunk_id']] = true;
        }

        $existingmap = [];
        $existingids = [];
        $existingsource = [];
        foreach ($existing as $record) {
            // Key includes the title so multiple sub-chunks of one activity
            // (e.g. a split lesson: "… (part 1/2)", "… (part 2/2)") are distinct rows.
            $key = self::chunk_key($record->chunk_type, $record->chunk_id, $record->chunk_title);
            $existingmap[$key] = $record;
            $existingids[$record->id] = $key;
            $existingsource[$record->id] = $record->chunk_type . '::' . $record->chunk_id;
        }

        // Track which chunk keys are still active.
        $activekeys = [];
        $toembed = [];
        $toembedmeta = [];

        foreach ($chunks as $chunk) {
            $key = self::chunk_key($chunk['chunk_type'], $chunk['chunk_id'], $chunk['chunk_title']);
            $hash = hash('sha256', $chunk['content_text']);
            $activekeys[$key] = true;

            // Check if this chunk exists and is unchanged.
            if (isset($existingmap[$key]) && $existingmap[$key]->content_hash === $hash) {
                // Content is unchanged, so no re-embed - but keep source_hash current
                // (cheap field update) so the extractor's pre-parse skip can fire next run.
                $sourcehash = $chunk['source_hash'] ?? null;
                if ($sourcehash !== null && ($existingmap[$key]->source_hash ?? null) !== $sourcehash) {
                    $DB->set_field('local_aichat_embeddings', 'source_hash', $sourcehash,
                        ['id' => $existingmap[$key]->id]);
                }
                $skipped++;
                continue;
            }

            // Queue for embedding.
            $toembed[] = $chunk['content_text'];
            $toembedmeta[] = array_merge($chunk, ['content_hash' => $hash]);
        }

        // Embed new/changed chunks in batches.
        if (!empty($toembed)) {
            $vectors = embedding_client::embed_batch($toembed);

            foreach ($toembedmeta as $i => $meta) {
                $key = self::chunk_key($meta['chunk_type'], $meta['chunk_id'], $meta['chunk_title']);
                $record = new \stdClass();
                $record->courseid = $courseid;
                $record->chunk_type = $meta['chunk_type'];
                $record->chunk_id = $meta['chunk_id'];
                $record->chunk_title = $meta['chunk_title'];
                $record->content_text = $meta['content_text'];
                $record->content_hash = $meta['content_hash'];
                $record->source_hash = $meta['source_hash'] ?? null;
                $record->embedding = json_encode($vectors[$i]);
                $record->token_count = (int) ceil(\core_text::strlen($meta['content_text']) / 4);
                $record->timemodified = $now;

                if (isset($existingmap[$key])) {
                    // Update existing record.
                    $record->id = $existingmap[$key]->id;
                    $DB->update_record('local_aichat_embeddings', $record);
                } else {
                    // Insert new record.
                    $record->timecreated = $now;
                    $DB->insert_record('local_aichat_embeddings', $record);
                }
                $indexed++;
            }
        }

        // Delete orphaned embeddings (activities/sections removed from course).
        // A source whose extraction FAILED this run is preserved: absence of fresh
        // chunks there means "could not extract", not "content removed".
        $deleted = 0;
        foreach ($existingids as $id => $key) {
            if (!isset($activekeys[$key])) {
                if (isset($existingsource[$id]) && isset($failedset[$existingsource[$id]])) {
                    continue;
                }
                $DB->delete_records('local_aichat_embeddings', ['id' => $id]);
                $deleted++;
            }
        }

        return [
            'indexed' => $indexed,
            'skipped' => $skipped,
            'deleted' => $deleted,
        ];
    }

    /**
     * Build the in-memory identity key for an embedding chunk.
     *
     * Includes the title so that an activity that yields several chunks
     * (a large page or SCORM lesson split into parts) maps to several distinct
     * rows rather than collapsing onto one.
     *
     * @param string $chunktype
     * @param int $chunkid
     * @param string $chunktitle
     * @return string
     */
    private static function chunk_key(string $chunktype, int $chunkid, string $chunktitle): string {
        return $chunktype . '_' . $chunkid . '_' . $chunktitle;
    }

    /**
     * Search for relevant course content chunks using cosine similarity.
     *
     * @param int $courseid The course ID.
     * @param string $query The user's query text.
     * @param int $topk Maximum number of results to return.
     * @return array Matching chunks with similarity scores.
     */
    public static function search(int $courseid, string $query, int $topk = 0): array {
        global $DB;

        if ($topk <= 0) {
            $topk = (int) get_config('local_aichat', 'ragtopk') ?: 5;
        }
        $threshold = (float) get_config('local_aichat', 'ragthreshold') ?: 0.3;

        // Embed the query.
        $queryvector = embedding_client::embed($query);

        // Load all embeddings for this course.
        $records = $DB->get_records('local_aichat_embeddings', ['courseid' => $courseid]);
        if (empty($records)) {
            return [];
        }

        // Calculate cosine similarity for each chunk.
        $results = [];
        foreach ($records as $record) {
            if (empty($record->embedding)) {
                continue;
            }
            $vector = json_decode($record->embedding, true);
            if (!is_array($vector)) {
                continue;
            }
            $similarity = self::cosine_similarity($queryvector, $vector);
            if ($similarity >= $threshold) {
                $results[] = [
                    'chunk_title' => $record->chunk_title,
                    'content_text' => $record->content_text,
                    'chunk_type' => $record->chunk_type,
                    'chunk_id' => $record->chunk_id,
                    'similarity_score' => $similarity,
                    'token_count' => $record->token_count,
                ];
            }
        }

        // Sort by similarity descending.
        usort($results, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);

        // Return top-K results.
        return array_slice($results, 0, $topk);
    }

    /**
     * Get index statistics for a course.
     *
     * @param int $courseid The course ID.
     * @return array {chunk_count: int, last_indexed: int, total_tokens: int}
     */
    public static function get_index_stats(int $courseid): array {
        global $DB;

        $count = $DB->count_records('local_aichat_embeddings', ['courseid' => $courseid]);
        $totaltokens = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(token_count), 0) FROM {local_aichat_embeddings} WHERE courseid = :cid",
            ['cid' => $courseid]
        );
        $lastindexed = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(timemodified), 0) FROM {local_aichat_embeddings} WHERE courseid = :cid",
            ['cid' => $courseid]
        );

        return [
            'chunk_count' => $count,
            'last_indexed' => $lastindexed,
            'total_tokens' => $totaltokens,
        ];
    }

    /**
     * Delete all embeddings for a course.
     *
     * @param int $courseid The course ID.
     */
    public static function delete_course_index(int $courseid): void {
        global $DB;
        $DB->delete_records('local_aichat_embeddings', ['courseid' => $courseid]);
    }

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array $a First vector.
     * @param array $b Second vector.
     * @return float Cosine similarity (0-1).
     */
    private static function cosine_similarity(array $a, array $b): float {
        $dotproduct = 0.0;
        $magnitudea = 0.0;
        $magnitudeb = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dotproduct += $a[$i] * $b[$i];
            $magnitudea += $a[$i] * $a[$i];
            $magnitudeb += $b[$i] * $b[$i];
        }

        $magnitudea = sqrt($magnitudea);
        $magnitudeb = sqrt($magnitudeb);

        if ($magnitudea == 0 || $magnitudeb == 0) {
            return 0.0;
        }

        return $dotproduct / ($magnitudea * $magnitudeb);
    }
}
