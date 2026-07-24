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
 * AI Chat - SCORM video transcription cache.
 *
 * Repository over local_aichat_transcriptions. Each unique video (Moodle file
 * contenthash) is transcribed once per transcription policy (provider + model +
 * recipe). A policy change yields a new policyhash so stale results are never
 * reused; content-specific outcomes (empty/undecodable) are cached, while
 * provider/config failures are NOT cached as permanent.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Cache of SCORM video transcriptions keyed by (contenthash, policyhash).
 */
class transcription_cache {

    /** @var string Transcription produced usable text. */
    const STATUS_SUCCESS = 'success';

    /** @var string Provider returned no/blank text for decodable audio. */
    const STATUS_EMPTY = 'empty';

    /** @var string Provider could not decode the file (e.g. no audio track). */
    const STATUS_UNDECODABLE = 'undecodable';

    /** @var string Transient failure (429/5xx/network); retry after backoff. */
    const STATUS_RETRYABLE = 'retryable_error';

    /** @var string Content-specific hard failure (e.g. oversized file). */
    const STATUS_PERMANENT = 'permanent_error';

    /** @var string Recipe version — bump to invalidate all cached results on a pipeline change. */
    const RECIPE_VERSION = 'v1';

    /** @var int Base retry backoff (seconds). */
    const RETRY_BASE_SECONDS = 300;

    /** @var int Maximum retry backoff (seconds). */
    const RETRY_MAX_SECONDS = 21600;

    /**
     * Policy hash identifying the current transcription recipe. A change to the
     * provider, model, or recipe version invalidates cached results.
     *
     * @return string 40-char sha1.
     */
    public static function policy_hash(): string {
        $provider = \local_aichat\azure_openai_client::get_provider();
        $model = trim((string) get_config('local_aichat', 'transcriptionmodel'));
        if ($model === '') {
            $model = 'whisper-1';
        }
        return sha1($provider . '|' . $model . '|recipe:' . self::RECIPE_VERSION);
    }

    /**
     * Fetch the cached row for a content hash under the current policy.
     *
     * @param string $contenthash
     * @return \stdClass|null
     */
    public static function lookup(string $contenthash) {
        global $DB;
        $row = $DB->get_record('local_aichat_transcriptions', [
            'contenthash' => $contenthash,
            'policyhash'  => self::policy_hash(),
        ]);
        return $row ?: null;
    }

    /**
     * Whether a fresh transcription attempt should be made given the cached row.
     *
     * true  = no usable cached outcome (no row, or a retryable error now due).
     * false = a usable outcome exists (success/empty/undecodable/permanent), or a
     *         retryable error is still within its backoff window.
     *
     * @param \stdClass|null $row Result of lookup().
     * @return bool
     */
    public static function should_attempt($row): bool {
        if ($row === null) {
            return true;
        }
        if ($row->status === self::STATUS_RETRYABLE) {
            return (int) $row->retryafter <= time();
        }
        return false;
    }

    /**
     * The usable transcript for a cached row, or null if none.
     *
     * @param \stdClass|null $row
     * @return string|null
     */
    public static function usable_transcript($row) {
        if ($row !== null && $row->status === self::STATUS_SUCCESS) {
            return (string) $row->transcript;
        }
        return null;
    }

    /**
     * Upsert an outcome for (contenthash, current policy).
     *
     * @param string $contenthash
     * @param string $status One of the STATUS_* constants.
     * @param array $data {transcript?, language?, provider?, model?, lasterror?, retryafter?}
     */
    public static function store(string $contenthash, string $status, array $data = []): void {
        global $DB;

        $now = time();
        $policyhash = self::policy_hash();
        $existing = $DB->get_record('local_aichat_transcriptions', [
            'contenthash' => $contenthash,
            'policyhash'  => $policyhash,
        ]);

        $record = $existing ?: new \stdClass();
        $record->contenthash  = $contenthash;
        $record->policyhash   = $policyhash;
        $record->status       = $status;
        $record->transcript   = isset($data['transcript']) ? $data['transcript'] : null;
        $record->language     = isset($data['language']) ? $data['language'] : null;
        $record->provider     = isset($data['provider']) ? $data['provider'] : null;
        $record->model        = isset($data['model']) ? $data['model'] : null;
        $record->lasterror    = isset($data['lasterror']) ? $data['lasterror'] : null;
        $record->retryafter   = isset($data['retryafter']) ? (int) $data['retryafter'] : null;
        $record->attemptcount = ($existing ? (int) $existing->attemptcount : 0) + 1;
        $record->timemodified = $now;

        if ($existing) {
            $DB->update_record('local_aichat_transcriptions', $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record('local_aichat_transcriptions', $record);
        }
    }

    /**
     * Compute the next allowed retry time for a retryable failure.
     *
     * Honors a provider Retry-After (seconds) when present, otherwise applies
     * bounded exponential backoff on the attempt count.
     *
     * @param int $attemptcount Attempts made so far (after this one).
     * @param int|null $retryafterheader Retry-After header value in seconds, if any.
     * @return int Unix timestamp.
     */
    public static function next_retry_after(int $attemptcount, $retryafterheader = null): int {
        if ($retryafterheader !== null && (int) $retryafterheader > 0) {
            return time() + (int) $retryafterheader;
        }
        $exp = self::RETRY_BASE_SECONDS * (2 ** max(0, $attemptcount - 1));
        return time() + (int) min($exp, self::RETRY_MAX_SECONDS);
    }

    /**
     * Administrator invalidation: delete cached rows so they will be recomputed.
     *
     * @param string|null $contenthash Limit to one video, or null for all.
     * @return int Rows deleted.
     */
    public static function invalidate($contenthash = null): int {
        global $DB;
        $conditions = [];
        if ($contenthash !== null) {
            $conditions['contenthash'] = $contenthash;
        }
        $count = $DB->count_records('local_aichat_transcriptions', $conditions);
        $DB->delete_records('local_aichat_transcriptions', $conditions);
        return $count;
    }
}
