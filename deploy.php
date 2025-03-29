<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Deployer\Exception\GracefulShutdownException;
use Deployer\Exception\RunException;
use Deployer\Deployer;
use Deployer\Host\Host;
use Deployer\Task\Context;

require_once 'recipe/common.php';
require_once 'include/opcache.php';
require_once 'include/prepare_config.php';
require_once 'include/update_code.php';

const DB_UPDATE_NEEDED_EXIT_CODE = 2;
const CONFIG_PHP_UPDATE_NEEDED_EXIT_CODE = 1;

function log_time($task_name, $start_time)
{
    $end_time = microtime(true);
    $duration = $end_time - $start_time;
    writeln("Task $task_name took $duration seconds");
}

// These will be managed with set() and get() instead of global variables

/**
 * Config of hosts
 */
import('hosts.yml');
foreach (Deployer::get()->hosts as $host) {
    $host->setSshArguments(['-o StrictHostKeyChecking=no']);
}


/**
 * Configuration
 */
set('deploy_path', '~/deploy');
set('repo_path', 'src');
set('keep_releases', 3);
set('asset_locales', 'en_US en_IE');

set('is_hyva_project', 0);
set('hyva_path', 'app/design/frontend/Aonach/hyva');
set('bin/npm', function () {
    return which('npm');
});

set('symlinks', [
    'pub/pub' => '.'
]);
set('shared_files', [
    'app/etc/env.php',
    'pub/robots.txt',
    'pub/sitemap.xml',
    'pub/.htaccess'
]);
set('shared_dirs', [
    'pub/media',
    'pub/sitemaps',
    'var/backups',
    'var/composer_home',
    'var/export',
    'var/import',
    'var/import_history',
    'var/importexport',
    'var/log',
    'var/report',
    'var/session',
    'var/tmp'
]);

set('magento_dir', '.');

set('bin/magento', '{{release_or_current_path}}/{{magento_dir}}/bin/magento');

set('m2_version', function () {
    $m2version = run('{{bin/php}} {{release_path}}/bin/magento --version');
    preg_match('/((\d+\.?)+)/', $m2version, $regs);

    return $regs[0];
});

set('task_timings', []);

/**
 * Tasks
 */
