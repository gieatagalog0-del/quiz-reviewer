<?php
/*
 * docx_parser.php - reads a Microsoft Word (.docx) file and turns it into multiple-choice questions.
 * A rule-based reader (no internet, no external AI service):
 *   - finds numbered questions ("1.", "Q1.", "Question 1:") or un-numbered ones
 *   - finds choices A-D ("A.", "a)", "(A)")
 *   - finds the answer from "Answer: C", an answer key at the end, a bold/underlined/
 *     highlighted/colored choice, or a * / (correct) marker after a choice
 *   - finds "Explanation:" lines
 * Anything it cannot understand is returned as a warning so the user can fix it in the preview screen.
 */

class QuizDraft
{
    public $text = '';
    public $choices = [];
    public $marked = [];
    public $answer = -1;
    public $explanation = '';

    public function __construct($text)
    {
        $this->text = $text;
    }
}

final class DocxQuizParser
{
    const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const MAX_XML = 26214400;          // 25 MB of XML
    const MAX_QUESTIONS = 500;
    const NO_EXPLANATION = 'No explanation was provided for this question.';

    const P_QUESTION = 0;
    const P_CHOICES = 1;
    const P_DONE = 2;

    const RE_ANSWER = '~^(?:correct\s+)?(?:answer|ans|key)\s*(?:is\s*)?[:\-=.]?\s*\(?([A-Da-d])\)?(?:[.):,\-].*)?$~iuD';
    const RE_ANSWER_TEXT = '~^(?:correct\s+)?(?:answer|ans)\s*[:\-=]\s*(\S.*)$~iuD';
    const RE_EXPL = '~^(?:explanation|rationale|reason|solution|why)\s*[:\-]\s*(.*)$~iuD';
    const RE_CHOICE = '~^\(?([A-Da-d])[.):]\s*(\S.*)$~uD';
    const RE_QNUM = '~^(?:q(?:uestion)?\s*)?(\d{1,3})\s*[.):\-]\s+(\S.*)$~iuD';
    const RE_KEY_HDR = '~^(?:answer\s*key|answers?|key)\s*:?$~iuD';
    const RE_KEY_ENTRY = '~(\d{1,3})\s*[.):\-]?\s*([A-Da-d])\b~u';
    const RE_MARK = '~\s*(?:\*|\x{2713}|\x{2714}|\(correct\)|\[correct\])\s*$~iu';

