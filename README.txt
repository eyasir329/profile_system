PROFILE SYSTEM

A small, self-hosted profile / account app built with vanilla PHP, vanilla
CSS, and a sprinkle of vanilla JavaScript. No frameworks, no build step.

The full documentation is in README.md. This file is kept short for the
benefit of anyone who lands on it first (e.g. from a `dir` listing or
file-association link).

GETTING STARTED (Docker)

    cp .env.example .env
    docker compose up -d --build
    # open http://localhost:8080

GETTING STARTED (without Docker)

See README.md → "Quick start (XAMPP / native PHP)" for prerequisites and
step-by-step instructions.

DOCS

    README.md              Architecture, quick start, user guide, admin guide,
                           developer notes, troubleshooting, security notes.
    CHANGELOG.md           Release notes; one entry per notable change.
    CONTRIBUTING.md        Workflow, code style, testing, "definition of done".
    docs/DIRECTORY.md      File-by-file tour of every PHP, SQL, CSS, JS file.

IMPORTANT

This is a learning project. Before publishing it on the public internet,
read README.md → "Security notes" and harden accordingly: use HTTPS, set
a strong DB password, add rate limiting, configure a real email transport
for password reset, and consider 2FA. Do not allow users to self-assign
the admin role — the first registered user has `role='user'`; promote
explicitly via SQL (see README.md → "Default credentials").

LICENSE

No license is granted by default. See README.md for details.
