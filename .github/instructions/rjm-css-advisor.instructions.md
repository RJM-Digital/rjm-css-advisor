---
description: "RJM CSS Advisor maintenance rules for versioning and packaging."
applyTo: "**/*"
---

- When modifying any file in this repo, always update both the plugin header `Version:` line and the `RJM_CSS_ADVISOR_VERSION` constant in `rjm-css-advisor.php`.
- After any plugin change, package the plugin into a WordPress-installable zip using `scripts/package-plugin.sh`.
- Prefer versioned archive names that include the current plugin version.
- Keep changes additive and avoid renaming the plugin root, because the archive must import cleanly into WordPress.
- Releases are published via GitHub Releases (tag `v{version}`) with the packaged zip attached; the bundled update checker polls these for WP auto-updates.
