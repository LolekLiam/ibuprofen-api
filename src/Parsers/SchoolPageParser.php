<?php

namespace App\Parsers;

use App\Models\SchoolMeta;
use App\Models\ClassInfo;
use App\Models\TeacherInfo;
use Symfony\Component\DomCrawler\Crawler;

class SchoolPageParser {
    public static function parse(string $html, string $schoolKey): SchoolMeta {
        $crawler = new Crawler($html);
        
        // The requested public key identifies this school, even without an id_sola script.
        
        $classes = [];
        // eAsistent might use different IDs for parameters (classes, teachers, rooms)
        // Usually id_razred or id_parameter
        $parameterSelector = '#id_parameter > option';
        if ($crawler->filter($parameterSelector)->count() === 0) {
            $parameterSelector = '#id_razred > option';
        }

        foreach ($crawler->filter($parameterSelector) as $option) {
            $id = (int)$option->getAttribute('value');
            if ($id > 0) {
                $classes[] = new ClassInfo($id, trim($option->nodeValue));
            }
        }

        $teachers = [];
        // eAsistent might use #id_profesor or #id_parameter for teachers
        $teacherSelector = '#id_profesor > option';
        if ($crawler->filter($teacherSelector)->count() === 0) {
            // If it's the same dropdown, we might have already parsed it, 
            // but usually it's a separate one if both are present.
            // If only one is present, it's classes.
        }

        foreach ($crawler->filter($teacherSelector) as $option) {
            $id = (int)$option->getAttribute('value');
            if ($id > 0) {
                $teachers[] = new TeacherInfo($id, trim($option->nodeValue));
            }
        }
        
        $schoolName = null;
        $titleNode = $crawler->filter('div.urnik_title');
        if ($titleNode->count() > 0) {
            $schoolName = trim($titleNode->text());
        }
        
        $currentWeekId = null;
        $currentWeekLabel = null;
        $allWeekIds = [];
        $tedniToggleNode = $crawler->filter('#tedni-toggle');
        if ($tedniToggleNode->count() > 0) {
            $currentWeekLabel = trim(preg_replace('/\s+/', ' ', $tedniToggleNode->text()));
            if (preg_match("/Teden\s+(\d+)/", $currentWeekLabel, $matches)) {
                $currentWeekId = (int)$matches[1];
            }
        }
        
        foreach ($crawler->filter('[data-teden]') as $weekLink) {
            $week = filter_var($weekLink->getAttribute('data-teden'), FILTER_VALIDATE_INT);
            if ($week !== false && $week > 0) $allWeekIds[] = $week;
        }
        $allWeekIds = array_values(array_unique($allWeekIds));

        return new SchoolMeta(
            $schoolKey,
            $classes,
            $teachers,
            $schoolName,
            $currentWeekId,
            $currentWeekLabel,
            $allWeekIds
        );
    }
}
