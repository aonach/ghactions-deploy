<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Deployer\Exception\GracefulShutdownException;
use Deployer\Exception\RunException;
use Deployer\Host\Host;

use function Deployer\Support\array_is_list;


require_once 'recipe/common.php';
require_once 'include/opcache.php';
require_once 'include/prepare_config.php';
require_once 'include/update_code.php';
require_once 'include/shared.php';
require_once 'include/vendors.php';
require_once 'include/cleanup.php';

const DB_UPDATE_NEEDED_EXIT_CODE = 2;

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

// Static content settings, ported unchanged from Deployer's own Magento recipe (recipe/magento2.php,
// Deployer 7.5.12) so they work -- and are named -- exactly as upstream documents them. Only the
// defaults of static_content_locales differs: it follows our older asset_locales setting, so existing
// site config keeps working.
//
// With nothing set, every theme Magento knows about (Magento/blank, Magento/luma, Hyva/default, ...)
// is built in every locale, as before. To build only what a site serves, e.g. in hosts.yml:
//     static_content_locales: en_IE
//     magento_themes: [Aonach/hyva, Aonach/checkout, Magento/backend]
//     static_deploy_options: --no-parent
// List every theme assigned to a store view, the Hyvä checkout fallback theme and the admin theme; a
// live theme that is not listed serves 404s for its CSS/JS. The admin needs every admin user's
// interface locale AND the store's default locale (general/locale/code), which the admin login page
// uses before anyone is logged in. Simplest is one locale for everything: the store's default locale,
// with every admin user set to it.

// By default setup:static-content:deploy uses `en_US`.
// To change that, simply put `set('static_content_locales', 'en_US de_DE');`
// in you deployer script.
set('static_content_locales', '{{asset_locales}}');

// You can also set the themes to run against. By default it'll deploy
// all themes - `add('magento_themes', ['Magento/luma', 'Magento/backend']);`
// If the themes are set as a simple list of strings, then all languages defined in {{static_content_locales}} are
// compiled for the given themes.
// Alternatively The themes can be defined as an associative array, where the key represents the theme name and
// the key contains the languages for the compilation (for this specific theme)
// Example:
// set('magento_themes', ['Magento/luma']); - Will compile this theme with every language from {{static_content_locales}}
// set('magento_themes', [
//     'Magento/luma'   => null,                              - Will compile all languages from {{static_content_locales}} for Magento/luma
//     'Custom/theme'   => 'en_US fr_FR'                      - Will compile only en_US and fr_FR for Custom/theme
//     'Custom/another' => '{{static_content_locales}} it_IT' - Will compile all languages from {{static_content_locales}} + it_IT for Custom/another
// ]); - Will compile this theme with every language
set('magento_themes', [

]);

// Static content deployment options, e.g. '--no-parent'
set('static_deploy_options', '');

// Deploy frontend and adminhtml together as default
set('split_static_deployment', false);

// Use the default languages for the backend as default
set('static_content_locales_backend', '{{static_content_locales}}');

// backend themes to deploy. Only used if split_static_deployment=true
// This setting supports the same options/structure as {{magento_themes}}
set('magento_themes_backend', ['Magento/backend' => null]);

// Also set the number of concurrent jobs to run. The default is 1
// Update using: `set('static_content_jobs', '1');`
set('static_content_jobs', '1');

set('content_version', function () {
    return time();
});

// Deployer's own defaults are too tight for a Magento asset build. When they are exceeded the
// failure is nasty and quiet: setup:static-content:deploy completes, Deployer then kills a
// process group that has already exited, the failed `kill` exits 1, and the deploy dies BEFORE
// deploy:symlink -- so the new release is built but never swapped in and the site silently keeps
// serving the previous one, while the deploy reports failure. During a security round that means
// a release believed deployed is not actually live.
//
// Setting them here means every site inherits sane values. A site's own root deploy.php is
// loaded AFTER this recipe (`require_once 'deployer/deploy.php'; set(...)`), so any explicit
// per-site value still overrides these.
set('default_timeout', 600);      // 10 minutes
set('default_idle_timeout', 300); // 5 minutes

