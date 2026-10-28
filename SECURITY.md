# Security policy

## Reporting a vulnerability

Please do **not** open a public issue for a security problem. Use GitHub's
private reporting: **Security → Report a vulnerability** on this
repository, or email the maintainer through the address on their GitHub
profile. Include what you found, how to reproduce it, and the impact.

You can expect an acknowledgement within a few days and a fix or a
mitigation plan as soon as it is understood. Please give a reasonable time
to fix before disclosing.

## Scope

The application in this repository and its documented deployments
(Docker, and shared hosting as in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)).
Testing against the live site must not touch other people's accounts or
data, and must not degrade the service.

## How the app is protected

Design, audit results and the operator checklist are in
[`docs/SECURITY.md`](docs/SECURITY.md); who may do what is in
[`docs/PERMISSIONS.md`](docs/PERMISSIONS.md).
