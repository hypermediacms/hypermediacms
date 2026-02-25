# HypermediaCMS

HypermediaCMS is a **server-driven, hypermedia-first CMS** built around an XML-like domain-specific language called **HTX**. HTX defines content queries, templates, and storage actions with a declarative contract designed to be secure, testable, and versionable.

Instead of relying on heavy client-side JavaScript stacks, you define buildless content queries, mutations, and response behavior in HTX templates. Those templates drive a secure two-phase workflow between the Rufinus runtime and Origen server, returning dynamic, hydrated HTML fragments ready for HTMX swaps.

## Why HypermediaCMS

- **Hypermedia-first DX**: build UI behavior in compact HTX templates and render stateless HTML on demand.
- **HTMX-native workflow**: return fully hydrated, HTMX-ready fragments from server-side contracts.
- **Secure mutations**: declare actions in HTX, sign payloads in prepare phase, then validate and execute server-side.

Learn more at [hypermediacms.org](https://hypermediacms.org)
