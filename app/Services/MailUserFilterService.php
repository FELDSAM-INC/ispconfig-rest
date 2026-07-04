<?php

namespace App\Services;

use App\Models\MailUser;
use App\Models\MailUserFilter;

/**
 * custom_mailfilter regeneration for mail user filter rules — a faithful
 * port of source_code/interface/lib/plugins/mail_user_filter_plugin.inc.php.
 *
 * Every filter insert/update/delete rewrites the block between
 * `### BEGIN FILTER_ID:<id>` and `### END FILTER_ID:<id>` in the owning
 * mailbox's mail_user.custom_mailfilter (new rules are PREPENDED, inactive
 * rules render nothing, deleted rules lose their block) and saves the
 * MailUser through BaseModel — emitting the companion mail_user datalog
 * update legacy produces. Rule syntax (sieve vs maildrop) follows the
 * server mail config mail_filter_syntax, exactly like legacy
 * (anything but 'sieve' renders the maildrop branch).
 */
class MailUserFilterService
{
    public function __construct(protected MailUserService $mailUsers) {}

    /**
     * Rewrite the filter's block after an insert/update
     * (plugin mail_user_filter_edit).
     */
    public function applyFilter(MailUser $user, MailUserFilter $filter): void
    {
        $filterId = (int) $filter->getKey();
        $record = $filter->getAttributes();
        $active = ($record['active'] ?? 'n') === 'y';

        $lines = explode("\n", (string) ($user->getAttributes()['custom_mailfilter'] ?? ''));
        $out = '';
        $skip = false;
        $found = false;

        foreach ($lines as $line) {
            $line = rtrim($line);

            if ($line === '### BEGIN FILTER_ID:'.$filterId) {
                $skip = true;
                $found = true;
            }

            if ($skip === false && $line !== '') {
                $out .= $line."\n";
            }

            if ($line === '### END FILTER_ID:'.$filterId) {
                if ($active) {
                    $out .= $this->renderRule($user, $filter);
                }
                $skip = false;
            }
        }

        // Rule not present yet: prepend it (legacy "add it now as first rule").
        if ($found === false && $active) {
            $out = $this->renderRule($user, $filter).$out;
        }

        $this->saveCustomMailfilter($user, $out);
    }

    /**
     * Remove the filter's block after a delete (plugin mail_user_filter_del).
     */
    public function removeFilter(MailUser $user, int $filterId): void
    {
        $lines = explode("\n", (string) ($user->getAttributes()['custom_mailfilter'] ?? ''));
        $out = '';
        $skip = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '### BEGIN FILTER_ID:'.$filterId) {
                $skip = true;
            }

            if ($skip === false && $line !== '') {
                $out .= $line."\n";
            }