// Skip Git LFS files during clone - these are typically dev database backups not needed on servers
set('skip_lfs', true);
set('env', function () {
    if (get('skip_lfs', true)) {
        return ['GIT_LFS_SKIP_SMUDGE' => '1'];
    }
    return [];
});

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
        run('{{bin/npm}} install && {{bin/npm}} run build-prod');
    } else {
        writeln('Not applicable. This is not a Hyva project :(');
    }

});

// magento:deploy:assets and its helpers below are ported unchanged from Deployer's recipe/magento2.php
// (7.5.12). The previous version here also supported Magento 2.1 (--quiet instead of -f); every site
// runs 2.4 and upstream dropped that long ago.
desc('Deploys assets');
task('magento:deploy:assets', function () {
    $themesToCompile = '';
    if (get('split_static_deployment')) {
        invoke('magento:deploy:assets:adminhtml');
        invoke('magento:deploy:assets:frontend');
    } else {
        if (count(get('magento_themes')) > 0) {
            $themes = array_is_list(get('magento_themes')) ? get('magento_themes') : array_keys(get('magento_themes'));
            foreach ($themes as $theme) {
                $themesToCompile .= ' -t ' . $theme;
            }
        }
        run("{{bin/php}} {{release_or_current_path}}/bin/magento setup:static-content:deploy -f --content-version={{content_version}} {{static_deploy_options}} {{static_content_locales}} $themesToCompile -j {{static_content_jobs}}");
    }
});

desc('Deploys assets for backend only');
task('magento:deploy:assets:adminhtml', function () {
    magentoDeployAssetsSplit('backend');
});

desc('Deploys assets for frontend only');
task('magento:deploy:assets:frontend', function () {
    magentoDeployAssetsSplit('frontend');
});

/**
 * @phpstan-param 'frontend'|'backend' $area
 *
 * @throws ConfigurationException
 */
function magentoDeployAssetsSplit(string $area)
{
    if (!in_array($area, ['frontend', 'backend'], true)) {
        throw new ConfigurationException("\$area must be either 'frontend' or 'backend', '$area' given");
    }

    $isFrontend = $area === 'frontend';
    $suffix = $isFrontend
        ? ''
        : '_backend';

    $themesConfig = get("magento_themes$suffix");
    $defaultLanguages = get("static_content_locales$suffix");
    $useDefaultLanguages = array_is_list($themesConfig);

    /** @var list<string> $themes */
    $themes = $useDefaultLanguages
        ? array_values($themesConfig)
        : array_keys($themesConfig);

    $staticContentArea = $isFrontend
        ? 'frontend'
        : 'adminhtml';

    if ($useDefaultLanguages) {
        $themes = '-t ' . implode(' -t ', $themes);

        run("{{bin/php}} {{bin/magento}} setup:static-content:deploy -f --area=$staticContentArea --content-version={{content_version}} {{static_deploy_options}} $defaultLanguages $themes -j {{static_content_jobs}}");
        return;
    }

    foreach ($themes as $theme) {
        $languages = parse($themesConfig[$theme] ?? $defaultLanguages);

        run("{{bin/php}} {{bin/magento}} setup:static-content:deploy -f --area=$staticContentArea --content-version={{content_version}} {{static_deploy_options}} $languages -t $theme -j {{static_content_jobs}}");
    }
}

desc('Syncs content version');
task('magento:sync:content_version', function () {
    $timestamp = time();
    on(select('all'), function (Host $host) use ($timestamp) {
        $host->set('content_version', $timestamp);
    });
})->once();

before('magento:deploy:assets', 'magento:sync:content_version');

desc('Magento2 create symlinks');
task('magento:create:symlinks', function () {
    $commands = [];
    foreach (get('symlinks') as $key => $value) {
        $commands[] = 'ln -sf ' . $value . ' ' . $key;
    }
    if ($commands) {
        cd('{{release_path}}');
        run(implode(' && ', $commands));
    }
});

// Only setup:db:status decides whether the DB is upgraded, as in the upstream Deployer magento2 recipe.
// module:config:status is deliberately NOT checked: the upgrade below runs setup:db-schema:upgrade and
// setup:db-data:upgrade, which never rewrite app/etc/config.php (only setup:upgrade does), so a
// config.php it reports as outdated could not be fixed here anyway. It also compares module order
// strictly, so it flagged most of our sites on every deploy, and every one of those deploys went into
// maintenance mode for an upgrade that changed nothing (TASK-37178618).
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

    return false;
});

