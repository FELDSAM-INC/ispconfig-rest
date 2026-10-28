# Reporting security issues

Use [GitHub private vulnerability reporting](https://github.com/FELDSAM-INC/ispconfig-rest/security/advisories/new).
If private reporting is unavailable, contact FELDSAM through your existing private support channel.
Do not put credentials, exploit details or raw scanner reports in public issues, PR comments or attachments.

Include the affected version/commit, impact, required permissions, and a minimal reproduction with synthetic data.
Keep reports for the separate hosting extension in its own repository's private reporting channel.

Install the latest stable release and its locked dependencies. Old releases and local modifications need separate
assessment; a passing scan is not a guarantee that a deployment is secure. See [Security checks](docs/security-checks.md)
for the automated coverage and local development commands.
