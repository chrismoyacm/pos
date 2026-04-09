# Vendor JS (offline)

This folder stores third-party JavaScript libraries used by the POS.

Goal:
- Keep the system working even without internet.
- Avoid runtime dependency on CDNs.

## Current libraries

- `jquery-3.7.1.min.js` (used by modules in `public/assets/js/*.js`)

## Rules

- Always save libraries in this folder.
- Prefer pinned versions in file names, for example: `library-x.y.z.min.js`.
- Do not reference CDN URLs directly in views/layouts.
- Load vendor files from local paths in `public/index.php` or the corresponding view.

## How to add or update a library

1. Download the library file from the official source.
2. Save it into this folder with the version in the filename.
3. Update the `<script src="...">` reference to local path.
4. If possible, use cache busting with `assetVersion(...)` in PHP.
5. Test the target module with internet disconnected.

## Example include (local)

```php
<script src="assets/js/vendor/jquery-3.7.1.min.js?v=<?php echo urlencode(assetVersion('assets/js/vendor/jquery-3.7.1.min.js')); ?>"></script>
```

## Quick verification

Run this command to check for common CDN references:

```powershell
Get-ChildItem -Recurse -File -Filter *.php |
  Select-String -Pattern 'code\.jquery\.com|unpkg|jsdelivr|cdnjs' |
  Select-Object Path,LineNumber,Line
```
