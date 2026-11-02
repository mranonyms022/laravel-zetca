# Publishing the package

Two ways to distribute it. Pick one before the first release, because it decides the license text and
where customers point Composer.

| | Public (Packagist) | Private / commercial |
|---|---|---|
| Who can install | Anyone | Only customers you give access to |
| Where | GitHub public repo + packagist.org | Private GitHub repo + VCS repository, Private Packagist, or Satis |
| License | Open-source (e.g. MIT) | Proprietary EULA |
| Cost | Free | Private Packagist is paid; private GitHub + VCS is free |

## Before the first release

- [ ] Decide public vs private and set `"license"` in `composer.json` (currently `proprietary`)
- [ ] Add a `LICENSE` file
- [ ] CI is green on GitHub Actions for every Laravel/PHP cell you list as supported
- [ ] `composer validate --strict` passes
- [ ] `CHANGELOG.md` updated
- [ ] Version tag chosen (see below)

## Versioning

Use semantic versions with a leading `v`. Until the package can issue invoices end-to-end, only publish
**pre-releases** so nobody installs it as production-ready:

| Stage | Tag |
|---|---|
| Foundation (now) | `v0.1.0-alpha.1` |
| Devices (M3) | `v0.2.0-alpha.1` |
| End-to-end sandbox (M6) | `v0.6.0-beta.1` |
| First production release | `v1.0.0` |

Composer ignores pre-release tags unless a user asks for them (`minimum-stability` or `:@alpha`).

## Option A: public on Packagist

1. Create an empty GitHub repository, e.g. `codingtriplets/laravel-zatca`.
2. Push:
   ```bash
   cd D:/nirbhay/packages/laravel-zatca
   git add . && git commit -m "Package foundation (M2)"
   git branch -M main
   git remote add origin https://github.com/codingtriplets/laravel-zatca.git
   git push -u origin main
   git tag v0.1.0-alpha.1 && git push origin v0.1.0-alpha.1
   ```
3. Sign in at <https://packagist.org> with GitHub, choose **Submit**, paste the repository URL.
4. Enable auto-update: Packagist profile → *Show API token*, then in GitHub → Settings → Webhooks, add
   `https://packagist.org/api/github?username=<packagist-user>` with that token (Packagist's GitHub
   integration can also set this up automatically).
5. Customers install with `composer require codingtriplets/laravel-zatca:^0.1@alpha`.

## Option B: private distribution

**Private GitHub repository (free):**

1. Push to a private repository as in Option A, steps 1–2.
2. Give each customer read access (or a deploy token).
3. Customers add to their `composer.json`:
   ```json
   "repositories": [{ "type": "vcs", "url": "https://github.com/codingtriplets/laravel-zatca" }]
   ```
   and authenticate Composer: `composer config --global github-oauth.github.com <token>`.
4. `composer require codingtriplets/laravel-zatca:^0.1@alpha`.

**Private Packagist (paid):** connect the private repository, create a customer, and give them the
repository URL and credentials Private Packagist generates. Best when selling licenses to many
customers (per-customer access, download stats, license expiry).

## After each release

- Tag the version, push the tag.
- Check that the CI run for the tag is green.
- Update `CHANGELOG.md` and the "Supported versions" table in `README.md` from the CI results.
