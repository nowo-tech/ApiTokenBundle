# Templates

The account and operator pages (S3, contract §8) are Twig templates in the `@NowoApiTokenBundle` namespace, built on
UiKit's page scaffolds (REQ-UI-003). They ship no CSS and no JavaScript of their own: restyle through
`nowo_ui_kit.theme` (slots), not by copying markup.

## Table of contents

- [Pages](#pages)
- [Partials](#partials)
- [Overriding](#overriding)
- [Scripts](#scripts)

## Pages

| Template | Scaffold | Variables | Blocks filled |
| --- | --- | --- | --- |
| `@NowoApiTokenBundle/account/list.html.twig` | `@NowoUiKit/page/list.html.twig` | `page` (UiKit `ListPage`, rows `Presentation\View\TokenRow`), `issued` (`IssuedToken`, only in the POST answer that created it), `secret`, `created_template`, `list_href` | `nowo_ui_list_row` (cells), `nowo_ui_list_after` (the one-time secret dialog, opened on load), `nowo_ui_scripts` (adds UiKit's `secret-once` and `open-modal` runtimes) |
| `@NowoApiTokenBundle/account/new.html.twig` | `@NowoUiKit/page/form.html.twig` | `page` (UiKit `FormPage`) | `nowo_ui_form_body` (form-level errors above the fields) |
| `@NowoApiTokenBundle/account/revoke_all.html.twig` | `@NowoUiKit/page/form.html.twig` | `page` (UiKit `FormPage`) | `nowo_ui_form_body` |
| `@NowoApiTokenBundle/operator/list.html.twig` | `@NowoUiKit/page/list.html.twig` | `page` (UiKit `ListPage`), `search` (`FormView` of the POST leaked-token search; its password field is never printed back), `next_href` (keyset page) | `nowo_ui_list_filters` (adds the search after the GET filters), `nowo_ui_list_row`, `nowo_ui_list_after` (pager) |

The page contract test (`tests/Integration/PageContractTest.php`, UiKit `PageContractTestCase`) renders every page with
fixtures: no raw translation keys, no framework utility classes outside theme slots, action forms with their CSRF
fields.

## Partials

| Template | Used by | Notes |
| --- | --- | --- |
| `@NowoApiTokenBundle/_row.html.twig` | both lists | Cells of one token: (owner), name, *agents may delete* badge, handle `nwt_k7m2…` (the whole `nwt_k7m2q9x4ab3c` on the operator page), module levels, reach (*everything* or a count, never the entries), created, last used, expires (warning within 7 days) |
| `@NowoApiTokenBundle/account/_created.html.twig` | account list, dialog answer | The secret through UiKit's `secret_once` partial (printed **once**, as the text of one element; masked, *Show* / *Copy*, wiped on hide, dialog close and `pagehide`), the "only time you will see it" notice and a `curl` example with a `$TOKEN` placeholder |
| `@NowoApiTokenBundle/account/_new_fragment.html.twig` | UiKit remote form dialog | The *New token* form for an `X-Nowo-Dialog: 1` request |
| `@NowoApiTokenBundle/account/_revoke_all_fragment.html.twig` | UiKit remote form dialog | The *Revoke all* form for a dialog request |

## Overriding

- `web_ui.templates.list` and `web_ui.templates.created` replace the list page and the secret block.
- Any template: put a copy in `templates/bundles/NowoApiTokenBundle/…` of your application, or extend
  `@!NowoApiTokenBundle/<path>` and override one block.
- `web_ui.layout_template` sets the layout of the account pages (default: UiKit's admin layout).
- Keep the secret printed once and never move it into an attribute, a `data-*` value, a flash or the session.

## Scripts

The list page adds UiKit's opt-in `secret-once` (masks, reveals, copies and wipes the one-time secret) and `open-modal`
(opens the secret dialog after a create) runtimes to UiKit's default ones. It loads `secret-once` on every visit, so the
runtime also upgrades the secret of the *New token* remote dialog fragment when the dialog inserts it. Without
JavaScript the pages still work: the forms are full pages, the secret is plain selectable text and the buttons stay
hidden.

If you override `web_ui.templates.created`, keep the `secret_once` partial (never print the secret anywhere else, never
in an attribute), and if you override the list, keep `secret-once` in `script_names`:

```twig
{{ include(nowo_ui_partial('scripts', page.bundle), {script_names: nowo_ui_script_names()|merge(['secret-once', 'open-modal'])}, false) }}
```

**Caching.** The bundle sends `Cache-Control: no-store, private` on the page and on the dialog fragment (no script can
wipe a response replayed from the HTTP cache). On **Turbo Drive** hosts also print, in the layout of the account pages:

```twig
<meta name="turbo-cache-control" content="no-cache">
```

so Turbo never keeps a snapshot preview of the page (the runtime also wipes on `turbo:before-cache`).

`make test-e2e` renders the pages with PHPUnit and checks them in Chromium: the secret is in
`document.documentElement.outerHTML` exactly once after a create (page and dialog fragment), *Copy* puts it on the
clipboard, and it is gone after the dialog closes or on `pagehide`.

Front-end guidance (owner rule 2026-11-22): checked against Chrome Modern Web Guidance `dialog`, `copy-button`,
`forms`, `autofill-sign-in-form` and `passkey-reauthentication`: native `<dialog>` from UiKit, the re-auth password
field with `autocomplete="current-password"`, the copy control hidden until its script runs.
