# Releasing

This is the step-by-step process for publishing a new version of Maintenance
Tracker. It assumes you work in a clone of the Forgejo repository with the
`forgejo` remote; adjust the remote name if yours is called `origin`.

The version number (for example `0.2.0`) appears in three places that must agree:

- `<version>` in `appinfo/info.xml`
- a `## [0.2.0] - YYYY-MM-DD` section in `CHANGELOG.md`
- the git tag `v0.2.0`

The release workflow refuses to publish if they differ.

## One-time setup

### 1. Sign tags with your SSH key

Git can sign tags with the SSH key you already use for pushing.

```bash
git config --global gpg.format ssh
git config --global user.signingkey ~/.ssh/id_ed25519.pub   # your public key
git config --global tag.gpgSign true                         # sign every annotated tag
```

To let `git tag -v` verify signatures locally, list trusted keys:

```bash
echo "alan@argentwolf.org $(cat ~/.ssh/id_ed25519.pub)" >> ~/.ssh/allowed_signers
git config --global gpg.ssh.allowedSignersFile ~/.ssh/allowed_signers
```

In Forgejo, open **Settings → SSH / GPG Keys** and make sure the same key is
listed and verified; Forgejo then shows tags and commits signed with it as
verified.

### 2. Get an App Store certificate and signing key

The Nextcloud App Store identifies the app by a certificate issued by Nextcloud.

1. Create a private key and a certificate request. Keep the key secret and back
   it up somewhere safe; it cannot be recovered.

   ```bash
   mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
   openssl req -nodes -newkey rsa:4096 -keyout maintenance_tracker.key \
     -out maintenance_tracker.csr -subj "/CN=maintenance_tracker"
   ```

2. Request the certificate by opening a pull request with the `.csr` file at
   <https://github.com/nextcloud/app-certificate-requests>, following that
   repository's instructions. You receive `maintenance_tracker.crt`.
3. Register the app at <https://apps.nextcloud.com/developer/apps/new>. The form
   asks for the certificate and for a signature of the app id:

   ```bash
   echo -n "maintenance_tracker" | openssl dgst -sha512 \
     -sign ~/.nextcloud/certificates/maintenance_tracker.key | openssl base64
   ```

See Nextcloud's [publishing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
and [code signing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html)
documentation for details.

### 3. Add the key to Forgejo

In the Forgejo repository open **Settings → Actions → Secrets → Add secret**:

- **Name:** `APP_SIGNING_KEY`
- **Value:** the complete contents of `maintenance_tracker.key`, including the
  `-----BEGIN PRIVATE KEY-----` and `-----END PRIVATE KEY-----` lines.

Without this secret the release workflow still publishes, but the archive has no
`.sig` file and cannot be uploaded to the App Store.

The workflow creates the Forgejo release with the job's built-in token. If that
fails with a permission error (`403`), create an access token under
**your user Settings → Applications** with *repository: read and write*
permission, and add it as a second secret named `RELEASE_TOKEN`.

## Each release

1. **Start a branch** from an up-to-date `main`:

   ```bash
   git switch main
   git pull forgejo main
   git switch -c release/0.2.0
   ```

2. **Bump the version.** Change `<version>` in `appinfo/info.xml`. In
   `CHANGELOG.md`, rename the `## [Unreleased]` heading to
   `## [0.2.0] - YYYY-MM-DD` with today's date, add a new empty
   `## [Unreleased]` section above it, and read through the entries so they
   describe what users get.

