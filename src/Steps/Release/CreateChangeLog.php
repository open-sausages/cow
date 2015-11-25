<?php

namespace SilverStripe\Cow\Steps\Release;

use Exception;
use SilverStripe\Cow\Commands\Command;
use SilverStripe\Cow\Model\ChangeLog;
use SilverStripe\Cow\Model\ReleaseVersion;
use SilverStripe\Cow\Steps\ChangeLogStep;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates a new changelog
 */
class CreateChangeLog extends ChangeLogStep
{
    /**
     * @var ReleaseVersion
     */
    protected $version;
    
    /**
     *
     * @var ReleaseVersion
     */
    protected $from;

    /**
     * Paths to check for changelog folder
     *
     * @var type
     */
    protected $paths = array(
        "framework/docs/en/04_Changelogs",
        "framework/docs/en/changelogs"
    );

    /**
     * Create a changelog command
     *
     * @param Command $command
     * @param ReleaseVersion $version
     * @param ReleaseVersion $from
     * @param string $directory
     * @param array $modules Optional list of modules to limit changelog source to
     * @param bool $listIsExclusive If this list is exclusive. If false, this is inclusive
     */
    public function __construct(
        Command $command, ReleaseVersion $version, ReleaseVersion $from, $directory = '.',
        $modules = array(), $listIsExclusive = false
    ) {
        parent::__construct($command, $directory, $modules, $listIsExclusive);
        $this->version = $version;
        $this->from = $from;
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        $this->log($output, "Generating changelog content");

        // Generate changelog content
        $changelog = new ChangeLog($this->getModules(), $this->from);
        $content = $changelog->getMarkdown($output);

        // Now we need to merge this content with the file, or otherwise create it
        $path = $this->getChangelogPath();
        $this->writeChangelog($output, $content, $path);

        // Now commit to git (but don't push!)
        $this->commitChanges($output, $path);

        $this->log($output, "Changelog successfully saved!");
    }

    /**
     * Get full path to this changelog
     *
     * @return string
     */
    protected function getChangelogPath()
    {
        $folder = $this->getChangelogFolder();

        // Suffix for release
        $suffix = $this->version->getStability();
        if ($suffix) {
            $folder .= DIRECTORY_SEPARATOR . $suffix;
        }

        return $folder . DIRECTORY_SEPARATOR . $this->version->getValue() . ".md";
    }

    /**
     * Find best changelog folder for this repo
     *
     * @return string
     */
    protected function getChangelogFolder()
    {
        $root = $this->getProject()->getDirectory();

        foreach ($this->paths as $path) {
            $directory = realpath($root . DIRECTORY_SEPARATOR . $path);
            if (is_dir($directory)) {
                return $directory;
            }
        }

        throw new Exception("Could not find changelog folder in project {$root}");
    }

    protected function getChangelogTitle(OutputInterface $output) {
        return $this->version->getValue();
    }

    /**
     * Commit changes to git
     *
     * @param OutputInterface $output
     * @param type $path
     */
    public function commitChanges(OutputInterface $output, $path)
    {
        $this->log($output, 'Committing changes to git');

        // Get framework to commit to
        $framework = $this->getProject()->getModule('framework');
        if (!$framework) {
            throw new Exception("Could not find module framework in project " . $this->getProject()->getDirectory());
        }
        $repo = $framework->getRepository();

        // Write changes to git
        $repo->run("add", array($path));
        $version = $this->version->getValue();
        $repo->run("commit", array("-m", "Added {$version} changelog"));
    }
}
