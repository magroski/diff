<?php

declare(strict_types=1);

namespace Diff;

/**
 * class.Diff.php
 *
 * A class containing a diff implementation
 *
 * Created by Stephen Morley - http://stephenmorley.org/ - and released under the
 * terms of the CC0 1.0 Universal legal code:
 *
 * http://creativecommons.org/publicdomain/zero/1.0/legalcode
 */
class Diff
{
    /** @var int */
    public const UNMODIFIED = 0;
    /** @var int */
    public const DELETED = 1;
    /** @var int */
    public const INSERTED = 2;

    /**
     * Returns the diff for two strings. The return value is an array, each of
     * whose values is an array containing two values: a line (or character, if
     * $compareCharacters is true), and one of the constants DIFF::UNMODIFIED (the
     * line or character is in both strings), DIFF::DELETED (the line or character
     * is only in the first string), and DIFF::INSERTED (the line or character is
     * only in the second string). The parameters are:
     *
     * $string1           - the first string
     * $string2           - the second string
     * $compareCharacters - true to compare characters, and false to compare
     *                      lines; this optional parameter defaults to false
     * @param string $string1
     * @param string $string2
     * @param bool   $compareCharacters
     * @return mixed[]
     */
    public static function compare(string $string1, string $string2, bool $compareCharacters = false) : array
    {
        // initialise the sequences and comparison start and end positions
        $start = 0;
        if ($compareCharacters) {
            $sequence1 = $string1;
            $sequence2 = $string2;
            $end1      = strlen($string1) - 1;
            $end2      = strlen($string2) - 1;
        } else {
            $sequence1 = preg_split('/\R/', $string1);
            if ($sequence1 === false) {
                throw new \RuntimeException('Failed to split');
            }
            $sequence2 = preg_split('/\R/', $string2);
            if ($sequence2 === false) {
                throw new \RuntimeException('Failed to split');
            }
            $end1 = count($sequence1) - 1;
            $end2 = count($sequence2) - 1;
        }

        // skip any common prefix
        while ($start <= $end1 && $start <= $end2 && $sequence1[$start] === $sequence2[$start]) {
            $start++;
        }

        // skip any common suffix
        while ($end1 >= $start && $end2 >= $start && $sequence1[$end1] === $sequence2[$end2]) {
            $end1--;
            $end2--;
        }

        // compute the table of longest common subsequence lengths
        $table = self::computeTable($sequence1, $sequence2, $start, $end1, $end2);

        // generate the partial diff
        $partialDiff =
            self::generatePartialDiff($table, $sequence1, $sequence2, $start);

        // generate the full diff
        $diff = [];
        for ($index = 0; $index < $start; $index++) {
            $diff[] = [$sequence1[$index], self::UNMODIFIED];
        }
        while (count($partialDiff) > 0) {
            $diff[] = array_pop($partialDiff);
        }
        $max = $compareCharacters && is_string($sequence1) ? strlen($sequence1) : (is_array($sequence1) ? count($sequence1) : 0);
        for ($index = $end1 + 1; $index < $max; $index++) {
            $diff[] = [$sequence1[$index], self::UNMODIFIED];
        }

        return $diff;
    }

    /**
     * Returns the diff for two files. The parameters are:
     *
     * $file1             - the path to the first file
     * $file2             - the path to the second file
     * $compareCharacters - true to compare characters, and false to compare
     *                      lines; this optional parameter defaults to false
     * @param string $file1
     * @param string $file2
     * @param bool   $compareCharacters
     * @return string[]
     */
    public static function compareFiles(string $file1, string $file2, bool $compareCharacters = false) : array
    {
        $contents1 = file_get_contents($file1);
        $contents2 = file_get_contents($file2);
        if (!$contents1 || !$contents2) {
            throw new \RuntimeException('Failed to get file contents');
        }

        return self::compare($contents1, $contents2, $compareCharacters);
    }

