<?php

namespace Deployer;

set('composer_action', 'install');

set('composer_options', '--verbose --prefer-dist --no-progress --no-interaction --no-dev --optimize-autoloader');

// Returns Composer binary path in found. Otherwise try to install latest
// composer version to `.dep/composer.phar`. To use specific composer version
// download desired phar and place it at `.dep/composer.phar`.
// Checked in one remote call rather than one per candidate (each is a full SSH round trip).
set('bin/composer', function () {
    $composer = trim(run(
        'if [ -f {{deploy_path}}/.dep/composer.phar ]; then echo {{deploy_path}}/.dep/composer.phar; ' .
        'elif [ -f ~/.local/bin/composer-2.phar ]; then echo ~/.local/bin/composer-2.phar; ' .
        'else command -v composer || true; fi'
    ));
    if ($composer !== '') {
        return '{{bin/php}} ' . $composer;
    }

    warning("Composer binary wasn't found. Installing latest composer to \"{{deploy_path}}/.dep/composer.phar\".");
    run("cd {{deploy_path}} && curl -sS https://getcomposer.org/installer | {{bin/php}}");
    run('mv {{deploy_path}}/composer.phar {{deploy_path}}/.dep/composer.phar');
    return '{{bin/php}} {{deploy_path}}/.dep/composer.phar';
});

desc('Installs vendors');
task('deploy:vendors', function () {
    run('hash unzip 2>/dev/null || echo "WARNING: To speed up composer installation setup \"unzip\" command with PHP zip extension."; ' .
        'cd {{release_or_current_path}} && {{bin/composer}} {{composer_action}} {{composer_options}} 2>&1');
});