<?php

namespace App\Parsers;

use App\Models\TimetableWeek;
use App\Models\TimetableDay;
use App\Models\Lesson;
use Symfony\Component\DomCrawler\Crawler;
use \DateTime;

class TimetableParser {
    const US = "\x1F";

    public static function parse(int $classId, string $payload): TimetableWeek {
        if (!str_contains($payload, self::US)) {
            $page = new Crawler($payload);
            $table = $page->filter('table.ednevnik-seznam_ur_teden');
            if (!$table->count()) throw new \RuntimeException('Upstream timetable table is missing');
            $header = self::xpath($table, './thead/tr/th')->filter('.date')->first();
            if (!$header->count() || !preg_match('/(\d+)\.\s*(\d+)\./', $header->text(), $dm))
                throw new \RuntimeException('Upstream timetable dates are missing');
            $anchor = new \DateTimeImmutable('today');
            foreach ($table->filter('[id]') as $node) {
                if (preg_match('/\d{4}-\d{2}-\d{2}/', $node->getAttribute('id'), $iso)) { $anchor = new \DateTimeImmutable($iso[0]); break; }
            }
            $candidates = [];
            foreach (range((int)$anchor->format('Y')-1,(int)$anchor->format('Y')+1) as $year) {
                if (checkdate((int)$dm[2],(int)$dm[1],$year)) $candidates[] = new \DateTimeImmutable(sprintf('%04d-%02d-%02d',$year,$dm[2],$dm[1]));
            }
            usort($candidates, fn($a,$b) => abs($a->getTimestamp()-$anchor->getTimestamp()) <=> abs($b->getTimestamp()-$anchor->getTimestamp()));
            $start = $candidates[0];
            $payload = $start->format('j. n. Y') . self::US . $start->modify('+6 days')->format('j. n. Y') . self::US . $payload;
        }
        $parts = explode(self::US, $payload);
        if (count($parts) < 3) {
            throw new \Exception("Unexpected payload format: parts=" . count($parts));
        }

        // Current eAsistent format sometimes has an extra part at the beginning (e.g. week number)
        // [0] => "32", [1] => "6. 4. 2026", [2] => "12. 4. 2026", [3] => HTML
        // Or older format: [0] => "6. 4. 2026", [1] => "12. 4. 2026", [2] => HTML
        
        $weekStartStr = "";
        $weekEndStr = "";
        $html = "";

        if (str_contains($parts[0], '.')) {
            // Older format
            $weekStartStr = trim($parts[0]);
            $weekEndStr = trim($parts[1]);
            $html = $parts[2];
        } else {
            // Newer format with week number at [0]
            $weekStartStr = trim($parts[1]);
            $weekEndStr = trim($parts[2]);
            $html = $parts[3] ?? "";
        }

        $weekStart = DateTime::createFromFormat('j. n. Y', $weekStartStr);
        $weekEnd = DateTime::createFromFormat('j. n. Y', $weekEndStr);

        if (!$weekStart || !$weekEnd) {
             throw new \Exception("Invalid date format in payload: $weekStartStr / $weekEndStr");
        }

        $crawler = new Crawler($html);
        $tableNode = $crawler->filter('table.ednevnik-seznam_ur_teden');
        if ($tableNode->count() === 0) {
            throw new \RuntimeException('Upstream timetable table is missing');
        }

        // Days headers
        $dayDates = [];
        $headerNodes = self::xpath($tableNode, './thead/tr/th');
        // Skip the first header (time column)
        for ($i = 1; $i < $headerNodes->count(); $i++) {
            $th = $headerNodes->eq($i);
            $shortDate = "";
            $dateNode = $th->filter('.date');
            if ($dateNode->count() > 0) {
                $shortDate = trim($dateNode->text());
            }
            $clean = rtrim($shortDate, '.');
            $dm = explode('.', $clean);
            $d = isset($dm[0]) ? (int)trim($dm[0]) : null;
            $m = isset($dm[1]) ? (int)trim($dm[1]) : null;
            
            if ($d && $m) {
                $year = self::inferYearForDay($weekStart, $weekEnd, $d, $m);
                $dt = new DateTime();
                $dt->setDate($year, $m, $d);
                $dayDates[] = $dt->format('Y-m-d');
            }
        }

        $rows = self::xpath($tableNode, './tbody/tr | ./tr');

        $dayCount = count($dayDates);
        $periodsPerDay = array_fill(0, $dayCount, []);

        foreach ($rows as $rowIdx => $row) {
            $rowCrawler = new Crawler($row);
            $tds = self::xpath($rowCrawler, './td');
            if ($tds->count() === 0) continue;

            $left = $tds->eq(0);
            $periodNumber = $rowIdx + 1;
            $nazivUreNode = $left->filter('.naziv-ure');
            if ($nazivUreNode->count() > 0) {
                $pText = $nazivUreNode->text();
                $periodNumber = (int)strtok($pText, '.');
            }

            $timeRangeText = "";
            $potekUreNode = $left->filter('.potek-ure');
            if ($potekUreNode->count() > 0) {
                $timeRangeText = trim($potekUreNode->text());
            }

            $timeRange = ['start' => '00:00', 'end' => '00:00'];
            if (preg_match("/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/", $timeRangeText, $m)) {
                $timeRange['start'] = self::formatTime($m[1]);
                $timeRange['end'] = self::formatTime($m[2]);
            }

            for ($dIdx = 1; $dIdx < min($tds->count(), $dayCount + 1); $dIdx++) {
                $cell = $tds->eq($dIdx);
                $isCancelledCell = str_contains($cell->attr('class') ?? '', 'ednevnik-seznam_ur_teden-td-odpadla-ura');

                // Try to find the date from the cell ID (format: ednevnik-seznam_ur_teden-td-PERIOD-YYYY-MM-DD)
                $cellId = $cell->attr('id') ?? '';
                $dayIndex = null;
                if (preg_match("/(\d{4}-\d{2}-\d{2})/", $cellId, $matches)) {
                    $cellDate = $matches[1];
                    $dayIndex = array_search($cellDate, $dayDates);
                }
                
                // Fallback to column index if ID mapping failed
                if ($dayIndex === false || $dayIndex === null) {
                    $dayIndex = $dIdx - 1;
                }

                $blocks = [];
                foreach ($cell->filter('div.ednevnik-seznam_ur_teden-blok-wrap') as $block) {
                    $blocks[] = new Crawler($block);
                }
                foreach ($cell->filter('div.hidden.teden-blok-wrapper div.ednevnik-seznam_ur_teden-blok-wrap') as $block) {
                    $blocks[] = new Crawler($block);
                }

                $lessons = [];
                foreach ($blocks as $blockCrawler) {
                    $lesson = self::parseLesson($blockCrawler, $isCancelledCell);
                    if ($lesson) {
                        $lessons[] = $lesson;
                    }
                }

                if ($dayIndex !== false && $dayIndex < $dayCount) {
                    $periodsPerDay[$dayIndex][] = self::finalizePeriodCell($periodNumber, $timeRange, $lessons);
                }
            }
        }

        $days = [];
        for ($d = 0; $d < $dayCount; $d++) {
            $days[] = new TimetableDay($dayDates[$d], $periodsPerDay[$d]);
        }

        return new TimetableWeek($classId, $weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d'), $days);
    }

    private static function xpath(Crawler $crawler, string $expression): Crawler {
        $nodes=[];
        foreach ($crawler as $node) foreach ((new \DOMXPath($node->ownerDocument))->query($expression,$node) as $match) $nodes[]=$match;
        return new Crawler($nodes);
    }

    private static function finalizePeriodCell(int $periodNumber, array $timeRange, array $lessons): array {
        // Deduplicate
        $uniqueLessons = [];
        foreach ($lessons as $les) {
            $key = implode('|', [
                $les->subjectCode ?? $les->subjectTitle ?? "",
                $les->teacherFullName ?? $les->teacher ?? "",
                $les->room ?? "",
                $les->groupLabel ?? "",
                $les->isCancelled ? '1' : '0'
            ]);
            if (!isset($uniqueLessons[$key])) {
                $uniqueLessons[$key] = $les;
            }
        }

        return [
            'periodNumber' => $periodNumber,
            'timeRange' => $timeRange,
            'lessons' => array_values($uniqueLessons)
        ];
    }

    private static function parseLesson(Crawler $block, bool $isCancelledCell): ?Lesson {
        $titleSpan = $block->filter('.ednevnik-title span');
        if ($titleSpan->count() === 0) {
            // Check for substituted or other blocks
            $titleSpan = $block->filter('.ednevnik-seznam_ur_teden-blok span');
        }
        
        $subjectCode = null;
        $subjectTitle = null;
        
        if ($titleSpan->count() > 0) {
            $subjectCode = trim($titleSpan->text());
            if ($subjectCode === "") $subjectCode = null;
            $subjectTitle = trim($titleSpan->attr('title') ?? "");
            if ($subjectTitle === "") {
                $subjectTitle = $subjectCode;
            }
        } else {
            // Handle events like "Praznik" (Holiday) which are in .ednevnik-title but not in a span
            $titleNode = $block->filter('.ednevnik-title');
            if ($titleNode->count() > 0) {
                $subjectTitle = trim($titleNode->text());
                $subjectCode = $subjectTitle;
            }
        }

        if ($subjectCode === null && $subjectTitle === null) {
             // Still nothing? Maybe it's a different kind of block
             if ($block->filter('.ednevnik-seznam_ur_teden-td-dogodek')->count() > 0 || str_contains($block->attr('class') ?? '', 'dogodek')) {
                 $subjectTitle = trim($block->text());
                 $subjectCode = "EVENT";
             }
        }

        if ($subjectCode === null && $subjectTitle === null) return null;

        $subtitles = $block->filter('.ednevnik-subtitle');
        $teacherRoom = "";
        $teacherFullName = null;
        if ($subtitles->count() > 0) {
            $teacherSubtitle = $subtitles->eq(0);
            $teacherRoom = trim($teacherSubtitle->text());
            $teacherFullName = trim($teacherSubtitle->attr('title') ?? "");
            if ($teacherFullName === "") $teacherFullName = null;
        }

        $groups = [];
        // Group labels might be in additional subtitles
        for ($i = 1; $i < $subtitles->count(); $i++) {
            $text = trim($subtitles->eq($i)->text());
            if ($text !== "") {
                $groups[] = $text;
            }
        }
        
        $teacher = null;
        $room = null;
        if ($teacherRoom !== "") {
            // Sometimes it's "Teacher, Room" or just "Teacher"
            if (str_contains($teacherRoom, ',')) {
                $parts = explode(',', $teacherRoom);
                $teacher = trim($parts[0]);
                $room = trim($parts[1]);
            } else {
                // Heuristic: if it's very short, it might be a room? 
                // But usually it's teacher initials.
                $teacher = $teacherRoom;
            }
        }

        $groupLabel = !empty($groups) ? implode(' | ', $groups) : null;
        $cancelled = $isCancelledCell || $block->filter('.wl-tag-cancelled')->count() > 0 || str_contains($block->attr('class') ?? '', 'odpadlo');

        if (!$subjectCode && !$subjectTitle && !$teacher && !$room && !$groupLabel) return null;

        return new Lesson($subjectCode, $subjectTitle, $teacher, $room, $groupLabel, $cancelled, $teacherFullName);
    }

    private static function inferYearForDay(DateTime $weekStart, DateTime $weekEnd, int $d, int $m): int {
        $yStart = (int)$weekStart->format('Y');
        $yEnd = (int)$weekEnd->format('Y');
        
        if ($yStart === $yEnd) return $yStart;

        // Compare distance in month/day to start and end
        $diffStart = abs($m - (int)$weekStart->format('n')) * 31 + abs($d - (int)$weekStart->format('j'));
        $diffEnd = abs($m - (int)$weekEnd->format('n')) * 31 + abs($d - (int)$weekEnd->format('j'));

        return ($diffStart < $diffEnd) ? $yStart : $yEnd;
    }

    private static function formatTime(string $time): string {
        $parts = explode(':', $time);
        return sprintf("%02d:%02d", $parts[0], $parts[1]);
    }
}
