<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
declare(strict_types=1);

namespace Vanengers\PrestashopPrepareModuleArchive\Command;

use PHPZip\Zip\File\Zip;
use PHPZip\Zip\Stream\ZipStream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

class ArchiveCommand extends Command
{
    const DEFAULT_FILTERS = [];

    /**
     * List of folders to exclude from the search
     *
     * @var array<int, string>
     */
    private $filters;
    /** @var mixed path */
    private $path;
    /** @var OutputInterface output */
    private $output;

    /** @var string toCopyPathFolder */
    private $toCopyPathFolder = 'temp_copy';
    private $toCopySubDir = 'temp_copy';
    private $zipName = 'archive.zip';
    /** @var Filesystem fs */
    private $fs;

    protected function configure(): void
    {
        $this
            ->setName('prestashop:archive')
            ->setDescription('Run commands to prepare your module archive. Archive the zip for production')
            ->addArgument(
                'real_path',
                InputArgument::OPTIONAL,
                'The real path of your module'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of folders to exclude from the update',
                implode(',', self::DEFAULT_FILTERS)
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the module to update'
            );

    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->output = $output;
        $this->filters = explode(',', $input->getOption('exclude'));
        $this->path = $input->getOption('path');
        if (empty($this->path)) {
            $this->path = realpath('.');
        }
        $this->toCopyPathFolder = $this->path . (str_ends_with($this->path, '/') ? '' : '/') . $this->toCopySubDir;

        $this->fs = new Filesystem;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable
     * @since 03-10-2023
     * @author George van Engers <george@dewebsmid.nl>
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->fs->exists($this->path . $this->zipName)) {
            $this->fs->remove($this->path . $this->zipName);
        }

        $this->deleteTempFolder($this->toCopyPathFolder);
        $this->copy();
        $this->autoindex();
        $this->composerUpdate();
        $this->zip();
        $this->deleteTempFolder($this->toCopyPathFolder);

        return 0;
    }

    /**
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function zip()
    {
        ob_start();
        $zip = new Zip(true);

        $files = $this->getAllFilesRecursive($this->toCopyPathFolder);
        if ($files->hasResults()) {
            foreach($files as $file) {
                $absoluteFilePath = $file->getRealPath();
                $fileNameWithExtension = $file->getRelativePathname();
                $zip->addFile(file_get_contents($absoluteFilePath), $fileNameWithExtension);
            }

            $zip->saveZipFile($this->path . '\\'. $this->zipName);
            $zip->finalize();
        }
    }

    /**
     * @param $dir
     * @return Finder
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function getAllFilesRecursive($dir)
    {
        $finder = new Finder();
        $found = $finder->files()
            ->in($dir)
            ->exclude($this->filters)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->exclude('node_modules')
            ->exclude('.git')
            ->exclude('.idea')
            ->notName('README.md')
            ->notName('composer.lock')
        ;

        return $found;
    }

    /**
     * @return void
     * @throws Throwable
     * @since 03-10-2023
     * @author George van Engers <george@dewebsmid.nl>
     */
    private function autoindex()
    {
        $input = new ArrayInput([
            'command' => 'prestashop:add:index',
            'real_path' => $this->toCopyPathFolder,
        ]);
        $this->getApplication()->doRun($input, $this->output);
    }

    /**
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function copy()
    {
        $found = $this->findFiles($this->path);
        $this->fs->mkdir($this->toCopyPathFolder);
        foreach($found as $file) {
            $this->doCopy($file);
        }
    }

    /**
     * @param SplFileInfo $file
     * @param $addRelativePath
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function doCopy(SplFileInfo $file, $addRelativePath = '')
    {
        if ($file->isDir()) {
            $this->fs->mkdir($this->toCopyPathFolder . '\\' . $addRelativePath . '\\' . $file->getRelativePathname());
        }
        else if ($this->isSymlink($file) && empty($addRelativePath)) {
            $nPath = $this->toCopyPathFolder . '\\' . $addRelativePath . '\\' .$file->getRelativePath();
            $rel = $file->getRelativePathname();
            $this->fs->mkdir($nPath);
            $files = $this->findFiles($file);
            foreach($files as $file) {
                $this->doCopy($file, $rel);
            }
        }
        else {
            try {
                $this->fs->copy($file->getRealPath(), $this->toCopyPathFolder . '\\' . $addRelativePath . '\\' . $file->getRelativePathname());
            }
            catch (Throwable $e) {
                var_dump($e->getMessage());
                die;
            }
        }
    }

    /**
     * @param SplFileInfo $file
     * @return boolean
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function isSymlink(SplFileInfo $file)
    {
        $absPathOrg = $this->path . '\\' .$file->getRelativePathname();
        return !str_contains($absPathOrg, $file->getRealPath());
    }

    /**
     * @param string $toCopyPathFolder
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function deleteTempFolder(string $toCopyPathFolder)
    {
        $this->fs->remove($toCopyPathFolder);
    }

    /**
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function composerUpdate()
    {
        exec('composer update --no-dev --working-dir='.$this->toCopyPathFolder);
    }

    /**
     * @param $path
     * @return Finder
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function findFiles($path)
    {
        $finder = new Finder();
        $found = $finder->in($path instanceof SplFileInfo ? $path->getRealPath() : $path)
            ->exclude($this->filters)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->notPath('.zip')
            ->exclude($this->toCopySubDir);

        if ($path instanceof SplFileInfo) {
            // we probably are in symlinked composer package, so lets skip the vendor folder
            $found = $found->exclude('vendor');
        }

        return $found;
    }
}