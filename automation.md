# Automation Notes

## Bulk loading of slow logs
- logParser3 defaults to BULK mode (TSV + LOAD DATA LOCAL INFILE).
- Sources:
  - No-arg: reads `casino_slow.log`, `backoffice_slow.log`, `betmaker_slow.log`.
  - With arg: parses the provided file path (or chunk).
- Targets when BULK is ON:
  - Loads into `slow_query_log` and `query_type_info` via LOAD DATA LOCAL INFILE.
- When BULK is OFF:
  - Per-row INSERTs into the same tables via prepared statements.

### Control flags
- Bulk ON (default):
  - Single file: `php /home/bytoz/.aws/logParser3 /path/to/file.log`
  - Chunked: `/home/bytoz/.aws/parse_split_chunks.sh /pp/split`
- Bulk OFF (old behavior):
  - Single file: `BULK=0 php /home/bytoz/.aws/logParser3 /path/to/file.log`
  - Chunked: `BULK=0 /home/bytoz/.aws/parse_split_chunks.sh /pp/split`

### Requirements
- MySQL server: `local_infile=ON` (check with `SHOW VARIABLES LIKE 'local_infile';`).
- PDO client already uses `local_infile=1` and `PDO::MYSQL_ATTR_LOCAL_INFILE => true`.
- TSV paths (by default):
  - `/tmp/slow_query_log.tsv`
  - `/tmp/query_type_info.tsv`

## MFA (2FA) automation
- Goal: avoid manual MFA code entry during automatic runs.
- Current flow:
  - `getAuth.php` checks AWS logs; if credentials invalid/expired, it renews MFA.
  - Manual fallback reads `/var/www/html/2fa_code.inc`.
- Enhancement (added):
  - Auto-generate TOTP codes if a Base32 secret is available.
  - Secret sources (in order):
    - Env var: `MFA_SECRET`
    - File: `/home/<user>/.aws/mfa_secret`
    - File: `./mfa_secret` (same dir as scripts)
  - If secret present:
    - Generate TOTP → `aws sts get-session-token` → write `./credentials` from `./credentials_template`.
    - On failure or if no secret, fallback to manual file polling.

### Where to get the MFA secret
- AWS Console: IAM → Users → your user → Security credentials → Assign MFA device → Authenticator app → Show secret key.
- You can only view the secret at assignment time.
- For an existing device, either add another virtual device or reassign to get a new secret.
- CLI alternative:
  - `aws iam create-virtual-mfa-device --virtual-mfa-device-name <name> --bootstrap-method Base32 --outfile QR.png`
  - Then enable it with two codes using `aws iam enable-mfa-device`.

### Security
- Treat the secret as sensitive. If stored on disk: `chmod 600 ~/.aws/mfa_secret`.
- Ensure system time is accurate (TOTP depends on time).

## Operational tips
- BULK mode is typically 10–100x faster than per-row INSERTs.
- If temporarily using per-row inserts, wrap them in a single transaction for speed.
- Optional (advanced during large imports, if safe):
  - `SET innodb_flush_log_at_trx_commit=2;`
  - `SET unique_checks=0, foreign_key_checks=0;`
  - Restore settings after the import.

## Quick commands
- Chunked bulk:
  - `export BULK=1`
  - `/home/bytoz/.aws/parse_split_chunks.sh /pp/split`
- Single file bulk:
  - `BULK=1 php /home/bytoz/.aws/logParser3 /path/to/file.log`
- Force non-bulk:
  - `BULK=0 php /home/bytoz/.aws/logParser3`
  - `BULK=0 /home/bytoz/.aws/parse_split_chunks.sh /pp/split`
