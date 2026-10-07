# Contributing

Contributions are **welcome** and will be fully **credited**.

Please read and understand the contribution guide before creating an issue or pull request.

## Etiquette

This project is open source, and as such, the maintainers give their free time to build and maintain the source code
held within. They make the code freely available in the hope that it will be of use to other developers. It would be
extremely unfair for them to suffer abuse or anger for their hard work.

Please be considerate towards maintainers when raising issues or presenting pull requests. Let's show the
world that developers are civilized and selfless people.

It's the duty of the maintainer to ensure that all submissions to the project are of sufficient
quality to benefit the project. Many developers have different skillsets, strengths, and weaknesses. Respect the maintainer's decision, and do not be upset or abusive if your submission is not used.

## Viability

When requesting or submitting new features, first consider whether it might be useful to others. Open
source projects are used by many developers, who may have entirely different needs to your own. Think about
whether or not your feature is likely to be used by other users of the project.

## Procedure

Before filing an issue:

- Attempt to replicate the problem, to ensure that it wasn't a coincidental incident.
- Check to make sure your feature suggestion isn't already present within the project.
- Check the pull requests tab to ensure that the bug doesn't have a fix in progress.
- Check the pull requests tab to ensure that the feature isn't already in progress.

Before submitting a pull request:

- Check the codebase to ensure that your feature doesn't already exist.
- Check the pull requests to ensure that another person hasn't already submitted the feature or fix.

Security vulnerabilities are never reported through issues or pull requests: see [SECURITY.md](SECURITY.md).

## Requirements

- **Coding style**: PSR-12 as enforced by [Laravel Pint](https://laravel.com/docs/pint). Run `composer format` before committing; CI runs `composer format-check`.
- **Static analysis**: `composer analyse` (PHPStan with Larastan, level max) must pass without a baseline.
- **Add tests!** `composer test-coverage` must stay at 100% line coverage. Bug fixes start with a test that fails without the fix.
- **Pull request titles** follow [Conventional Commits](https://www.conventionalcommits.org/); the title decides the next release version (see the README).
- **Document any change in behaviour**: keep `README.md`, `UPGRADE.md` and `CHANGELOG.md` up to date.
- **Consider our release cycle**: we follow [SemVer v2.0.0](https://semver.org/). Randomly breaking public APIs is not an option.
- **One pull request per feature**: if you want to do more than one thing, send multiple pull requests.
- **Send coherent history**: make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](https://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.

## Running the checks

```bash
composer test
composer test-coverage
composer analyse
composer format-check
```

**Happy coding**!
