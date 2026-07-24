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
 * Unit tests for Storyline video->slide mapping (pure helpers).
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat;

use local_aichat\rag\scorm_extractor;

/**
 * @covers \local_aichat\rag\scorm_extractor
 */
class scorm_video_mapping_test extends \advanced_testcase {

    /**
     * Load a JSON fixture.
     *
     * @param string $name
     * @return array
     */
    private function fixture(string $name): array {
        return json_decode(file_get_contents(__DIR__ . '/fixtures/' . $name), true);
    }

    public function test_video_assets_indexes_only_mp4(): void {
        $assets = scorm_extractor::storyline_video_assets($this->fixture('storyline_min.json'));
        $this->assertSame('story_content/video_narrated_1196x670.mp4', $assets[10]);
        $this->assertCount(4, $assets);
        $this->assertArrayNotHasKey(20, $assets); // Decorative image ignored.
    }

    public function test_slide_video_map(): void {
        $map = scorm_extractor::storyline_slide_video_map($this->fixture('storyline_min.json'));
        $this->assertSame([10, 12], $map['sceneA.slide1']);
        $this->assertSame([11, 12], $map['sceneA.slide2']);
        $this->assertArrayNotHasKey('sceneB.slide1', $map); // Empty assetIds -> not mapped.
    }

    public function test_model_video_ids_ignores_skip_pruning(): void {
        $model = ['slideLayers' => [['objects' => [
            ['data' => ['videodata' => ['assetId' => 24]]],
            ['kind' => 'group', 'objects' => [['data' => ['videodata' => ['assetId' => 26]]]]],
        ]]]];
        $ids = scorm_extractor::storyline_model_video_ids($model);
        sort($ids);
        $this->assertSame([24, 26], $ids);
    }

    public function test_classify_agreement(): void {
        $r = scorm_extractor::classify_slide_videos([10, 12], [12, 10]);
        $this->assertSame('both', $r['sources']);
        $this->assertSame([10, 12], $r['attach']);
    }

    public function test_classify_single_source(): void {
        $this->assertSame('single', scorm_extractor::classify_slide_videos([10], [])['sources']);
        $this->assertSame([10], scorm_extractor::classify_slide_videos([], [10])['attach']);
    }

    public function test_classify_conflict_attaches_nothing(): void {
        $r = scorm_extractor::classify_slide_videos([10], [11]);
        $this->assertSame('conflict', $r['sources']);
        $this->assertSame([], $r['attach']);
    }

    public function test_classify_none(): void {
        $this->assertSame('none', scorm_extractor::classify_slide_videos([], [])['sources']);
    }

    public function test_asset_relpath_valid(): void {
        $r = scorm_extractor::storyline_asset_relpath('story_content/video_a.mp4');
        $this->assertSame('/story_content/', $r['filepath']);
        $this->assertSame('video_a.mp4', $r['filename']);
    }

    public function test_asset_relpath_rejections(): void {
        $this->assertNull(scorm_extractor::storyline_asset_relpath('https://evil.example/x.mp4'));
        $this->assertNull(scorm_extractor::storyline_asset_relpath('../../secret.mp4'));
        $this->assertNull(scorm_extractor::storyline_asset_relpath('story_content/notes.txt'));
        $this->assertNull(scorm_extractor::storyline_asset_relpath('other_dir/video.mp4'));
        $this->assertNull(scorm_extractor::storyline_asset_relpath(''));
    }
}
