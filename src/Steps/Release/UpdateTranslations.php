<?php

namespace SilverStripe\Cow\Steps\Release;

use InvalidArgumentException;
use SilverStripe\Cow\Commands\Command;
use SilverStripe\Cow\Model\Module;
use SilverStripe\Cow\Steps\ModuleStep;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Synchronise all translations with transifex, merging these with strings detected in code files
 *
 * Basic process follows:
 *  - Set mtime on all local files to long ago (1 year in the past?) because tx pull breaks on new files and won't update them
 *  - Pull all source files from transifex with the below:
 *      `tx pull -a -s -f --minimum-perc=10`
 *  - Detect all new translations, making sure to merge in changes
 *      `./framework/sake dev/tasks/i18nTextCollectorTask "flush=all" "merge=1"
 *  - Detect all new JS translations in a similar way (todo)
 *  - Generate javascript from js source files
 *      `phing -Dmodule=my-module translation-generate-javascript-for-module`
 *  - Push up all source translations
 *      `tx push -s`
 *  - Commit changes to source control (without push)
 */
class UpdateTranslations extends ModuleStep
{
    /**
     * Min tx client version
     
     * @var string
     */
    protected $txVersion = '0.11';

    /**
     * Min % difference required for tx updates
     *
     * @var int
     */
    protected $txMinimumPerc = 10;

    /**
     * Flag whether we should do push on each git repo
     *
     * @var bool
     */
    protected $push;

    /**
     * Create a new translation step
     *
     * @param Command $command
     * @param string $directory Where to translate
     * @param array $modules Optional list of modules to limit translation to
     * @param bool $listIsExclusive If this list is exclusive. If false, this is inclusive
     * @param bool $doPush Do git push at end
     */
    public function __construct(Command $command, $directory, $modules = array(), $listIsExclusive = false, $doPush = false)
    {
        parent::__construct($command, $directory, $modules, $listIsExclusive);
        $this->push = $doPush;
    }

    public function getStepName()
    {
        return 'translations';
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        $modules = $this->getModules();
        $this->log($output, sprintf("Updating translations for %d module(s)", count($modules)));
        $this->checkVersion($output);
        $this->pullSource($output, $modules);
        $this->collectStrings($output, $modules);
        $this->generateJavascript($output, $modules);
        $this->pushSource($output, $modules);
        $this->commitChanges($output, $modules);
        $this->log($output, 'Translations complete');
    }

    /**
     * Test that tx tool is installed
     * 
     * @param OutputInterface $output
     * @throws InvalidArgumentException
     */
    protected function checkVersion(OutputInterface $output)
    {
        $error = "translate requires transifex {$this->txVersion} at least. Run 'pip install transifex-client==0.11b3' to update.";
        $result = $this->runCommand($output, array("tx", "--version"), $error);
        if (!version_compare($result, $this->txVersion, '<')) {
            throw new InvalidArgumentException($error ." Current version: ".$result);
        }

        $this->log($output, "Using transifex CLI version: $result");
    }

    /**
     * Update sources from transifex
     * 
     * @param OutputInterface $output
     * @param Module[] $modules List of modules
     */
    protected function pullSource(OutputInterface $output, $modules)
    {
        $this->log($output, "Pulling sources from transifex (min %{$this->txMinimumPerc} delta)");

        foreach ($modules as $module) {
            // Set mtime to a year ago so that transifex will see these as obsolete
            $touchCommand = sprintf(
                'find %s -type f \( -name "*.yml" \) -exec touch -t %s {} \;',
                $module->getLangDirectory(),
                date('YmdHi.s', strtotime('-1 year'))
            );
            $this->runCommand($output, $touchCommand);

            // Run tx pull
            $pullCommand = sprintf(
                '(cd %s && tx pull -a -s -f --minimum-perc=%d)',
                $module->getDirectory(),
                $this->txMinimumPerc
            );
            $this->runCommand($output, $pullCommand);
        }
    }

    /**
     * Run text collector on the given modules
     *
     * @param OutputInterface $output
     * @param Module[] $modules List of modules
     */
    protected function collectStrings(OutputInterface $output, $modules)
    {
        $this->log($output, "Running i18nTextCollectorTask");

        // Get code dirs for each module
        $dirs = array();
        foreach ($modules as $module) {
            $dirs[] = basename($module->getMainDirectory());
        }

        $sakeCommand = sprintf(
            '(cd %s && ./framework/sake dev/tasks/i18nTextCollectorTask "flush=all" "merge=1" "module=%s")',
            $this->getProject()->getDirectory(),
            implode(',', $dirs)
        );
        $this->runCommand($output, $sakeCommand, "Error encountered running i18nTextCollectorTask");
    }

    /**
     * Generate javascript for all modules
     *
     * @param OutputInterface $output
     * @param Module[] $modules
     */
    protected function generateJavascript(OutputInterface $output, $modules)
    {
        $this->log($output, "Generating javascript locale files");
        // Check which paths in each module require processing
        $count = 0;
        foreach ($modules as $module) {
            $base = $module->getMainDirectory();
            $jsPath = $module->getJSLangDirectory();
            foreach ((array)$jsPath as $path) {
                $count += $this->generateJavascriptInDirectory($output, $base, $path);
            }
        }
        $this->log($output, "Finished generating {$count} files");
    }


    /**
     * Process all javascript in a given path
     * 
     * @param OutputInterface $output
     * @param string $base Base directory of the module
     * @param string $path Path to the location of JS files
     * @return int Number of files generated
     */
    protected function generateJavascriptInDirectory(OutputInterface $output, $base, $path)
    {
        // Iterate through each source file
        $count = 0;
        $template = <<<TMPL
// This file was generated by silverstripe/cow from %FILE%.
// See https://github.com/tractorcow/cow for details
if(typeof(ss) == 'undefined' || typeof(ss.i18n) == 'undefined') {
	if(typeof(console) != 'undefined') console.error('Class ss.i18n not defined');
} else {
	ss.i18n.addDictionary('%LOCALE%', %TRANSLATIONS%);
}
TMPL;
        // Update each source file
        foreach (glob("{$path}/src/*.js") as $sourceFile) {
            $count++;
            // Get contents and location
            $sourceContents = file_get_contents($sourceFile);
            $locale = preg_replace('/\.js$/', '', basename($sourceFile));
            $targetFile = dirname(dirname($sourceFile)) . '/' . $locale . '.js';

            if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $output->getVerbosity()) {
                $this->log($output, "Generating file {$targetFile}", "info");
            }

            file_put_contents(
                $targetFile,
                str_replace(
                    array(
                        '%TRANSLATIONS%',
                        '%FILE%',
                        '%LOCALE%'
                    ),
                    array(
                        $sourceContents,
                        substr($sourceFile, strlen($base) + 1), // Trim off base dir
                        $locale
                    ),
                    $template
                )
            );
        }
        return $count;
    }
    
    /**
     * Push source updates to transifex
     *
     * @param OutputInterface $output
     * @param Module[] $modules
     */
    public function pushSource(OutputInterface $output, $modules)
    {
        $this->log($output, "Pushing updated sources to transifex");

        foreach ($modules as $module) {
            // Run tx pull
            $pushCommand = sprintf(
                '(cd %s && tx push -s)',
                $module->getDirectory()
            );
            $moduleName = $module->getName();
            $this->runCommand($output, $pushCommand, "Error pushing module {$moduleName} to origin");
        }
    }
    
    /**
     * Commit changes for all modules
     *
     * @param OutputInterface $output
     * @param Module[] $modules
     */
    public function commitChanges(OutputInterface $output, $modules)
    {
        $this->log($output, 'Committing translations to git');
        
        foreach ($modules as $module) {
            $repo = $module->getRepository();

            // Add all changes
            $jsPath = $module->getJSLangDirectory();
            $langPath = $module->getLangDirectory();
            foreach (array_merge((array)$jsPath, (array)$langPath) as $path) {
                if (is_dir($path)) {
                    $repo->run("add", array($path . "/*"));
                }
            }

            // Commit changes if any exist
            $status = $repo->run("status");
            if (stripos($status, 'Changes to be committed:')) {
                $this->log($output, "Comitting changes for module " . $module->getName());
                $repo->run("commit", array("-m", "Update translations"));
            }

            // Do push if selected
            if ($this->push) {
                $this->log($output, "Pushing upstream for module " . $module->getName());
                $repo->run("push", array("origin"));
            }
        }
    }

    /**
     * Get the list of module objects to translate
     *
     * @return Module[]
     */
    protected function getModules()
    {
        $modules = parent::getModules();
        
        // Get only modules with translations
        return array_filter($modules, function (Module $module) {
            // Automatically skip un-translateable modules
            return $module->isTranslatable();
        });
    }
}
