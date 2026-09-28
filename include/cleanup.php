<?php
namespace Deployer;

/**
 * Replaces Deployer's deploy:cleanup. Deleting an old Magento release (vendor/, generated/,
 * pub/static/ -- hundreds of thousands of files) took 5-45s of every deploy, all of it after the
 * site was already live on the new release. Old releases are now moved out of releases/ (a rename on
 * the same filesystem, instant) and deleted in the background once the deploy has finished.
 *
 * Anything left in .dep/trash -- e.g. a host that kills background processes when the SSH session
 * ends -- is picked up again by the next deploy's cleanup, and reported so it is not silent.
 */
task('deploy:cleanup', function () {
    $releases = get('releases_list');
    $keep = get('keep_releases');
    $sudo = get('cleanup_use_sudo') ? 'sudo' : '';
    $trash = '{{deploy_path}}/.dep/trash';

    $commands = ['cd {{deploy_path}} && if [ -e release ]; then rm release; fi'];

    if ($keep > 0) {
        $commands[] = "mkdir -p $trash";
        $commands[] = "LEFT=$(ls -A $trash | wc -l); " .
            "[ \"\$LEFT\" -gt 0 ] && echo \"WARNING: \$LEFT old release(s) from a previous cleanup still in .dep/trash, deleting them now\" || true";
        foreach (array_slice($releases, $keep) as $release) {
            // Suffixed with the time so a later release with the same name can never collide.
            $commands[] = "$sudo mv {{deploy_path}}/releases/$release $trash/$release." . time();
        }
        $commands[] = "(nohup $sudo rm -rf $trash/* > /dev/null 2>&1 < /dev/null &)";
    }

    run(implode(' && ', $commands));
});
