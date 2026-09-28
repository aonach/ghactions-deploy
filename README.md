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

## Configuration

### Git LFS

By default, Git LFS files are skipped during deployment (`skip_lfs: true`). This prevents large LFS objects (e.g., local database backups) from being downloaded to servers, saving both deployment time and disk space.

To disable this, set `skip_lfs: false` in your _hosts.yml_:

```yaml
production:
  hostname: prod-server
  remote_user: deploy
  skip_lfs: false
```

**Note:** The `skip_lfs` option uses `set('env', ...)` to configure the `GIT_LFS_SKIP_SMUDGE` environment variable. If your project needs additional environment variables, add them inside the same closure in _deploy.php_ rather than calling `set('env', ...)` separately, which would overwrite the LFS setting.

### Static content themes and locales

Static content uses the same settings as Deployer's own Magento recipe (`static_content_locales`, `magento_themes`, `static_deploy_options`, `split_static_deployment`, `static_content_locales_backend`, `magento_themes_backend`, `static_content_jobs`); see the comments in _deploy.php_ or [Deployer's magento2 recipe](https://github.com/deployphp/deployer/blob/master/recipe/magento2.php). `static_content_locales` defaults to the older `asset_locales` setting.

By default every theme Magento knows about (Magento/blank, Magento/luma, Hyva/default, ...) is built, which is most of the asset build time. To build only what the site serves, in _hosts.yml_:

```yaml
production:
  static_content_locales: en_IE                                  # the store's default locale
  magento_themes: [Aonach/hyva, Aonach/checkout, Magento/backend] # store view theme(s), Hyvä checkout fallback, admin theme
  static_deploy_options: --no-parent
```

A live theme that is not listed serves 404s for its CSS/JS, so check the design configuration first. The admin needs every admin user's interface locale **and** the store's default locale (`general/locale/code`), which the admin login page uses before anyone is logged in. Simplest: build one locale, the store's default, and set every admin user's interface locale to it.

## Related links:

https://deployer.org

https://help.github.com/en/github/automating-your-workflow-with-github-actions
