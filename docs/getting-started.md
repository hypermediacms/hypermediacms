# Chapter 2: The Virtue of Simplicity

The modern web stack is a monument to accidental complexity.

Build tools that compile other build tools. Package managers downloading gigabytes for a blog. Configuration files that require their own configuration files. We've built cathedrals of abstraction on foundations of sand, then wonder why everything feels so fragile.

Hypermedia CMS is a deliberate rejection of this trajectory. Not because complexity is always wrong — but because complexity should be *earned*, not inherited.

---

## The Complexity Tax

Every dependency is a liability. Every abstraction layer is a potential failure point. Every build step is time stolen from building.

This isn't Luddism. It's accounting.

When you add a tool to your stack, you're not just adding its features — you're adding its bugs, its upgrade cycles, its breaking changes, its documentation you'll need to read, its mental model you'll need to internalize. You're adding the time you'll spend debugging it when it fails at 2 AM. You're adding the weekend you'll lose when a major version drops and everything breaks.

Most teams never do this accounting. They add tools because they're popular, because everyone else uses them, because it seems like the professional thing to do. They accumulate complexity the way old houses accumulate junk in the attic — gradually, thoughtlessly, until one day you can't move.

Hypermedia CMS starts from a different premise: **every piece of complexity must justify its existence.**

---

## Files as Foundation

The flat-file architecture isn't a limitation we're working around. It's a philosophical commitment.

Files are the most battle-tested storage abstraction in computing. They work on every operating system. They're trivially backed up. They diff cleanly in version control. They survive decades while databases come and go. They can be edited with any tool — from vim to VS Code to a sed script.

When your content lives in files, you own it completely. No export step. No migration script. No database dump to interpret. Just files.

This is what "portable" actually means. Not "we support multiple databases" — but "your content exists independently of our software."

Consider the alternative: content trapped in a database, accessible only through the CMS that created it. Want to move to a different system? Better hope someone wrote an importer. Want to edit in bulk? Better learn the database schema. Want to version control your content alongside your code? Good luck.

Files eliminate an entire category of problems by refusing to create them in the first place.

---

## The Two-Server Philosophy

Hypermedia CMS runs as two separate processes: Origen and Rufinus. This isn't an implementation detail — it's a statement about separation of concerns.

**Origen** handles content: storage, retrieval, authentication, indexing. It speaks JSON over HTTP. It knows nothing about HTML, nothing about templates, nothing about how content will be displayed.

**Rufinus** handles presentation: template parsing, HTML generation, routing. It speaks HTML to browsers. It knows nothing about how content is stored, nothing about authentication, nothing about file systems.

This separation isn't just clean architecture — it's a forcing function for good decisions.

When you can't cheat, you can't make a mess. Rufinus can't "just quickly" write to the database. Origen can't "just quickly" render some HTML. Every interaction must go through the defined interface. The API becomes real because it has to be.

This is why many "API-first" systems aren't, really. When your frontend and backend share a runtime, the temptation to bypass the API is irresistible. Direct database queries sneak in. Shared state accumulates. The "API" becomes a formality — documented but not actually used.

Separate processes make the API honest.

---

## Against Build Steps

Hypermedia CMS has no build step.

You clone the repo, run composer install, start the servers. That's it. No transpilation. No bundling. No watching. No waiting.

This is possible because we refuse to use technologies that require building. No TypeScript. No JSX. No Sass. No webpack, vite, esbuild, or whatever replaced them this month.

Some will call this backward. We call it sustainable.

Build tools create a temporal dependency: the thing you deploy is not the thing you wrote. There's a transformation step in between, and that step can fail. The build can break. The output can differ from what you expected. The tool can have bugs. The tool can be abandoned.

When you skip the build step, your code is your code. Edit a file, refresh the browser. The feedback loop is instant because there's no loop — just direct cause and effect.

This also means your code is debuggable. When something goes wrong in production, you can read the actual source. No source maps. No minification mysteries. Just the code you wrote, running exactly as you wrote it.

---

## The PHP Question

Yes, we chose PHP. Let's talk about why.

PHP gets a bad reputation, much of it deserved, most of it outdated. The PHP of 2024 is not the PHP of 2005. Modern PHP has types, attributes, enums, fibers, JIT compilation. It's a genuinely good language that happens to carry historical baggage.

But we didn't choose PHP because it's secretly great. We chose it because it's *ubiquitous*.

