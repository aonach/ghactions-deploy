<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Deployer\Exception\GracefulShutdownException;
use Deployer\Exception\RunException;
use Deployer\Host\Host;


require_once 'recipe/common.php';
require_once 'include/opcache.php';
require_once 'include/prepare_config.php';
require_once 'include/update_code.php';

const DB_UPDATE_NEEDED_EXIT_CODE = 2;
const CONFIG_PHP_UPDATE_NEEDED_EXIT_CODE = 1;

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


/**
 * Tasks
 */
desc('Magento2 apply patches');
task('magento:apply:patches', function () {
    cd('{{release_path}}');
    run('
    for patch in patch/*.patch; do
        if [ -f $patch ]; then
            {{bin/git}} apply -v $patch || printf "##[%s]The patch $patch is not applicable" "error";
        fi;
    done');
});

desc('Magento2 dependency injection compile');
task('magento:di:compile', function () {
    run('{{bin/php}} {{release_path}}/bin/magento setup:di:compile');
});

desc('Hyva styles compile (if applicable)');
task('npm run build-prod', function () {

    if ((bool)get('is_hyva_project')) {
        cd('{{release_path}}/{{hyva_path}}/web/tailwind');
        run('{{bin/npm}} install');
        run('{{bin/npm}} run build-prod');
    } else {
        writeln('Not applicable. This is not a Hyva project :(');
    }

});

desc('Magento2 deploy assets');
task('magento:deploy:assets', function () {
    // Magento 2.1 has different arguments for setup:static-content:deploy, so
    // we need to do the condition to take this
    $additionalOptions = version_compare(get('m2_version'), '2.2', '>=') ? '--force' : '--quiet';

    run('{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy ' .
        $additionalOptions . ' ' .
        get('asset_locales')
    );
});

desc('Magento2 create symlinks');
task('magento:create:symlinks', function () {
    cd('{{release_path}}');
    foreach (get('symlinks') as $key => $value) {
        run('ln -sf ' . $value . ' ' . $key);
    }
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
    // new method/version from https://github.com/deployphp/deployer/blob/master/recipe/magento2.php
    // detect if setup:upgrade is needed
    $currentExists = test('[ -d {{deploy_path}}/current ]');

    if ($currentExists && get('database_upgrade_needed')) {
        run('{{bin/php}} {{deploy_path}}/current/bin/magento maintenance:enable');
        run('{{bin/php}} {{release_path}}/bin/magento setup:db-schema:upgrade --no-interaction');
        run('{{bin/php}} {{release_path}}/bin/magento setup:db-data:upgrade --no-interaction');
        run('{{bin/php}} {{deploy_path}}/current/bin/magento maintenance:disable');
    }
})->once();

desc('Magento2 cache flush');
task('magento:cache:flush', function () {
    run('{{bin/php}} {{release_path}}/bin/magento cache:flush');
    run('{{bin/php}} {{release_path}}/bin/magento cache:enable');
});

desc('Deploy your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:shared',
    'magento:apply:patches',
    'php:opcache:flush',
    'magento:di:compile',
    'npm run build-prod',
    'magento:deploy:assets',
    'magento:upgrade:db',
    'magento:create:symlinks',
    'magento:cache:flush',
    'deploy:symlink',
    'deploy:unlock',
    'php:opcache:flush',
    'deploy:cleanup',
    'deploy:success'
]);
after('deploy:failed', 'deploy:unlock');