desc('Magento2 upgrade database');
task('magento:upgrade:db', function () {
    // new method/version from https://github.com/deployphp/deployer/blob/master/recipe/magento2.php
    // detect if setup:upgrade is needed
    $currentExists = test('[ -d {{deploy_path}}/current ]');

    if ($currentExists && get('database_upgrade_needed')) {
        run('{{bin/php}} {{deploy_path}}/current/bin/magento maintenance:enable');
        // The cache is no longer flushed before the flip, so the config cache still holds the old
        // release's view of things here. A release that adds a module or an MQ topic can fail the
        // schema upgrade on that stale config, so clean just the config cache first. Same as the
        // upstream recipe.
        run('{{bin/php}} {{release_path}}/bin/magento cache:clean config');
        run('{{bin/php}} {{release_path}}/bin/magento setup:db-schema:upgrade --no-interaction');
        run('{{bin/php}} {{release_path}}/bin/magento setup:db-data:upgrade --no-interaction');
        run('{{bin/php}} {{deploy_path}}/current/bin/magento maintenance:disable');
    }
})->once();

desc('Magento2 cache flush');
task('magento:cache:flush', function () {
    // Runs after deploy:symlink, so `current` IS the new release by this point. Using the path
    // explicitly (the same idiom as magento:upgrade:db) rather than {{bin/magento}} or
    // {{release_path}} is deliberate: {{release_or_current_path}} is not reliable at this point in
    // the flow, and going through `current` means the task fails loudly if the symlink did not
    // actually move, instead of quietly flushing on behalf of a release that is not live.
    run('{{bin/php}} {{deploy_path}}/current/bin/magento cache:flush && ' .
        '{{bin/php}} {{deploy_path}}/current/bin/magento cache:enable');
});

// magento:cache:flush runs AFTER deploy:symlink, never before it. Flushing before the flip leaves
// a window -- measured at 6-8 seconds on a real deploy -- in which the OLD release is still the one
// serving traffic, and that traffic repopulates the shared Redis cache with old-generation entries.
// Most of those are harmless because the next cache:clean removes them, but Magento writes some
// entries with no tags and no TTL (Reflection MethodsMap is the one that bit us), and an untagged,
// TTL-less entry written in that window survives every subsequent cache:clean indefinitely. The
// symptom is a release that is live and correct on disk while the application keeps reading a map
// of the previous release's interfaces, which breaks config saves with an undefined array key.
//
// Flushing after the flip closes the window: anything written by old-release traffic is discarded
// by the flush, and everything written afterwards comes from the new release. This matches the
// upstream Deployer recipe, which does `after('deploy:symlink', 'magento:cache:flush')`.
//
// See TASK-37113007 and aonach/workflows/CACHE-FLUSH-ORDERING.md for the full write-up.
//
// deploy:shared runs twice ON PURPOSE: once in deploy:prepare, and again after deploy:vendors. It is
// not redundant. composer install runs the magento2-base installer with magento-force: override, which
// unlink()s any mapped file that already exists -- pub/.htaccess, .htaccess, app/etc/di.xml -- and
// copies Magento's stock file in its place. That replaces the shared symlink the first pass made, so
// without the second pass a release serves stock files instead of the shared ones (e.g. a pub/.htaccess
// that loses a test site's basic auth).
// Deployer's own magento2 recipe runs deploy:shared only once because it only shares app/etc/env.php
// and var/.maintenance.ip, which composer never writes. We also share pub/.htaccess (per-environment
// settings such as basic auth), so we need the second pass. A site can also stop the installer
// touching a file at all, e.g. a customised pub/.htaccess committed to the repo, by listing it in its
// root composer.json: "extra": {"magento-deploy-ignore": {"magento/magento2-base": ["/pub/.htaccess"]}}.
desc('Deploy your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:shared',
    'magento:apply:patches',
    'magento:di:compile',
    'npm run build-prod',
    'magento:deploy:assets',
    'magento:upgrade:db',
    'magento:create:symlinks',
    'deploy:symlink',
    'magento:cache:flush',
    'php:opcache:flush',
    'deploy:unlock',
    'deploy:cleanup',
    'deploy:success'
]);
after('deploy:failed', 'deploy:unlock');
