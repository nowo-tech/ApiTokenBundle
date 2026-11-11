# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.1.0](#to-010)

## Unreleased

## To 0.1.0

First release (S0 scaffold, S1 core).

No release yet. The first tag follows [ROADMAP.md](ROADMAP.md). Every schema change in 1.x is additive and will be
listed here with its DDL.

S1 adds the table `<prefix>api_token` (default `nowo_api_token`) once the bundle is enabled: run
`doctrine:migrations:diff` and compare with the reference DDL in [INSTALLATION.md](INSTALLATION.md#reference-ddl-doctrinetable_prefix-nowo_).
Enabling the bundle now needs `owner.class` or `owner.resolver`, and DoctrineBundle.

Owner status: with the default status, the container refuses to build unless one of your firewalls has a user checker
other than Symfony's stock one, or you set `owner.user_checker` (a checker service id, or `none` to let the resolver
alone decide), or `owner.status` ([CONFIGURATION.md](CONFIGURATION.md#owner-status)).

`IssuedToken::$secret` is private: read it with `IssuedToken::secret()`.

**Changes that invalidate every token** (plan them as a forced re-issue): a new `hash.pepper`, going from
`hash.version: 2` back to 1, or a new `token_prefix` ([CONFIGURATION.md](CONFIGURATION.md#changes-that-invalidate-every-token)).
