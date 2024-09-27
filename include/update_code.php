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
        run('mkdir {{keep_path}}');
        run('
        shopt -s dotglob
        mv {{release_path}}/* {{keep_path}}
        mv {{keep_path}}/{{repo_path}}/* {{release_path}}');
        run('rm -rf {{keep_path}}');
    }
})->hidden();

before('deploy:update_code', 'set:repository');
after('deploy:update_code', 'set:repo_path');
