/* ------------------------------------------------------------------
   AmeenBill — least-privilege database login.

   The dashboard only ever reads. It must never connect as 'sa', and the
   password below must be a NEW one (the old values were committed to git
   and are considered public).

   Replace <STRONG-NEW-PASSWORD> before running.
------------------------------------------------------------------ */

USE master;
GO

IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = 'alameenbill_reader')
BEGIN
    CREATE LOGIN alameenbill_reader
        WITH PASSWORD = '<STRONG-NEW-PASSWORD>',
             CHECK_POLICY = ON,
             DEFAULT_DATABASE = AlbassaDB2026;
END
ELSE
BEGIN
    -- Rotating the leaked password.
    ALTER LOGIN alameenbill_reader
        WITH PASSWORD = '<STRONG-NEW-PASSWORD>';
END
GO

USE AlbassaDB2026;
GO

IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = 'alameenbill_reader')
BEGIN
    CREATE USER alameenbill_reader FOR LOGIN alameenbill_reader;
END
GO

/* Read-only, and only on the tables the dashboard actually touches. */
GRANT SELECT ON dbo.bu000          TO alameenbill_reader;
GRANT SELECT ON dbo.bi000          TO alameenbill_reader;
GRANT SELECT ON dbo.mt000          TO alameenbill_reader;
GRANT SELECT ON dbo.my000          TO alameenbill_reader;
GRANT SELECT ON dbo.st000          TO alameenbill_reader;
GRANT SELECT ON dbo.co000          TO alameenbill_reader;
GRANT SELECT ON dbo.DistDeviceST000 TO alameenbill_reader;
GO

DENY INSERT, UPDATE, DELETE, EXECUTE TO alameenbill_reader;
GO
