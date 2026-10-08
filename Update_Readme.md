# cPanel upload: registration PIN and profile-photo policy

Upload these files to the matching paths in the Laravel `attendance` project:

- `app/Http/Controllers/CompanyController.php`
- `app/Http/Controllers/MemberRegistrationController.php`
- `app/Models/User.php`
- `routes/api.php`
- `database/migrations/2026_07_23_180000_add_attendance_pin_and_profile_photo_to_users_table.php`

Then, from the Laravel project directory on cPanel, run:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan route:list --path=api
```

The route list must include `GET|HEAD api/invites/{token}/registration`.

Do not upload the local `vendor` directory. The new migration adds nullable columns, so existing accounts remain valid. New registrations require a four-digit attendance PIN. New company-admin registrations also require a profile photo; member photos remain controlled by each company's existing **Require selfie to join** setting.
