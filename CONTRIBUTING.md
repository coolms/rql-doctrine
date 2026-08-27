# Contributing to `coolms/rql-doctrine`

Thank you for taking the time. This file is the whole of the process -- if
something here is unclear or wrong, that is a bug in this file and worth an
issue of its own.

## Releases leave on Tuesdays

`develop` is the integration branch. Merge there when your work is green.

**A release train leaves on Tuesday.** Whatever is in `develop` and passing goes
out. Nothing jumps the train because it feels urgent, and nothing holds the
train because it is not finished -- it catches the next one.

The one exception is a **security fix**, which ships when it is ready, on any
day.

If your pull request misses a Tuesday, nothing is wrong. It goes out on the
next one.

## Versioning is strict semver, and majors are planned

| Change | Version |
|---|---|
| a fix | patch -- `1.2.3` to `1.2.4` |
| a backward-compatible addition | minor -- `1.2.3` to `1.3.0` |
| a break | major -- and **not on the Tuesday it becomes ready** |

Majors are planned, not triggered. A breaking change that is finished waits for
a planned major; it does not create one.

### This package versions entirely on its own

`coolms/rql-doctrine` is a **standalone library**. It does not require `coolms/core`
and it is useful without CoolMS, so its version number answers to its own API
and nothing else.

⚠️ **That includes the major.** `coolms/rql-doctrine 2.0.0` means *this package* broke
something. It never moves for a reason external to this repository.

This is worth stating because the sibling packages published from the same
project do the opposite. Those that require `coolms/core` -- the CoolMS platform
packages -- share a major as a generation marker, so that a set of them is known
to work together. Forcing that number onto a library whose own API did not
change would make it meaningless for the people who installed the library on its
own merits. If this package ever grows a dependency on `coolms/core`, it stops
being standalone and joins that set; until then it does not.

### What "compatible" guarantees, precisely

**The latest release of each major works together.** Within a major, a minor may
raise a dependency's floor (`^2.0` to `^2.3`) but never cross into a different
major.

The constraint in `composer.json` is the real guarantee, and CI proves it by
running the suite against both the highest and the **lowest** dependency
resolution. A floor that drifted below what the code actually needs fails the
build rather than waiting to fatal in somebody's application.

## A break arrives as a deprecation first

**This is the part that makes the version numbers mean anything.**

A break is introduced as a **deprecation in a minor release**: the old path keeps
working, emits a deprecation notice, and the changelog names the replacement. It
is removed in the **next planned major**.

So a change that would break callers is two pull requests, usually months apart:

1. Add the replacement. Keep the old path working. Emit a deprecation notice.
   Name the replacement in `CHANGELOG.md`. This ships in a minor.
2. Remove the old path. This ships in the next planned major.

Without the deprecation window a Tuesday cadence would just batch the same
churn -- two breaking changes a fortnight apart still produce 2.0 and then 3.0.
The window is the mechanism; the calendar is not.

## Write the changelog in the same commit as the work

`CHANGELOG.md` is written **at merge time, in the same commit as the change** --
never reconstructed on a Tuesday from a list of merges. Unreleased work lives
under a `## Unreleased` heading; the release renames it and adds a date.

Say what changed and, for anything a caller can notice, why. A changelog entry
that names a replacement is the difference between a deprecation someone can act
on and a notice they will suppress.

A pull request that changes behaviour and does not touch `CHANGELOG.md` is
incomplete. That is not bureaucracy: the changelog is the only place a
deprecation reaches the person who has to act on it.

## Before you open a pull request

```bash
composer install
vendor/bin/phpunit
```

Match the surrounding code -- its comment density, its naming, its idiom. Tests
are expected for anything a caller can observe; a test that would still pass if
the change were reverted is not one.
