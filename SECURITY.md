# Security policy

Sokolipa Checkout handles payment confirmations and operator credentials, so security reports are taken seriously and handled privately.

## Supported versions

Security fixes go into the latest release. Please make sure you can reproduce an issue on the latest version before reporting it.

## Reporting a vulnerability

**Please do not open a public issue, discussion or pull request for a vulnerability.**

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**, or go straight to [the private report form](https://github.com/Dickytush/sokolipa-checkout/security/advisories/new).

Please include:

- the plugin version, and the WordPress, WooCommerce and PHP versions
- which network and checkout type (block or classic) are involved, if any
- a description of the problem and its impact
- steps to reproduce, or a proof of concept against a test site

Do not include real API keys, tokens or customers' personal data in a report.

We aim to acknowledge reports within a few working days, keep you informed while a fix is prepared, and credit you in the release notes unless you would rather stay anonymous. Please give us reasonable time to release a fix before disclosing the issue publicly.

## Scope

In scope: how orders are confirmed and marked paid, the REST routes under `/wp-json/sokolipa/v1/`, storage and logging of credentials, and cross-site scripting, request forgery or injection anywhere in the plugin.

Out of scope: vulnerabilities in the operators' own platforms (report those to the operator), problems that need an already compromised administrator account, and issues in WordPress, WooCommerce or other plugins (report those to their maintainers).
