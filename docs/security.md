# Security and privacy

Maintenance Tracker can contain trip destinations, VINs, receipts, financial
records, and health-equipment history. Treat all of it as sensitive personal
data even though the app is not a medical product.

## Authorization

- Every request requires a Nextcloud user except the standard login flow.
- Normal-user endpoints use `NoAdminRequired`, not `PublicPage`.
- OCS endpoints retain framework CSRF protection; API/mobile clients send `OCS-APIRequest: true`.
- Workspace membership is resolved before resource access and authorization is capability-based. Owner/Manager/Contributor/Viewer are explicit capability bundles; Manager deliberately does not receive membership administration.
- Every mapper query includes the authorized workspace.
- UUIDs are identifiers, never bearer secrets.
- Object existence is not disclosed across workspace boundaries.
- Personal workspaces are purged when a Nextcloud user is deleted or an
  external identity mapping is removed, preventing UID reuse from inheriting
  the previous account's records. A database-serialized lifecycle row retains
  only a SHA-256 UID key so cleanup cannot race lazy workspace creation.
- Capability-authorized mutations serialize by changing a dedicated random lock token on the authorized workspace row as well as the actor's lifecycle row. Membership mutations lock actor and target lifecycle rows in deterministic UID order. This prevents concurrent shared-workspace invariant races and grant/delete UID-reuse races.
  Personal-workspace deletion acquires the same workspace-row lock before
  purging child records so a concurrent member write cannot leave an orphan.
- Project validation discovers every migration-created `workspace_id` table and
  requires account deletion cleanup to cover it; child/history tables are
  purged before assets.

## Input and rendering

- Notes and imported profile text render as text. Vue `v-html` is prohibited
  unless a dedicated sanitizer and test suite are introduced.
- Profile JSON has size, depth, count, type, and string-length limits.
- Profiles contain no HTML, scripts, executable expressions, credentials, or
  remote code.
- Product URLs allow safe `https` URLs (and optionally explicit `http` during
  development); `javascript:`, `data:`, local-file, and custom schemes are
  rejected.
- The server does not fetch arbitrary part/vendor URLs in the MVP.
- Database access uses Nextcloud query builders and named parameters.
- GET routes never mutate state.

## Files

- Upload bytes go through Nextcloud Files/WebDAV, not a web-accessible app path.
- Validate detected MIME, extension, size, quota, and ownership.
- Generate collision-resistant names and never trust a client path.
- Revalidate file ownership whenever a linked document is read, moved, or
  deleted.
- Consider EXIF removal as an explicit privacy option; do not silently alter a
  user's original file.
- Log IDs and result codes, never file contents or receipt OCR text.

## Mobile credentials

- Use Login Flow v2 and its unique revocable app password.
- The PWA/package must never request or store the user's primary password.
- Packaged Capacitor clients must use platform-appropriate protected credential
  storage; exact native storage integration is selected and reviewed with the
  mobile implementation rather than hard-coding an obsolete client stack here.
- Never log authorization headers, app passwords, or login polling responses.
- Do not implement a trust-all TLS manager or an "accept invalid certificate"
  prompt.
- Publicly trusted HTTPS is the safe initial policy. Private-CA support requires
  a designed trust-onboarding flow.
- Optional biometric app lock is a foreground UX control, not a requirement for
  background synchronization.

## Calendar privacy

Calendar events default to a neutral title such as “Maintenance due.” Detailed
CPAP or health-component names in a shared calendar could reveal medical
information. Users can opt into more descriptive titles after a warning.

## Tax and location privacy

The IRS-oriented log needs date, mileage, destination/area, and business
purpose—not a continuous GPS route. Exact background location is excluded from
the initial mobile client because it adds privacy, battery, and Google Play policy
cost.

Trip records keep entry time separately from trip time and preserve corrections
with reasons. No third-party analytics receives locations, costs, or receipt
data.

## Meter and reading integrity

- Meter configuration is distinct from field observation: Owner/Manager can
  configure meters and correct history; Contributor can record new readings;
  Viewer cannot write readings.
- Readings are append-only observations. A correction inserts another row with a
  supersession link rather than changing/deleting the original.
- Monotonic meters validate historical inserts/corrections against neighboring
  effective observations. This is an integrity check, not proof that a physical
  odometer/hour meter was truthful.
- Canonical integer conversion retains the original normalized value/unit so
  audit/support workflows do not have to reverse a rounded conversion.

## Audit and evidence retention

The implemented `maint_audit` stream is an append-only audit stream. Significant domain and
membership mutations record actor/subject metadata, not free-form notes or file
contents. Shared-workspace member deletion removes authorization but preserves
historical audit actor attribution. Personal-workspace deletion purges its audit
rows with the rest of that personal workspace.

Future evidence retention treats blob deletion separately from durable metadata.
Automatic pruning must respect per-item Protect/Keep state and expose a policy
simulation before destructive action. Public report tokens and external mechanic
submissions are scoped/revocable future capabilities; neither grants workspace
membership or direct canonical-history write access.

## Logging and exports

- Do not log notes, destinations, VINs, file names, receipt contents, or
  authentication material.
- User exports are explicit and include a clear sensitivity warning.
- Tax and report snapshots are integrity-hashed.
- No automatic deletion of tax records is enabled by default.
- The initial account-deletion purge must be expanded and tested as attachments,
  shared workspaces, exports, and report retention are implemented.

See Nextcloud's
[security guidelines](https://docs.nextcloud.com/server/stable/developer_manual/prologue/security.html)
for the platform baseline.
