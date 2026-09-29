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

### Static content: build only what the site serves

**Why.** Out of the box, `setup:static-content:deploy` builds every theme Magento knows about (Magento/blank, Magento/luma, Hyva/default, Hyva/reset, ...) in every locale, one after another, and a site only ever serves a few of them. On sportresponse (Sept 2026) that step took 311s; 14 theme/locale combinations were built and 3 were needed. Building only those took 84s on the test server. It's the biggest single saving available per deploy.

**How.** The recipe uses the static content settings from Deployer's own Magento recipe ([`recipe/magento2.php`](https://github.com/deployphp/deployer/blob/v7.5.12/recipe/magento2.php#L25-L70)): `static_content_locales`, `magento_themes`, `static_deploy_options`, `split_static_deployment`, `static_content_locales_backend`, `magento_themes_backend`, `static_content_jobs`. See the comments in _deploy.php_. `static_content_locales` defaults to our older `asset_locales`. **With nothing set, everything is built exactly as before**, so each site opts in when it is ready.

**The one trap: locales.** A page's CSS/JS only exists for the locales that are built:
- the storefront uses its store view's locale;
- a logged-in admin user uses their own *Interface Locale*;
- the **admin login page** uses the store's default locale (`general/locale/code`), because nobody is logged in yet.

Miss one and that page loads with no CSS/JS. Nothing errors and the deploy succeeds (sportresponse test lost its admin login page this way).

**Our rule: build the storefront in its store views' locales, and leave the admin as it is.** The admin gets the store's default locale plus every locale an admin user is on, which in practice is usually `en_US` + the store locale. No admin user has to change anything, and the admin behaves exactly as before, so there's nothing to retest. It's also what Magento itself does when it chooses the locales. One locale for everything would save another ~25-30s per deploy, but every admin user would need switching and logging in again. Admin users created later with Magento's `en_US` default (e.g. `bin/magento admin:user:create`) would get a broken admin, and third-party extensions that assume US date format in the admin could mis-read dates. The team decided against it (TASK-37178618).

#### Enabling it on a site (sportresponse as the worked example)

1. **Find out what the site serves.** On the site's server (test first, then production), run this read-only query from `~/deploy/current`. If `app/etc/env.php` sets a table prefix, add it to the table names.
   ```bash
   n98-magerun2 db:query "
   SELECT 'store theme' AS setting, CONCAT(c.scope, ':', c.scope_id) AS scope, t.theme_path AS value
     FROM core_config_data c JOIN theme t ON t.theme_id = c.value WHERE c.path = 'design/theme/theme_id'
   UNION ALL SELECT 'checkout fallback', CONCAT(scope, ':', scope_id), value
     FROM core_config_data WHERE path = 'hyva_theme_fallback/general/theme_full_path'
   UNION ALL SELECT 'admin theme', CONCAT(c.scope, ':', c.scope_id), COALESCE(t.theme_path, c.value)
     FROM core_config_data c LEFT JOIN theme t ON t.theme_id = c.value WHERE c.path = 'admin/system_admin_design/active_theme'
   UNION ALL SELECT 'store locale', CONCAT(scope, ':', scope_id), value
     FROM core_config_data WHERE path = 'general/locale/code'
   UNION ALL SELECT 'admin user locale', CONCAT(COUNT(*), ' users'), interface_locale
     FROM admin_user GROUP BY interface_locale"
   ```
   sportresponse test gave:
   ```
   setting            scope      value
   store theme        default:0  Aonach/hyva
   checkout fallback  default:0  frontend/Aonach/checkout
   store locale       default:0  en_IE
   admin user locale  8 users    en_US
   ```
   Reading it:
   - `magento_themes`: every *store theme*, plus the *checkout fallback* without `frontend/`. Here that's `[Aonach/hyva, Aonach/checkout]`.
   - `static_content_locales`: every *store locale*, `default:0` plus any `websites:` or `stores:` rows. Here that's `en_IE`.
   - `static_content_locales_backend`: the *store locale* at `default:0` (the admin login page uses it) plus every *admin user locale*. Here that's `en_US en_IE`.
   - `magento_themes_backend`: the *admin theme*; no row means `Magento/backend`.
   - Rows at `websites:` or `stores:` scope are store views with their own theme or locale; include those values too.

2. **Set the site's config** in _hosts.yml_, on every host:
   ```yaml
   split_static_deployment: true                     # storefront and admin built separately
   static_deploy_options: --no-parent
   static_content_locales: en_IE                     # the store views' locale(s)
   magento_themes: [Aonach/hyva, Aonach/checkout]    # store view theme(s) + Hyvä checkout fallback
   static_content_locales_backend: en_US en_IE       # the store's default locale + every admin user's locale
   magento_themes_backend: [Magento/backend]         # the admin theme
   ```
   If the storefront and admin locales are the same single locale (e.g. everything is `en_US`), the simpler form does the same in one build:
   ```yaml
   static_content_locales: en_US
   magento_themes: [Aonach/hyva, Soundstore/soundstore, Magento/backend]
   static_deploy_options: --no-parent
   ```
   `--no-parent` stops parent themes (Magento/blank, Magento/luma, Hyva/default, ...) being built as themes of their own. They are still used as fallback sources. A live theme that isn't listed serves 404s for its CSS/JS.

3. **Deploy to test** (PR into `test`) and check:
   - `pub/static` contains only the listed themes and locales;
   - the storefront, a checkout page (the fallback theme), the **admin login page** and a logged-in admin page all load their CSS/JS;
   - the deploy log shows the `setup:static-content:deploy` command(s) with the themes and locales above.

4. **Production:** run step 1 on the production database, give the production host the same settings, and ship it with the usual `test` → `main` PR. No admin user changes are needed.

## Related links:

https://deployer.org

https://help.github.com/en/github/automating-your-workflow-with-github-actions
