# Changelog

All notable changes to `coolms/rql-doctrine` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

⚠️ Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased

Contributor documentation only: `CONTRIBUTING.md`, describing the Tuesday
release train, the deprecation window, and how this package's version number
relates to the CoolMS platform packages.

No code changed, so **this will not be released on its own.** It rides out with
the next change that is worth a version number -- publishing an empty patch to
ship a documentation file would contradict the policy the file describes.

## 1.0.1 - 2026-08-17

### Changed -- ⚠️ this was a breaking change, and it should not have been a patch

The root namespace was renamed from `CoolMS\RqlDoctrine\` to
`CoolMS\Rql\Doctrine\`, and the PSR-4 autoload prefix with it. Every `use`
statement referring to this package had to change.

That is a major-version change and it went out as a patch, four days after the
first release. It is recorded here rather than quietly corrected, because it is
precisely the failure the current release policy exists to prevent: a break
arrives as a **deprecation in a minor**, naming its replacement, and is removed
in the next planned major.

**Migration, if you are still on 1.0.0:** replace `CoolMS\RqlDoctrine\` with
`CoolMS\Rql\Doctrine\` throughout. There is no compatibility shim; adding one
now would be a new API surface to support for the sake of a four-day window.

Also dropped the VCS repository entry and its readme instructions, now that
Packagist resolves the package by name.

## 1.0.0 - 2026-08-13

First release. The Doctrine ORM translator for `coolms/rql`: walks the RQL AST
onto a `QueryBuilder`, with portable JSON filtering across PostgreSQL, MySQL,
MariaDB, SQLite, SQL Server and Oracle.
