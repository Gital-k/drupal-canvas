# Changesets

This directory holds pending release notes for the npm workspace packages in
`packages/*`. When a merge request changes one of those packages in a way that
should be released, add a changeset:

```sh
npx changeset
```

The command asks interactively which packages are affected, the semver bump
level for each, and a summary. The summary becomes the entry in the package's
`CHANGELOG.md`, so write it for consumers of the package.

If a merge request touches package files but should not trigger a release
(for example, test-only or documentation changes), add an empty changeset:

```sh
npx changeset --empty
```

Merge requests that do not touch `packages/*` never need a changeset.

For the release flow, see `docs/release-process.md`. General documentation:
https://github.com/changesets/changesets
