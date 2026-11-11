# Daraja integration tests

The test bootstrap only runs from the CLI and refuses to start unless `APP_ENV=test`,
`DB_HOST` is loopback/localhost, and `DB_NAME` contains a standalone `test` token. It applies the existing
`database/schema.sql` tables and views to that configured database after removing
the schema file's default database selector. It does not create, drop, truncate,
or clean databases or records.

1. Create a separate empty MySQL database such as `chamawise_test`.
2. Copy `tests/.env.test.example` to `tests/.env.test` and set only the test
   database connection details. Do not copy production credentials.
3. Run `composer test`.

Daraja callback tests invoke the existing processing functions in CLI and use
test-only STK-query responses. They cover matched contribution, repayment and
investment callbacks, duplicate callbacks, invalid/missing callback details,
failure statuses, and repayment overbalance. They do not send callbacks to the
public endpoint or make Daraja API requests. Fixtures are labeled `TEST DATA`
and remain only in the isolated test database.

To test Daraja Sandbox initiation separately, use the existing member payment
pages and a single intentional Sandbox request. An accepted STK request is only
an initiation acknowledgement; it is not evidence that a customer approved or
completed a payment.
