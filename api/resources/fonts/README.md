<!-- SPDX-License-Identifier: Apache-2.0 -->

# Bundled fonts

Both files ship with the application rather than being fetched at runtime.
Constraint C1 forbids a third-party runtime dependency, and a webfont pulled
from a CDN is exactly that: it would put a request to somebody else's server on
the critical path of a page that has to render on a throttled connection, and it
would leak every reader's IP to a third party.

| File | Family | Licence | Upstream |
|---|---|---|---|
| `instrument-serif-latin.woff2` | Instrument Serif 400 | [OFL-1.1](https://openfontlicense.org/) | [Instrument Serif](https://fonts.google.com/specimen/Instrument+Serif) |
| `dm-sans-latin.woff2` | DM Sans, variable 300–500 | [OFL-1.1](https://openfontlicense.org/) | [DM Sans](https://fonts.google.com/specimen/DM+Sans) |

OFL-1.1 is OSI-approved, so both satisfy C1's requirement that every dependency
be openly licensed. 52 KB for the pair.

**Latin subsets only, deliberately.** Neither family covers Arabic, and neither
is asked to: the stylesheet lists them ahead of a system Arabic stack, so Latin
text gets the intended typography and Arabic text is rendered by whatever the
device ships — which is a better Arabic face than either of these would be, and
costs nothing to download. The same reasoning is recorded for the reporter app,
where the scaffold's own webfont had no Arabic coverage either.
