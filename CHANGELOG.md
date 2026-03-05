# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `getDisk(): string` on `TicketBAI` to get the configured storage disk.
- Territory validation in `invoice()`: only `ARABA`, `BIZKAIA`, `GIPUZKOA` accepted (case-insensitive).
- `InvalidTerritoryException` and `CertificateNotFoundException` for clearer errors.
- Certificate path check in `getCertificate()`: throws `CertificateNotFoundException` if file is missing or not readable.
- Optional `territory` column: stored when generating an invoice for resend support.
- Artisan command `ticketbai:resend` to re-queue sending of invoices with `sent=null` (`--id=`, `--all`, `--dry-run`).
- Job `ResendInvoice` to resend a single invoice by loading XML from storage and calling the TicketBAI API.
- Migration `add_territory_to_invoices_table` (adds nullable `territory` column).

### Changed

- Config defaults: column names aligned with default migration (`issuer`, `number`, `signature`, `path`, `data`, `sent`). For custom tables use env vars (e.g. `TICKETBAI_COLUMN_ISSUER=transaction_id`).
- `InvoiceSend` uses `TicketBAI::getDisk()` instead of config; on failure logs invoice number and exception and fails with a short message (no full XML in exception).
- Territory is passed to barnetik as codes `01`, `02`, `03` (ARABA, BIZKAIA, GIPUZKOA); stored in DB as code for resend.

### Fixed

- **InvoiceSend**: read XML from Storage disk instead of `file_get_contents($model->path)` (path is relative to disk).
- Typo: `simplyfyHeader()` renamed to `simplifyHeader()`.

## [1.0.0] - YYYY-MM-DD

### Added

- Initial release: TicketBAI generation, signing, storage, and queue-based submission.
- Flexible table/column configuration.
- QR code generation.
- Support for Araba, Bizkaia, Gipuzkoa.

[Unreleased]: https://github.com/ebethus/laravel-ticketbai/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/ebethus/laravel-ticketbai/releases/tag/v1.0.0
