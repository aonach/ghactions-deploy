<?php
namespace Deployer;


set( 'lock_env', get( 'lock_env' ) );
/**
 * Tasks
 */
desc('Lock environment with htaccess');
task('deploy:lock_env', function() {
  //$currentExists = test('[ -d {{deploy_path}}/current ]');
  //&& test("[ -f {{release_path}}/$file ]"
  run ( 'echo "Lock env section"' );
  run( 'echo {{{lock_env}}}' );

})->setPrivate();