            if ($line === '### END FILTER_ID:'.$filterId) {
                $skip = false;
            }
        }

        $this->saveCustomMailfilter($user, $out);
    }

    /**
     * Persist the regenerated ruleset — datalog 'u' on mail_user, exactly
     * like legacy's datalogUpdate('mail_user', ..., custom_mailfilter).
     */
    protected function saveCustomMailfilter(MailUser $user, string $content): void
    {
        $user->setAttribute('custom_mailfilter', $content);
        $user->save();
    }

    /**
     * Render one rule block (plugin mail_user_filter_get_rule).
     */
    protected function renderRule(MailUser $user, MailUserFilter $filter): string
    {
        $config = $this->mailUsers->mailConfig((int) $user->getAttributes()['server_id']);

        $record = $filter->getAttributes();
        $filterId = (int) $filter->getKey();

        if (($config['mail_filter_syntax'] ?? '') === 'sieve') {
            return $this->renderSieve($filterId, $record);
        }

        return $this->renderMaildrop($filterId, $record);
    }

    /**
     * Sieve branch, ported verbatim (incl. the special-char double-backslash
     * escaping and the Header-source searchterm split).
     *
     * @param  array<string, mixed>  $record
     */
    protected function renderSieve(int $filterId, array $record): string
    {
        $source = (string) $record['source'];
        $searchterm = (string) $record['searchterm'];
        $op = (string) $record['op'];
        $action = (string) $record['action'];
        $target = (string) ($record['target'] ?? '');

        $content = '### BEGIN FILTER_ID:'.$filterId."\n";

        if ($source === 'Header') {
            $parts = explode(':', trim($searchterm));
            $source = trim((string) array_shift($parts));
            $searchterm = trim(implode(':', $parts));
        }

        if ($op === 'domain') {
            $content .= 'if address :domain :is "'.strtolower($source).'" "'.$searchterm.'" {'."\n";
        } elseif ($op === 'localpart') {
            $content .= 'if address :localpart :is "'.strtolower($source).'" "'.$searchterm.'" {'."\n";
        } elseif ($source === 'Size') {
            $unit = in_array(substr(trim($searchterm), -1), ['k', 'K'], true) ? 'k' : 'm';
            $content .= 'if size :over '.intval($searchterm).$unit.' {'."\n";
        } else {
            $content .= 'if header :regex    "'.strtolower($source).'" ["';

            if ($op === 'regex') {
                // A provided regex must already be quoted as intended; only
                // an obviously unquoted double-quote is handled.
                $patterns = ['/([^\\\\]{2})"/', '/([^\\\\])\\\\"/'];
                $replace = ['${1}\\\\\\\\"', '${1}\\\\\\\\"'];
                $escaped = preg_replace($patterns, $replace, $searchterm);
            } else {
                $sieveRegexEscape = [
                    '\\' => '\\\\\\',
                    '+' => '\\\\+',
                    '*' => '\\\\*',
                    '?' => '\\\\?',
                    '[' => '\\\\[',
                    '^' => '\\\\^',
                    ']' => '\\\\]',
                    '$' => '\\\\$',
                    '(' => '\\\\(',
                    ')' => '\\\\)',
                    '{' => '\\\\{',
                    '}' => '\\\\}',
                    '|' => '\\\\|',
                    '.' => '\\\\.',
                ];
                $escaped = strtr($searchterm, $sieveRegexEscape);
            }

            $content .= match ($op) {
                'contains' => '.*'.$escaped,
                'is' => '^'.$escaped.'$',
                'regex' => $escaped,
                'begins' => '^'.$escaped,
                'ends' => '.*'.$escaped.'$',
                default => $escaped,
            };

            $content .= '"] {'."\n";
        }

        if ($action === 'move') {
            $content .= '    fileinto "'.$target.'";'."\n    stop;\n";
        } elseif ($action === 'keep') {
            $content .= "    keep;\n";
        } elseif ($action === 'stop') {
            $content .= "    stop;\n";
        } elseif ($action === 'reject') {
            $content .= '    reject "'.$target.'";'."\n    stop;\n";
        } else {
            $content .= "    discard;\n    stop;\n";
        }

        $content .= "}\n";
        $content .= '### END FILTER_ID:'.$filterId."\n";

        return $content;
    }

    /**
     * Maildrop branch, ported verbatim.
     *
     * @param  array<string, mixed>  $record
     */
    protected function renderMaildrop(int $filterId, array $record): string
    {
        $source = (string) $record['source'];
        $searchterm = preg_quote((string) $record['searchterm']);
        $op = (string) $record['op'];
        $action = (string) $record['action'];
        $target = (string) ($record['target'] ?? '');

        $content = '### BEGIN FILTER_ID:'.$filterId."\n";

        $testChDirQuotes = '"$DEFAULT/.'.$target.'"';
        $mailDirMakeNoQuotes = '"'.$target.'" $DEFAULT';

        if ($action === 'move') {
            $content .= '
`test -e '.$testChDirQuotes." && exit 1 || exit 0`
if ( \$RETURNCODE != 1 )
{
	`maildirmake -f $mailDirMakeNoQuotes`
	`chmod -R 0700 ".$testChDirQuotes."`
	`echo \"INBOX.$target\" >> \$DEFAULT/courierimapsubscribed`
}
";
        }

        $content .= 'if (/^'.$source.': ';

        $content .= match ($op) {
            'contains' => '.*'.$searchterm."/:h)\n",
            'is' => $searchterm."$/:h)\n",
            'begins' => $searchterm."/:h)\n",
            'ends' => '.*'.$searchterm."$/:h)\n",
            default => $searchterm."/:h)\n",
        };

        $content .= "{\n";
        $content .= "exception {\n";

        if ($action === 'move') {
            $content .= 'ID'.$filterId.'EndFolder = "$DEFAULT/.'.$target.'/"'."\n";
            $content .= 'xfilter "/usr/bin/formail -A \\"X-User-Mail-Filter-ID'.$filterId.': Yes\\""'."\n";
            $content .= 'to $ID'.$filterId.'EndFolder'."\n";
        } else {
            $content .= "to /dev/null\n";
        }

        $content .= "}\n";
        $content .= "}\n";

        $content .= '### END FILTER_ID:'.$filterId."\n";

        return $content;
    }
}
