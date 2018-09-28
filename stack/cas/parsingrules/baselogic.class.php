<?php
// This file is part of Stack - http://stack.maths.ed.ac.uk/
//
// Stack is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stack is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Stack.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../casstring.class.php');
require_once(__DIR__ . '/../../utils.class.php');
require_once(__DIR__ . '/../../maximaparser/utils.php');
require_once(__DIR__ . '/../../maximaparser/MP_classes.php');


/**
 * Instances of this class handle the pre-parsing fixing of a student input
 * by adding stars between characters or in place of spaces it also includes
 * the logic applied post-parse to split identifiers to insert additional
 * stars.
 */
abstract class stack_parser_logic {

    /**
     * @var all the permitted patterns in which spaces occur.  Simple find and replace.
     */
    private static $safespacepatterns = array(
             ' or ' => 'STACKOR', ' and ' => 'STACKAND', 'not ' => 'STACKNOT',
             ' nounor ' => 'STACKNOUNOR', ' nounand ' => 'STACKNOUNAND');

    // $string => $ast, with direct assignments of details to fields in
    // the casstring, will update the string given as it is changed.
    public abstract function parse(&$string, &$valid, &$errors, &$answernote, $syntax, $safevars, $safefunctions);

    // This is the minimal implementation of pre-parse syntax fail fixes.
    // Should be enough for most logics will return an $ast or null.
    // If $insertstars or $fixspaces are false and such functionality is
    // required will set $valid=false and so on.
    protected function preparse(&$string, &$valid, &$errors, &$answernote, $insertstars = false, $fixspaces = false) {
        // These will store certain errors if the parsing is impossible.
        $err1 = false;
        $err2 = false;

        $stringles = stack_utils::eliminate_strings($string);
        // Fix ?-chars as those can do many things.
        $stringles = str_replace('?', 'QMCHAR', $stringles);

        // Missing stars patterns to fix.
        // NOTE: These patterns take into account floats, if the logic wants to
        // kill floats it can do it later after the parsing.
        $starpatterns   = array("/(\))([0-9A-Za-z_])/");    // E.g. )a, or )3.
        $starpatterns[] = "/([^0-9A-Za-z_][0-9]+)([A-DF-Za-df-z_]+|[eE][^\+\-0-9]+)/"; // +3z(, -2ee+ not *4e-2 or /1e3
        $starpatterns[] = "/^([\+\-]?[0-9]+)([A-DF-Za-df-z_]+|[eE][^\+\-0-9]+)/"; // Same but start of line.
        $starpatterns[] = "/([^0-9A-Za-z_][0-9]+)(\()/"; // -124()
        $starpatterns[] = "/^([\+\-]?[0-9]+)(\()/"; // Same but start of line
        $starpatterns[] = "/([^0-9A-Za-z_][0-9]+[.]?[0-9]*[eE][\+\-]?[0-9]+)(\()/"; // -124.4e-3()
        $starpatterns[] = "/^([\+\-]?[0-9]+[.]?[0-9]*[eE][\+\-]?[0-9]+)(\()/"; // Same but start of line.

        $missingstar    = false;
        $missingstring  = '';

        foreach ($starpatterns as $pat) {
            if (preg_match($pat, $stringles)) {
                $missingstar = true;
                $stringles = preg_replace($pat, "\${1}*%%IS\${2}", $stringles);
                if (!$insertstars) {
                    $valid = false;
                }
            }
        }

        if (false !== $missingstar) {
            // Just so that we do not add this for each star.
            $answernote[] = 'missing_stars';
            if (!$insertstars) {
                $stringged = $this->strings_replace($stringles, $string);
                $missingstring = stack_utils::logic_nouns_sort($stringged, 'remove');
                $missingstring = str_replace('*%%IS', '*', $missingstring);
                $missingstring = stack_maxima_format_casstring(preg_replace($pat,
                  "\${1}<font color=\"red\">*</font>\${2}", $missingstring));
                $stringles = preg_replace($pat, "\${1}*%%IS\${2}", $stringles);

                $missingstring = stack_utils::logic_nouns_sort($missingstring, 'remove');
                $a['cmd']  = str_replace('QMCHAR', '?', $missingstring);
                $err1 = stack_string('stackCas_MissingStars', $a);
            }
        }

        // Spaces to stars.
        $stringles = trim($stringles);
        $stringles = preg_replace('!\s+!', ' ', $stringles);

        if (strpos($stringles, ' ') !== false) {
            // Special cases: allow students to type in expressions such as "x>1 and x<4".
            foreach (self::$safespacepatterns as $key => $pat) {
                $stringles = str_replace($key, $pat, $stringles);
            }

            // NOTE: this pattern "fixes" certain valid things like calling
            // the result of a group, but as this is only applied to student
            // input and especially that example is something we do not want
            // it should not be an issue.
            $pat = "|([A-Za-z0-9_\)]+) ([A-Za-z0-9\(_]+)|";
            $fixedspace = false;
            if (preg_match($pat, $stringles)) {
                $fixedspace = true;
                $stringles = str_replace(' ', '*%%Is', $stringles);
                if (!$fixspaces) {
                    $valid = false;
                }
            }

            if ($fixedspace) {
                $answernote[] = 'spaces';
                if (!$fixspaces) {
                    $cmds = $this->strings_replace($stringles, $string);

                    foreach (self::$safespacepatterns as $key => $pat) {
                        $cmds = str_replace($pat, $key, $cmds);
                    }
                    $cmds = str_replace('*%%IS', '*', $cmds);
                    $cmds = str_replace('*%%Is', '<font color="red">_</font>', $cmds);
                    $cmds = stack_utils::logic_nouns_sort($cmds, 'remove');
                    $err2 = stack_string('stackCas_spaces', array('expr' => stack_maxima_format_casstring($cmds)));
                }
            }

            // Reverse safe spaces.
            foreach (self::$safespacepatterns as $key => $pat) {
                $stringles = str_replace($pat, $key, $stringles);
            }
        }

        $string = $this->strings_replace($stringles, $string);

        try {
            return maxima_parser_utils::parse($string);
        } catch (SyntaxError $e) {
            $valid = false;

            if ($err1 !== false) {
                $errors[] = $err1;
            }
            if ($err2 !== false) {
                $errors[] = $err2;
            }

            // No luck
            // TODO: work on the parser grammar rule naming to make the errors more readable.
            $this->handle_parse_error($e, $string, $errors, $answernote);
            return null;
        }
    }

