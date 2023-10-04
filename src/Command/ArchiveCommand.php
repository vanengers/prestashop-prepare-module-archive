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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;
use ZipArchive;

class ArchiveCommand extends Command
{
    const DEFAULT_FILTERS = [];

    /** @var array $filters */
    private array $filters;

    /** @var string $path */
    private string $path;

    /** @var OutputInterface output */
    private OutputInterface $output;

    /** @var string toCopyPathFolder */
    private string $toCopyPathFolder = 'temp_copy';

    /** @var string $toCopySubDir */
    private string $toCopySubDir = 'temp_copy';

    /** @var string $zipName */
    private string $zipName = 'archive.zip';

    /** @var Filesystem fs */
    private Filesystem $fs;
    /** @var mixed|string moduleName */

    /** @var string $moduleName */
    private string $moduleName;

    /**
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    protected function configure(): void
    {
        $this
            ->setName('prestashop:archive')
            ->setDescription('Run commands to prepare your module archive. Archive the zip for production')
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of folders to exclude from the update',
                implode(',', self::DEFAULT_FILTERS)
            )
            ->addOption(
                'module',
                null,
                InputOption::VALUE_REQUIRED,
                'Module name',
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
        if (empty($input->getOption('path'))) {
            $this->path = realpath('.');
        } else {
            $this->path = $input->getOption('path');
        }
        $this->toCopyPathFolder = $this->path . (str_ends_with($this->path, '/') ? '' : '/') . $this->toCopySubDir;

        if (!empty($input->getOption('module'))) {
            $this->moduleName = $input->getOption('module');
        } else {
            $this->moduleName = $this->zipName;
        }

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
        $this->removeZip();
        $this->deleteTempFolder($this->toCopyPathFolder);
        $this->copy();
        $this->autoIndex();
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
    private function zip(): void
    {
        ob_start();
        $zipper = new ZipArchive();
        $zipper->open($this->path . '\\'. $this->moduleName.(str_ends_with($this->moduleName, '.zip') ? '' : '.zip'),ZipArchive::CREATE);

        $files = $this->getAllFilesRecursive($this->toCopyPathFolder);
        if ($files->hasResults()) {
            foreach($files as $file) {
                $absoluteFilePath = $file->getRealPath();
                $fileNameWithExtension = $file->getRelativePathname();
                $zipper->addFile($absoluteFilePath, str_replace('\\','/',$fileNameWithExtension));
            }

            $zipper->close();
        }
    }

    /**
     * @param $dir
     * @return Finder
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function getAllFilesRecursive($dir): Finder
    {
        $finder = new Finder();
        return $finder->files()
            ->in($dir)
            ->exclude($this->filters)
            ->ignoreDotFiles(true)
            ->ignoreVCS(true)
            ->exclude('node_modules')
            ->exclude('.git')
            ->exclude('.idea')
            ->notName('README.md')
            ->notName('composer.lock');
    }

    /**
     * @return void
     * @throws Throwable
     * @since 03-10-2023
     * @author George van Engers <george@dewebsmid.nl>
     */
    private function autoIndex(): void
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
    private function copy(): void
    {
        $found = $this->findFiles($this->path);
        $this->fs->mkdir($this->toCopyPathFolder);
        $this->fs->mkdir($this->toCopyPathFolder . '\\' .$this->moduleName);
        foreach($found as $file) {
            $this->doCopy($file);
        }
    }

    /**
     * @param SplFileInfo $file
     * @param string $addRelativePath
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function doCopy(SplFileInfo $file, string $addRelativePath = ''): void
    {
        if ($file->isDir()) {
            $this->fs->mkdir($this->toCopyPathFolder . '\\' .$this->moduleName . '\\' . $addRelativePath . '\\' . $file->getRelativePathname());
        }
        else if ($this->isSymlink($file) && empty($addRelativePath)) {
            $nPath = $this->toCopyPathFolder . '\\' .$this->moduleName . '\\' . $addRelativePath . '\\' .$file->getRelativePath();
            $rel = $file->getRelativePathname();
            $this->fs->mkdir($nPath);
            $files = $this->findFiles($file);
            foreach($files as $file) {
                $this->doCopy($file, $rel);
            }
        }
        else {
            try {
                $this->fs->copy($file->getRealPath(), $this->toCopyPathFolder . '\\' .$this->moduleName . '\\' . $addRelativePath . '\\' . $file->getRelativePathname());
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
    private function isSymlink(SplFileInfo $file): bool
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
    private function deleteTempFolder(string $toCopyPathFolder): void
    {
        $this->fs->remove($toCopyPathFolder);
    }

    /**
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function composerUpdate(): void
    {
        exec('composer update --no-dev --working-dir='.$this->toCopyPathFolder. '\\' .$this->moduleName);
    }

    /**
     * @param $path
     * @return Finder
     * @author George van Engers <george@dewebsmid.nl>
     * @since 03-10-2023
     */
    private function findFiles($path): Finder
    {
        $finder = new Finder();;
        $found = $finder
            ->in($path instanceof SplFileInfo ? $path->getRealPath() : $path)
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

    /**
     * @return void
     * @author George van Engers <george@dewebsmid.nl>
     * @since 04-10-2023
     */
    private function removeZip(): void
    {
        if ($this->fs->exists($this->path . '/'. $this->zipName)) {
            $this->fs->remove($this->path . '/'. $this->zipName);
        }
    }
}