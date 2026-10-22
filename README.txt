PROFILE SYSTEM

A private member register for a research lab: each member keeps their lab
details up to date, teachers read them as one sheet, admins manage accounts.

The full documentation is in README.md and the docs/ folder.
New computer with nothing installed: docs/INSTALL.md.
Why it is built this way: docs/DECISIONS.md.

GETTING STARTED (Docker)

    cp .env.example .env
    make up
    # open http://localhost:8080

The first account created becomes the admin. Everyone after that joins
through an invitation link made on the Invitations page.
