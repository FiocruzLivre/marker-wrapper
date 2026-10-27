# Contributor and agent guide

Scope: the Marker Wrapper gateway. Keep it independent of any single
deployment, customer domain, private instance, or proxy topology.

- Follow [docs/development.md](docs/development.md) for the local setup,
  tests and Compose commands.
- Keep README.md short. Document environment variables immediately above
  their entries in `docker-compose.yml`.
- Never commit `.env`, secrets, tile provider credentials, private URLs,
  test exports or cache data.
- Preserve the default internal-only network behavior. The optional
  `docker-compose.dev.yml` publishes a loopback-only port.
- Keep package versions reproducible through committed Composer locks.
  Install PHPUnit, Psalm and PHP-CS-Fixer in isolated `vendor-bin/` paths.
- When modifying tile or map routes, add a regression test; validate HTTP
  codes, content types, image payloads and upstream request restrictions.
- Do not disable Composer security auditing or mask exceptions to make CI
  pass.
- Review provider usage/attribution policies. This service must not
  circumvent third-party access controls.
- Never deploy to production or merge a pull request as part of routine
  local testing.

Before completion: run quality checks, validate both Compose variants,
and confirm the integration tests from `docs/development.md`.