    // This will only strip the %%IS and %%Is markers and modify the matching
    // multiplication operations.
    protected function commonpostparse($ast) {
        $processmarkkers = function($node) {
            $setop = -1;
            // %%IS is used in the pre-parser to mark implied multiplications
            if ($node instanceof MP_FunctionCall && $node->name instanceof MP_Identifier && core_text::substr($node->name->value, 0, 4) === '%%IS') {
                $node->name->value = core_text::substr($node->name->value, 4);
                if ($node->name->value === '') {
                    $node->parentnode->replace($node, new MP_Group($node->arguments));
                } else if (ctype_digit($node->name->value)) {
                    $node->parentnode->replace($node, new MP_Operation('*', new MP_Integer(intval($node->name->value), new MP_Group($node->arguments))));
                }
                $setop = null;
            } else if ($node instanceof MP_Identifier && core_text::substr($node->value, 0, 4) === '%%IS') {
                $node->value = core_text::substr($node->value, 4);
                if (ctype_digit($node->value)) {
                    $node->parentnode->replace($node, new MP_Integer(intval($node->value)));
                }
                $setop = null;
            }
            if ($setop === null) {
                if ($node->parentnode instanceof MP_Operation && $node->parentnode->rhs === $node) {
                  $node->parentnode->position = null;
                }
                return false;
            }
            // and %%Is that is used for pre-parser fixed spaces.
            if ($node instanceof MP_FunctionCall && $node->name instanceof MP_Identifier && core_text::substr($node->name->value, 0, 4) === '%%Is') {
                $node->name->value = core_text::substr($node->name->value, 4);
                if ($node->name->value === '') {
                    $node->parentnode->replace($node, new MP_Group($node->arguments));
                } else if (ctype_digit($node->name->value)) {
                    $node->parentnode->replace($node, new MP_Operation('*', new MP_Integer(intval($node->name->value), new MP_Group($node->arguments))));
                }
                $setop = false;
            } else if ($node instanceof MP_Identifier && core_text::substr($node->value, 0, 4) === '%%Is') {
                $node->value = core_text::substr($node->value, 4);
                if (ctype_digit($node->value)) {
                    $node->parentnode->replace($node, new MP_Integer(intval($node->value)));
                }
                $setop = false;
            }
            if ($setop === false) {
                if ($node->parentnode instanceof MP_Operation && $node->parentnode->rhs === $node) {
                  $node->parentnode->position = false;
                }
                return false;
            }
            return true;
        };

        while ($ast->callbackRecurse($processmarkkers) !== true) {}
    }




    private function strings_replace($stringles, $original) {
        $strings = stack_utils::all_substring_strings($original);
        if (count($strings) > 0) {
            $split = explode('""', $stringles);
            $stringbuilder = array();
            $i = 0;
            foreach ($strings as $string) {
                $stringbuilder[] = $split[$i];
                $stringbuilder[] = $string;
                $i++;
            }
            $stringbuilder[] = $split[$i];
            $stringles = implode('"', $stringbuilder);
        }
        return $stringles;
    }

