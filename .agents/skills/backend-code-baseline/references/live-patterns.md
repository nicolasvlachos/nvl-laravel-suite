# Live Code Anchors

Use these files as current-pattern anchors:

- `app/Lib/Inertia/InertiaController.php`
- `app/Lib/Inertia/InertiaPage.php`
- `Modules/Vendors/app/Http/Controllers/VendorsController.php`
- `Modules/Vendors/app/Actions/Vendor/ListVendorsAction.php`
- `Modules/Auth/app/Http/Controllers/AuthController.php`
- `Modules/Auth/app/Services/AuthenticationService.php`

Quick inventory command:
```bash
rg --files Modules/<Module>/app | rg '/Actions/|/Data/|/Http/Controllers/|/Models/|/Traits/|/Services/'
```

Cross-stack contract anchors (Core Services pages):
- `resources/js/services/core/hooks/use-core.ts`
- `resources/js/services/core/utils/create-config.ts`
- `resources/js/pages/admin/vendors/pages/configs/vendors.index.config.ts`
