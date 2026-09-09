# Maintenance Tracker CI images

Routine Forgejo CI should not spend runner time reconstructing the same PHP and
Docker-client toolchains on every run. These definitions build project-owned CI
images that are qualified separately, published to the Forgejo container
registry, and then pinned by immutable registry digest in routine workflows.

The images contain toolchains only. Application dependencies remain owned by
`composer.lock` and `package-lock.json` and continue to be installed from the
checked-out repository during CI.

## Images

- `ci-php:8.2-v2` — PHP 8.2, required PHP extensions including `ext-zip`,
  Composer, Git, and the minimal Node runtime required by Forgejo JavaScript
  actions.
- `ci-php:8.5-v2` — the equivalent PHP 8.5 environment with `ext-zip`.
- `ci-nextcloud:v1` — Node 24 plus the Docker client used by the disposable
  Nextcloud integration harness. It does not start a Docker daemon; jobs still
  use the isolated runner-provided `DOCKER_HOST` transport.

The upstream image references in `scripts/build-ci-images.sh` are pinned to
reviewed multi-platform index digests. Updating one is an explicit toolchain
change requiring rebuild and requalification.

## Build and qualify locally

From a clean repository checkout:

```bash
bash scripts/build-ci-images.sh
```

The script builds all three images and runs their self-tests. No registry
mutation occurs unless `--push` is supplied.

## Publish to Forgejo

Authenticate interactively with a Forgejo personal access token that is allowed
to publish packages for the `alan` owner. Do not store the token in this
repository or command history.

```bash
docker login forgejo.argentwolf.org
bash scripts/build-ci-images.sh --push
```

When qualifying only a new PHP image generation, use the narrower mode so an
unchanged Nextcloud discovery tag is not rebuilt or republished:

```bash
bash scripts/build-ci-images.sh --php-only --push
```

The image names are nested under the repository name so Forgejo can associate
them with this repository:

```text
forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/ci-php:8.2-v2
forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/ci-php:8.5-v2
forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/ci-nextcloud:v1
```

After publication, capture the `RepoDigests` printed by the script. Routine CI
must consume those `@sha256:...` identities, not merely the mutable tags. The
machine-readable authority for the currently qualified set is
`ci/images/qualified-images.json`; the normal workflow is validated against it.


### PHP v2 qualification prerequisite

The PHP v2 discovery tags add `ext-zip`/`ZipArchive` so ZIP-backed application
unit tests run in both supported PHP lanes instead of being skipped by a
toolchain that lacks the application-required extension. Building and
publishing the v2 tags does **not** make them routine-CI authority. The v1
immutable identities below remain authoritative until the v2 images have been
locally qualified, published, their registry `RepoDigests` captured, and a
separate reviewed change updates `qualified-images.json` and the workflow pins.

### Qualified v1 identities

These images were built and locally qualified from source revision
`4ac2406a87aa4070ed50e3e8164b593699f4d470`, then published to Forgejo:

| Toolchain | Mutable discovery tag | Immutable routine-CI identity |
| --- | --- | --- |
| PHP 8.2 | `ci-php:8.2-v1` | `ci-php@sha256:fbbd1d9067f302fc769e342fe292836131cde3c8b809522f90d7165bbfb2fdc6` |
| PHP 8.5 | `ci-php:8.5-v1` | `ci-php@sha256:a1c8b402f8cc6a61609cf4b0459b2a795b1b0671b95867995a1dd0b257c2a7dc` |
| Nextcloud harness | `ci-nextcloud:v1` | `ci-nextcloud@sha256:3eea2dd55afb6004f7d8721a5c9e497cccc1e53ce476e997706aa9222d885d2c` |

The abbreviated repository names in the table are under
`forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/`. Do not edit
`qualified-images.json` to point at a new digest until the corresponding image
definition has been built, self-tested, published, and its registry digest has
been observed. Tags are convenient discovery labels, not CI authority.

Publishing is intentionally separate from pull-request execution. Forgejo
pull-request tokens are read-only, and registry publication is a maintainer
operation rather than something untrusted PR code may perform.
