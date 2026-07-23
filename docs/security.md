# Security and privacy

Maintenance Tracker can contain trip destinations, VINs, receipts, financial
records, and health-equipment history. Treat all of it as sensitive personal
data even though the app is not a medical product.

## Authorization

- Every request requires a Nextcloud user except the standard login flow.
- Normal-user endpoints use `NoAdminRequired`, not `PublicPage`.
- OCS endpoints retain framework CSRF protection; Android sends
  `OCS-APIRequest: true`.
- Workspace membership and role are checked before resource access.
- Every mapper query includes the authorized workspace.
- UUIDs are identifiers, never bearer secrets.
- Object existence is not disclosed across workspace boundaries.
- Personal workspaces are purged when a Nextcloud user is deleted or an
  external identity mapping is removed, preventing UID reuse from inheriting
  the previous account's records. A database-serialized lifecycle row retains
  only a SHA-256 UID key so cleanup cannot race lazy workspace creation.

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

## Android credentials

- Use Login Flow v2 and its unique revocable app password.
- Store the app password with an AES-GCM key held by Android Keystore.
- Exclude credentials from Android backup and device transfer.
- Never log authorization headers, app passwords, or login polling responses.
- Do not implement a trust-all TLS manager or an “accept invalid certificate”
  prompt.
- Publicly trusted HTTPS is the safe initial policy. Private-CA support requires
  a designed trust-onboarding flow.
- A background-sync key cannot require biometric interaction; optional
  biometric app lock is a separate foreground control.

## Calendar privacy

Calendar events default to a neutral title such as “Maintenance due.” Detailed
CPAP or health-component names in a shared calendar could reveal medical
information. Users can opt into more descriptive titles after a warning.

## Tax and location privacy

The IRS-oriented log needs date, mileage, destination/area, and business
purpose—not a continuous GPS route. Exact background location is excluded from
the initial Android app because it adds privacy, battery, and Google Play policy
cost.

Trip records keep entry time separately from trip time and preserve corrections
with reasons. No third-party analytics receives locations, costs, or receipt
data.

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
