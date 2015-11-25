<?php

namespace SilverStripe\Cow\Model;

use DateTime;
use Gitonomy\Git\Commit;

/**
 * Represents a line-item in a changelog
 */
class ChangeLogItem
{
    /**
     * @var Module
     */
    protected $module;
    
    /**
     * @var Commit
     */
    protected $commit;
    
    /**
     * Rules for ignoring commits
     *
     * @var array
     */
    protected $ignoreRules = array(
        '/^Merge/',
        '/^Blocked revisions/',
        '/^Initialized merge tracking /',
        '/^Created (branches|tags)/',
        '/^NOTFORMERGE/',
        '/^\s*$/'
    );

    /**
     * Url for CVE release notes
     *
     * @var string
     */
    protected $cveURL = "http://www.silverstripe.org/download/security-releases/";

    /**
     * Order of the array keys determines order of the lists.
     *
     * @var array
     */
    protected static $types = array(
        'Security' => array(
            // E.g. "[ss-2015-016]: Security fix"
            '/^(\[SS-2(\d){3}-(\d){3}\])\s?:?/i'
        ),
        'API Changes' => array(
            '/^(APICHANGE|API-CHANGE|API CHANGE|API)\s?:?/i'
        ),
        'Features and Enhancements' => array(
            '/^(ENHANCEMENT|ENHNACEMENT|FEATURE|NEW)\s?:?/i'
        ),
        'Bugfixes' => array(
            '/^(BUGFIX|BUGFUX|BUG|FIX)\s?:?/',
            '/^(BUG FIX)\s?:?/'
        )
    );

    /**
     * Get list of categorisations of commit types
     *
     * @return array
     */
    public static function get_types()
    {
        return array_keys(self::$types);
    }

    /**
     * Create a changelog item
     *
     * @param Module $module
     * @param Commit $commit
     */
    public function __construct(Module $module, Commit $commit)
    {
        $this->module = $module;
        $this->commit = $commit;
    }
    
    /**
     * Get the raw commit
     *
     * @return Commit
     */
    public function getCommit()
    {
        return $this->commit;
    }
    
    /**
     * Should this commit be ignored?
     * 
     * @return boolean
     */
    public function isIgnored()
    {
        $message = $this->getRawMessage();
        foreach ($this->ignoreRules as $ignoreRule) {
            if (preg_match($ignoreRule, $message)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the commit date
     *
     * @return DateTime
     */
    public function getDate()
    {
        return $this->getCommit()->getAuthorDate();
    }

    /**
     * Get author name
     *
     * @return string
     */
    public function getAuthor()
    {
        return $this->getCommit()->getAuthorName();
    }

    /**
     * Get unsanitised commit message
     *
     * @return string
     */
    public function getRawMessage()
    {
        return $this->getCommit()->getSubjectMessage();
    }

    /**
     * Get sanitised message (omit emails, tags, etc)
     *
     * @return string markdown safe string
     */
    public function getMessage()
    {
        $message = $this->getRawMessage();

        // Strip emails
        $message = preg_replace('/(<?[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}>?)/mi', '', $message);

        // Condense git-style "From:" messages (remove preceding newline)
        if (preg_match('/^From\:/mi', $message)) {
            $message = preg_replace('/\n\n^(From\:)/mi', ' $1', $message);
        }

        // Strip categorisation tags (API, BUG FIX, etc)
        foreach (self::$types as $rules) {
            foreach ($rules as $rule) {
                $message = trim(preg_replace($rule, '', $message));
            }
        }

        // Encode HTML tags
        $message = str_replace(array('<', '>'), array('&lt;', '&gt;'), $message);

        return $message;
    }

    /**
     * Get category for this type
     *
     * @return string|null Return the category of this commit, or null if uncategorised
     */
    public function getType()
    {
        $message = $this->getRawMessage();
        foreach (self::$types as $type => $rules) {
            foreach ($rules as $rule) {
                if (preg_match($rule, $message)) {
                    return $type;
                }
            }
        }
        
        // Fallback check for CVE (not at start of string)
        if ($this->getSecurityCVE()) {
            return 'Security';
        }
        
        return null;
    }

    /**
     * Get the URl where this link should be on open source
     *
     * @return string
     */
    public function getLink()
    {
        $base = $this->module->getLink();
        $sha = $this->getCommit()->getHash();
        return "{$base}commit/{$sha}";
    }

    /**
     * Get short hash for this commit
     *
     * @return string
     */
    public function getShortHash()
    {
        return $this->getCommit()->getShortHash();
    }

    /**
     * If this is a security fix, get the CVP (in 'ss-2015-016' fomat)
     *
     * @return string|null cvp, or null if not
     */
    public function getSecurityCVE()
    {
        if (preg_match('/^\[(?<cve>SS-2(\d){3}-(\d){3})\]/i', $this->getRawMessage(), $matches)) {
            return strtolower($matches['cve']);
        }
    }

    /**
     * Get markdown content for this line item, including end of line
     *
     * @return string
     */
    public function getMarkdown()
    {
        $link = $this->getLink();
        $shortHash = $this->getShortHash();
        $date = $this->getDate()->format('Y-m-d');
        $message = $this->getMessage();
        $author = $this->getAuthor();
		$content = " * {$date} [{$shortHash}]({$link}) {$message} ({$author})";

        // Append security identifier
        if ($cve = $this->getSecurityCVE()) {
            $content .= " - See [{$cve}]({$this->cveURL}{$cve})";
        }

        return $content . "\n";
    }
}
