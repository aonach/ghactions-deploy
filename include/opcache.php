<?php
namespace Deployer;

/**
 * Configuration
 */
set('php_version', function() {
    return run('{{bin/php}} -r "echo phpversion();"');
});
set('bin/curl', function() {
    return locateBinaryPath('curl');
});

/**
 * Tasks
 */
desc('PHP Opcache flush');
task('php:opcache:flush', function() {
    $cacheToolVersion = version_compare(get('php_version'), '7.2', '>=') ? '' : '-3.2.1';

    run('
    {{bin/curl}} -s http://gordalina.github.io/cachetool/downloads/cachetool'.$cacheToolVersion.'.phar -o cachetool.phar
    chmod +x cachetool.phar
    for sock in {~/run/*.php-fpm.sock,/var/run/$(whoami)-remi-safe-php*.sock}; do
        [ -S $sock ] && ./cachetool.phar opcache:reset --fcgi=$sock
    done');
});