    /**
     * Returns the table of longest common subsequence lengths for the specified
     * sequences. The parameters are:
     *
     * $sequence1 - the first sequence
     * $sequence2 - the second sequence
     * $start     - the starting index
     * $end1      - the ending index for the first sequence
     * $end2      - the ending index for the second sequence
     * @param string[]|string $sequence1
     * @param string[]|string $sequence2
     * @param int             $start
     * @param int             $end1
     * @param int             $end2
     * @return mixed[]
     */
    private static function computeTable($sequence1, $sequence2, int $start, int $end1, int $end2) : array
    {
        // determine the lengths to be compared
        $length1 = $end1 - $start + 1;
        $length2 = $end2 - $start + 1;

        // initialise the table
        $table = [array_fill(0, $length2 + 1, 0)];

        // loop over the rows
        for ($index1 = 1; $index1 <= $length1; $index1++) {
            // create the new row
            $table[$index1] = [0];

            // loop over the columns
            for ($index2 = 1; $index2 <= $length2; $index2++) {
                // store the longest common subsequence length
                if ($sequence1[$index1 + $start - 1] === $sequence2[$index2 + $start - 1]) {
                    $table[$index1][$index2] = $table[$index1 - 1][$index2 - 1] + 1;
                } else {
                    $table[$index1][$index2] = max($table[$index1 - 1][$index2], $table[$index1][$index2 - 1]);
                }
            }
        }

        return $table;
    }

    /**
     * Returns the partial diff for the specificed sequences, in reverse order.
     * The parameters are:
     *
     * $table     - the table returned by the computeTable function
     * $sequence1 - the first sequence
     * $sequence2 - the second sequence
     * $start     - the starting index
     * @param mixed[]         $table
     * @param string[]|string $sequence1
     * @param string[]|string $sequence2
     * @param int             $start
     * @return mixed[]
     */
    private static function generatePartialDiff(array $table, $sequence1, $sequence2, int $start) : array
    {
        //  initialise the diff
        $diff = [];

        // initialise the indices
        $index1 = count($table) - 1;
        $index2 = count($table[0]) - 1;

        // loop until there are no items remaining in either sequence
        while ($index1 > 0 || $index2 > 0) {
            // check what has happened to the items at these indices
            if ($index1 > 0 && $index2 > 0
                && $sequence1[$index1 + $start - 1]
                === $sequence2[$index2 + $start - 1]
            ) {
                // update the diff and the indices
                $diff[] = [$sequence1[$index1 + $start - 1], self::UNMODIFIED];
                $index1--;
                $index2--;
            } else {
                if ($index2 > 0
                    && $table[$index1][$index2] === $table[$index1][$index2 - 1]
                ) {
                    // update the diff and the indices
                    $diff[] = [$sequence2[$index2 + $start - 1], self::INSERTED];
                    $index2--;
                } else {
                    // update the diff and the indices
                    $diff[] = [$sequence1[$index1 + $start - 1], self::DELETED];
                    $index1--;
                }
            }
        }

        return $diff;
    }

    /**
     * Returns a diff as a string, where unmodified lines are prefixed by '  ',
     * deletions are prefixed by '- ', and insertions are prefixed by '+ '. The
     * parameters are:
     *
     * $diff      - the diff array
     * $separator - the separator between lines; this optional parameter defaults
     *              to "\n"
     *
     * @param string[] $diff
     * @param string   $separator
     * @return string
     */
    public static function toString(array $diff, string $separator = "\n") : string
    {
        // initialise the string
        $string = '';

        // loop over the lines in the diff
        foreach ($diff as $line) {
            // extend the string with the line
            switch ($line[1]) {
                case self::UNMODIFIED:
                    $string .= '  ' . $line[0];
                    break;
                case self::DELETED:
                    $string .= '- ' . $line[0];
                    break;
                case self::INSERTED:
                    $string .= '+ ' . $line[0];
                    break;
            }

            // extend the string with the separator
            $string .= $separator;
        }

        return $string;
    }