    /** @return array{questions: array, warnings: array} */
    public static function parse($docxBytes)
    {
        $questions = [];
        $warnings = [];
        $lines = self::readLines($docxBytes);

        $all = [];
        $key = [];
        $cur = null;
        $phase = self::P_QUESTION;
        $keyMode = false;
        $expectExpl = false;

        foreach ($lines as $ln) {
            $t = $ln['text'];

            if ($keyMode) {
                $any = false;
                if (preg_match_all(self::RE_KEY_ENTRY, $t, $km, PREG_SET_ORDER)) {
                    foreach ($km as $m) {
                        $key[(int) $m[1]] = ord(strtoupper($m[2])) - ord('A');
                        $any = true;
                    }
                }
                if ($any) {
                    continue;
                }
                $keyMode = false;
            }
            if (preg_match(self::RE_KEY_HDR, $t)) {
                self::finish($cur, $all);
                $cur = null;
                $keyMode = true;
                continue;
            }

            if (preg_match(self::RE_ANSWER, $t, $am)) {
                if ($cur !== null) {
                    $cur->answer = ord(strtoupper($am[1])) - ord('A');
                    $phase = self::P_DONE;
                }
                continue;
            }
            if (preg_match(self::RE_ANSWER_TEXT, $t, $at)) {
                if ($cur !== null) {
                    $idx = self::matchChoice($cur, $at[1]);
                    if ($idx >= 0) {
                        $cur->answer = $idx;
                    }
                    $phase = self::P_DONE;
                }
                continue;
            }
            if (preg_match(self::RE_EXPL, $t, $em)) {
                if ($cur !== null) {
                    $cur->explanation = trim($em[1]);
                    $expectExpl = ($cur->explanation === '');
                    $phase = self::P_DONE;
                }
                continue;
            }
            if (preg_match(self::RE_CHOICE, $t, $cm) && $cur !== null && count($cur->choices) < 4
                && ord(strtoupper($cm[1])) - ord('A') === count($cur->choices)) {
                self::addChoice($cur, trim($cm[2]), $ln['marked']);
                $phase = self::P_CHOICES;
                continue;
            }
            if (preg_match(self::RE_QNUM, $t, $qm)) {
                self::finish($cur, $all);
                $cur = new QuizDraft(trim($qm[2]));
                $phase = self::P_QUESTION;
                $expectExpl = false;
                continue;
            }

            // A plain line that matched nothing above
            if ($expectExpl && $cur !== null) {
                $cur->explanation = $t;
                $expectExpl = false;
                continue;
            }
            if ($cur === null) {
                $cur = new QuizDraft($t);
                $phase = self::P_QUESTION;
                continue;
            }
            if ($phase === self::P_QUESTION) {
                $cur->text .= "\n" . $t;
            } elseif ($phase === self::P_CHOICES) {
                if (count($cur->choices) < 4) { // wrapped choice text
                    $last = count($cur->choices) - 1;
                    $cur->choices[$last] .= ' ' . $t;
                } else {
                    self::finish($cur, $all);
                    $cur = new QuizDraft($t);
                    $phase = self::P_QUESTION;
                }
            } else { // P_DONE: the previous question is complete, so this starts an un-numbered one
                self::finish($cur, $all);
                $cur = new QuizDraft($t);
                $phase = self::P_QUESTION;
            }
        }
        self::finish($cur, $all);

        // Apply the answer key (by position) where an answer is still missing
        foreach ($all as $i => $q) {
            if (isset($key[$i + 1]) && $q->answer === -1) {
                $q->answer = $key[$i + 1];
            }
        }

        // Build the result and report problems
        $n = 0;
        foreach ($all as $i => $q) {
            $label = 'Question ' . ($i + 1) . ': ';
            $text = trim($q->text);
            if (count($q->choices) !== 4) {
                $warnings[] = $label . 'found ' . count($q->choices) . ' choice(s) but exactly 4 (A, B, C, D) are needed. This question was skipped.';
                continue;
            }
            if ($text === '') {
                $warnings[] = $label . 'the question text is empty. Skipped.';
                continue;
            }
            if ($q->answer < 0 || $q->answer > 3) {
                $found = -1;
                $count = 0;
                for ($k = 0; $k < 4; $k++) {
                    if ($q->marked[$k]) {
                        $found = $k;
                        $count++;
                    }
                }
                $q->answer = ($count === 1) ? $found : -1;
            }
            if ($q->answer < 0) {
                $warnings[] = $label . 'no correct answer was found. Pick it in the preview below.';
            }
            $uniq = [];
            foreach ($q->choices as $c) {
                $uniq[mb_strtolower($c)] = true;
            }
            if (count($uniq) < 4) {
                $warnings[] = $label . 'two choices look identical. Please check them.';
            }

            if (++$n > self::MAX_QUESTIONS) {
                $warnings[] = 'Only the first ' . self::MAX_QUESTIONS . ' questions are kept.';
                break;
            }
            $questions[] = [
                'q'           => $text,
                'choices'     => array_values($q->choices),
                'answer'      => $q->answer,
                'explanation' => $q->explanation === '' ? self::NO_EXPLANATION : $q->explanation,
            ];
        }
        if (count($questions) === 0 && count($all) === 0) {
            $warnings[] = 'No questions were found. Check that the file follows the format guide on this page.';
        }
        return ['questions' => $questions, 'warnings' => $warnings];
    }

    // ---------------------------------------------------------------------
    private static function finish($q, array &$all)
    {
        if ($q === null) {
            return;
        }
        if (count($q->choices) === 0 && count($all) === 0) {
            return; // title or instructions before the first question
        }
        $all[] = $q;
    }

