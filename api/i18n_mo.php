<?php
/**
 * School Connect API — gettext .mo reader (used by i18n.php)
 *
 * Reads a compiled gettext .mo file straight from disk, instead of going
 * through PHP's gettext(), for two reasons:
 *   - gettext() only works when the server's operating system has that
 *     language installed (core calls setlocale(LC_ALL, 'fr_FR.utf8')); if
 *     it isn't, gettext() silently returns English. Reading the file has
 *     no such dependency.
 *   - The app needs the whole list in one response so it can work
 *     offline, and gettext() can only look up one string at a time.
 * The files themselves are exactly the ones Gibbon uses: core's
 * i18n/<code>/LC_MESSAGES/gibbon.mo and this module's own
 * modules/School Connect/i18n/<code>/LC_MESSAGES/School Connect.mo (the
 * text domain core registers for every active additional module in
 * Locale::setTextDomain / setModuleTextDomain, v30).
 *
 * No database access, no output. Safe to include from any endpoint.
 */

/**
 * Parses a .mo file. Returns null if the file is missing or not a valid
 * .mo file, otherwise:
 *   'strings'     => [msgid => msgstr]                  (singular entries)
 *   'plurals'     => [singular msgid => [form0, form1, …]] (ngettext entries)
 *   'pluralForms' => the header's Plural-Forms value, or ''
 * Entries with a context (msgctxt) are skipped: Gibbon doesn't use them.
 */
function gibbonConnectReadMo(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) return null;
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 28) return null;

    // Magic number tells us the byte order the file was written in.
    $magic = unpack('V', substr($data, 0, 4))[1];
    if ($magic === 0x950412de) {
        $fmt = 'V';            // little-endian
    } elseif ($magic === 0xde120495) {
        $fmt = 'N';            // big-endian
    } else {
        return null;
    }

    $header = unpack("{$fmt}revision/{$fmt}count/{$fmt}origOffset/{$fmt}transOffset", substr($data, 4, 16));
    $count = $header['count'];
    $len = strlen($data);
    if ($header['origOffset'] + $count * 8 > $len || $header['transOffset'] + $count * 8 > $len) return null;

    $strings = [];
    $plurals = [];
    $pluralForms = '';

    for ($i = 0; $i < $count; $i++) {
        $o = unpack("{$fmt}length/{$fmt}offset", substr($data, $header['origOffset'] + $i * 8, 8));
        $t = unpack("{$fmt}length/{$fmt}offset", substr($data, $header['transOffset'] + $i * 8, 8));
        if ($o['offset'] + $o['length'] > $len || $t['offset'] + $t['length'] > $len) return null;

        $msgid  = (string) substr($data, $o['offset'], $o['length']);
        $msgstr = (string) substr($data, $t['offset'], $t['length']);

        if ($msgid === '') {
            // Header entry: pick out Plural-Forms.
            if (preg_match('/^Plural-Forms:\s*(.+)$/mi', $msgstr, $m)) {
                $pluralForms = trim($m[1]);
            }
            continue;
        }
        if (strpos($msgid, "\x04") !== false) continue; // has a context

        if (strpos($msgid, "\0") !== false) {
            // Plural entry: "singular\0plural" => "form0\0form1\0…"
            $singular = explode("\0", $msgid)[0];
            $forms = explode("\0", $msgstr);
            if (implode('', $forms) !== '') $plurals[$singular] = $forms;
        } elseif ($msgstr !== '') {
            $strings[$msgid] = $msgstr;
        }
    }

    return ['strings' => $strings, 'plurals' => $plurals, 'pluralForms' => $pluralForms];
}

/**
 * Turns a Plural-Forms header ("nplurals=2; plural=(n > 1);") into a table
 * of which plural form to use for n = 0…199, so the app never has to
 * evaluate the expression itself. For n >= 200 the app uses entry
 * 100 + (n % 100): every standard gettext rule depends only on n % 10,
 * n % 100, or comparisons with numbers below 100, so the pattern from
 * 100 to 199 repeats from then on.
 *
 * Returns ['nplurals' => int, 'table' => int[200]]. If the header is
 * missing or can't be parsed, falls back to English (n == 1 singular).
 */
