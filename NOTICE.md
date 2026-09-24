# Notices

AI Sooq Connector
Copyright (C) 2026 AI Sooq

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 2 of the License, or (at your option) any later
version. The full text is in [LICENSE](LICENSE).

## Courier names and marks

This plugin talks to courier companies operating in Bangladesh — Steadfast,
Pathao, RedX, eCourier, Paperfly, Sundarban, CarryBee, ParcelDex and any others
the platform adds — and names them in its admin screens so an operator can tell
one parcel's carrier from another's.

Those names are the trademarks of their respective owners. They are used here
**nominatively**: to identify the carrier a parcel was sent with, and nothing
more. No affiliation, sponsorship or endorsement is claimed or implied.

**No courier artwork is bundled with this plugin.** Earlier versions shipped the
carriers' logo files. They were removed in 2.11.0: the logos are the companies'
own trademarked artwork, and redistributing them inside a GPL-licensed zip put a
question on every store that installed the plugin. Couriers are now shown as a
monogram tile — the carrier's initials on its own colours — which carries the
same information at a glance and is this project's own work.

## Third-party software

It requires WooCommerce (GPL-3.0) and WordPress (GPL-2.0-or-later) at runtime,
and uses Action Scheduler (GPL-3.0) as provided by WooCommerce. None of these
are redistributed here.

### Phosphor Icons

`includes/class-aisooq-icons.php` bundles the path data of forty-odd glyphs from
[Phosphor Icons](https://phosphoricons.com) 2.1.1, which is MIT licensed:

> Copyright (c) 2023 Phosphor Icons
>
> Permission is hereby granted, free of charge, to any person obtaining a copy
> of this software and associated documentation files (the "Software"), to deal
> in the Software without restriction, including without limitation the rights
> to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
> copies of the Software, and to permit persons to whom the Software is
> furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all
> copies or substantial portions of the Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
> IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
> FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
> AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
> LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
> OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
> SOFTWARE.

MIT is GPL-compatible, so redistributing these paths inside this GPL-licensed
plugin is unambiguous — unlike the courier artwork above. They are bundled
rather than fetched from a CDN so that no admin page load depends on a
third-party host; that reasoning is in the icon file's own header.
