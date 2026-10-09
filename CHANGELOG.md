# Changelog

Canonical URL (`canonicalUrl`), an OJS generic plugin. Two packages carry the same version number and behave the same:
one for OJS 3.3, one for OJS 3.4 and 3.5.

## 1.3.2.5 — 2026-10-09
- The sitemap cache keeps at most 8 files per journal (or twice the number of journal languages, if that is more); the
  least recently used file is removed first.
- Spanish translation of the plugin name, description and settings page.
- `LICENSE` file (GNU GPL v3) added to the packages.
- README: a "Sitemap" section, the OJS and PHP versions the plugin was tested on, "Upgrading OJS from 3.3 to 3.4 or
  3.5", "External requests" and "Support".

## 1.3.2.0 — 2026-10-04
- One canonical link per page: when the theme, another plugin or the journal's custom tags already print
  `rel="canonical"`, the plugin leaves out its own link (and its `Link` header when the two addresses differ) and says so
  on the settings page.
- Fixed: the home page variants `/<journal>/index` and `/<journal>/index/index` now point to `/<journal>`.
- A cached sitemap is sent with `Cache-Control: public, max-age=3600` and without a session cookie.
- Settings page: warns when the cache folder cannot be written; also opens at site level.
- Fixed: the host name warning on the settings page never appeared; it now compares the name in use with `base_url`.

## 1.3.1.1 — 2026-10-04
- Fixed on OJS 3.4 and 3.5 as well: no second canonical link and no `Link` header on the page of an outdated article
  version (`/article/view/N/version/P`); OJS itself already marks that page `noindex` and points to the current version.
- `README.md` and `README_TR.md` added to the packages.

## 1.3.1.0 — 2026-10-02 (OJS 3.3 only)
- Fixed: two canonical links on the page of an outdated article version.

## 1.3.0.0 — 2026-09-28
- Sitemap cache: a valid cached sitemap is answered before OJS builds it again; rebuilt when articles or issues change,
  when the settings are saved, and after a configurable lifetime (1–720 hours, default 24).
- Settings page per journal (canonical address, sitemap, excluded from indexing). Everything is on by default.
- Citation format downloads (`citationstylelanguage`) get an `X-Robots-Tag: noindex` header.

## 1.2.0.0 — 2026-09-27
- First release for OJS 3.3.
- Thin pages (search, login, user pages, notifications) get `noindex` instead of a canonical address.
- Articles and issues use their custom URL path in the canonical address; error pages get none.
- HTTP `Link: rel="canonical"` header on article and galley viewer pages.
- Sitemap dates are read in two queries.
- OJS 3.5: `hreflang` alternates point to the canonical addresses.

## 1.1.0.0 — 2026-09-27 (OJS 3.4)
- Sitemap filter: galley viewer addresses, login, registration, search and `/issue/current` are removed; articles and
  issues get `lastmod`.

## 1.0.0.0 — 2026-09-05 (OJS 3.4)
- `<link rel="canonical">` on reader pages.