PHP runs everywhere. Every shared host. Every VPS. Every major cloud provider. If you can run a website, you can run PHP. This matters more than language aesthetics.

The goal of Hypermedia CMS is to be deployable by anyone, anywhere, without special infrastructure. Not everyone has Kubernetes. Not everyone wants to manage Node processes. Not everyone can afford the complexity tax of modern deployment.

PHP gives us reach. A teenager can deploy this to a $5 shared host. A small business can run it on their existing infrastructure. A large enterprise can scale it across their cloud. The same codebase serves all of them.

This is what "accessible" means in practice. Not a polished marketing site — but genuine deployability for real people in real situations.

---

## SQLite: The Right Default

For the index layer, we chose SQLite.

Not MySQL. Not PostgreSQL. Not MongoDB. Not Elasticsearch. SQLite.

This is, again, a philosophical choice. SQLite is a library, not a server. It requires no configuration, no process management, no port allocation, no connection pooling. It's just a file.

Want to back up your database? Copy the file. Want to reset everything? Delete the file. Want to inspect it? Open it with any SQLite client. Want to ship it? Include it with your content.

The operational simplicity is transformative. There's no database server to crash at 3 AM. No connection limits to tune. No clustering to configure. No credentials to manage. The database is just there, embedded in your application, requiring nothing from you.

Critics will say SQLite doesn't scale. They're right — and it doesn't matter.

SQLite handles thousands of concurrent reads. It handles hundreds of writes per second. For the vast majority of websites, this is more than enough. And for those who outgrow it, the architecture allows swapping the index layer. The flat files don't care.

We optimize for the common case. The common case is a small-to-medium site that will never see enough traffic to stress SQLite. Why impose the operational burden of a database server on everyone, just to serve the edge cases?

---

## No Framework, By Design

Hypermedia CMS doesn't use a PHP framework. No Laravel. No Symfony. No Laminas.

This might seem like reinventing wheels. It's actually about controlling dependencies.

Frameworks are opinions frozen in code. When you adopt a framework, you adopt its opinions — about routing, about dependency injection, about configuration, about a hundred things you may not have considered. Some of those opinions will age poorly. Some will conflict with your needs. All of them will change on someone else's schedule.

By owning our infrastructure, we control our evolution. We can change how routing works without waiting for a framework release. We can modify the DI container without fighting upstream design decisions. We can delete code that no longer serves us.

This is not about ego. It's about independence.

The code we write, we own. The bugs we create, we can fix. The design decisions we make, we can revisit. There's no upstream to wait on, no release cycle to align with, no breaking changes imposed from outside.

Yes, this means more code. But it's *our* code. We understand it completely because we wrote it completely.

---

## Methodology: Start with Constraints

The Hypermedia CMS methodology begins with constraints.

Not features. Not requirements. Constraints.

What will we *not* do? What complexity will we *refuse* to accept? What dependencies will we *reject*? These questions come first, before any code is written.

This is counterintuitive. Modern software development is additive: what features can we add? What tools can we integrate? What would be cool to include?

We work subtractively: what can we remove? What can we avoid? What can we refuse?

Every subtraction is a gift to the future. Every refused dependency is a problem that will never occur. Every eliminated feature is documentation that doesn't need writing, tests that don't need maintaining, bugs that can't exist.

Constraints liberate. When you can't add a database server, you find ways to work without one. When you can't add a build step, you write code that doesn't need building. When you can't add a framework, you discover how little framework you actually needed.

The result is software that's small enough to understand, simple enough to deploy, and stable enough to trust.

---

## The Goal

Hypermedia CMS is not trying to be the most powerful CMS. It's not trying to be the most feature-rich. It's not trying to compete with WordPress on plugins or Contentful on scale.

It's trying to be *comprehensible*.

A single developer should be able to read the entire codebase in a day. A small team should be able to understand every component. A new contributor should be able to make meaningful changes within hours.

This is the virtue of simplicity: not doing less, but doing *what matters* with nothing in the way.

When your tools are simple, you spend your time building, not configuring. When your stack is shallow, you debug problems instead of stack traces. When your dependencies are few, you update on your schedule, not theirs.

We believe this matters. Not because simplicity is fashionable — but because complexity has costs, and those costs are usually invisible until they're catastrophic.

Hypermedia CMS is an argument that those costs can be avoided. Not perfectly, not completely, but substantially. By making different choices. By refusing the defaults. By insisting that every piece of complexity earn its place.

Start simple. Stay simple. Add complexity only when it's unavoidable.

This is the way.