desc('Magento2 apply patches');
task('magento:apply:patches', function () {
    $start_time = microtime(true);
    cd('{{release_path}}');
    run('
    for patch in patch/*.patch; do
        if [ -f $patch ]; then
            {{bin/git}} apply -v $patch || printf "##[%s]The patch $patch is not applicable" "error";
        fi;
    done');
    log_time('magento:apply:patches', $start_time);
});

desc('Magento2 dependency injection compile');
task('magento:di:compile', function () {
    $start_time = microtime(true);
    run('{{bin/php}} {{release_path}}/bin/magento setup:di:compile');
    log_time('magento:di:compile', $start_time);
});

desc('Hyva styles compile (if applicable)');
task('npm run build-prod', function () {
    $start_time = microtime(true);

    if ((bool)get('is_hyva_project')) {
        cd('{{release_path}}/{{hyva_path}}/web/tailwind');
        run('{{bin/npm}} install');
        run('{{bin/npm}} run build-prod');
    } else {
        writeln('Not applicable. This is not a Hyva project :(');
    }

    log_time('npm run build-prod', $start_time);
});

desc('Magento2 deploy assets');
task('magento:deploy:assets', function () {
    $start_time = microtime(true);
    // Magento 2.1 has different arguments for setup:static-content:deploy, so
    // we need to do the condition to take this
    $additionalOptions = version_compare(get('m2_version'), '2.2', '>=') ? '--force' : '--quiet';

    run('{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy ' .
        $additionalOptions . ' ' .
        get('asset_locales')
    );

    log_time('magento:deploy:assets', $start_time);
});

desc('Magento2 create symlinks');
task('magento:create:symlinks', function () {
    $start_time = microtime(true);
    cd('{{release_path}}');
    foreach (get('symlinks') as $key => $value) {
        run('ln -sf ' . $value . ' ' . $key);
    }
    log_time('magento:create:symlinks', $start_time);
});

set('database_upgrade_needed', function () {
    // detect if setup:upgrade is needed
    try {
        run('{{bin/php}} {{bin/magento}} setup:db:status');
    } catch (RunException $e) {
        if ($e->getExitCode() == DB_UPDATE_NEEDED_EXIT_CODE) {
            return true;
        }

        throw $e;
    }
    try {
        run('{{bin/php}} {{bin/magento}} module:config:status');
    } catch (RunException $e) {
        if ($e->getExitCode() == CONFIG_PHP_UPDATE_NEEDED_EXIT_CODE) {
            return true;
        }

        throw $e;
    }

    return false;
});

desc('Magento2 upgrade database');
task('magento:upgrade:db', function () {
    $start_time = microtime(true);
    // new method/version from https://github.com/deployphp/deployer/blob/master/recipe/magento2.php
    // detect if setup:upgrade is needed
    $currentExists = test('[ -d {{deploy_path}}/current ]');

    if ($currentExists && get('database_upgrade_needed')) {
        run('{{bin/php}} {{deploy_path}}/current/bin/magento maintenance:enable');
        run('{{bin/php}} {{release_path}}/bin/magento setup:db-schema:upgrade --no-interaction');
        run('{{bin/php}} {{release_path}}/bin/magento setup:db-data:upgrade --no-interaction');
        run('{{bin/php}} {{deploy_path}}/current/bin/magento maintenance:disable');
    }

    log_time('magento:upgrade:db', $start_time);
})->once();

desc('Magento2 cache flush');
task('magento:cache:flush', function () {
    $start_time = microtime(true);
    run('{{bin/php}} {{release_path}}/bin/magento cache:flush');
    run('{{bin/php}} {{release_path}}/bin/magento cache:enable');
    log_time('magento:cache:flush', $start_time);
});


task('timer:start', function () {
    set('task_start_time', microtime(true));
    writeln("timer zeb started " . get('task_start_time'));
});
// option for hidden


task('timer:stop', function () {
    $startTime = get('task_start_time');
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    $taskTimings=get('task_timings');
    $taskTimings[]=$duration;
    set('task_timings', $taskTimings);
    writeln("timer zeb stopped $endTime");
    writeln("timer zeb start time using $startTime");
    writeln("Task took $duration seconds");
    writeln(print_r($taskTimings, true));
});

before('deploy:prepare', 'timer:start');
after('deploy:prepare', 'timer:stop');
before('deploy:vendors', 'timer:start');
after('deploy:vendors', 'timer:stop');
before('deploy:shared', 'timer:start');
after('deploy:shared', 'timer:stop');
before('magento:apply:patches', 'timer:start');
after('magento:apply:patches', 'timer:stop');
before('magento:di:compile', 'timer:start');
after('magento:di:compile', 'timer:stop');
before('npm run build-prod', 'timer:start');
after('npm run build-prod', 'timer:stop');
before('magento:deploy:assets', 'timer:start');
after('magento:deploy:assets', 'timer:stop');
before('magento:upgrade:db', 'timer:start');
after('magento:upgrade:db', 'timer:stop');
before('magento:create:symlinks', 'timer:start');
after('magento:create:symlinks', 'timer:stop');
before('magento:cache:flush', 'timer:start');
after('magento:cache:flush', 'timer:stop');
before('deploy:symlink', 'timer:start');
after('deploy:symlink', 'timer:stop');
before('php:opcache:flush', 'timer:start');
after('php:opcache:flush', 'timer:stop');
before('deploy:unlock', 'timer:start');
after('deploy:unlock', 'timer:stop');
before('deploy:cleanup', 'timer:start');
after('deploy:cleanup', 'timer:stop');
before('deploy:success', 'timer:start');
after('deploy:success', 'timer:stop');


desc('Deploy your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:shared',
    'magento:apply:patches',
    'magento:di:compile',
    'npm run build-prod',
    'magento:deploy:assets',
    'magento:upgrade:db',
    'magento:create:symlinks',
    'magento:cache:flush',
    'deploy:symlink',
    'php:opcache:flush',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success'
]);
after('deploy:failed', 'deploy:unlock');