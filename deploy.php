<?php
namespace Deployer;

require_once 'recipe/common.php'; //JS Was wondering where this came from as don't see it in the repo?
require_once 'include/update_code.php'; //JS Funciton of this file?

/**
 * Config of hosts
 */
inventory('hosts.yml'); // JS Why not store the hosts.yml in the .github/workflow folder or similar rather than the root? Would it get deployed? Although it wouldn't have password wouldn't want even that info directly accessible.
foreach (Deployer::get()->hosts as $host) {
    $host->addSshOption('StrictHostKeyChecking', 'no');
}

/**
 * Configuration
 */
set('deploy_path', '~/deploy');
set('repo_path', 'src');
set('asset_locales', 'en_US en_IE'); //JS What about multilingual sites? Maybe overridable.

set('symlinks', [
    '.' => 'pub/pub' //JS Why do we do this?
]);
set('shared_files', [
    'app/etc/env.php' 
]);
set('shared_dirs', [ // JS Presume this can be extended / overridden for individual repos if needed?
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
    $m2version = run('{{bin/php}} {{release_path}}/bin/magento --version');
    preg_match('/((\d+\.?)+)/', $m2version, $regs);

    $additionalOptions = version_compare($regs[0], '2.2', '>=') ? //JS Just add some comments explaining what this does so my brain doesn't have to work it out every time!
        '--force --strategy=compact' : '--quiet';

    run('{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy '.
        $additionalOptions.' '.
        get('asset_locales')
    );
});

desc('Magento2 create symlinks');
task('magento:create:symlinks', function() {
    cd('{{release_path}}');
    foreach (get('symlinks') as $destination => $link) {
        run('ln -s '.$destination.' '.$link);
    }
});

desc('Magento2 upgrade database');
task('magento:upgrade:db', function() {
    run('
    if ! {{bin/php}} {{release_path}}/bin/magento setup:db:status; then
        if [ -d {{deploy_path}}/current ]; then
            {{bin/php}} {{deploy_path}}/current/bin/magento maintenance:enable
        fi
        {{bin/php}} {{release_path}}/bin/magento setup:upgrade --keep-generated
        if [ -d {{deploy_path}}/current ]; then
            {{bin/php}} {{deploy_path}}/current/bin/magento maintenance:disable
        fi
    fi');
});

desc('Magento2 cache flush');
task('magento:cache:flush', function() {
    run('{{bin/php}} {{release_path}}/bin/magento cache:flush');
    run('{{bin/php}} {{release_path}}/bin/magento cache:enable');
});

desc('PHP Opcache flush'); // JS This is for the Nexcess issue I think. Is this always necessary?
task('php:opcache:flush', function() {
    $phpVersion = run('{{bin/php}} -r "echo phpversion();"');
    $cacheToolVersion = version_compare($phpVersion, '7.2', '>=') ? '' : '-3.2.1';
    
    // JS Should we fork this to always have it availble in our repo at least?
    run(' 
    {{bin/curl}} http://gordalina.github.io/cachetool/downloads/cachetool'.$cacheToolVersion.'.phar -o cachetool.phar
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
    'magento:upgrade:db',
    'magento:create:symlinks',
    'magento:cache:flush',
    'php:opcache:flush',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success'
]);
after('deploy:failed', 'deploy:unlock');

