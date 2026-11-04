## What and why

<!-- What does this change, and which problem does it solve? Link the issue, e.g. "Closes #12". -->

## How I tested it

- Network(s):
- Sandbox or live:
- Checkout type (block / classic):
- PHP / WordPress / WooCommerce versions:

<!-- For new networks: which cases did you try (success, cancelled, insufficient funds or wrong PIN, timeout, callback not reaching the site)? Add screenshots for anything buyers or merchants see. -->

## Checklist

- [ ] `php tests/run.php` passes, with new tests where it makes sense
- [ ] No keys, tokens or real phone numbers in code, tests, screenshots or logs
- [ ] Output is escaped, input is sanitised, new strings use the `sokolipa-checkout` text domain
- [ ] Still compatible with PHP 7.4 and adds no runtime dependencies
- [ ] `readme.txt` updated (changelog, and "External services" for a new network)