3. **Check locally** (see [development.md](development.md#checks) for the full
   list) and build the archive once to make sure it works:

   ```bash
   composer test
   npm run validate:project
   make appstore            # or: bash scripts/build-appstore.sh
   ```

4. **Commit and open a pull request:**

   ```bash
   git add appinfo/info.xml CHANGELOG.md
   git commit -m "Release 0.2.0"
   git push forgejo release/0.2.0
   ```

   Open the pull request in Forgejo, wait for CI to pass, and merge it.

5. **Tag the merged commit** and push the tag:

   ```bash
   git switch main
   git pull forgejo main
   git log -1 --oneline        # check this is the merged release commit
   git tag -s v0.2.0 -m "Maintenance Tracker 0.2.0"
   git tag -v v0.2.0           # optional: verify the signature
   git push forgejo v0.2.0
   ```

   Pushing the tag starts the **Release** workflow (`.forgejo/workflows/release.yml`).
   It checks that the tag matches the app version and that the changelog has a
   section for it, runs all checks, builds `maintenance_tracker.tar.gz`, signs
   it when `APP_SIGNING_KEY` is set, and creates a Forgejo release with the
   archive, its `.sha256`, and its `.sig`, using the changelog section as release
   notes. Versions below 1.0 are marked as pre-releases.

   If you tagged the wrong commit and nothing has been published yet, delete the
   tag locally and on Forgejo before tagging again:

   ```bash
   git tag -d v0.2.0
   git push forgejo :refs/tags/v0.2.0
   ```

6. **Upload to the App Store** (manual). At
   <https://apps.nextcloud.com/developer/apps/releases/new> enter:

   - **Download URL:** the link to `maintenance_tracker.tar.gz` on the Forgejo
     release page (right-click the attachment and copy the link);
   - **Signature:** the contents of `maintenance_tracker.tar.gz.sig`;
   - leave **Nightly** unchecked.

   The App Store downloads the archive, verifies the signature against your
   certificate, and reads the version and compatibility from `appinfo/info.xml`.

7. **Add the new tag to the upgrade test.** In `.forgejo/workflows/ci.yml`,
   update the `NC_UPGRADE_FROM` line so it holds the tag you just pushed plus the
   oldest supported release (see
   [development.md](development.md#upgrade-policy)), for example
   `NC_UPGRADE_FROM: 'v1.0.0 v1.2.0'`. Replace the previous latest tag rather
   than appending. On the first release, the tag replaces `6fc011d` unless
   upgrades from v0.1.10 are still supported. Commit it
   as its own pull request; it is a one-line change. CI already tests the newest
   tag automatically, so this only keeps the list honest.

## The archive

`make appstore` (or `bash scripts/build-appstore.sh`) writes
`build/artifacts/maintenance_tracker.tar.gz` and `.sha256`. It contains a single
top-level `maintenance_tracker/` directory with only the git-tracked runtime
files: `appinfo`, `lib`, `templates`, `img`, `js`, `css`, `l10n`, `profiles`,
`schemas`, `fitment/core-v1.json`, `openapi.json`, `LICENSE`, `README.md`, and
`CHANGELOG.md`. Sources, tests, documentation, and tooling are left out. The app
has no runtime Composer dependencies, so no `vendor/` directory is included.

The build is reproducible: entries are sorted, owned by `0:0`, and stamped with
the time of the last commit (override with `SOURCE_DATE_EPOCH`), so building the
same commit twice gives the same checksum. It refuses to run while runtime files
have uncommitted changes.

### Signing

- **Release signature (required by the App Store).** With
  `APP_SIGNING_KEY=/path/to/maintenance_tracker.key`, the script also writes
  `maintenance_tracker.tar.gz.sig`, equivalent to

  ```bash
  openssl dgst -sha512 -sign maintenance_tracker.key maintenance_tracker.tar.gz | openssl base64 -A
  ```

  This is the signature you paste when uploading a release.

- **Code signing (optional).** Nextcloud can also check every file of an
  installed app against an `appinfo/signature.json` made with
  `occ integrity:sign-app`. The App Store does not require it, and an app
  without `signature.json` is simply not checked. To include it, run the build on
  a machine with a Nextcloud installation and set:

  ```bash
  APP_SIGNING_KEY=~/.nextcloud/certificates/maintenance_tracker.key \
  APP_SIGNING_CERT=~/.nextcloud/certificates/maintenance_tracker.crt \
  NC_OCC="sudo -u www-data php /var/www/nextcloud/occ" \
  make appstore
  ```

  The script signs the staged files before packing them. Once an app ships
  `signature.json`, every later release must too, or administrators see
  integrity warnings after an upgrade from a signed version. The Forgejo
  workflow does not do code signing.

### Installing an archive by hand

```bash
tar -xzf maintenance_tracker.tar.gz -C /path/to/nextcloud/custom_apps
chown -R www-data:www-data /path/to/nextcloud/custom_apps/maintenance_tracker
sudo -u www-data php occ app:enable maintenance_tracker   # or occ upgrade for an update
```
