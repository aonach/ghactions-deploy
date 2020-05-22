<?php
namespace Deployer;

/**
 * Configuration
 */
set('php_sock_path', '~/run/*.php-fpm.sock,/var/run/$(whoami)-remi-safe-php*.sock');

/**
 * Tasks
 */
desc('PHP Opcache flush');
task('php:opcache:flush', function() {

    // Php socket to clear opcache can be located in different places
    // on different servers, just add your paths, if needed
    run('
    if [ ! -d ~/cachetool ]; then
        {{bin/composer}} create-project gordalina/cachetool ~/cachetool
    fi');

    run('
    for sock in {{{php_sock_path}}}; do
        if [ -S $sock ]; then
            ~/cachetool/bin/cachetool opcache:reset --fcgi=$sock && \
            echo "Opcache was cleared (php sock is $sock)"
        fi;
    done');
});
