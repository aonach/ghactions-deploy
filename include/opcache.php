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
    if (test('[ ! -d ~/cachetool ]')) {
        run('{{bin/composer}} create-project gordalina/cachetool ~/cachetool');
    }

    // Randomly go and try and update the cachetool if todays date is divisible by 3
    run('(( $(date +%d) % 3 == 0 )) && {{bin/composer}} update -d ~/cachetool || echo "Not updating cachetool" ');

    run('
    for sock in {{{php_sock_path}}}; do
        if [ -S $sock ]; then
            ~/cachetool/bin/cachetool stat:realpath_size --fcgi=$sock && \
            ~/cachetool/bin/cachetool opcache:reset --fcgi=$sock && \
            ~/cachetool/bin/cachetool stat:realpath_size --fcgi=$sock && \
            echo "Opcache was cleared (php sock is $sock)"
        else
            ~/cachetool/bin/cachetool opcache:status
            ~/cachetool/bin/cachetool opcache:reset
            ~/cachetool/bin/cachetool opcache:status
        fi;
    done');
});
