<?php
// This file is part of ProFormA Question Type for Moodle
//
// ProFormA Question Type for Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// ProFormA Question Type for Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat extensions for proforma
 *
 * @package    qtype_proforma
 * @copyright  2019 Ostfalia Hochschule fuer angewandte Wissenschaften
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');
require_once(__DIR__ . '/../../../../../../mod/quiz/tests/behat/behat_mod_quiz.php');

use Behat\Gherkin\Node\TableNode as TableNode;

use Behat\Mink\Exception\ExpectationException as ExpectationException;

class behat_responses extends behat_mod_quiz {

    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'download text responses':
                return new moodle_url('/mod/quiz/report.php',
                    ['id' => $this->get_cm_by_quiz_name($identifier)->id, 'mode' => 'responsedownload']);

        }
        return parent::resolve_page_instance_url($type, $identifier);
    }

}
