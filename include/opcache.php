<?php
namespace Deployer;

/**
 * Configuration
 */
set('php_sock_path', '~/run/*.php-fpm.sock,/var/run/$(whoami)-remi-safe-php*.sock');
//set('php_sock_path', '/var/run/$(whoami)-remi-safe-php8*.sock');

// TCP fallback for hosts where PHP-FPM listens on a TCP port instead of a unix
// socket (e.g. Hypernode: 127.0.0.1:9000). Empty = disabled: the task flushes via
// matching unix sockets only, exactly as before. Opt in per project, e.g. in the
// project's deploy.php:
//     set('php_fcgi_fallback', '127.0.0.1:9000');
// History: the old bare `cachetool opcache:reset` else-branch (which defaulted to
// 127.0.0.1:9000) was removed in Feb 2025 (d848d1b7) because it also fired on
// unix-socket hosts whenever a socket was skipped, and errored there. This opt-in
// variable replaces it and can never fire on hosts that do not declare it.
set('php_fcgi_fallback', '');

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
    FLUSHED=0
    for sock in {{{php_sock_path}}}; do
        if [ -e "$sock" ] && [ -S "$sock" ] && [[ "$sock" != *"56"* ]] && [[ "$sock" != *"php-fpm-56"* ]]; then
            ~/cachetool/bin/cachetool stat:realpath_size --fcgi=$sock && \
            ~/cachetool/bin/cachetool opcache:reset --fcgi=$sock && \
            ~/cachetool/bin/cachetool stat:realpath_size --fcgi=$sock && \
            echo "Opcache was cleared (php sock is $sock)" && \
            FLUSHED=1
        fi;
    done
    if [ "$FLUSHED" = "0" ]; then
        if [ -n "{{php_fcgi_fallback}}" ]; then
            ~/cachetool/bin/cachetool opcache:reset --fcgi={{php_fcgi_fallback}} && \
            ~/cachetool/bin/cachetool opcache:status --fcgi={{php_fcgi_fallback}} && \
            echo "Opcache was cleared (fcgi {{php_fcgi_fallback}})" || \
            { echo "ERROR: opcache flush FAILED via fcgi {{php_fcgi_fallback}}"; exit 1; }
        else
            echo "WARNING: no matching PHP-FPM socket and no php_fcgi_fallback configured - opcache was NOT flushed"
        fi
    fi');
});
