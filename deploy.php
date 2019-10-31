<?php
namespace Deployer;
use Symfony\Component\Console\Input\InputOption;

require_once 'recipe/common.php';

/**
 * Config of hosts
 */
inventory('hosts.yml');
foreach (Deployer::get()->hosts as $host) {
    $host->addSshOption('StrictHostKeyChecking', 'no');
}

/**
 * Configuration
 */
set('deploy_path',
    '~/deploy'
);
set('shared_files', [
    'app/etc/env.php'
]);
set('shared_dirs', [
    'pub/media',
    'var/log'
]);
set('bin/curl', function() {
    return locateBinaryPath('curl');
});

/**
 * Tasks
 */
desc('Magento2 apply patches');
task('magento:apply:patches', function() {
    run('
    cd {{release_path}}
    if [ -d patch ]; then
        for patch in patch/*.patch; do
            {{bin/git}} apply -v $patch
        done
    fi');
});

desc('Magento2 dependency injection compile');
task('magento:di:compile', function() {
    run('{{bin/php}} {{release_path}}/bin/magento setup:di:compile');
});

desc('Magento2 deploy assets');
task('magento:deploy:assets', function() {
    run('{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy --force --strategy=compact en_US en_IE');
});

desc('Magento2 Gulp compile');
task('magento:gulp:compile', function() {
    run('
    cd {{release_path}}
    if [ -f gulpfile.js ]; then
        gulp
    fi');
});

desc('Magento2 upgrade database');
task('magento:upgrade:db', function() {
    run('
    if ! {{release_path}}/bin/magento setup:db:status; then
        if [ -d {{current_path}} ]; then
            {{bin/php}} {{current_path}}/bin/magento maintenance:enable
        fi
        {{bin/php}} {{release_path}}/bin/magento setup:upgrade --keep-generated
        if [ -d {{current_path}} ]; then
            {{bin/php}} {{current_path}}/bin/magento maintenance:disable
        fi
    fi');
});

desc('Magento2 cache flush');
task('magento:cache:flush', function() {
    run('{{bin/php}} {{release_path}}/bin/magento cache:flush');
    run('{{bin/php}} {{release_path}}/bin/magento cache:enable');
});

desc('Wordpress cache flush');
task('wordpress:cache:flush', function() {
    run('
    cd {{release_path}}
    if [ -f clear_wp_transients.sh ]; then
        chmod +x clear_wp_transients.sh
        ./clear_wp_transients.sh
    fi');
});

desc('PHP Opcache flush');
task('php:opcache:flush', function() {
    run('
    {{bin/curl}} -sO http://gordalina.github.io/cachetool/downloads/cachetool.phar
    chmod +x cachetool.phar
    for sock in /var/run/$(whoami)-remi-safe-php*.sock; do
        ./cachetool.phar opcache:reset --fcgi=$sock
    done');
});

desc('Deploy your project');
task('deploy', [
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:vendors',
    'deploy:shared',
    'magento:apply:patches',
    'magento:di:compile',
    'magento:deploy:assets',
    'magento:gulp:compile',
    'magento:upgrade:db',
    'magento:cache:flush',
    'wordpress:cache:flush',
    'php:opcache:flush',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success'
]);

/**
 * Set repository name from input
 */
option('repository',
    null,
    InputOption::VALUE_REQUIRED,
    'Repository for pull'
);

task('set:repository' , function() {
    if(input()->hasOption('repository')) {
        set('repository', input()->getOption('repository'));
    }
})->setPrivate();
before('deploy:update_code', 'set:repository');
