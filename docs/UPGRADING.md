# Upgrading

## Table of contents

- [Unreleased (S0 scaffold, S1 core)](#unreleased-s0-scaffold-s1-core)

## Unreleased (S0 scaffold, S1 core)

No release yet. The first tag follows [ROADMAP.md](ROADMAP.md). Every schema change in 1.x is additive and will be
listed here with its DDL.

S1 adds the table `<prefix>api_token` (default `nowo_api_token`) once the bundle is enabled: run
`doctrine:migrations:diff` and compare with the reference DDL in [INSTALLATION.md](INSTALLATION.md#reference-ddl-doctrinetable_prefix-nowo_).
Enabling the bundle now needs `owner.class` or `owner.resolver`, and DoctrineBundle.