    /**
     * Returns a diff as an HTML string, where unmodified lines are contained
     * within 'span' elements, deletions are contained within 'del' elements, and
     * insertions are contained within 'ins' elements. The parameters are:
     *
     * $diff      - the diff array
     * $separator - the separator between lines; this optional parameter defaults
     *              to '<br>'
     *
     * @param string[] $diff
     * @param string   $separator
     * @return string
     */
    public static function toHTML(array $diff, string $separator = '<br>') : string
    {
        $html = $element = '';

        // loop over the lines in the diff
        foreach ($diff as $line) {
            // extend the HTML with the line
            switch ($line[1]) {
                case self::UNMODIFIED:
                    $element = 'span';
                    break;
                case self::DELETED:
                    $element = 'del';
                    break;
                case self::INSERTED:
                    $element = 'ins';
                    break;
            }
            $html .=
                '<' . $element . '>'
                . htmlspecialchars($line[0])
                . '</' . $element . '>';

            // extend the HTML with the separator
            $html .= $separator;
        }

        return $html;
    }

    /**
     * Returns a diff as an HTML table. The parameters are:
     *
     * $diff        - the diff array
     * $indentation - indentation to add to every line of the generated HTML; this
     *                optional parameter defaults to ''
     * $separator   - the separator between lines; this optional parameter
     *                defaults to '<br>'
     * @param string[] $diff
     * @param string   $indentation
     * @param string   $separator
     * @return string
     */
    public static function toTable(array $diff, string $indentation = '', string $separator = '<br>') : string
    {
        // initialise the HTML
        $html = $indentation . "<table class=\"diff\">\n";

        // loop over the lines in the diff
        $index = 0;
        while ($index < count($diff)) {
            $leftCell = $rightCell = '';
            // determine the line type
            switch ($diff[$index][1]) {
                // display the content on the left and right
                case self::UNMODIFIED:
                    $leftCell  =
                        self::getCellContent($diff, $separator, $index, self::UNMODIFIED);
                    $rightCell = $leftCell;
                    break;

                // display the deleted on the left and inserted content on the right
                case self::DELETED:
                    $leftCell  =
                        self::getCellContent($diff, $separator, $index, self::DELETED);
                    $rightCell =
                        self::getCellContent($diff, $separator, $index, self::INSERTED);
                    break;

                // display the inserted content on the right
                case self::INSERTED:
                    $leftCell  = '';
                    $rightCell =
                        self::getCellContent($diff, $separator, $index, self::INSERTED);
                    break;
            }

            // extend the HTML with the new row
            $html .=
                $indentation
                . "  <tr>\n"
                . $indentation
                . '    <td class="diff'
                . ($leftCell === $rightCell
                    ? 'Unmodified'
                    : ($leftCell === '' ? 'Blank' : 'Deleted'))
                . '">'
                . $leftCell
                . "</td>\n"
                . $indentation
                . '    <td class="diff'
                . ($leftCell === $rightCell
                    ? 'Unmodified'
                    : ($rightCell === '' ? 'Blank' : 'Inserted'))
                . '">'
                . $rightCell
                . "</td>\n"
                . $indentation
                . "  </tr>\n";
        }

        return $html . $indentation . "</table>\n";
    }

    /**
     * Returns the content of the cell, for use in the toTable function. The
     * parameters are:
     *
     * $diff        - the diff array
     * $separator   - the separator between lines
     * $index       - the current index, passes by reference
     * $type        - the type of line
     * @param mixed[] $diff
     * @param string  $separator
     * @param int     $index
     * @param int     $type
     * @return string
     */
    private static function getCellContent(array $diff, string $separator, int &$index, int $type) : string
    {
        // initialise the HTML
        $html = '';

        // loop over the matching lines, adding them to the HTML
        while ($index < count($diff) && $diff[$index][1] === $type) {
            $html .=
                '<span>'
                . htmlspecialchars($diff[$index][0])
                . '</span>'
                . $separator;
            $index++;
        }

        return $html;
    }
}
