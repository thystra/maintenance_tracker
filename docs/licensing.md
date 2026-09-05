# Licensing and distribution

This is engineering and product-risk guidance, not legal or tax advice.

## Server app

Use `AGPL-3.0-or-later`.

That is compatible with Nextcloud and is the lowest-friction choice for its App
Store, whose rules require AGPL-3.0-or-later or a compatible license. It also
fits a self-hosted app whose users should be able to inspect and modify the code.

App Store releases require:

- a public source repository;
- only public `OCP` APIs;
- a signed app tree and signed release archive;
- a recognized app license;
- privacy, support, and bug-reporting information;
- an archive rooted at `maintenance_tracker/`.

References:
[Nextcloud App Store rules](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
and [code signing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html).

## Profile data

Code and profile facts should not be conflated.

- Prefer `CC0-1.0` for original generic factual profiles.
- Use `CC-BY-4.0` when attribution is required.
- Store an SPDX license, source URL, version, author, and content hash.
- Do not copy manufacturer manual prose, diagrams, or substantial tables without
  permission merely because individual intervals or part numbers may be facts.
- Imported profiles are user-supplied data; the UI must show provenance and
  should distinguish first-party, locally created, and third-party definitions.

## Paid packaged mobile client

The planned mobile implementation is a Vue offline-first PWA packaged with
Capacitor for Android/iOS when native capabilities are required. It is not a
separate Kotlin Android client or an unrelated Swift iOS data model. Shared
API/schema/sync code can remain distinct from the server while the mobile UI is
purpose-built for field entry.

Commercial distribution and source-license choices for packaged clients require
a separate legal/product decision before publication. Do not assume that sharing
Vue code with the AGPL server, using Capacitor, or charging for an app by itself
determines the correct license. Preserve third-party notices and have qualified
open-source counsel review the final dependency and distribution boundary.

Charging money does not inherently require proprietary licensing; the business
model and redistribution goals should be evaluated separately from technical
architecture.

## Google Play

An upfront paid download is the simplest first model. It does not need an
in-app `BillingClient` when there are no separately sold in-app features.

Important operational points:

- Google Play developer registration has historically been a one-time USD 25
  fee; confirm the amount during enrollment.
- Set the app to paid before its first public release. Google states that an app
  once offered free cannot later become paid under the same package name.
- In-app sales of digital features or subscriptions normally require Play
  Billing unless an applicable regional program applies.
- A paid app is subject to the service fee in effect for its market and program.
  Google's fee model changed in 2026, so do not hardcode a single percentage in
  a business plan.
- Choose an organization account if publishing as a business; Google requires a
  D-U-N-S number and website for that account type.
- Complete Data Safety declarations and publish a privacy policy even for a
  self-hosted client.
- Avoid background location in the initial release.

References:
[Play pricing](https://support.google.com/googleplay/android-developer/answer/6334373),
[payments policy](https://support.google.com/googleplay/android-developer/answer/9858738),
and [current service fees](https://support.google.com/googleplay/android-developer/answer/112622).

## Apple later

Deferring iOS packaging is reasonable. As of July 2026, Apple lists the Developer Program at USD 99 per membership year (or local equivalent), plus applicable App Store commission. A Capacitor package still requires Apple signing/provisioning, privacy disclosures, review, and ongoing device testing.

Apple's current terms and fees can change:
[Apple Developer Program membership](https://developer.apple.com/programs/whats-included/).

If the server API and sync behavior stabilize first, Android/iOS Capacitor packages can share the Vue mobile application and OpenAPI/sync fixtures while retaining platform-specific native integrations where required.

## Branding

Use “Maintenance Tracker” or an independent product brand. Nextcloud's App Store
rules prohibit “Nextcloud” in an app name. Its trademarks also need care in a
paid Android listing; describe compatibility factually and seek permission
before prominent commercial use of the Nextcloud mark.