    private static function addChoice(QuizDraft $q, $text, $marked)
    {
        if (preg_match(self::RE_MARK, $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
            $text = trim(substr($text, 0, $m[0][1]));
            $marked = true;
            if ($q->answer === -1) {
                $q->answer = count($q->choices); // an explicit marker counts as the answer
            }
        }
        $q->choices[] = $text;
        $q->marked[] = (bool) $marked;
    }

    private static function matchChoice(QuizDraft $q, $answerText)
    {
        $a = self::norm($answerText);
        foreach ($q->choices as $i => $c) {
            if (self::norm($c) === $a) {
                return $i;
            }
        }
        return -1;
    }

    private static function norm($s)
    {
        return preg_replace('~[.\s]+$~u', '', mb_strtolower(trim($s)));
    }

    // ---------------------------------------------------------------------
    // Reading the .docx (a ZIP file that contains word/document.xml)
    // ---------------------------------------------------------------------
    private static function readLines($docx)
    {
        if (strlen($docx) < 4 || substr($docx, 0, 2) !== 'PK') {
            throw new InvalidArgumentException('This is not a .docx file. Open it in Word and use File > Save As > Word Document (*.docx).');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP "zip" extension is not enabled.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cqr');
        file_put_contents($tmp, $docx);
        $xml = null;
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new InvalidArgumentException('The Word file could not be opened. It may be damaged.');
            }
            $idx = $zip->locateName('word/document.xml');
            if ($idx !== false) {
                $st = $zip->statIndex($idx);
                if ($st['size'] > self::MAX_XML) {
                    $zip->close();
                    throw new InvalidArgumentException('The Word file is too large to read.');
                }
                $xml = $zip->getFromIndex($idx);
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }
        if ($xml === null || $xml === false) {
            throw new InvalidArgumentException('This does not look like a Word document (.docx).');
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('The Word file could not be read. It may be damaged.'); // blocks XXE
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new InvalidArgumentException('The Word file could not be read. It may be damaged.');
        }

        $out = [];
        $paras = $doc->getElementsByTagNameNS(self::W, 'p');
        foreach ($paras as $p) {
            self::extractLines($p, $out);
        }
        return $out;
    }

    private static function extractLines(DOMElement $p, array &$out)
    {
        $text = '';
        $marked = 0;
        $total = 0;
        foreach ($p->getElementsByTagNameNS(self::W, 'r') as $r) {
            $m = self::isMarked($r);
            foreach ($r->childNodes as $c) {
                if ($c->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $n = $c->localName;
                if ($n === 't') {
                    $t = $c->textContent;
                    $text .= $t;
                    $chars = preg_match_all('~\S~u', $t);
                    $total += $chars;
                    if ($m) {
                        $marked += $chars;
                    }
                } elseif ($n === 'tab') {
                    $text .= ' ';
                } elseif ($n === 'br' || $n === 'cr') {
                    if ($c->getAttributeNS(self::W, 'type') !== 'page') {
                        self::flush($text, $marked, $total, $out);
                        $text = '';
                        $marked = 0;
                        $total = 0;
                    }
                }
            }
        }
        self::flush($text, $marked, $total, $out);
    }

    private static function flush($text, $marked, $total, array &$out)
    {
        $t = preg_replace('~^\s+|\s+$~u', '', str_replace("\xC2\xA0", ' ', $text));
        if ($t !== '' && $t !== null) {
            $out[] = ['text' => $t, 'marked' => ($total > 0 && $marked * 2 > $total)];
        }
    }

    /** True when a run is bold, underlined, highlighted, or colored. */
    private static function isMarked(DOMElement $run)
    {
        $rPr = null;
        foreach ($run->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'rPr') {
                $rPr = $c;
                break;
            }
        }
        if ($rPr === null) {
            return false;
        }
        foreach ($rPr->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $v = $c->getAttributeNS(self::W, 'val');
            switch ($c->localName) {
                case 'b':
                    if ($v !== '0' && $v !== 'false') {
                        return true;
                    }
                    break;
                case 'u':
                case 'highlight':
                    if ($v !== 'none') {
                        return true;
                    }
                    break;
                case 'color':
                    if ($v !== '' && strcasecmp($v, 'auto') !== 0 && $v !== '000000') {
                        return true;
                    }
                    break;
            }
        }
        return false;
    }
}
