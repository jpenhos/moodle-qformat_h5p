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
 * Question Import for H5P Quiz content type
 *
 * @package    qformat_h5p
 * @copyright  2020 Daniel Thies <dethies@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

use qformat_h5p\local;

/**
 * Question Import for H5P Quiz content type
 *
 * @copyright  2020 Daniel Thies <dethies@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qformat_h5p extends qformat_default {
    public function provide_import() {
        return true;
    }

    public function provide_export() {
        return false;
    }

    public function export_file_extension() {
        return '.h5p';
    }

    public function mime_type() {
        // This is a hack to support version before h5p support.
        if (mimeinfo('type', $this->export_file_extension()) == 'document/unknown') {
            return mimeinfo('type', '.zip');
        }
        return mimeinfo('type', $this->export_file_extension());
    }

    /**
     * Return the content of a file given by its path in the tempdir directory.
     *
     * @param string $path path to the file inside tempdir
     * @return mixed contents array or false on failure
     */
    public function get_filecontent($path) {
        $fullpath = $this->tempdir . '/' . $path;
        if (is_file($fullpath) && is_readable($fullpath)) {
            return file_get_contents($fullpath);
        }
        return false;
    }


    /**
     * Return content of all files containing questions,
     * as an array one element for each file found,
     * For each file, the corresponding element is an array of lines.
     *
     * @param string $filename name of file
     * @return mixed contents array or false on failure
     */
    public function readdata($filename) {
        global $CFG;

        $uniquecode = time();
        $this->tempdir = make_temp_directory('h5p_import/' . $uniquecode);
        if (is_readable($filename)) {
            if (!copy($filename, $this->tempdir . '/content.zip')) {
                $this->error(get_string('cannotcopybackup', 'question'));
                fulldelete($this->tempdir);
                return false;
            }
            $packer = get_file_packer('application/zip');
            if ($packer->extract_to_pathname($this->tempdir . '/content.zip', $this->tempdir)) {
                $h5p = json_decode($this->get_filecontent('h5p.json'));

                $questions = array();
                $content = json_decode($this->get_filecontent('content/content.json'));

                switch ($h5p->mainLibrary) {
                    case 'H5P.Column':
                        return array_column($content->content, 'content');
                    case 'H5P.QuestionSet':
                        return $content->questions;
                    case 'H5P.SingleChoiceSet':
                        $questions = array();
                        foreach ($content->choices as $choice) {
                            $answers = array();
                            foreach ($choice->answers as $key => $answer) {
                                $answers[] = (object) array(
                                    'text' => $answer,
                                    'correct' => empty($key),
                                    'tipsAndFeedback' => (object) array(
                                        'chosenFeedback' => empty($key) ? $content->l10n->correctText : $content->l10n->incorrectText,
                                    ),
                                );
                            }
                            $questions[] = (object) array(
                                'params' => (object) array(
                                    'question' => $choice->question,
                                    'answers' => $answers,
                                ),
                                'metadata' => (object) array(
                                    'title' => $choice->question,
                                ),
                                'library' => 'H5P.MultiChoice',
                            );
                        };
                        return $questions;
                    case 'H5P.Blanks':
                    case 'H5P.DragQuestion':
                    case 'H5P.Multichoice':
                    case 'H5P.TrueFalse':
                    case 'H5P.DragText':
                        $question = new stdClass();
                        $question->params = $content;
                        $question->metadata = (object) array(
                            'title' => $h5p->title,
                        );
                        $question->library = $h5p->mainLibrary;
                        return array($question);
                    default:
                        fulldelete($this->tempdir);
                        return false;
                }

                return $questions;
            } else {
                $this->error(get_string('cannotunzip', 'question'));
                fulldelete($this->temp_dir);
            }
        } else {
            $this->error(get_string('cannotreaduploadfile', 'error'));
            fulldelete($this->tempdir);
        }
        return false;
    }

    /**
     * Parse the array of objects into an array of questions.
     *
     * @param array $lines array of json decoded h5p content objects for each input file.
     * @return array (of objects) question objects.
     */
    public function readquestions($lines) {

        // Set up array to hold all our questions.
        $questions = array();

        // Each element of $lines is a h5p content type with data.
        foreach ($lines as $content) {

            if (($type = $this->create_content_type($content)) && $qo = $type->import_question()) {
                $questions[] = $qo;
            }
        }
        return $questions;
    }

    /**
     * Find read question type from content and provide appropriate converter
     *
     * @param object content question data
     * @retun object import object
     */
    public function create_content_type($content) {
        if (empty($content->library)) {
            return '';
        }
        switch (preg_replace('/ .*/', '', $content->library)) {
            case 'H5P.Blanks':
                return new local\type_fib($content, $this->tempdir);
            case 'H5P.MultiChoice':
                return new local\type_mc($content, $this->tempdir);
            case 'H5P.TrueFalse':
                return new local\type_tf($content, $this->tempdir);
            case 'H5P.DragQuestion':
                return new local\type_dnd($content, $this->tempdir);
                return new local\type_drag($content, $this->tempdir);
            case 'H5P.DragText':
                return new local\type_dtw($content, $this->tempdir);
            default:
                return '';
                return new local\type_desc($content, $this->tempdir); // This is more helpful for debugging.
        }
    }
}
