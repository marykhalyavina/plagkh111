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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * plagiarism_plagkh_utils.class.php
 * @package   plagiarism_plagkh
  * @copyright 2023 plagkh
 * @author    Gil Cohen <gilc@plagkh.com>
 
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/plagiarism/plagkh/constants/plagiarism_plagkh.constants.php');
require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_dbutils.class.php');


/**
 * This class include functions that can be used in multiple places
 */
class plagiarism_plagkh_utils {

    /**
     * Return the current lang code to use with plagkh
     * @return string Supported plagkh lang code
     */
    public static function get_lang() {
        $defaultlangcode = 'ru';
        try {
            $langcode = str_replace("_utf8", "", current_language());
            $langarray = array(
                'ru' => $defaultlangcode,
                'fr' => 'fr',
                'fr_ca' => 'fr',
                'en' => 'en'
            );
            return (isset($langarray[$langcode])) ? $langarray[$langcode] : $defaultlangcode;
        } catch (Exception $e) {
            return $defaultlangcode;
        }
    }

    /**
     * Get plagkh temp course module id .
     * @param string $courseid
     * @return string
     */
    public static function get_plagkh_temp_course_module_id($courseid) {
        $number = rand(100, 100000);
        $t = time();
        return $courseid . $number . ($t % 10);
    }

    /**
     * Set plagkh page navbar breadcrumbs.
     * @param mixed $cm
     * @param mixed $course
     * @return array $breadcrumbs
     */
    public static function set_plagkh_page_navbar_breadcrumbs($cm, $course) {
        global $CFG;
        $breadcrumbs = [];
        if (isset($cm)) {
            $moodlecontext = get_site();
            $moodlename = $moodlecontext->fullname;
            $coursename = $course->fullname;
            $cmid = $cm == 'new' ? '123' : $cm->id;

            $breadcrumbs = [
                [
                    'url' => "$CFG->wwwroot",
                    'name' => $moodlename,
                ],
                [
                    'url' => "$CFG->wwwroot/course/view.php?id=$course->id",
                    'name' => $coursename,
                ],
                [
                    'url' => "$CFG->wwwroot/mod/assign/view.php?id=$cmid",
                    'name' => $cm == 'new' ? 'New Activity' : $cm->name,
                ],
            ];
        } else {
            $breadcrumbs = [
                [
                    'url' => "$CFG->wwwroot/admin/search.php",
                    'name' => 'Site Administration',
                ],
                [
                    'url' => "$CFG->wwwroot/plagiarism/plagkh/settings.php",
                    'name' => 'plagkh Plugin',
                ],
                /*[
                    'url' => "$CFG->wwwroot/plagiarism/plagkh/plagiarism_plagkh_settings.php",
                    'name' => 'Integration Settings',
                ],*/
            ];
        }
        return $breadcrumbs;
    }

    /**
     * Get plagkh buttom for settings page.
     * @param string $settingsurlparams - assign the url to the link
     * @param bool $isadminform - for note above the link
     * @return string
     */
    public static function get_plagkh_settings_button_link($settingsurlparams, $isadminform = false, $cmid = null) {
        global $CFG;
        $isbtndisabled = false;
        if (!$isadminform && isset($cmid)) {
            if (plagiarism_plagkh_moduleconfig::is_course_module_request_queued($cmid)) {
                $isbtndisabled = true;
            }
        }

        $settingsurl = "";//"$CFG->wwwroot/plagiarism/plagkh/plagiarism_plagkh_settings.php";
        if (!isset($settingsurlparams) || $settingsurlparams != "") {
            $settingsurl = $settingsurl . $settingsurlparams;
        }

        $text = get_string('clscansettingspagebtntxt', 'plagiarism_plagkh');
        if (!$isadminform) {
            $text = get_string('clmodulescansettingstxt', 'plagiarism_plagkh');
        }

        $content = $isbtndisabled ?
            html_writer::div($text, null, array(
                'style' => 'color:#8c8c8c',
                'title' => get_string('cldisablesettingstooltip', 'plagiarism_plagkh')
            )) :
            html_writer::link("$settingsurl", $text, array('target' => '_blank'));

        return
            "<div class='form-group row'>" .
            "<div class='col-md-3'></div>" .
            "<div class='col-md-9'>" .
            $content
            . "</div>" .
            "</div>";
    }
}
