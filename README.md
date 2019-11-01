# Deploy via Github Actions and Deployer

The repository contains <a href="https://deployer.org" target="_blank">Deployer</a> configuration for Magento2 and example of <a href="https://help.github.com/en/github/automating-your-workflow-with-github-actions" target="_blank">Github Actions</a> workflow. The workflow creates events on push into dev/test/master branches and initiate a deployment process to dev/test/master servers, correspondingly.

You need to follow this simple steps to integrate in your project:

1. Copy _deploy.yml_ from the repo to _.github/workflow_ folder

2. Copy _hosts.yml_ to root folder and fill the file with your data

3. Copy _deploy.php_ to root folder if you want to override some tasks

4. Create required _DEPLOY_KEY_ secret in the settings on your repository, it will be used for connect to servers

5. Prepare shared folder on your servers:
* copy _app/etc/env.php_ from current document root to _#deploy_path#/shared/app/etc/env.php_
* copy all media files from _pub/media_ to _#deploy_path#/shared/pub/media_

6. Be sure all deployment steps are going right on servers (take care about composer/ssh keys)

7. Push a commit to dev/test/master branch!

## Related links:

https://deployer.org

https://help.github.com/en/github/automating-your-workflow-with-github-actions
