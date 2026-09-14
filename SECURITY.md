# Security Policy

## Overview

Security is an important part of this project.

NoRoot VPN Panel is an experimental research project designed to operate within highly restricted PHP/shared-hosting environments. Because the project may handle configuration data, authentication information, and network-related settings, security issues should be reported responsibly.

This document defines how security vulnerabilities should be reported and what users can reasonably expect from the project.

## Supported Versions

Only the latest version available in the `main` branch is considered actively maintained for security issues.

Older versions, forks, modified copies, and unofficial distributions may contain known or unknown vulnerabilities and are not guaranteed to receive security fixes.

## Reporting a Vulnerability

Do **not** publicly disclose an unpatched security vulnerability through GitHub Issues, Discussions, pull requests, social media, or other public channels.

For security-sensitive reports, contact the project maintainer privately through the contact method listed in the repository profile.

A useful security report should include:

* A clear description of the vulnerability.
* The affected component, file, endpoint, or feature.
* The conditions required to reproduce the issue.
* Reproduction steps or a minimal proof of concept.
* The potential security impact.
* The affected version or commit.
* Any relevant logs, screenshots, or error messages, where safe to provide.

Please avoid including passwords, API keys, private configuration files, personal information, or other sensitive data in a report.

## What Should Be Reported

Examples of issues that should be treated as security vulnerabilities include:

* Authentication or authorization bypasses.
* SQL injection or other injection vulnerabilities.
* Remote or arbitrary code execution.
* Command injection.
* Path traversal or arbitrary file access.
* Unsafe file upload vulnerabilities.
* Cross-site scripting (XSS) with meaningful security impact.
* Cross-site request forgery (CSRF) affecting sensitive operations.
* Exposure of credentials, tokens, private keys, or sensitive configuration data.
* Privilege escalation.
* Session-management weaknesses.
* Vulnerabilities that allow unauthorized access to administrative functionality.
* Security flaws that expose other users' data or configurations.

## Issues Outside the Security Scope

The following are generally not considered security vulnerabilities in the project itself unless they result from a demonstrable software security flaw:

* A hosting provider blocking, suspending, or terminating an account.
* A hosting provider's firewall or network restrictions.
* Resource limits imposed by shared hosting.
* Provider-specific restrictions on PHP, processes, ports, binaries, or background execution.
* VPN/proxy traffic being blocked by an ISP, hosting provider, government, or network administrator.
* Misconfiguration by the operator.
* Weak passwords chosen by the operator.
* Vulnerabilities introduced by third-party hosting environments.
* Abuse or misuse of the software by users.
* Violations of hosting-provider policies or applicable laws.

Users are responsible for securing their own hosting environment, credentials, server configuration, and deployment.

## Responsible Disclosure

Please allow reasonable time for investigation and remediation before publicly disclosing a confirmed vulnerability.

Security reports may be investigated, reproduced, fixed, documented, or otherwise addressed at the maintainers' discretion.

There is no guarantee that every report will result in a security advisory or code change.

## Third-Party Components

This project may depend on third-party software, PHP packages, libraries, binaries, hosting services, or external infrastructure.

Security vulnerabilities in third-party components should be reported to their respective maintainers when appropriate. However, issues caused by how this project integrates or configures those components may still be relevant to this repository.

## Security Disclaimer

No software can be guaranteed to be completely secure.

This project is provided on an experimental basis and is not represented as a hardened production security solution.

The maintainers and contributors are not responsible for security incidents caused by:

* improper deployment;
* insecure hosting environments;
* compromised hosting accounts;
* leaked credentials;
* modified source code;
* unsupported third-party software;
* operator misconfiguration;
* or vulnerabilities outside the project's control.

Operators should review the source code, deployment environment, permissions, authentication configuration, and hosting-provider policies before using the software.

## Safe Testing

Security research and vulnerability testing should only be performed against systems you own or systems for which you have explicit authorization to conduct testing.

Do not use this project to gain unauthorized access to third-party systems, bypass access controls, interfere with shared infrastructure, or access data belonging to other users.

Unauthorized testing may violate laws, contracts, hosting-provider policies, or network-use policies.