    // This is the student only parser error handler. For teachers we might
    // want to give raw errors as the syntax they use may be quite a bit more
    // complex.
    private function handle_parse_error($exception, $string, &$errors, &$answernote) {
        static $disallowedfinalchars = '/+*^#~=,_&`;:$-.<>';

        $found_char = $exception->found;
        $previous_char = null;
        $next_char = null;

        if ($exception->grammarOffset >= 1) {
            $previous_char = core_text::substr($string, $exception->grammarOffset - 1, 1);
        }
        if ($exception->grammarOffset < (core_text::strlen($string) - 1)) {
            $next_char = core_text::substr($string, $exception->grammarOffset + 1, 1);
        }

        // Some common output processing.
        $original = $string;
        $string = str_replace('*%%IS', '*', $string);
        $string = str_replace('*%%Is', '*', $string);

        if ($found_char === '(' || $found_char === ')' || $previous_char === '(' || $previous_char === ')' || $found_char === null) {
          $stringles = stack_utils::eliminate_strings($string);
          $inline = stack_utils::check_bookends($stringles, '(', ')');
          if ($inline === 'left') {
            $answernote[] = 'missingLeftBracket';
            $errors[] = stack_string('stackCas_missingLeftBracket',
              array('bracket' => '(', 'cmd' => stack_maxima_format_casstring($string)));
            return;
          } else if ($inline === 'right') {
            $answernote[] = 'missingRightBracket';
            $errors[] = stack_string('stackCas_missingRightBracket',
              array('bracket' => ')', 'cmd' => stack_maxima_format_casstring($string)));
            return;
          }
        }
        if ($found_char === '[' || $found_char === ']' || $previous_char === '[' || $previous_char === ']' || $found_char === null) {
          $stringles = stack_utils::eliminate_strings($string);
          $inline = stack_utils::check_bookends($stringles, '[', ']');
          if ($inline === 'left') {
            $answernote[] = 'missingLeftBracket';
            $errors[] = stack_string('stackCas_missingLeftBracket',
              array('bracket' => '[', 'cmd' => stack_maxima_format_casstring($string)));
            return;
          } else if ($inline === 'right') {
            $answernote[] = 'missingRightBracket';
            $errors[] = stack_string('stackCas_missingRightBracket',
              array('bracket' => ']', 'cmd' => stack_maxima_format_casstring($string)));
            return;
          }
        }
        if ($found_char === '{' || $found_char === '}' || $previous_char === '{' || $previous_char === '}' || $found_char === null) {
          $stringles = stack_utils::eliminate_strings($string);
          $inline = stack_utils::check_bookends($stringles, '{', '}');
          if ($inline === 'left') {
            $answernote[] = 'missingLeftBracket';
            $errors[] = stack_string('stackCas_missingLeftBracket',
              array('bracket' => '{', 'cmd' => stack_maxima_format_casstring($string)));
            return;
          } else if ($inline === 'right') {
            $answernote[] = 'missingRightBracket';
            $errors[] = stack_string('stackCas_missingRightBracket',
              array('bracket' => '}', 'cmd' => stack_maxima_format_casstring($string)));
            return;
          }
        }

        if ($previous_char === '=' && ($found_char === '<' || $found_char === '>')) {
            $a = array();
            if ($found_char === '<') {
                $a['cmd'] = stack_maxima_format_casstring('=<');
            } else {
                $a['cmd'] = stack_maxima_format_casstring('=>');
            }
            $errors[] = stack_string('stackCas_backward_inequalities', $a);
            $answernote[] = 'backward_inequalities';
        } else if ($found_char === '=' && ($next_char === '<' || $next_char === '>')) {
            $a = array();
            if ($next_char === '<') {
                $a['cmd'] = stack_maxima_format_casstring('=<');
            } else {
                $a['cmd'] = stack_maxima_format_casstring('=>');
            }
            $errors[] = stack_string('stackCas_backward_inequalities', $a);
            $answernote[] = 'backward_inequalities';
        } else if ($found_char === "'") {
            $errors[] = stack_string('stackCas_apostrophe');
            $answernote[] = 'apostrophe';
        } else if (($found_char === '/' && $next_char === '*') || ($found_char === '*' && $previous_char === '/')) {
            $a = array('cmd' => stack_maxima_format_casstring('/*'));
            $errors[] = stack_string('stackCas_spuriousop', $a);
            $answernote[] = 'spuriousop';
        } else if ($found_char === '=' && $next_char === '=' && $previous_char === '=') {
            $a = array('cmd' => stack_maxima_format_casstring('==='));
            $errors[] = stack_string('stackCas_spuriousop', $a);
            $answernote[] = 'spuriousop';
        } else if ($found_char === '=' && ($next_char === '=' || $previous_char === '=')) {
            $a = array('cmd' => stack_maxima_format_casstring('=='));
            $errors[] = stack_string('stackCas_spuriousop', $a);
            $answernote[] = 'spuriousop';
        } else if ($found_char === '&') {
            $a = array('cmd' => stack_maxima_format_casstring('&'));
            $errors[] = stack_string('stackCas_spuriousop', $a);
            $answernote[] = 'spuriousop';
        } else if (ctype_alpha($found_char) && ctype_digit($previous_char)) {
            $a = array('cmd' => stack_maxima_format_casstring(core_text::substr($string, 0, $exception->grammarOffset) . '<font color="red">*</font>' . core_text::substr($string, $exception->grammarOffset)));
            $answernote[] = 'missing_stars';
        } else if ($found_char === ',' || (ctype_digit($found_char) && $previous_char === ',')) {
            $errors[] = stack_string('stackCas_unencpsulated_comma');
            $answernote[] = 'unencpsulated_comma';
        } else if ($found_char === '\\') {
            $errors[] = stack_string('illegalcaschars');
            $answernote[] = 'illegalcaschars';
        } else if ($previous_char === ' ') {
            $cmds = trim(core_text::substr($original, 0, $exception->grammarOffset - 1));
            $cmds .= '<font color="red">_</font>';
            $cmds .= core_text::substr($original, $exception->grammarOffset);
            $cmds = str_replace('*%%IS', '*', $cmds);
            $cmds = str_replace('*%%Is', '<font color="red">_</font>', $cmds);
            $answernote[] = 'spaces';
            $cmds = stack_utils::logic_nouns_sort($cmds, 'remove');
            $errors[] = stack_string('stackCas_spaces', array('expr' => stack_maxima_format_casstring($cmds)));
        } else if ($found_char === ':' && (strpos($string, ':lisp') !== false)) {
            $errors[] = stack_string('stackCas_forbiddenWord',
                    array('forbid' => stack_maxima_format_casstring('lisp')));
            $answernote[] = 'forbiddenWord';
        } else if (count($exception->expected) === 6 &&
                   $exception->expected[0]['type'] === 'literal' && $exception->expected[0]['value'] === ',' &&
                   $exception->expected[1]['type'] === 'literal' && $exception->expected[1]['value'] === ':' &&
                   $exception->expected[2]['type'] === 'literal' && $exception->expected[2]['value'] === ';' &&
                   $exception->expected[3]['type'] === 'literal' && $exception->expected[3]['value'] === '=' &&
                   $exception->expected[4]['type'] === 'end' &&
                   $exception->expected[5]['type'] === 'other' && $exception->expected[5]['description'] === 'whitespace') {
            // This is a sensitive check matching the expectations of the parser....
            // This is extra special, if we have an unencpsulated comma we might be parsing for an evaluation
            // flag but not find the assingment of flag value...
            $errors[] = stack_string('stackCas_unencpsulated_comma');
            $answernote[] = 'unencpsulated_comma';
        } else if ($next_char === null && ($found_char !== null && core_text::strpos($disallowedfinalchars, $found_char) !== false)) {
            $a = array();
            $a['char'] = $found_char;
            $cdisp = stack_utils::logic_nouns_sort($string, 'remove');
            $a['cmd']  = stack_maxima_format_casstring($cdisp);
            $errors[] = stack_string('stackCas_finalChar', $a);
            $answernote[] = 'finalChar';
        } else if ($found_char === null && ($previous_char !== null && core_text::strpos($disallowedfinalchars, $previous_char) !== false)) {
            $a = array();
            $a['char'] = $previous_char;
            $cdisp = stack_utils::logic_nouns_sort($string, 'remove');
            $a['cmd']  = stack_maxima_format_casstring($cdisp);
            $errors[] = stack_string('stackCas_finalChar', $a);
            $answernote[] = 'finalChar';
        } else if ($found_char === '!' && ($previous_char === null || !(ctype_alpha($previous_char) || ctype_digit($previous_char) || $previous_char === ')' || $previous_char === ']'))) {
            // TODO: Localise... "Operator X without a valid target. Needs something in front of it."
            $a = array('op' => stack_maxima_format_casstring('!'));
            $errors[] = stack_string('stackCas_badpostfixop', $a);
            $answernote[] = 'badpostfixop';
        } else {
            $errors[] = $exception->getMessage();
            $answernote[] = 'ParseError';
        }
    }
}