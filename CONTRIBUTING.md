# Contributing

Thank you for contributing to NoRoot VPN Panel.

NoRoot VPN Panel is an experimental research and educational project focused on exploring the technical feasibility of network-related services within highly restricted PHP/shared-hosting environments.

Contributions are welcome only when they are technically relevant to the project's goals, maintainable, and compatible with its restricted-environment philosophy.

## Contribution Requirements

Before submitting a Pull Request, make sure the proposed change:

* Is clearly compatible with the project's research and educational objectives.
* Does not introduce unnecessary complexity or dependencies.
* Does not unnecessarily increase CPU, memory, storage, or network requirements.
* Remains practical for restricted shared-hosting environments.
* Does not break existing functionality.
* Is tested on **both Windows and Linux**.
* Does not introduce platform-specific behavior unless that behavior is explicitly documented and justified.
* Does not contain hard-coded credentials, API keys, tokens, private keys, or other secrets.
* Does not contain unnecessary telemetry, tracking, or undisclosed external communication.
* Includes appropriate documentation when behavior, configuration, installation, or API usage changes.
* Preserves existing security and access-control expectations.

## Testing

Every Pull Request must be tested on both supported operating-system families:

```text
Windows
Linux
```

Testing should cover the functionality affected by the change, including relevant installation, configuration, runtime, and error-handling paths.

A Pull Request that has only been tested on one operating system may be rejected or returned for additional testing.

When an issue is platform-specific, clearly document:

* Operating system and version.
* PHP version.
* Hosting/server environment where applicable.
* Relevant configuration.
* Steps used for testing.
* Expected and actual results.

## Pull Request Requirements

A Pull Request should:

* Have a clear and concise title.
* Explain what was changed and why.
* Describe any important implementation details.
* Include testing information for **Windows and Linux**.
* Include screenshots or logs when they materially help explain the change.
* Keep unrelated changes out of the Pull Request.
* Use a focused commit history where practical.

Large refactors, architectural changes, or major new features should be justified carefully and must remain consistent with the project's intended scope.

## Code Quality

Contributions should follow the existing project's coding conventions and structure.

Prefer simple, readable, maintainable implementations over unnecessary abstraction or over-engineering.

Avoid introducing a dependency when the same result can reasonably be achieved using the existing project or standard functionality.

Changes should fail safely and should not silently weaken authentication, authorization, input validation, configuration protection, or other security controls.

## Compatibility

Because the project targets constrained hosting environments, compatibility is an important requirement.

Contributors should avoid assumptions about:

* Root or administrator privileges.
* SSH access.
* Arbitrary port binding.
* Persistent background processes.
* Specific operating-system features.
* Unrestricted filesystem access.
* Unrestricted PHP functions.
* High CPU or memory availability.
* Server configurations that are unavailable on typical shared hosting.

A feature that works only in a privileged environment may be outside the project's intended scope unless there is a strong research reason to include it.

## Security

Do not submit credentials, secrets, malicious payloads, intentionally destructive code, or changes that intentionally weaken security.

For suspected security vulnerabilities, do **not** open a public Pull Request or Issue. Follow [`SECURITY.md`](SECURITY.md).

## Documentation

If your contribution changes user-visible behavior, configuration, installation, compatibility, or supported functionality, update the relevant documentation.

Keep documentation accurate and avoid claiming compatibility or functionality that has not been tested.

## Pull Request Review

All Pull Requests are subject to review.

A contribution may be rejected when it:

* Conflicts with the project's goals.
* Has not been tested on both Windows and Linux.
* Introduces unnecessary complexity.
* Requires infrastructure or permissions incompatible with the project's intended environment.
* Introduces significant security, stability, or compatibility risks.
* Duplicates existing functionality without a clear benefit.
* Contains undocumented breaking changes.
* Provides insufficient information to reproduce or verify the change.

Maintainers may request changes before a Pull Request can be merged.

## Scope and Direction

Contributions should strengthen the project's core purpose rather than turn it into a fundamentally different type of project.

Experimental ideas are welcome when they have a clear technical or research justification and remain reasonably aligned with the project's architecture and constraints.

By submitting a contribution, you acknowledge that maintainers have final discretion over whether it fits the project's scope, quality requirements, technical direction, and maintenance capacity.
