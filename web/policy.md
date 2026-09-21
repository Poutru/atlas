# Atlas: inclusion and ranking policy — version 1

Atlas (onym:component:atlas) is an independently operated public Onym discovery catalog. It indexes signed service manifests; inclusion is not certification, protocol permission, a service audit, or a guarantee of uptime. Users choose services separately in their client.

## Automatic inclusion

Anyone may submit an HTTPS manifest URL without an account. We verify a bounded UTF-8 JSON document, reject duplicate keys and noncanonical number forms, verify its Ed25519 signature under the declared operator key, validate version 1, componentId, seat and published expiry where applicable. The storage.backup role may omit validUntil under its manifest shape. All syntactically valid seat types are eligible. These are baseline manifest checks, not a complete implementation of every destination seat's conformance suite. The destination client must verify seat-specific schemas, profiles, compatibility, consent and operation before use. Unsupported claims never grant capabilities.

No third-party audit is required or implied. A submitted manifest must be reachable through public IPv4 DNS and HTTPS without explicit ports, userinfo, query or fragment. Retrieval follows at most three validated redirects and accepts at most 256 KiB. We do not accept private-network targets. The initial catalog is bounded to 500 non-blocked records; reaching capacity requires operator intervention.

## Ranking and relationships

Entries are ordered by first inclusion time, then componentId. No personalized ranking, listing payment, sponsored placement or downstream commission is currently offered. All entries use policy-ranked placement. The relationship field is none for independently operated services; the owner must disclose common ownership, listing fees or catalog sponsorship if applicable. The original Onym reference services are operated by Onym, not by Atlas. Web-local search, filters and comparison are the visitor's view, not another provider ranking.

Editorial titles, descriptions, categories and notes are stored separately from operator-signed data. They do not override endpoint, key, offer, signature or contract claims. Browser cards and operator declarations are labelled separately.

## Updates, exclusion and review

New valid records are automatically published. Existing componentIds cannot be automatically moved to another URL or operator key. Changed manifest bytes require owner review; they are excluded from the signed catalog until accepted. Invalid, expired or unreachable manifests are excluded; their web cards may remain with an explicit state. A warning can be published through the profile's status field. Disabling a record removes it from subsequent snapshots and blocks automatic resubmission by its componentId and operator key. This is local curation, not revocation of the underlying service or an instruction to disconnect existing users.

Checks run hourly. Snapshots expire after seven days and are renewed only through a checked publishing process. Every published sequence is immutable and retained; updates append to the previous digest. The public change journal explains additions, edits and exclusions. An emergency removal can use a bounded public explanation.

For correction or appeal, open an issue at https://github.com/Poutru/atlas/issues and identify the componentId. Do not publish private keys or confidential incident evidence. The owner may restore a record after revalidation. Direct use of services and alternative catalogs remain possible regardless of Atlas inclusion.

## Limitations

We do not claim complete ecosystem coverage, service quality, independent security audit, verified jurisdiction, undisclosed pricing or full destination-seat conformance. Fetch success observes the manifest, not the service's actual delivery/storage behavior. A valid signature proves key control, not a person's legal identity or trustworthiness.
