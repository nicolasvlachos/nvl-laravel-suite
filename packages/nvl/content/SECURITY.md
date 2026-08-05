# Security

Report vulnerabilities privately to the package maintainers. Do not open a
public issue until a fix is available.

Management and public routes are disabled by default. Applications must bind a
real authorization policy, register only safe owner/reference aliases, keep
private media delivery behind Media authorization, and review published Blade
views. Rich text is sanitized on mutation, JSON Schema remote references are
disabled, unknown fields fail closed, and definition/output paths are restricted
to configured roots. Never render untrusted values with raw Blade output except
for the package's sanitized rich-text value object.

Snapshot hashes include owner identity and detect corruption, but are not
cryptographic authentication for untrusted input. Sign externally supplied
snapshots at the application boundary. Rich text is sanitized again at render
time, private Media uses a distinct typed projection, URL fields use allowlisted
schemes, always deny script/data/file schemes, reject credentials, and invalid
route middleware fails closed. Signed private Media routes retain the Media
uploader identity independently of the actor rendering Content.
Content and Media association writes must share a named database connection so
authorization failures and transaction rollbacks cannot leave partial links.
Reference resolver output is treated as an external projection boundary: it is
limited to bounded JSON-compatible values and cannot spoof the stable reference
identifier.

Definition migration plans and events expose block identity, versions, and
revisions but never values or translations. Migration batches authorize the
operation globally and per block, lock exact planned revisions, validate final
content, scope, placement, and Media policy, and roll back the complete batch
on any failure. Authorization adapters that expose a block catalog should
implement `ContentBlockQueryScope` so tenant constraints are applied before
caller-controlled filters.
