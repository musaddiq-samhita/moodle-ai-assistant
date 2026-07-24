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
 * AI Chat - Extraction failed exception.
 *
 * Thrown when extracting a single content source (e.g. a SCORM package) fails in
 * an indeterminate way. It carries the source identity so the indexer can
 * PRESERVE that source's existing embeddings rather than treating the absence of
 * fresh chunks as "source removed" and deleting them.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\rag;

defined('MOODLE_INTERNAL') || die();

/**
 * Indeterminate failure extracting one content source.
 */
class extraction_failed_exception extends \moodle_exception {

    /** @var string Chunk type of the failed source (e.g. 'scorm'). */
    public $chunktype;

    /** @var int Chunk id of the failed source (e.g. the cmid). */
    public $chunkid;

    /**
     * @param string $chunktype Chunk type of the failed source.
     * @param int $chunkid Chunk id of the failed source.
     * @param string $reason Human-readable reason (developer log only).
     */
    public function __construct(string $chunktype, int $chunkid, string $reason = '') {
        $this->chunktype = $chunktype;
        $this->chunkid = $chunkid;
        parent::__construct('error', 'local_aichat', '', null,
            "extraction failed for {$chunktype}#{$chunkid}: {$reason}");
    }
}
