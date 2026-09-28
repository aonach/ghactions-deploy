<?php

namespace Deployer;

use Deployer\Exception\Exception;
use Symfony\Component\Console\Output\OutputInterface;

// List of dirs what will be shared between releases.
// Each release will have symlink to those dirs stored in {{deploy_path}}/shared dir.
// ```php
// set('shared_dirs', ['storage']);
// ```
set('shared_dirs', []);

// List of files what will be shared between releases.
// Each release will have symlink to those files stored in {{deploy_path}}/shared dir.
// ```php
// set('shared_files', ['.env']);
// ```
set('shared_files', []);

desc('Creates symlinks for shared files and dirs');
task('deploy:shared', function () {
    $sharedPath = "{{deploy_path}}/shared";

    // Validate shared_dir, find duplicates
    foreach (get('shared_dirs') as $a) {
        foreach (get('shared_dirs') as $b) {
            if ($a !== $b && strpos(rtrim($a, '/') . '/', rtrim($b, '/') . '/') === 0) {
                throw new Exception("Can not share same dirs `$a` and `$b`.");
            }
        }
    }

    $copyVerbosity = output()->getVerbosity() === OutputInterface::VERBOSITY_DEBUG ? 'v' : '';

    $sharedDirs = get('shared_dirs');
    if (!empty($sharedDirs)) {
        // Process directories in batches
        $batchSize = 100;
        $batches = array_chunk($sharedDirs, $batchSize);

        foreach ($batches as $batch) {
            // Commands for checking and creating shared directories
            $mkdirCommands = [];
            $copyCommands = [];
            $rmCommands = [];
            $mkdirPathCommands = [];
            $symlinkCommands = [];

            foreach ($batch as $dir) {
                // Make sure all path without tailing slash.
                $dir = trim($dir, '/');

                // Check if shared dir does not exist and create it if needed
                $mkdirCommands[] = "if [ ! -d $sharedPath/$dir ]; then mkdir -p $sharedPath/$dir; fi";

                // If release contains shared dir, copy that dir from release to shared
                $copyCommands[] = "if [ ! -d $sharedPath/$dir ] && [ -d {{release_path}}/$dir ]; then cp -r$copyVerbosity {{release_path}}/$dir $sharedPath/" . dirname($dir) . "; fi";

                // Remove from source
                $rmCommands[] = "rm -rf {{release_path}}/$dir";

                // Create path to shared dir in release dir
                $mkdirPathCommands[] = "mkdir -p `dirname {{release_path}}/$dir`";

                // Symlink shared dir to release dir
                $symlinkCommands[] = "{{bin/symlink}} $sharedPath/$dir {{release_path}}/$dir";
            }

            runSteps([$mkdirCommands, $copyCommands, $rmCommands, $mkdirPathCommands, $symlinkCommands]);
        }
    }

    $sharedFiles = get('shared_files');
    if (!empty($sharedFiles)) {
        // Process files in batches
        $batchSize = 100;
        $batches = array_chunk($sharedFiles, $batchSize);

        foreach ($batches as $batch) {
            // Commands for processing shared files
            $mkdirCommands = [];
            $copyCommands = [];
            $rmCommands = [];
            $mkdirReleaseCommands = [];
            $touchCommands = [];
            $symlinkCommands = [];

            foreach ($batch as $file) {
                $dirname = dirname(parse($file));

                // Create dir of shared file if not existing
                $mkdirCommands[] = "if [ ! -d $sharedPath/$dirname ]; then mkdir -p $sharedPath/$dirname; fi";

                // Check if shared file does not exist in shared and file exists in release
                $copyCommands[] = "if [ ! -f $sharedPath/$file ] && [ -f {{release_path}}/$file ]; then cp -r$copyVerbosity {{release_path}}/$file $sharedPath/$file; fi";

                // Remove from source
                $rmCommands[] = "if [ -f $(echo {{release_path}}/$file) ]; then rm -rf {{release_path}}/$file; fi";

                // Ensure dir is available in release
                $mkdirReleaseCommands[] = "if [ ! -d $(echo {{release_path}}/$dirname) ]; then mkdir -p {{release_path}}/$dirname; fi";

                // Touch shared
                $touchCommands[] = "[ -f $sharedPath/$file ] || touch $sharedPath/$file";

                // Symlink shared file to release file
                $symlinkCommands[] = "{{bin/symlink}} $sharedPath/$file {{release_path}}/$file";
            }

            runSteps([$mkdirCommands, $copyCommands, $rmCommands, $mkdirReleaseCommands, $touchCommands, $symlinkCommands]);
        }
    }
});

/**
 * Runs a sequence of steps in one remote call rather than one call per step: each run() is a full
 * SSH round trip, 0.3-1.3s from GitHub's runners to our servers. A step is a list of commands joined
 * with `;`, so a step's status is its last command's -- the same as when each step was its own
 * run() -- and a failing step stops the ones after it, as the separate run()s did.
 */
function runSteps(array $steps): void
{
    $steps = array_filter($steps);
    if ($steps) {
        run(implode(' && ', array_map(fn($commands) => '{ ' . implode('; ', $commands) . '; }', $steps)));
    }
}
