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

namespace quiz_responses;

use question_bank;
use quiz_attempt;

defined('MOODLE_INTERNAL') || die();
define('UNITTEST_IS_RUNNING', true);


global $CFG;
require_once($CFG->dirroot . '/mod/quiz/tests/attempt_walkthrough_from_csv_test.php');
// require_once($CFG->dirroot . '/mod/quiz/report/default.php');
require_once($CFG->dirroot . '/mod/quiz/report/statistics/report.php');
require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');
//require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport.php');
require_once($CFG->dirroot . '/mod/quiz/report/responsedownload/report.php');
require_once($CFG->dirroot . '/question/type/proforma/question.php');


/**
 * Quiz attempt walk through using data from csv file.
 *
 * @package    quiz_responses
 * @category   test
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responsedownload_from_steps_walkthrough_test extends \mod_quiz\tests\attempt_walkthrough_testcase {

    const delete_tmp_archives = false;

    protected static function get_full_path_of_csv_file(string $setname, string $test): string {
        // Overridden here so that __DIR__ points to the path of this file.
        return  __DIR__."/fixtures/{$setname}{$test}.csv";
    }

    protected $files = array('questions', 'steps', 'responses');
    protected $users = [];

    /**
     * Helper method: Store a test file with a given name and contents in a
     * draft file area.
     *
     * @param int $context context.
     * @param int $draftitemid draft item id.
     * @param string $contents file contents.
     * @param string $filename filename.
     */
    protected function upload_file($context, $draftitemid, $contents, $filename) {
        $fs = get_file_storage();

        $filerecord = new \stdClass();
        $filerecord->contextid = $context->id;
        $filerecord->component = 'user';
        $filerecord->filearea = 'draft';
        $filerecord->itemid = $draftitemid;
        $filerecord->filepath = '/';
        $filerecord->filename = $filename;

        // print_r($filerecord);
        $fs->create_file_from_string($filerecord, $contents);
        return $draftitemid;
    }

    /**
     * @param $steps array the step data from the csv file.
     * @return array attempt no as in csv file => the id of the quiz_attempt as stored in the db.
     */
    protected function my_walkthrough_attempts($steps) {
        global $DB;
        $attemptids = array();

        $quizobj = null;
        foreach ($steps as $steprow) {

            $step = $this->explode_dot_separated_keys_to_make_subindexs($steprow);
            // Find existing user or make a new user to do the quiz.
            $username = array('firstname' => $step['firstname'],
                'lastname'  => $step['lastname']);

            if (!$user = $DB->get_record('user', $username)) {
                $user = $this->getDataGenerator()->create_user($username);
                $this->users[$user->id] = $user;

                $quizobj = \quiz::create($this->quiz->id, $user->id);
                if ($quizobj->has_questions()) {
                    $quizobj->load_questions();
                }

                $this->slots = [];
                foreach ($quizobj->get_questions() as $question) {
                    $this->slots[$question->slot] = $question;
                }
            }

            global $USER;
            // Change user.
            $USER = $user;
            $usercontext = \context_user::instance($user->id);

            if (!isset($attemptids[$step['quizattempt']])) {
                // Start the attempt.
                foreach ($step['responses'] as $slot => &$response) { // slot or question??
                    $type = $this->slots[$slot]->qtype;
                    switch ($type) {
                        case 'proforma':
                            // Check for filepicker and explorer
                            switch ($this->slots[$slot]->options->responseformat) {
                                case 'editor':
                                    break;
                                case 'filepicker':
                                case 'explorer':
                                    $attachementsdraftid = file_get_unused_draft_itemid();
                                    $response['attachments'] = $this->upload_file($usercontext,
                                            $attachementsdraftid, $response['answer'],
                                        'response_' . $user->id. '_' . $slot . '.java');
                                    unset($response['answer']);
                                    break;
                                default:
                                    throw new \coding_exception('invalid proforma subtype ' . $this->slots[$slot]->options->responseformat);
                            }
                            break;
                        case 'essay':
                            $response['answerformat'] = FORMAT_PLAIN;
                            break;
                    }
                }

                $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', /* $usercontext*/ $quizobj->get_context());
                $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

                $prevattempts = quiz_get_user_attempts($this->quiz->id, $user->id, 'all', true);
                $attemptnumber = count($prevattempts) + 1;
                $timenow = time();
                $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, $timenow, false, $user->id);
                // Select variant and / or random sub question.
                if (!isset($step['variants'])) {
                    $step['variants'] = array();
                }
                if (isset($step['randqs'])) {
                    // Replace 'names' with ids.
                    foreach ($step['randqs'] as $slotno => $randqname) {
                        $step['randqs'][$slotno] = $this->randqids[$slotno][$randqname];
                    }
                } else {
                    $step['randqs'] = array();
                }

                quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow, $step['randqs'], $step['variants']);
                quiz_attempt_save_started($quizobj, $quba, $attempt);
                $attemptid = $attemptids[$step['quizattempt']] = $attempt->id;
            } else {
                $attemptid = $attemptids[$step['quizattempt']];
            }

            // Process some responses from the student.
            $attemptobj = quiz_attempt::create($attemptid);
            $attemptobj->process_submitted_actions($timenow, false, $step['responses']);

            // Finish the attempt.
            if (!isset($step['finished']) || ($step['finished'] == 1)) {
                $attemptobj = quiz_attempt::create($attemptid);
                $attemptobj->process_finish($timenow, false);
            }
        }
        return $attemptids;
    }

    /**
     * Create a quiz add questions to it, walk through quiz attempts and then check results.
     *
     * @param array $quizsettings settings to override default settings for quiz created by generator. Taken from quizzes.csv.
     * @param array $csvdata of data read from csv file "questionsXX.csv", "stepsXX.csv" and "responsesXX.csv".
     * @dataProvider get_data_for_walkthrough
     */
    public function test_walkthrough_from_csv($quizsettings, $csvdata) : void
    {
        // Suppress actual grading in qtype_proforma.
        \qtype_proforma_question::$systemundertest = true;

        $this->resetAfterTest(true);
        question_bank::get_qtype('random')->clear_caches_before_testing();

        $this->create_quiz($quizsettings, $csvdata['questions']);

        $quizattemptids = $this->my_walkthrough_attempts($csvdata['steps']);

        foreach ($csvdata['responses'] as $responsesfromcsv) {
            $responses = $this->explode_dot_separated_keys_to_make_subindexs($responsesfromcsv);

            if (!isset($quizattemptids[$responses['quizattempt']])) {
                throw new \coding_exception("There is no quizattempt {$responses['quizattempt']}!");
            }
            $this->assert_response_test($quizattemptids[$responses['quizattempt']], $responses);
        }

        // Prepare check.
        $report = new \quiz_responsedownload_report();
        // call of protected method $report->download_proforma_submissions
        $init = new \ReflectionMethod('\quiz_responsedownload_report', 'init');
        $init->setAccessible(true);
        $load = new \ReflectionMethod('\quiz_responsedownload_report', 'loadData');
        $load->setAccessible(true);
        $create_table = new \ReflectionMethod('\quiz_responsedownload_report', 'create_table');
        $create_table->setAccessible(true);
        global $DB;
        $course = $DB->get_record('course', array('id' => $this->quiz->course));
        $cm = \get_coursemodule_from_instance("quiz", $this->quiz->id, $course->id);
        $context = \context_module::instance($cm->id);

        // Possible combinations.
        $showqtexts = [
            0,
            1,
        ];
        $attempts = [
            \quiz_attempts_report::ALL_WITH,
//            \quiz_attempts_report::ENROLLED_WITH,
//            \quiz_attempts_report::ENROLLED_WITHOUT,
//            \quiz_attempts_report::ENROLLED_ALL,
        ];
        $whichtries = [
            \question_attempt::LAST_TRY,
            \question_attempt::FIRST_TRY,
            \question_attempt::ALL_TRIES
        ];
        $states = [
//            'overdue',
//            'inprogress',
            'finished',
//            'abandoned'
        ];

        foreach ($whichtries as $whichtry) {
            foreach ($showqtexts as $showqtext) {
                foreach ($attempts as $attempt) {
                    foreach ($states as $state) {
                        // Start without download:
                        $this->run_html_test($init, $report, $cm, $course, $attempt, $showqtext, $whichtry, $state, $load, $create_table, $csvdata);

                        // Download version:
                        $this->run_download_test($init, $report, $cm, $course, $attempt, $showqtext, $whichtry, $state, $load, $create_table, $csvdata);
                    }
                }
            }
        }
    }

    /**
     * @param \ReflectionMethod $init
     * @param \quiz_responsedownload_report $report
     * @param \stdClass $cm
     * @param $course
     * @param $attempt
     * @param int $showqtext
     * @param $whichtry
     * @param string $state
     * @param \ReflectionMethod $load
     * @param \ReflectionMethod $create_table
     * @param array $csvdata
     * @return
     * @throws \ReflectionException
     */
    protected function run_html_test(\ReflectionMethod $init, \quiz_responsedownload_report $report, \stdClass $cm, $course, $attempt, int $showqtext, $whichtry, string $state, \ReflectionMethod $load, \ReflectionMethod $create_table, array $csvdata) {
        list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $init->invoke($report,
            'responsedownload', 'quiz_responsedownload_settings_form', $this->quiz, $cm, $course);

        $options = new \quiz_responsedownload_options('responsedownload', $this->quiz, $cm, $course);
        $options->attempts = $attempt;
        $options->showqtext = $showqtext;
        $options->whichtries = $whichtry;
        $options->download = 0;
        $options->states = [$state];

        echo '$attempt ' . $attempt . ' $showqtext ' . $showqtext . PHP_EOL;

        ob_start();
        list($questions, $table, $hasstudents, $allowedjoins, $hasquestions) =
            $load->invoke($report,
                $this->quiz, $course, $options, $groupstudentsjoins, $studentsjoins, $allowedjoins, $cm, $currentgroup);

        $create_table->invoke($report,
            $table, $questions, $this->quiz, $options, $allowedjoins);
        $output = ob_get_contents();
        ob_end_clean();
        $this->checkHtml($output, $csvdata, $options);
    }

    /**
     * @param \ReflectionMethod $init
     * @param \quiz_responsedownload_report $report
     * @param \stdClass $cm
     * @param $course
     * @param $attempt
     * @param int $showqtext
     * @param $whichtry
     * @param string $state
     * @param \ReflectionMethod $load
     * @param \ReflectionMethod $create_table
     * @param array $csvdata
     * @throws \ReflectionException
     */
    protected function run_download_test(\ReflectionMethod $init, \quiz_responsedownload_report $report, \stdClass $cm, $course, $attempt, int $showqtext, $whichtry, string $state, \ReflectionMethod $load, \ReflectionMethod $create_table, array $csvdata): void
    {
// => more iterations.
        $downloadpaths = [
            \quiz_responsedownload_options::STUDENT_WISE,
            \quiz_responsedownload_options::QUESTION_WISE,
        ];
        $editorfilenames = [
            \quiz_responsedownload_options::NAME_FROM_QUESTION_WITH_PATH,
            \quiz_responsedownload_options::FIXED_NAME,
            \quiz_responsedownload_options::NAME_FROM_QUESTION_WO_PATH,
        ];
        foreach ($downloadpaths as $downloadpath) {
            foreach ($editorfilenames as $editorfilename) {

                // Default initialisation.
                list($currentgroup, $studentsjoins, $groupstudentsjoins, $allowedjoins) = $init->invoke($report,
                    'responsedownload', 'quiz_responsedownload_settings_form', $this->quiz, $cm, $course);

                $options = new \quiz_responsedownload_options('responsedownload', $this->quiz, $cm, $course);
                $options->attempts = $attempt;
                $options->showqtext = $showqtext;
                $options->whichtries = $whichtry;
                $options->download = 1;
                $options->states = [$state];
                $options->folders = $downloadpath;
                $options->editorfilename = $editorfilename;
                echo '$attempt: ' . $attempt . ' $showqtext: ' . $showqtext .
                    ' $editorfilename: ' . $editorfilename .
                    ' $downloadpath: ' . $downloadpath .
                    PHP_EOL;
                flush();

                ob_start();
                list($questions, $table, $hasstudents, $allowedjoins, $hasquestions) =
                    $load->invoke($report,
                        $this->quiz, $course, $options, $groupstudentsjoins, $studentsjoins, $allowedjoins, $cm, $currentgroup);

                $create_table->invoke($report,
                    $table, $questions, $this->quiz, $options, $allowedjoins);
                $output = ob_get_contents();
                ob_end_clean();
                $filename = tempnam('/tmp', 'responsedowmload');
                $filename .= '.zip';

                file_put_contents($filename, $output);
                echo 'write zip file to ' . $filename . PHP_EOL;
                $this->checkZipContent($filename, $csvdata, '', $options);
            }
        }
    }

    protected function checkHtml($output, $csvdata, $options): void {
        // print_r($output);
        $doc = new \DOMDocument();
        $doc->loadHTML($output);
        $xpath = new \DOMXpath($doc);

        // Evaluate header columns.
        $headercol = $xpath->query("//table[@id='responses']/thead/tr/th/a[@data-sortby]");
        $header = [];
        $counter = 0;
        foreach ($headercol as $col) {
            switch ($col->getAttribute('data-sortby')) {
                case 'firstname':
                    $header[$counter] = 'name';
                    $counter++;
                    break;
                case 'lastname':
                    break;
                default:
                    $header[$counter] = $col->getAttribute('data-sortby');
                    $counter++;
                    break;
            }
        }

        $this->assertNotEquals(0, count($header));
        // print_r($header);

        // Evaluate body fields.
        $body = $xpath->query("//table[@id='responses']/tbody/tr");
        $rows = [];
        foreach ($body as $bodyrow) {
            $row = [];
            foreach ($bodyrow->childNodes as $child) {
                $classes = explode(' ', $child->getAttribute('class'));
                $col = array_filter($classes, function($x) { return str_starts_with($x, 'c') and $x != 'cell'; });
                $this->assertEquals(1, count($col));
                $col = reset($col);
                $col = substr($col, 1);
                $content = $child->textContent;
                $pos = strpos($content, 'Review attempt');
                if ($pos !== false) {
                    $content = substr($content, 0, $pos);
                }
                $row[$col] = trim($content);
            }
            // There are empty rows at the end. Check for them.
            $emptyrow = true;
            foreach ($row as $col) {
                if (strlen($col) > 2) {
                    $emptyrow = false;
                }
            }
            if (!$emptyrow) {
                // No empty row.
                $rows[] = $row;
            }
        }
        $this->assertNotEquals(0, count($rows));
        // var_dump($rows);

        // Check all question texts if available:
        $options->questionindex = [];
        if ($options->showqtext) {
            foreach ($header as $colindex => $headercol) {
                if (preg_match('/question(\d+)/i', $headercol, $matches)) {
                    $options->questionindex[$matches[1]] = $colindex;
                }
            }
        }

        $lastattemptindex = null;
        $laststep = null;
        foreach ($csvdata['steps'] as $stepfromcsv) {
            $step = $this->explode_dot_separated_keys_to_make_subindexs($stepfromcsv);
            $quizattempt = $stepfromcsv['quizattempt'];
            switch ($options->whichtries) {
                case \question_attempt::LAST_TRY:
                    if ($quizattempt != $lastattemptindex and isset($laststep)) {
                        $this->assertTrue($this->find_matching_row($laststep, $rows, $options, $header));
                    }
                    break;
                case \question_attempt::FIRST_TRY:
                    if ($quizattempt != $lastattemptindex) {
                        $this->assertTrue($this->find_matching_row($step, $rows, $options, $header));
                    }
                    break;
                case \question_attempt::ALL_TRIES:
                    $this->assertTrue($this->find_matching_row($step, $rows, $options, $header));
                    break;
            }
            $lastattemptindex = $quizattempt;
            $laststep = $step;
        }

        if (($options->whichtries == \question_attempt::LAST_TRY) and isset($laststep)) {
            $this->assertTrue($this->find_matching_row($laststep, $rows, $options, $header));
        }
    }

    protected function find_matching_row($step, $rows, $options, $header) {
        // print_r($step);

        $name = $step['firstname'] . ' ' . $step['lastname'];

        $lastusername = null;
        foreach ($rows as $row) {
            // Check user name.
            if ($row[1] != $name) {
                $emptyname = (strlen($row[1]) < 3);
                if (!$emptyname or $lastusername != $name) {
                    continue;
                }
            }
            $lastusername = $name;

            // Check question text if available
            if ($options->showqtext) {
                foreach ($options->questionindex as $slot => $qindex) {
                    // Convert German Umlauts.
                    $questiontext = mb_convert_encoding($row[$qindex], 'ISO-8859-1', 'UTF-8');
                    $this->assertEquals($this->slots[$slot]->questiontext, $questiontext);
                }
            }

            // Check all responses:
            if (!$this->responses_match($step, $row, $header)) {
                continue;
            }
            return true;
        }

        // No matching row found.
        return false;
    }


    protected function assert_response_test($quizattemptid, $responses) {
        $quizattempt = quiz_attempt::create($quizattemptid);

        foreach ($responses['slot'] as $slot => $tests) {
            $slothastests = false;
            foreach ($tests as $test) {
                if ('' !== $test) {
                    $slothastests = true;
                }
            }
            if (!$slothastests) {
                continue;
            }
            $qa = $quizattempt->get_question_attempt($slot);
            $stepswithsubmit = $qa->get_steps_with_submitted_response_iterator();
            $step = $stepswithsubmit[$responses['submittedstepno']];
            if (null === $step) {
                throw new \coding_exception("There is no step no {$responses['submittedstepno']} ".
                                           "for slot $slot in quizattempt {$responses['quizattempt']}!");
            }
            foreach (array('responsesummary', 'fraction', 'state') as $column) {
                if (isset($tests[$column]) && $tests[$column] != '') {
                    switch($column) {
                        case 'responsesummary' :
                            $actual = $qa->get_question()->summarise_response($step->get_qt_data());
                            break;
                        case 'fraction' :
                            if (count($stepswithsubmit) == $responses['submittedstepno']) {
                                // If this is the last step then we need to look at the fraction after the question has been
                                // finished.
                                $actual = $qa->get_fraction();
                            } else {
                                $actual = $step->get_fraction();
                            }
                           break;
                        case 'state' :
                            if (count($stepswithsubmit) == $responses['submittedstepno']) {
                                // If this is the last step then we need to look at the state after the question has been
                                // finished.
                                $state = $qa->get_state();
                            } else {
                                $state = $step->get_state();
                            }
                            $actual = substr(get_class($state), strlen('question_state_'));
                    }
                    $expected = $tests[$column];
                    $failuremessage = "Error in  quizattempt {$responses['quizattempt']} in $column, slot $slot, ".
                    "submittedstepno {$responses['submittedstepno']}";
                    $this->assertEquals($expected, $actual, $failuremessage);
                }
            }
        }
    }

    /**
     * @param $step
     * @param $row
     * @param $header
     * @return bool
     * @throws \coding_exception
     */
    protected function responses_match($step, $row, $header): bool
    {
        foreach ($step['responses'] as $index => $response) {
            // Find col => header = response $index
            $colname = 'response' . $index;
            $colindex = array_search($colname, $header);
            $col = $row[$colindex];
            switch ($this->slots[$index]->options->responseformat) {
                case 'editor':
                    if ($col != $response['answer']) {
                        return false;
                    }
                    break;
                case 'filepicker':
                case 'explorer':
                    if (strpos($col, 'Files:') === false) {
                        return false;
                    }
                    // Check if response belongs to user.
                    $pattern = "/Files: response_(\d+)_" . $index . ".java/i";
                    $matches = [];
                    if (!preg_match($pattern, $col, $matches)) {
                        return false;
                    }
                    $this->assertEquals(1, preg_match($pattern, $col, $matches));

                    $user = $this->users[$matches[1]];
                    $this->assertEquals($step['lastname'], $user->lastname);
                    $this->assertEquals($step['firstname'], $user->firstname);
                    $headercol = $header[$colindex];
                    $this->assertEquals(1, preg_match('/response(\d+)/i', $headercol, $matches));
                    $this->assertEquals($matches[1], $index);
                    break;
                default:
                    throw new \coding_exception('invalid proforma subtype ' .
                        $this->slots[$index]->options->responseformat);
            }
        }
        return true;
    }

    /**
     * @param $filenamearchive
     * @param $csvdata
     * @param string $editorfilename
     * @param \stdClass $data
     * @param $i
     */
    protected function checkZipContent($filenamearchive, $csvdata, string $editorfilename, $options): void
    {
        $archive = new \ZipArchive();
        $archive->open($filenamearchive);
        $countMatches = 0; // count number of matching files.

        $lastattemptindex = null;
        $laststep = null;
        foreach ($csvdata['steps'] as $stepfromcsv) {
            $step = $this->explode_dot_separated_keys_to_make_subindexs($stepfromcsv);
            $quizattempt = $stepfromcsv['quizattempt'];
            switch ($options->whichtries) {
                case \question_attempt::LAST_TRY:
                    if ($quizattempt != $lastattemptindex and isset($laststep)) {
                        $this->assertTrue($this->find_response($laststep, $archive, $options));
                        $countMatches++;
                    }
                    break;
                case \question_attempt::FIRST_TRY:
                    if ($quizattempt != $lastattemptindex) {
                        $this->assertTrue($this->find_response($step, $archive, $options));
                        $countMatches++;
                    }
                    break;
                case \question_attempt::ALL_TRIES:
                    $this->assertTrue($this->find_response($step, $archive, $options));
                    $countMatches++;
                    break;
            }
            $lastattemptindex = $quizattempt;
            $laststep = $step;
        }

        if (($options->whichtries == \question_attempt::LAST_TRY) and isset($laststep)) {
            $this->assertTrue($this->find_response($laststep, $archive, $options));
            $countMatches++;
        }


        // Note: Two attempts come from qtype_proforma - Test helper
        $this->assertTrue($archive->numFiles >= $countMatches);
        /*
                for ($i = 0; $i < $archive->numFiles; $i++) {
                    $filename = $archive->getNameIndex($i);
                    $filecontent = $archive->getFromName($filename);
                    // Dump first file name and content.
                    var_dump($filename);
                    var_dump($filecontent);
                    break;
                }
        */
        if (self::delete_tmp_archives) {
            unlink($filenamearchive);
        }
    }

    protected function find_response($step, $archive, $options) {
        foreach ($this->slots as $sindex => $question) {
            $answer = $step['responses'][$sindex];
            $this->assertTrue($this->find_answer($step, $sindex, $answer, $archive, $options));
            $this->assertEquals($options->showqtext, $this->find_questiontext($step, $sindex,
                $archive, $question));
        }
        return true;
    }

    protected function find_answer($step, $questionindex, $answer, $archive, $options) {
        $question = 'Q' . $questionindex;
        $name = $step['lastname'] . '-' . $step['firstname'];
        // var_dump($path);
        $content = $answer['answer'];

        for( $i = 0; $i < $archive->numFiles; $i++ ) {
            $filename = $archive->getNameIndex($i);
            if (strpos($filename, $question) === false) {
                continue;
            }
            if (strpos($filename, $name) === false) {
                continue;
            }
            // filename found => check content.
            // var_dump($filename);
            $filecontent = $archive->getFromName($filename);
            // var_dump($filecontent);
            if ($filecontent != $content) {
                continue;
            }
            return true;
        }

        return false;
    }

    protected function find_questiontext($steps, $questionindex, $archive, $questionobj) {
        $question = 'Q' . $questionindex;
        $name = $steps['lastname'] . '-' . $steps['firstname'];
        // var_dump($path);

        for( $i = 0; $i < $archive->numFiles; $i++ ) {
            $filename = $archive->getNameIndex($i);
            if (strpos(strtolower($filename), 'questiontext.') === false) { // may be txt or html
                continue;
            }

            if (strpos($filename, $question) === false) {
                continue;
            }

/*            // name is only in filepath if folders are studentwise
            if ($options->folders == \quiz_responsedownload_options::STUDENT_WISE) {
                if ((strpos($filename, $name) === false)) {
                    continue;
                }
            }*/

            // filename found => check content.
            $filecontent = $archive->getFromName($filename);
            // var_dump($filecontent);
            $this->assertEquals($questionobj->questiontext, $filecontent);
            return true;

            // $stat = $archive->statIndex( $i );
            // print_r( basename( $stat['name'] ) . PHP_EOL );
        }

        return false;
    }

}