function gibbonConnectPluralTable(string $pluralForms): array
{
    $english = ['nplurals' => 2, 'table' => array_map(fn ($n) => $n == 1 ? 0 : 1, range(0, 199))];

    if (!preg_match('/nplurals\s*=\s*(\d+)/', $pluralForms, $np)) return $english;
    if (!preg_match('/plural\s*=\s*([^;]+)/', $pluralForms, $ex)) return $english;
    $nplurals = (int) $np[1];
    if ($nplurals < 1 || $nplurals > 6) return $english;

    $tokens = gibbonConnectPluralTokens($ex[1]);
    if ($tokens === null) return $english;

    $table = [];
    for ($n = 0; $n < 200; $n++) {
        $pos = 0;
        $value = gibbonConnectPluralParse($tokens, $pos, $n);
        if ($value === null || $pos !== count($tokens)) return $english;
        $value = (int) $value;
        if ($value < 0 || $value >= $nplurals) $value = 0;
        $table[] = $value;
    }
    return ['nplurals' => $nplurals, 'table' => $table];
}

/** Splits a plural expression into tokens. Only n, numbers and C operators are allowed. */
function gibbonConnectPluralTokens(string $expr): ?array
{
    preg_match_all('/\s*(\d+|n|\|\||&&|==|!=|<=|>=|[<>?:%()!+\-*\/])/A', $expr, $m);
    $rebuilt = preg_replace('/\s+/', '', implode('', $m[1]));
    if ($rebuilt !== preg_replace('/\s+/', '', $expr)) return null; // unexpected characters
    return $m[1];
}

/*
 * Recursive-descent evaluator for gettext plural expressions, following C
 * operator precedence: ?: , ||, &&, == !=, < > <= >=, + -, * / %, unary !.
 * Each level returns the value (int) or null on a syntax error.
 */
function gibbonConnectPluralParse(array $t, int &$p, int $n): ?int
{
    $cond = gibbonConnectPluralBinary($t, $p, $n, 0);
    if ($cond === null) return null;
    if (($t[$p] ?? null) !== '?') return $cond;
    $p++;
    $a = gibbonConnectPluralParse($t, $p, $n);
    if ($a === null || ($t[$p] ?? null) !== ':') return null;
    $p++;
    $b = gibbonConnectPluralParse($t, $p, $n);
    if ($b === null) return null;
    return $cond ? $a : $b;
}

function gibbonConnectPluralBinary(array $t, int &$p, int $n, int $level): ?int
{
    $levels = [['||'], ['&&'], ['==', '!='], ['<', '>', '<=', '>='], ['+', '-'], ['*', '/', '%']];
    if ($level >= count($levels)) return gibbonConnectPluralUnary($t, $p, $n);

    $left = gibbonConnectPluralBinary($t, $p, $n, $level + 1);
    if ($left === null) return null;
    while (isset($t[$p]) && in_array($t[$p], $levels[$level], true)) {
        $op = $t[$p++];
        $right = gibbonConnectPluralBinary($t, $p, $n, $level + 1);
        if ($right === null) return null;
        switch ($op) {
            case '||': $left = ($left || $right) ? 1 : 0; break;
            case '&&': $left = ($left && $right) ? 1 : 0; break;
            case '==': $left = ($left == $right) ? 1 : 0; break;
            case '!=': $left = ($left != $right) ? 1 : 0; break;
            case '<':  $left = ($left < $right) ? 1 : 0; break;
            case '>':  $left = ($left > $right) ? 1 : 0; break;
            case '<=': $left = ($left <= $right) ? 1 : 0; break;
            case '>=': $left = ($left >= $right) ? 1 : 0; break;
            case '+':  $left = $left + $right; break;
            case '-':  $left = $left - $right; break;
            case '*':  $left = $left * $right; break;
            case '/':  if ($right == 0) return null; $left = intdiv($left, $right); break;
            case '%':  if ($right == 0) return null; $left = $left % $right; break;
        }
    }
    return $left;
}

function gibbonConnectPluralUnary(array $t, int &$p, int $n): ?int
{
    $tok = $t[$p] ?? null;
    if ($tok === '!') {
        $p++;
        $v = gibbonConnectPluralUnary($t, $p, $n);
        return $v === null ? null : ($v ? 0 : 1);
    }
    if ($tok === '(') {
        $p++;
        $v = gibbonConnectPluralParse($t, $p, $n);
        if ($v === null || ($t[$p] ?? null) !== ')') return null;
        $p++;
        return $v;
    }
    if ($tok === 'n') { $p++; return $n; }
    if ($tok !== null && ctype_digit($tok)) { $p++; return (int) $tok; }
    return null;
}
