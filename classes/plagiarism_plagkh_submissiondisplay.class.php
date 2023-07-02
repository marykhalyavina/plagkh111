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
 * submission display helpers methods
 * @package   plagiarism_plagkh
  * @copyright 2023 plagkh
 * @author    Маша Халявина
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_pluginconfig.class.php');
require_once($CFG->dirroot . '/plagiarism/plagkh/constants/plagiarism_plagkh.constants.php');
require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_assignmodule.class.php');

/**
 * submission display helpers methods
 */
class plagiarism_plagkh_submissiondisplay {
    /**
     * build the displayed output for submission
     * @param array  $submissionref contains all relevant information for the plugin to generate a link
     * @return string displayed output
     */
    public static function output($submissionref) {
        global $OUTPUT, $DB, $USER, $CFG;

        if (!empty($submissionref["file"])) {
            $file = $submissionref["file"];
            $area = $file->get_filearea();
            // Dont show file area of type feedback_files OR introattachment.
            if (in_array($area, array("feedback_files", "introattachment"))) {
                return;
            }
        }

        /* If this is a quiz, retrieve the cmid */
        $quizcomponent = (!empty($submissionref['component'])) ? $submissionref['component'] : "";
        if (empty($submissionref['cmid']) && !empty($submissionref['area']) && $quizcomponent == "qtype_essay") {
            $quizquestions = question_engine::load_questions_usage_by_activity($submissionref['area']);

            // Try to get cm using the questions owning context.
            $context = $quizquestions->get_owning_context();
            if ($context->contextlevel == CONTEXT_MODULE) {
                $submissionref['cmid'] = $context->instanceid;
            }
        }

        $output = "";

        // Get the course module.
        static $coursemodule;
        if (empty($coursemodule)) {
            $coursemodule = get_coursemodule_from_id(
                '',
                $submissionref["cmid"]
            );
        }

        // Get plagkh module config.
        static $clmodulesettings;
        if (empty($clmodulesettings)) {
            $clmodulesettings = $DB->get_records_menu(
                'plagiarism_plagkh_config',
                array('cm' => $submissionref["cmid"]),
                '',
                'name,value'
            );
        }

        // Get plagkh plugin admin config.
        static $adminconfig;
        if (empty($adminconfig)) {
            $adminconfig = plagiarism_plagkh_pluginconfig::admin_config();
        }

        // Is plagkh plugin enabled for this module type?
        static $ismodenabledforcl;
        if (empty($ismodenabledforcl)) {
            $moduleconfigname = 'plagiarism_plagkh_mod_' . $coursemodule->modname;
            if (!isset($adminconfig->$moduleconfigname) || $adminconfig->$moduleconfigname !== '1') {
                // Plugin not enabled for this module.
                $ismodenabledforcl = false;
            } else {
                $ismodenabledforcl = true;
            }
        }

        // Exit if plugin is disabled or only disabled for this module.
        $enabledproperty = 'plagiarism_plagkh_enable';
        if (empty($ismodenabledforcl) || empty($clmodulesettings[$enabledproperty])) {
            return $output;
        }

        // Init context.
        static $ctx;
        if (empty($ctx)) {
            $ctx = context_course::instance($coursemodule->course);
        }

        // Check current user if instructor.
        static $isinstructor;
        if (empty($isinstructor)) {
            $isinstructor = plagiarism_plagkh_assignmodule::is_instructor($ctx);
        }

        // Incase of students, check if he is allowed to view the plagiairsm report progress & results.
        if (!$isinstructor && empty($clmodulesettings['plagiarism_plagkh_allowstudentaccess'])) {
            return;
        }

        // Proceed if content / files exists and cmid was set.
        if (!empty($submissionref["cmid"] && (!empty($submissionref["content"]) || !empty($submissionref["file"])))) {

            $subitemid = 0;
            $subidentifier = '';

            // Set identifier & itemid for files.
            if (!empty($submissionref["content"])) {
                $subidentifier = sha1($submissionref["content"]);
            } else if (!empty($submissionref["file"])) {
                $subitemid = $file->get_itemid();
                $subidentifier = $file->get_pathnamehash();
            }

            // Passed userid is 0 for group submissions, change it to the current userid.
            if ($submissionref['userid'] == 0 && !$isinstructor) {
                $submissionref['userid'] = $USER->id;
            }

            /*
               If instructor is submitting on behalf of a student, set the author to the student.
            */
            $submittedfile = null;
            $assignsubmission = $DB->get_record(
                'assign_submission',
                array('id' => $subitemid),
                'id, groupid'
            );
            if ((!empty($assignsubmission->groupid)) && ($coursemodule->modname == "assign")) {
                $submittedfiles = $DB->get_records(
                    'plagiarism_plagkh_files',
                    ['itemid' => $subitemid, 'cm' => $coursemodule->id, 'identifier' => $subidentifier],
                    'lastmodified DESC',
                    '*',
                    0,
                    1
                );

                $submittedfile = reset($submittedfiles);

                if (!$submittedfile) {
                    return;
                }

                $author = $submittedfile->userid;
                $submissionref['userid'] = $author;
            } else if ($coursemodule->modname == "assign") {
                $author = $submissionref['userid'];
                if ($subitemid != 0) {
                    $author = plagiarism_plagkh_assignmodule::get_author($subitemid);
                    $submissionref['userid'] = (!empty($author)) ? $author : $submissionref['userid'];
                }
            }

            $submissionusers = array($submissionref["userid"]);
            switch ($coursemodule->modname) {
                case "assign":
                    $moduledata = $DB->get_record($coursemodule->modname, array('id' => $coursemodule->instance));
                    if ($moduledata->teamsubmission) {
                        // Allow all related group users to see the originality link & score.
                        require_once($CFG->dirroot . '/mod/assign/locallib.php');
                        $assignmentref = new assign($ctx, $coursemodule, null);

                        $submissionusers = array();
                        if ($groupref = $assignmentref->get_submission_group($submissionref["userid"])) {
                            $groupmembers = groups_get_members($groupref->id);
                            $submissionusers = array_keys($groupmembers);
                        }
                    }
                    break;
            }

            // Show the originality link and score for submission.
            if ($isinstructor || in_array($USER->id, $submissionusers)) {

                // If plagiarismfile is null, try to init it again.
                if (is_null($submittedfile)) {
                    $query = "cm = ? AND identifier = ?";
                    $queryparams = array($submissionref["cmid"], $subidentifier);

                    if (count($submissionusers) > 0) {
                        $query .= " AND userid IN (";
                        foreach ($submissionusers as $userid) {
                            $query .= "?,";
                            array_push($queryparams, $userid);
                        }
                        $query  = substr($query, 0, -1);
                        $query .= ")";
                    }

                    $submittedfiles = $DB->get_records_select(
                        'plagiarism_plagkh_files',
                        $query,
                        $queryparams,
                        '',
                        '*',
                        0,
                        1
                    );

                    $submittedfile = current($submittedfiles);
                }

                if ($submittedfile) {
                    //$clpoweredbyplagkhtxt = get_string('clpoweredbyplagkh', 'plagiarism_plagkh');
                    switch ($submittedfile->statuscode) {
                        case 'success':

                            if ($submittedfile->similarityscore <= 40) {
                                $htmlclassrank = "low";
                            } else if ($submittedfile->similarityscore <= 80) {
                                $htmlclassrank = "middle";
                            } else {
                                $htmlclassrank = "high";
                            }

                            $results["score"] = $submittedfile->similarityscore;

                            $results['reporturl'] =
                                "$CFG->wwwroot/plagiarism/plagkh/plagiarism_plagkh_report.php" .
                                "?cmid=$submittedfile->cm&userid=$submittedfile->userid" .
                                "&identifier=$submittedfile->identifier&modulename=$coursemodule->modname";
                                
                            $similaritystring = '&nbsp;<span class="' . $htmlclassrank . '">'
                                . '<span></span>'
                                . $results["score"] . '%</span>';

                            /*$similaritywrapper = '<a class="plagkh-text-gray" title="'
                                . get_string('clopenreport', 'plagiarism_plagkh') . '" href="'
                                . $results['reporturl'] . '" target="_blank">';*/

                            $similaritywrapper = /*get_string('clplagiarised', 'plagiarism_plagkh') . ':'
                                .*/ $similaritystring /*. '</a>'*/;

                            $divcontent = /*$OUTPUT->pix_icon(
                                'plagkh-logo',
                                $clpoweredbyplagkhtxt,
                                'plagiarism_plagkh',
                                array('class' => 'icon_size')
                            ) .*/ $similaritywrapper;

                            $output = html_writer::tag(
                                'div',
                                $divcontent,
                                array('class' => 'plagkh')
                            );

                            /*if ($submittedfile->ischeatingdetected) {
                                $cheatingdetectioncontent = $OUTPUT->pix_icon(
                                    'plagkh-warn',
                                    get_string('clcheatingdetected', 'plagiarism_plagkh'),
                                    'plagiarism_plagkh',
                                    array('class' => 'icon_size')
                                ) . '<a class="plagkh-text-warn" title="'
                                    . get_string('clcheatingdetected', 'plagiarism_plagkh')
                                    . '" href="'
                                    . $results['reporturl'] . '" target="_blank">'
                                    . get_string('clcheatingdetectedtxt', 'plagiarism_plagkh')
                                    . '</a>';
                                $output .= html_writer::tag(
                                    'div',
                                    $cheatingdetectioncontent,
                                    array('class' => 'plagkh')
                                );
                            }*/

                            break;
                        case 'error':
                            if ($isinstructor) {

                                $clplagiarised = get_string('clplagiarised', 'plagiarism_plagkh');
                                $errorstring = '&nbsp;<span class="plagkh-text-gray">'
                                    . $clplagiarised . ':&nbsp;</span>&nbsp;<span class="strong">'
                                    . get_string('clplagiarisefailed', 'plagiarism_plagkh')
                                    . '</span>&nbsp;';

                                $errorwrapper = '<span>' . $errorstring . '</span>';

                                $output = html_writer::tag(
                                    'div',
                                    /*$OUTPUT->pix_icon(
                                        'plagkh-logo',
                                        $clpoweredbyplagkhtxt,
                                        'plagiarism_plagkh',
                                        array('class' => 'icon_size')
                                    )
                                        .*/ $errorwrapper
                                        . $OUTPUT->pix_icon(
                                            'plagkh-error',
                                            $submittedfile->errormsg,
                                            'plagiarism_plagkh',
                                            array('class' => 'icon_size')
                                        ),
                                    array('class' => 'plagkh')
                                );
                            }
                            break;
                        case 'pending':
                            $clplagiarised = get_string('clplagiarised', 'plagiarism_plagkh');

                            $pendingstring = '&nbsp;<span class="plagkh-text-gray">'
                                . $clplagiarised . ':&nbsp;</span>';

                            $pendingwrapper = '<span title="' . get_string('clplagiarisescanning', 'plagiarism_plagkh') . '">'
                                . $pendingstring . '</span>';

                            $output = html_writer::tag(
                                'div',
                                /*$OUTPUT->pix_icon(
                                    'plagkh-logo',
                                    $clpoweredbyplagkhtxt,
                                    'plagiarism_plagkh',
                                    array('class' => 'icon_size')
                                )
                                    .*/ $pendingwrapper
                                    . $OUTPUT->pix_icon(
                                        'plagkh-loading',
                                        get_string('clplagiarisescanning', 'plagiarism_plagkh'),
                                        'plagiarism_plagkh',
                                        array('class' => 'icon_size')
                                    ),
                                array('class' => 'plagkh')
                            );
                            break;
                        case 'queued':
                            $clplagiarised = get_string('clplagiarised', 'plagiarism_plagkh');

                            $queuedstring = '&nbsp;<span class="plagkh-text-gray">'
                                . $clplagiarised . ':&nbsp;</span>';

                            $queuedtxt = get_string(
                                'clplagiarisequeued',
                                'plagiarism_plagkh',
                                date("F j, Y, g:i a", $submittedfile->scheduledscandate)
                            );

                            $queuedwrapper = '<span title="' . $queuedtxt . '">' . $queuedstring . '</span>';

                            $output = html_writer::tag(
                                'div',
                                /*$OUTPUT->pix_icon(
                                    'plagkh-logo',
                                    $clpoweredbyplagkhtxt,
                                    'plagiarism_plagkh',
                                    array('class' => 'icon_size')
                                )
                                    .*/ $queuedwrapper
                                    . $OUTPUT->pix_icon(
                                        'plagkh-scheduled',
                                        $queuedtxt,
                                        'plagiarism_plagkh',
                                        array('class' => 'icon_size')
                                    ),
                                array('class' => 'plagkh')
                            );
                            break;
                        default:
                            break;
                    }
                }
            }
        }

        return "<br/>$output<br/>";
    }
}
