## Summary

<!-- What does this change and why? -->

## Release

The PR title must follow [Conventional Commits](https://www.conventionalcommits.org/). It decides the next version when the PR is merged:

- `feat!: ...`, `fix!: ...` or a `BREAKING CHANGE:` line in this description: major
- `feat: ...`: minor
- anything else: patch

Add the `skip-release` label to merge without releasing.

## Checklist

- [ ] Tests added or updated (`composer test-coverage` stays at 100%)
- [ ] `composer analyse` and `composer format-check` pass
- [ ] README, UPGRADE and CHANGELOG updated when behavior changes
