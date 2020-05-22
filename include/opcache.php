<?php
namespace Deployer;

/**
 * Tasks
 */
desc('PHP Opcache flush');
task('php:opcache:flush', function() {

    // Php socket to clear opcache can be located in different places
    // on different servers, just add your paths, if needed
    run('[ ! -d ~/cachetool ] && composer create-project gordalina/cachetool ~/cachetool');

    run('
    for sock in {~/run/*.php-fpm.sock,/var/run/$(whoami)-remi-safe-php*.sock}; do
        if [ -S $sock ]; then
            ~/cachetool/bin/cachetool opcache:reset --fcgi=$sock && \
            echo "Opcache was cleared (php sock is $sock)"
        fi;
    done');
});
