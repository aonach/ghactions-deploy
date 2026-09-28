<?php
namespace Deployer;

use Symfony\Component\Console\Input\InputOption;

/**
 * Set repository name from input
 */
option('repository',
    null,
    InputOption::VALUE_REQUIRED,
    'Repository for pull'
);

set('git_ssh_command', 'ssh');

/**
 * Tasks
 */
desc('Setting repository from output');
task('set:repository' , function() {
    if(input()->hasOption('repository')) {
        set('repository', input()->getOption('repository'));
    }
})->hidden();

desc('Setting working path of repository');
task('set:repo_path', function() {
    if(get('repo_path')) {
        set('keep_path', '{{deploy_path}}/.dep/.keep');
        // One remote call. && so that a leftover .keep from an interrupted deploy still stops the
        // deploy at mkdir, as it did when mkdir was its own run().
        run('mkdir {{keep_path}} && shopt -s dotglob && ' .
            'mv {{release_path}}/* {{keep_path}} && ' .
            'mv {{keep_path}}/{{repo_path}}/* {{release_path}} && ' .
            'rm -rf {{keep_path}}');
    }
})->hidden();

before('deploy:update_code', 'set:repository');
after('deploy:update_code', 'set:repo_path');
