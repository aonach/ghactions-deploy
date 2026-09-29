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

Miss one and that page loads with no CSS/JS. Nothing errors and the deploy succeeds (sportresponse test lost its admin login page this way). The simple answer is **one locale per site**: the store's default locale, with every admin user set to it. In practice all our sites are English and the different locales are leftover defaults (`en_US` is Magento's default; `en_IE`/`en_GB` is where we are). A genuinely multi-language site lists its real locales instead.

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
   - `magento_themes`: every *store theme*, plus the *checkout fallback* without `frontend/`, plus the *admin theme* (no row means `Magento/backend`). Here that's `[Aonach/hyva, Aonach/checkout, Magento/backend]`.
   - `static_content_locales`: the *store locale* at `default:0`.
   - Rows at `websites:` or `stores:` scope are store views with their own theme or locale; include those values too.
   - Any *admin user locale* other than the store locale needs step 3.

2. **Pick the site's locale:** the store's default locale (`en_IE` for sportresponse). Keep the store's locale rather than switching the store to `en_US`: it sets date and number formats for customers. The English wording is the same either way.

3. **Switch the admin users to that locale.** Do this *before* step 4, while the admin is still built in both locales, so nobody is ever left on a locale that isn't built:
   ```sql
   UPDATE admin_user SET interface_locale = 'en_IE' WHERE interface_locale <> 'en_IE';
   ```
   In production mode Magento only offers built locales when creating an admin user, so new users can then only pick that locale.

4. **Set the site's config** in _hosts.yml_ for that host:
   ```yaml
   static_content_locales: en_IE                                   # the store's default locale
   magento_themes: [Aonach/hyva, Aonach/checkout, Magento/backend]  # store view theme(s), Hyvä checkout fallback, admin theme
   static_deploy_options: --no-parent
   ```
   List every theme assigned to a store view, the Hyvä checkout fallback theme, and the admin theme (`Magento/backend`, unless the site uses another admin theme). `--no-parent` stops parent themes being built as themes of their own. They are still used as fallback sources.

5. **Deploy to test** (PR into `test`) and check:
   - `pub/static` contains only the listed themes and locale;
   - the storefront, a checkout page (the fallback theme), the **admin login page** and a logged-in admin page all load their CSS/JS;
   - the deploy log shows a single `setup:static-content:deploy` with the themes and locale above.

6. **Production:** repeat steps 1-3 on the production database, add the same settings to the production host, and ship it with the usual `test` → `main` PR.

## Related links:

https://deployer.org

https://help.github.com/en/github/automating-your-workflow-with-github-actions
