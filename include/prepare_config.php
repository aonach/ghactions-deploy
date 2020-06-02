<?php
namespace Deployer;

/**
 * Tasks
 */
desc('Prepare configuration');
task('config:prepare', function() {
    foreach (['symlinks', 'shared_files', 'shared_dirs'] as $var) {
        $globalValue = get($var, []);
        $hostValue = get('+'.$var, []);

        set($var, array_merge($globalValue, $hostValue));
    }
})->setPrivate();
before('deploy:prepare', 'config:prepare');
