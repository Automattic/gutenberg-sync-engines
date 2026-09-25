# Contributing

This plugin is experimental. Settings, filters and stored data can
change or go away between versions.

The [Core team](https://make.wordpress.org/core/) sponsors this
repository, and the [maintainers](../README.md#maintainers) review every
pull request. Setup and tests are in the
[README](../README.md#development).

## Issues

Use the **Report a problem or suggest an idea** form and describe it in
your own words. For a security problem, follow [SECURITY.md](SECURITY.md)
instead.

| Label | Meaning |
| --- | --- |
| `agent:needs shaping` | Filed, not looked into yet. |
| `agent:ready` | Ready for someone to work on. Comment to be assigned. |
| `agent:parked` | Waiting on a decision. |
| `agent:in progress` | The assignee is working on it. |

## Pull requests

1. Branch off `trunk` and keep it to one change.
2. Follow the
   [WordPress coding standards](https://developer.wordpress.org/coding-standards/)
   and run `composer lint`, `npm run lint:js`, `npm run typecheck`,
   `npm run test:js` and `npm run test:php`.
3. Mark new code `@since n.e.x.t`.
4. Add to `CHANGELOG.md` only for a new feature, setting or extension
   point, or behavior that changed or went away.
5. If you used AI tools, say which and for what, per the
   [WordPress AI guidelines](https://make.wordpress.org/ai/handbook/ai-guidelines/).

Everyone follows the
[WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/),
and contributions are accepted under [GPL-2.0-or-later](../LICENSE).
