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
 * AI Chat - Indexing/transcription locks.
 *
 * Thin wrapper over Moodle's Lock API. A course-index lock serializes the whole
 * read/extract/embed/write/delete cycle of vector_store::index_course() so cron
 * and a manual rebuild cannot race. A per-video lock ensures a single unique
 * video is transcribed by only one process at a time.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Lock helpers for indexing and transcription.
 */
class index_lock {

    /** @var string Lock factory type for whole-course indexing. */
    const TYPE_INDEX = 'local_aichat_index';

    /** @var string Lock factory type for per-video transcription. */
    const TYPE_TRANSCRIPTION = 'local_aichat_transcription';

    /**
     * Acquire the course-index lock.
     *
     * @param int $courseid
     * @param int $timeout Seconds to wait (0 = do not wait).
     * @return \core\lock\lock|false The lock, or false if it could not be obtained.
     */
    public static function course(int $courseid, int $timeout = 0) {
        $factory = \core\lock\lock_config::get_lock_factory(self::TYPE_INDEX);
        return $factory->get_lock('course_' . $courseid, $timeout);
    }

    /**
     * Acquire a per-video transcription lock, keyed by content hash.
     *
     * @param string $contenthash
     * @param int $timeout Seconds to wait.
     * @return \core\lock\lock|false The lock, or false if it could not be obtained.
     */
    public static function transcription(string $contenthash, int $timeout = 30) {
        $factory = \core\lock\lock_config::get_lock_factory(self::TYPE_TRANSCRIPTION);
        return $factory->get_lock('video_' . $contenthash, $timeout);
    }
}
