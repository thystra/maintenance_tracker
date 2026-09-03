# Maintenance Tracker CI images

Routine Forgejo CI should not spend runner time reconstructing the same PHP and
Docker-client toolchains on every run. These definitions build project-owned CI
images that are qualified separately, published to the Forgejo container
registry, and then pinned by immutable registry digest in routine workflows.

The images contain toolchains only. Application dependencies remain owned by
`composer.lock` and `package-lock.json` and continue to be installed from the
checked-out repository during CI.

## Images

- `ci-php:8.2-v1` — PHP 8.2, required PHP extensions, Composer, Git, and the
  minimal Node runtime required by Forgejo JavaScript actions.
- `ci-php:8.5-v1` — the equivalent PHP 8.5 environment.
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

The image names are nested under the repository name so Forgejo can associate
them with this repository:

```text
forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/ci-php:8.2-v1
forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/ci-php:8.5-v1
forgejo.argentwolf.org/alan/maintenance_tracker_for_nextcloud/ci-nextcloud:v1
```

After publication, capture the `RepoDigests` printed by the script. Routine CI
must consume those `@sha256:...` identities, not merely the mutable tags.

Publishing is intentionally separate from pull-request execution. Forgejo
pull-request tokens are read-only, and registry publication is a maintainer
operation rather than something untrusted PR code may perform.
