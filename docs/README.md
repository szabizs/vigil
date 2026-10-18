# Vigil agent documentation

`materom/vigil` is the Laravel client ("agent") for Vigil, Materom's self-hosted monitoring product. You install it in each monitored Laravel application. It collects telemetry and ships it to the product.

This documentation covers the package only. For the monitoring product itself (dashboards, projects, environments, alerting, user management), see the product documentation.

| Page | What it covers |
| --- | --- |
| [01 - Overview](01-overview.md) | What the agent does, how data flows, what is captured and what is not, performance characteristics. |
| [02 - Installation](02-installation.md) | Requirements, `composer require`, the minimal `.env`, verifying with `vigil:status`. |
| [03 - Running the relay](03-running-the-relay.md) | `vigil:agent` in depth: spool, guarantees, deploys, Supervisor, systemd, Docker. |
| [04 - Configuration](04-configuration.md) | Every `config/vigil.php` key, its environment variable, default and when to change it. |
| [05 - Usage in code](05-usage-in-code.md) | The `Vigil` facade: users, rejecting and redacting records, sampling, reporting, long-running processes. |
| [06 - Deployments](06-deployments.md) | `vigil:deploy` in CI and deploy tools. |
| [07 - Migrating from Nightwatch](07-migrating-from-nightwatch.md) | Variable mapping, renames, removed pieces. |
| [08 - Troubleshooting](08-troubleshooting.md) | Symptom, cause and fix table, matched to the real log lines. |

Other files in the package: [`../README.md`](../README.md) (landing page), [`../CHANGELOG.md`](../CHANGELOG.md).
