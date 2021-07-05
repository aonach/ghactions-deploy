<?php
namespace Deployer;

/**
 * Tasks
 */
desc('Lock environment with htaccess');
task('deploy:lock_env', function() {
  //$currentExists = test('[ -d {{deploy_path}}/current ]');
  //&& test("[ -f {{release_path}}/$file ]"

  $x = get( 'lock_env' );
  echo $x;

})->setPrivate();
