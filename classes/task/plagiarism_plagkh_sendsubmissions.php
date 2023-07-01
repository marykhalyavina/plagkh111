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
 * plagkh Plagiarism Plugin - Handle Queued Files
 * @package   plagiarism_plagkh
  * @copyright 2023 plagkh
 * @author    Маша Халявина
 */

namespace plagiarism_plagkh\task;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/plagiarism/plagkh/constants/plagiarism_plagkh.constants.php');
require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_logs.class.php');
require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_dbutils.class.php');


/**
 * plagkh Plagiarism Plugin - Handle Queued Files
 */
class plagiarism_plagkh_sendsubmissions extends \core\task\scheduled_task {
    /**
     * Get scheduler name, this will be shown to admins on schedulers dashboard.
     */
    public function get_name() {
        return get_string('clsendqueuedsubmissions', 'plagiarism_plagkh');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $CFG;
        require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_submissions.class.php');
        require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_comms.class.php');
        require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_assignmodule.class.php');
        require_once($CFG->dirroot . '/plagiarism/plagkh/classes/plagiarism_plagkh_utils.class.php');

        $this->send_queued_submissions();
    }

    /**
     * Check and send queue files to plagkh for plagiarism scan.
     */
    private function send_queued_submissions() {
        global $CFG, $DB;

        $canloadmoredata = true;
        $maxdataloadloops = PLAGIARISM_plagkh_CRON_MAX_DATA_LOOP;

        while ($canloadmoredata && (--$maxdataloadloops) > 0) {

            $currentdate = strtotime("now");
            $queuedsubmissions = $DB->get_records_select(
                "plagiarism_plagkh_files",
                "statuscode = ? AND scheduledscandate < ?",
                array('queued', $currentdate),
                '',
                '*',
                0,
                PLAGIARISM_plagkh_CRON_QUERY_LIMIT
            );

            $canloadmoredata = count($queuedsubmissions) == PLAGIARISM_plagkh_CRON_QUERY_LIMIT;

            if (count($queuedsubmissions) > 0 && !\plagiarism_plagkh_comms::test_plagkh_connection('scheduler_task')) {
                return;
            }

            foreach ($queuedsubmissions as $submission) {
                $submittedtextcontent = "";
                // Check if submission type is supported.
                $subtype = $submission->submissiontype;
                if (!in_array(
                    $subtype,
                    PLAGIARISM_plagkh_SUPPORTED_SUBMISSION_TYPES
                )) {
                    \plagiarism_plagkh_submissions::mark_error(
                        $submission->id,
                        'Submission type is not supported.'
                    );
                    continue;
                }

                if (\plagiarism_plagkh_moduleconfig::is_course_module_request_queued($submission->cm)) {
                    continue;
                }

                // Check if course module exists.
                $coursemodule = get_coursemodule_from_id('', $submission->cm);
                if (empty($coursemodule)) {
                    \plagiarism_plagkh_submissions::mark_error($submission->id, "Course Module wasnt found for this record.");
                    continue;
                }

                $userid = $submission->userid;

                // Set submitter if it was not set previously.
                if (empty($submission->submitter)) {
                    $submission->submitter = $submission->userid;
                }

                // Mark as error if user id is 0 (user id should never be 0).
                if (empty($submission->userid)) {
                    \plagiarism_plagkh_submissions::mark_error($submission->id,  'User Id should never be 0.');
                    continue;
                }

                // Handle submission data according to the submission type.
                if ($submission->submissiontype == 'text_content') {
                    $moduledata = $DB->get_record($coursemodule->modname, array('id' => $coursemodule->instance));
                    if ($coursemodule->modname == 'workshop') {
                        $workshopsubmission = $DB->get_record(
                            'workshop_submissions',
                            array('id' => $submission->itemid),
                            'content'
                        );
                        $submittedtextcontent = $workshopsubmission->content;
                    } else if ($coursemodule->modname == 'assign') {
                        $submissionref = $DB->get_record(
                            'assign_submission',
                            array(
                                'id' => $submission->itemid,
                                'userid' => ($moduledata->teamsubmission) ? 0 : $submission->userid,
                                'assignment' => $coursemodule->instance
                            ),
                            'id'
                        );

                        $txtsubmissionref = $DB->get_record(
                            'assignsubmission_onlinetext',
                            array(
                                'submission' => $submissionref->id
                            ),
                            'onlinetext'
                        );
                        $submittedtextcontent = $txtsubmissionref->onlinetext;
                    } else {
                        $errormessage = 'Content not found for the submission.';
                    }

                    $filename = 'online_text_'
                        . $userid . "_"
                        . $coursemodule->id . "_"
                        . $coursemodule->instance . '.txt';

                    $submittedtextcontent = html_to_text($submittedtextcontent);
                } else if ($submission->submissiontype == 'forum_post') {
                    $forumpost = $DB->get_record_select(
                        'forum_posts',
                        " userid = ? AND id = ? ",
                        array($userid, $submission->itemid)
                    );
                    if ($forumpost) {
                        $filename = 'forumpost_'
                            . $userid . "_"
                            . $coursemodule->id . "_"
                            . $coursemodule->instance . "_"
                            . $submission->itemid . '.txt';

                        $submittedtextcontent = html_to_text(strip_tags($forumpost->message));
                    } else {
                        $errormessage = 'Content not found for the submission.';
                    }
                } else if ($submission->submissiontype == 'quiz_answer') {

                    require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                    $quizattempt = \quiz_attempt::create($submission->itemid);
                    foreach ($quizattempt->get_slots() as $slot) {
                        $questionattempt = $quizattempt->get_question_attempt($slot);
                        if ($submission->identifier == sha1($questionattempt->get_response_summary())) {
                            $submittedtextcontent = $questionattempt->get_response_summary();
                            break;
                        }
                    }

                    if (!empty($submittedtextcontent)) {
                        $submittedtextcontent = strip_tags($submittedtextcontent);
                        $filename = 'quizanswer_'
                            . $userid . "_"
                            . $coursemodule->id . "_"
                            . $coursemodule->instance . "_"
                            . $submission->itemid . '.txt';
                    } else {
                        $errormessage = 'Content not found for the submission.';
                    }
                } else {
                    // In case $submission->submissiontype == 'file'.
                    $filestorage = get_file_storage();
                    $fileref = $filestorage->get_file_by_hash($submission->identifier);

                    if (!$fileref) {
                        $errormessage = 'File/Content not found for the submission.';
                    } else {
                        try {
                            $filename = $fileref->get_filename();
                            $submittedtextcontent = $fileref->get_content();
                        } catch (\Exception $e) {
                            $errormessage = 'File/Content not found for the submission.';
                        }
                    }
                }

                // If $errormessage is not empty, then there was an error.
                if (isset($errormessage)) {
                    \plagiarism_plagkh_submissions::mark_error($submission->id,  $errormessage);
                    continue;
                }

                try {
                    // Read the submited work into a temp file for submitting.
                    $tempfilepath = $this->create_plagkh_tempfile($coursemodule->id, $filename);
                } catch (\Exception $e) {
                    \plagiarism_plagkh_submissions::mark_error($submission->id,  "Fail to create a tempfile.");
                    continue;
                }

                $fileref = fopen($tempfilepath, "w");
                fwrite($fileref, $submittedtextcontent);
                fclose($fileref);

                try {
                    $plagkhcomms = new \plagiarism_plagkh_comms();
                    $plagkhcomms->submit_for_plagiarism_scan(
                        $tempfilepath,
                        $filename,
                        $coursemodule->id,
                        $userid,
                        $submission->identifier,
                        $submission->submissiontype
                    );
                    \plagiarism_plagkh_submissions::mark_pending($submission->id);
                } catch (\Exception $e) {
                    $errorcode = $e->getCode();
                    $error = get_string(
                        'clapisubmissionerror',
                        'plagiarism_plagkh'
                    ) . ' ' . $e->getMessage();
                    if ($errorcode < 500 && $errorcode != 429) {
                        \plagiarism_plagkh_submissions::mark_error($submission->id,  $error);
                    } else {
                        \plagiarism_plagkh_logs::add($error, 'API_ERROR_RETRY_WILL_BE_DONE');
                    }
                }

                // After finished the scan proccess, delete the temp file (if it exists).
                if (!is_null($tempfilepath)) {
                    unlink($tempfilepath);
                }
            }
        }
    }

