# Security policy

## Supported versions

| Version | Bug fixes | Security fixes |
| --- | --- | --- |
| Latest minor (1.x) | yes | yes |
| Previous minor | no | for 6 months after the next minor is released |
| 0.x | no | no |

A fix is released as a patch version of each supported minor.

## Reporting a vulnerability

Please do not open a public issue, pull request or discussion for a security problem.

Report it privately through GitHub: on [prism-orm/prism](https://github.com/prism-orm/prism), open the **Security** tab, then **Report a vulnerability**. Only the maintainers can read the report.

Please include:

- the affected package (`prism-orm/dbal`, `prism-orm/orm`, `prism-orm/bundle`…) and its version;
- the database and its version, when it matters;
- a description of the problem and of its impact;
- the steps or the code to reproduce it.

## What happens next

- You receive an answer within 7 days, confirming the report or asking for details.
- Once the problem is confirmed, a fix is prepared in a private fork and released for every supported version.
- A security advisory is published on GitHub with the fixed versions. You are credited in it, unless you prefer not to be.

## Scope

Typical security issues in an ORM include SQL injection through an API that should bind its values, a tenant filter that lets a query read or write the rows of another tenant, and an audit log that can be bypassed or rewritten. They are all in scope.

The demo application ([prism-orm/demo](https://github.com/prism-orm/demo)) is an example, not a product: report a problem in it as a regular issue, unless it comes from Prism itself.
