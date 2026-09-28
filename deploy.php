<?php

namespace Deployer;

use Deployer\Exception\ConfigurationException;
use Deployer\Exception\GracefulShutdownException;
use Deployer\Exception\RunException;
use Deployer\Host\Host;


require_once 'recipe/common.php';
require_once 'include/opcache.php';
require_once 'include/prepare_config.php';
require_once 'include/update_code.php';
require_once 'include/shared.php';
require_once 'include/vendors.php';
require_once 'include/cleanup.php';

const DB_UPDATE_NEEDED_EXIT_CODE = 2;
const CONFIG_PHP_UPDATE_NEEDED_EXIT_CODE = 1;

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

// Which themes static content is built for. Left empty (the default), Magento builds every theme it
// knows about -- Magento/blank, Magento/luma, Hyva/default, Hyva/reset, ... -- in every locale, one
// after another, and most of those are never served. On a typical Hyvä site that is well over half
// of the magento:deploy:assets time.
//
// Set these per site to build only what is live:
//     set('asset_themes_frontend', ['Aonach/hyva', 'Aonach/checkout']);
//     set('asset_themes_adminhtml', ['Magento/backend']);
//     set('asset_locales_adminhtml', 'en_US');   // empty = same as asset_locales
// or the same keys as lists in hosts.yml. A listed theme's parents are still read as fallback
// sources; --no-parent only stops them being written out as themes of their own. So list every
// theme assigned to a store view, the Hyvä checkout fallback theme, and the admin theme. A live
// theme that is NOT listed serves 404s for its CSS/JS, so check design config before setting this.
// Admin locales must cover every admin user's interface locale.
set('asset_themes_frontend', []);
set('asset_themes_adminhtml', []);
set('asset_locales_adminhtml', '');

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
        run('{{bin/npm}} install');
        run('{{bin/npm}} run build-prod');
    } else {
        writeln('Not applicable. This is not a Hyva project :(');
    }

});

/**
 * Theme lists come from deploy.php as arrays or from hosts.yml as lists, but accept a
 * space-separated string too.
 */
function assetThemes(string $key): array
{
    $themes = get($key);
    if (is_string($themes)) {
        $themes = preg_split('/\s+/', trim($themes), -1, PREG_SPLIT_NO_EMPTY);
    }

    return array_values(array_filter((array)$themes));
}

function staticContentDeployCommand(string $area, array $themes, string $locales): string
{
    $options = ['--force', '--area ' . $area];
    if ($themes) {
        $options[] = '--no-parent';
        foreach ($themes as $theme) {
            $options[] = '--theme ' . escapeshellarg($theme);
        }
    }

    return '{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy ' .
        implode(' ', $options) . ' ' . $locales;
}

desc('Magento2 deploy assets');
task('magento:deploy:assets', function () {
    // Magento 2.1 has different arguments for setup:static-content:deploy, so
    // we need to do the condition to take this
    if (version_compare(get('m2_version'), '2.2', '<')) {
        run('{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy --quiet {{asset_locales}}');
        return;
    }

    $frontendThemes = assetThemes('asset_themes_frontend');
    $adminThemes = assetThemes('asset_themes_adminhtml');
    $adminLocales = trim((string)get('asset_locales_adminhtml'));

    if (!$frontendThemes && !$adminThemes && $adminLocales === '') {
        // Nothing configured for this site: build everything, exactly as before.
        run('{{bin/php}} {{release_path}}/bin/magento setup:static-content:deploy --force {{asset_locales}}');
        return;
    }

    // One command per area, because the locales argument applies to every area in a command and the
    // admin rarely needs all the storefront locales. Magento does not clean pub/static between runs.
    run(staticContentDeployCommand('frontend', $frontendThemes, get('asset_locales')));
    run(staticContentDeployCommand('adminhtml', $adminThemes, $adminLocales ?: get('asset_locales')));
});

desc('Magento2 create symlinks');
task('magento:create:symlinks', function () {
    cd('{{release_path}}');
    foreach (get('symlinks') as $key => $value) {
        run('ln -sf ' . $value . ' ' . $key);
    }
});

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
    try {
        run('{{bin/php}} {{bin/magento}} module:config:status');
    } catch (RunException $e) {
        if ($e->getExitCode() == CONFIG_PHP_UPDATE_NEEDED_EXIT_CODE) {
            if (moduleConfigDiffersOnlyInOrder()) {
                warning('app/etc/config.php lists the right modules in a different order to the one Magento ' .
                    'computes. Skipping maintenance mode and the DB upgrade: they would not change the order. ' .
                    'Run setup:upgrade locally and commit app/etc/config.php to clear this warning.');
                return false;
            }
            return true;
        }

        throw $e;
    }

    return false;
});

/**
 * module:config:status compares config.php to Magento's computed module list with a strict `!==`, so
 * a config.php with exactly the right modules in a different order counts as "outdated". The
 * setup:db-*:upgrade commands never rewrite config.php, so on a site in that state every deploy went
 * into maintenance mode for an upgrade that changed nothing -- most of our sites, as of Sept 2026.
 *
 * Builds the module list the same way the Installer does (ModuleList\Loader), without bootstrapping
 * the application, so no DB or cache connection. Anything unexpected -- an error, an older Magento
 * whose Loader signature differs -- returns false and the deploy upgrades exactly as before.
 */
function moduleConfigDiffersOnlyInOrder(): bool
{
    $script = <<<'PHP'
require 'app/autoload.php';
$parserFactory = new class extends \Magento\Framework\Xml\ParserFactory {
    public function __construct() {}
    public function create(): \Magento\Framework\Xml\Parser { return new \Magento\Framework\Xml\Parser(); }
};
$loader = new \Magento\Framework\Module\ModuleList\Loader(
    new \Magento\Framework\Module\Declaration\Converter\Dom(),
    new \Magento\Framework\Xml\Parser(),
    new \Magento\Framework\Component\ComponentRegistrar(),
    new \Magento\Framework\Filesystem\Driver\File(),
    $parserFactory
);
$computed = array_keys($loader->load());
$config = include 'app/etc/config.php';
$current = array_keys($config['modules'] ?? []);
$sameOrder = $computed === $current;
sort($computed);
sort($current);
echo $sameOrder ? 'IDENTICAL' : ($computed === $current ? 'ORDER_ONLY' : 'DIFFERENT');
PHP;

    try {
        $result = run('cd {{release_path}} && {{bin/php}} -r ' . escapeshellarg($script));
    } catch (RunException $e) {
        return false;
    }

    return trim($result) === 'ORDER_ONLY';
}

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
    run('{{bin/php}} {{deploy_path}}/current/bin/magento cache:flush');
    run('{{bin/php}} {{deploy_path}}/current/bin/magento cache:enable');
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
// deploy:shared is not listed again after deploy:vendors: Deployer 7's deploy:prepare already runs
// it, and the second pass only re-created the same symlinks (~18s on a slow link).
desc('Deploy your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
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
