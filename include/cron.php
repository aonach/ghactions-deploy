<?php
namespace Deployer;

/**
 * Configuration
 */
// Pause Magento's cron while a deploy runs (TASK-37387450). Off by default; opt in per host in
// hosts.yml with `pause_cron_during_deploy: true`.
//
// Why: on small Hypernode boxes MySQL, nginx, PHP-FPM, cron and the deploy's own SSH session all
// share one memory cgroup (user.slice, 2.27 GiB on organicotest47). Every minute cron:run starts
// one PHP process per cron group plus queue consumers, ~700-850 MB in total, and with MySQL already
// at ~800 MB there is no room left for setup:di:compile. The kernel OOM-kills the compile and
// Deployer only reports `exit code -1 (Unknown error)`.
//
// How: the deploy touches a flag file and the server's Magento cron:run crontab line skips while
// the flag exists and is less than 60 minutes old. That guard has to be added to the crontab by
// hand, once per server; see the README. The age check means a deploy that never reaches
// deploy:failed (e.g. a cancelled GitHub run) cannot leave cron off for more than an hour.
// Only the Magento cron:run line is guarded; other cron jobs (WordPress, logrotate, ...) keep running.
set('pause_cron_during_deploy', false);
set('cron_pause_flag', '{{deploy_path}}/.dep/cron.paused');

// How long magento:cron:wait waits, in seconds, for cron processes that were already running when
// the flag was set. It never kills them: after this it logs a warning and the deploy carries on.
set('cron_pause_max_wait', 300);

/**
 * Tasks
 */
desc('Pause Magento cron for the deploy (if enabled)');
task('magento:cron:pause', function () {
    if (!get('pause_cron_during_deploy')) {
        writeln('Not applicable. pause_cron_during_deploy is not enabled for this host.');
        return;
    }

    run('touch {{cron_pause_flag}}');
    writeln('Magento cron paused ({{cron_pause_flag}})');

    // The flag does nothing unless the crontab line checks for it. A `bin/magento cron:install
    // --force` rewrites the Magento block and drops the guard, so say so rather than fail.
    if (!test('crontab -l 2>/dev/null | grep -qF "$(basename {{cron_pause_flag}})"')) {
        writeln('WARNING: the crontab has no guard for {{cron_pause_flag}}, so cron is NOT actually ' .
            'paused. Add it as described in the README ("Pausing cron during a deploy").');
    }
});

// cron:run starts the cron-group processes (use_separate_process) and the queue consumers in the
// background, so they outlive cron:run itself and its flock lock. Wait for the processes instead.
// The [c]/[q] brackets stop the pattern matching this shell's own command line.
desc('Wait for already-running Magento cron to finish (if enabled)');
task('magento:cron:wait', function () {
    if (!get('pause_cron_during_deploy')) {
        return;
    }

    run('
    PATTERN="bin/magento ([c]ron:run|[q]ueue:consumers:start)"
    WAITED=0
    while pgrep -u "$(id -u)" -f "$PATTERN" > /dev/null; do
        if [ "$WAITED" -ge {{cron_pause_max_wait}} ]; then
            echo "WARNING: Magento cron still running after ${WAITED}s, continuing anyway:"
            pgrep -a -u "$(id -u)" -f "$PATTERN"
            exit 0
        fi
        sleep 10
        WAITED=$((WAITED + 10))
    done
    echo "No Magento cron running (waited ${WAITED}s)"');
});

desc('Resume Magento cron after the deploy');
task('magento:cron:resume', function () {
    // Runs whatever the setting says, so that turning pause_cron_during_deploy off never strands a
    // flag left behind by an earlier deploy.
    run('rm -f {{cron_pause_flag}}');
});
