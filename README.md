# BrgyTrace (PHP + MySQL + Bootstrap 5)

## Install with XAMPP
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy the `brgytrace` folder to `C:\xampp\htdocs\`.
3. Open **http://localhost/phpmyadmin** → *Import* → choose `database.sql` → *Go*.
   (This creates the `brgytrace_legacy` database, the tables and sample data.)
4. Open **http://localhost/brgytrace/**

## Sign in (change these after the first login)
| Role      | Username    | Password       |
|-----------|-------------|----------------|
| Secretary | `secretary` | `secretary123` |
| Admin     | `admin`     | `admin123`     |

## Settings
- Database login and barangay name: `includes/config.php` (XAMPP default is user `root`, no password).
- Certificate types and fees: `CERT_TYPES` in `includes/config.php`.
- Fee for a Barangay Clearance with a pending case: `PENDING_CASE_CLEARANCE_FEE`.
- Login lockout limits can be changed by an admin from the Settings page.

## Pages
| File | What it does |
|------|--------------|
| `login.php`, `logout.php` | Sign in (lockout limits configurable in admin Settings), sign out |
| `dashboard.php` | Secretary certificate desk or admin overview, depending on role |
| `issue.php` → `receipt.php` → `print.php` | Request + blotter check → record OR/cash ticket (used once only) → print |
| `residents.php`, `resident_add.php`, `resident_view.php` | Search, add, view residents |
| `blotter.php`, `blotter_add.php`, `blotter_case.php` | Blotter list, new entry, update case status |
| `certificates.php` | All certificates with filters |
| `audit.php`, `users.php`, `settings.php` | Audit log, user management and login protection settings (admin only) |
| `account.php`, `help.php` | Change password, how-to |

## Notes
- All database queries use PDO prepared statements; forms use a CSRF token; passwords are hashed.
- Bootstrap is included locally in `assets/vendor/`, so the system works without internet. Only the Inter font loads from Google Fonts (a system font is used if offline).
- The weekly chart counts certificates by the day they were issued.