    /**
     * Generate a temp file for the submission.
     * @param string $cmid
     * @param string $name
     * @return string temp file path
     */
    private function create_plagkh_tempfile($cmid, $name) {
        // Create plagkh directory under tempdir for submission usage.
        $tempdirref = make_temp_directory('plagiarism_plagkh');

        $parts = explode('.', $name);
        $extension = '';
        if (count($parts) > 1) {
            $extension = '.' . array_pop($parts);
        }

        $filestring = array($parts[0], $cmid);
        $filename = implode('_', $filestring);
        $filename = str_replace(' ', '_', $filename);
        $filename = clean_param(strip_tags($filename), PARAM_FILE);

        $maxstrlength = PLAGIARISM_plagkh_MAX_FILENAME_LENGTH -
            mb_strlen(
                $tempdirref . DIRECTORY_SEPARATOR,
                'UTF-8'
            );

        $extensionlength = mb_strlen(
            '_' . mt_getrandmax()
                . $extension,
            'UTF-8'
        );

        if ($extensionlength > $maxstrlength) {
            $extensionlength = $maxstrlength;
        }

        // Make the filename smaller if needed.
        $maxstrlength -= $extensionlength;
        $name = mb_substr($name, 0, $maxstrlength, 'UTF-8');

        // Clear invalid characters.
        $name = clean_param(
            $name
                . mb_substr(
                    '_' . mt_rand() . $extension,
                    0,
                    $extensionlength,
                    'UTF-8'
                ),
            PARAM_FILE
        );

        $tries = 0;
        do {
            if ($tries == 10) {
                throw new \invalid_dataroot_permissions("plagkh plagiarism plugin temporary file cannot be created.");
            }
            $tries++;

            $file = $tempdirref . DIRECTORY_SEPARATOR . $name;
        } while (!touch($file));

        return $file;
    }
}
