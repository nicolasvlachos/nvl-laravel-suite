# Mail Interception Safety

Configuration and runtime hooks:
- `config/mail.php` (`mail.testing`)
- `app/Providers/AppServiceProvider.php` (`Mail::alwaysTo(...)` boot logic)

Environment keys:
- `MAIL_TESTING_ENABLED`
- `MAIL_TESTING_TO_ADDRESS`
- `MAIL_TESTING_TO_NAME`
- `MAIL_TESTING_RESPECT_ENV`
- `MAIL_TESTING_ENVIRONMENTS`

Operational checks:
```bash
rg -n "mail.testing|Mail::alwaysTo|MAIL_TESTING" config/mail.php app/Providers/AppServiceProvider.php
```
