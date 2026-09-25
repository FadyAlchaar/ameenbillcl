-- ═════════════════════════════════════════════════════════════════════
--  install/setup_reader.sql
--
--  Complete read-only setup for the AmeenBill dashboard.
--  Run ONCE per install (per SQL Server instance + per database).
--  Safe to re-run: every step checks before acting.
--
--  WHAT IT DOES
--    1.  Creates the server login (or enables / resets it).
--    2.  Creates the DB user (or repairs an orphaned one after a
--        restore, fixing SID mismatch).
--    3.  Adds to db_datareader + grants CONNECT.
--    4.  Explicit SELECT grants on all dashboard tables.
--    5.  Creates the wrapper procedures for wrapped reports:
--          - ameenbill_GetMatMovements    (via repMatMoveMultiProduct)
--          - ameenbill_GetSnMovements     (via SNMove)
--          - ameenbill_GetCostCenterLedger (via RepCostGl)
--        Every wrapper runs WITH EXECUTE AS OWNER, so internal calls to
--        Alameen's security functions (fnGetUserSec, fnGetCurrentUserGUID,
--        fnIsAdmin, …) succeed without needing one grant per function.
--    6.  Grants EXECUTE on every wrapper present.
--    7.  Populates RepSrcs for each wrapper's SrcTypesguid.
--    8.  Verifies — prints a PASS/FAIL table.
--
--  WHAT IT CANNOT DO
--    The reader cannot INSERT, UPDATE, DELETE, TRUNCATE, DROP, ALTER,
--    or execute any procedure that isn't explicitly granted. Read-only
--    by design.
--
--  BEFORE RUNNING
--    Edit the three lines in the CONFIG block below.
-- ═════════════════════════════════════════════════════════════════════

SET NOCOUNT ON;

-- ─── CONFIG — edit these three lines per install ────────────────────
DECLARE @LoginName     sysname        = N'alameenbill_reader';
DECLARE @Password      nvarchar(128)  = N'P@ssw0rd@2026';
DECLARE @DatabaseName  sysname        = N'AlbassaDB2026';
-- ─────────────────────────────────────────────────────────────────────

PRINT '════════════════════════════════════════════════════════════';
PRINT '  AmeenBill reader setup';
PRINT '  Login:    ' + @LoginName;
PRINT '  Database: ' + @DatabaseName;
PRINT '════════════════════════════════════════════════════════════';
PRINT '';

-- ═════════════════════════════════════════════════════════════════════
--  STEP 1 — Server-level login
-- ═════════════════════════════════════════════════════════════════════
USE [master];
GO

IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = 'alameenbill_reader')
BEGIN
    CREATE LOGIN [alameenbill_reader]
        WITH PASSWORD = N'P@ssw0rd@2026',
             CHECK_POLICY = OFF,
             DEFAULT_DATABASE = [AlbassaDB2026];
    PRINT '[✓] Step 1: Login created at server level.';
END
ELSE
BEGIN
    ALTER LOGIN [alameenbill_reader] ENABLE;
    ALTER LOGIN [alameenbill_reader]
        WITH PASSWORD = N'P@ssw0rd@2026',
             CHECK_POLICY = OFF;
    PRINT '[✓] Step 1: Login existed — enabled and password reset.';
END
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 2 — Database user (with orphan repair)
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];   -- ← keep in sync with @DatabaseName above
GO

IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = 'alameenbill_reader')
BEGIN
    CREATE USER [alameenbill_reader] FOR LOGIN [alameenbill_reader];
    PRINT '[✓] Step 2: User created in database.';
END
ELSE
BEGIN
    DECLARE @dbSid  varbinary(85);
    DECLARE @srvSid varbinary(85);

    SELECT @dbSid  = sid FROM sys.database_principals WHERE name = 'alameenbill_reader';
    SELECT @srvSid = sid FROM sys.server_principals   WHERE name = 'alameenbill_reader';

    IF @dbSid IS NULL OR @srvSid IS NULL OR @dbSid <> @srvSid
    BEGIN
        ALTER USER [alameenbill_reader] WITH LOGIN = [alameenbill_reader];
        PRINT '[✓] Step 2: User was orphaned — SID remapped to the login.';
    END
    ELSE
        PRINT '[·] Step 2: User exists with matching SID — nothing to do.';
END
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 3 — Read-only role and CONNECT
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];
GO

ALTER ROLE [db_datareader] ADD MEMBER [alameenbill_reader];
GRANT CONNECT TO [alameenbill_reader];
PRINT '[✓] Step 3: db_datareader + CONNECT granted.';
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 4 — Explicit SELECT grants on all dashboard tables
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];
GO

-- Bills / sales
GRANT SELECT ON dbo.bu000           TO [alameenbill_reader];
GRANT SELECT ON dbo.bi000           TO [alameenbill_reader];

-- Materials, groups
GRANT SELECT ON dbo.mt000           TO [alameenbill_reader];
GRANT SELECT ON dbo.gr000           TO [alameenbill_reader];

-- Warehouses
GRANT SELECT ON dbo.st000           TO [alameenbill_reader];

-- Currencies
GRANT SELECT ON dbo.my000           TO [alameenbill_reader];
GRANT SELECT ON dbo.mh000           TO [alameenbill_reader];

-- Cost centers
GRANT SELECT ON dbo.co000           TO [alameenbill_reader];

-- Salesmen / distribution
GRANT SELECT ON dbo.Distributor000  TO [alameenbill_reader];
GRANT SELECT ON dbo.DistDeviceST000 TO [alameenbill_reader];

-- Customers
GRANT SELECT ON dbo.cu000           TO [alameenbill_reader];
GRANT SELECT ON dbo.CustAddress000  TO [alameenbill_reader];

-- Accounting
GRANT SELECT ON dbo.ac000           TO [alameenbill_reader];
GRANT SELECT ON dbo.en000           TO [alameenbill_reader];

-- Bill patterns
GRANT SELECT ON dbo.bt000           TO [alameenbill_reader];

-- Inventory
GRANT SELECT ON dbo.ms000           TO [alameenbill_reader];

-- Infrastructure tables used by wrapped reports
GRANT SELECT ON dbo.Connections     TO [alameenbill_reader];
GRANT SELECT ON dbo.RepSrcs         TO [alameenbill_reader];

PRINT '[✓] Step 4: SELECT grants applied on all dashboard tables.';
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 5 — Wrapper stored procedures
--
--  Every wrapper is created WITH EXECUTE AS OWNER. This makes the body
--  of the wrapper run as dbo — so internal calls to Alameen's security
--  functions (fnGetUserSec, fnGetCurrentUserGUID, fnIsAdmin, …) and
--  internal reads on tables succeed without a per-object grant to the
--  reader login. The reader only ever needs EXECUTE on the wrapper.
--
--  Auto-discovery inside each wrapper:
--    - base currency from my000 (CurrencyVal = 1)
--    - report user from the most recent admin session in Connections
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];
GO

-- ─────────────────────────────────────────────────────────────────────
--  Wrapper #1: ameenbill_GetMatMovements
--  Underlying: repMatMoveMultiProduct
--  Purpose: movement ledger for one material.
-- ─────────────────────────────────────────────────────────────────────
IF OBJECT_ID('dbo.ameenbill_GetMatMovements', 'P') IS NOT NULL
    DROP PROCEDURE dbo.ameenbill_GetMatMovements;
GO

CREATE PROCEDURE dbo.ameenbill_GetMatMovements
    @MatGUID      uniqueidentifier = NULL,
    @GroupGUID    uniqueidentifier = NULL,
    @StartDate    datetime,
    @EndDate      datetime,
    @StoreGUID    uniqueidentifier = NULL,
    @CostGUID     uniqueidentifier = NULL,
    @PostedValue  int = 1,
    @UseUnit      int = 0,
    @Lang         bit = 0
WITH EXECUTE AS OWNER
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @NIL uniqueidentifier = '00000000-0000-0000-0000-000000000000';

    SET @MatGUID   = ISNULL(@MatGUID,   @NIL);
    SET @GroupGUID = ISNULL(@GroupGUID, @NIL);
    SET @StoreGUID = ISNULL(@StoreGUID, @NIL);
    SET @CostGUID  = ISNULL(@CostGUID,  @NIL);

    -- Auto-discover base currency
    DECLARE @CurrencyGUID uniqueidentifier;
    SELECT TOP 1 @CurrencyGUID = GUID FROM dbo.my000 WHERE CurrencyVal = 1;
    IF @CurrencyGUID IS NULL
        SET @CurrencyGUID = '378C0ABE-FB27-4E68-BB84-95A4F2D3A137';

    -- Auto-discover admin user
    DECLARE @UserGUID uniqueidentifier;
    SELECT TOP 1 @UserGUID = UserGUID
    FROM dbo.Connections
    WHERE BranchMask = 9223372036854775807
      AND UserGUID IS NOT NULL
      AND UserGUID <> @NIL
      AND HostId <> HOST_ID()
    ORDER BY login_time DESC;

    IF @UserGUID IS NULL
    BEGIN
        DECLARE @errMsg1 nvarchar(4000) =
            N'No Alameen admin session found in Connections. '
          + N'Please log in to Alameen at least once as an administrator.';
        RAISERROR(@errMsg1, 16, 1);
        RETURN;
    END

    DECLARE @RID float = CAST(RAND() * 2000000000 AS int);

    DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
    INSERT INTO dbo.Connections (UserGUID, BranchMask, UserNumber)
    VALUES (@UserGUID, 9223372036854775807, 1);

    BEGIN TRY
        EXEC dbo.repMatMoveMultiProduct
            @MatGUID               = @MatGUID,
            @GroupGUID             = @GroupGUID,
            @StartDate             = @StartDate,
            @EndDate               = @EndDate,
            @CustCondGuid          = @NIL,
            @StoreGUID             = @StoreGUID,
            @CostGUID              = @CostGUID,
            @PostedValue           = @PostedValue,
            @NotesContain          = '',
            @NotesNotContain       = '',
            @SrcTypesguid          = '4737E1CE-4983-487F-9DA9-503B8171AE4B',
            @UseUnit               = @UseUnit,
            @PrevBal               = 0,
            @CurrencyGUID          = @CurrencyGUID,
            @RID                   = @RID,
            @ShowChecked           = 0,
            @ItemChecked           = -1,
            @CheckForUsers         = 0,
            @Lang                  = @Lang,
            @Class                 = '',
            @MatCond               = @NIL,
            @SelectedUserGuid      = @NIL,
            @PriceType             = 128,
            @PricePolicy           = 120,
            @CurVal                = 1,
            @IsIncludeOpenedLC     = 0,
            @DetailCompositionMove = 0,
            @AccSum                = 0,
            @IsCalledByWeb         = 1;
    END TRY
    BEGIN CATCH
        DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
        THROW;
    END CATCH

    DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
END
GO

PRINT '[✓] Step 5a: Wrapper ameenbill_GetMatMovements created.';
GO

-- ─────────────────────────────────────────────────────────────────────
--  Wrapper #2: ameenbill_GetSnMovements
--  Underlying: SNMove
--  Purpose: serial number / IMEI movement ledger.
-- ─────────────────────────────────────────────────────────────────────
IF OBJECT_ID('dbo.ameenbill_GetSnMovements', 'P') IS NOT NULL
    DROP PROCEDURE dbo.ameenbill_GetSnMovements;
GO

CREATE PROCEDURE dbo.ameenbill_GetSnMovements
    @SN           nvarchar(200)    = NULL,
    @StartDate    datetime,
    @EndDate      datetime,
    @MatGUID      uniqueidentifier = NULL,
    @GroupGUID    uniqueidentifier = NULL,
    @StoreGUID    uniqueidentifier = NULL,
    @CustGUID     uniqueidentifier = NULL,
    @PostedValue  int = 1,
    @Lang         bit = 0
WITH EXECUTE AS OWNER
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @NIL uniqueidentifier = '00000000-0000-0000-0000-000000000000';

    SET @MatGUID   = ISNULL(@MatGUID,   @NIL);
    SET @GroupGUID = ISNULL(@GroupGUID, @NIL);
    SET @StoreGUID = ISNULL(@StoreGUID, @NIL);
    SET @CustGUID  = ISNULL(@CustGUID,  @NIL);
    SET @SN        = ISNULL(@SN, N'');

    -- Auto-discover base currency
    DECLARE @CurrencyGUID uniqueidentifier;
    DECLARE @CurrencyVal  float;
    SELECT TOP 1 @CurrencyGUID = GUID, @CurrencyVal = CurrencyVal
    FROM dbo.my000 WHERE CurrencyVal = 1;
    IF @CurrencyGUID IS NULL
    BEGIN
        SET @CurrencyGUID = 'AC056455-2676-4DEE-9F77-DAE474FD6734';
        SET @CurrencyVal  = 1;
    END

    -- Auto-discover admin user
    DECLARE @UserGUID uniqueidentifier;
    SELECT TOP 1 @UserGUID = UserGUID
    FROM dbo.Connections
    WHERE BranchMask = 9223372036854775807
      AND UserGUID IS NOT NULL
      AND UserGUID <> @NIL
      AND HostId <> HOST_ID()
    ORDER BY login_time DESC;

    IF @UserGUID IS NULL
    BEGIN
        DECLARE @errMsg2 nvarchar(4000) =
            N'No Alameen admin session found in Connections. '
          + N'Please log in to Alameen at least once as an administrator.';
        RAISERROR(@errMsg2, 16, 1);
        RETURN;
    END

    DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
    INSERT INTO dbo.Connections (UserGUID, BranchMask, UserNumber)
    VALUES (@UserGUID, 9223372036854775807, 1);

    BEGIN TRY
        EXEC dbo.SNMove
            @SNName         = @SN,
            @StartDate      = @StartDate,
            @EndDate        = @EndDate,
            @PostedValue    = @PostedValue,
            @NotesContain   = '',
            @NotesNotContain= '',
            @CurrencyGUID   = @CurrencyGUID,
            @CurrencyVal    = @CurrencyVal,
            @SrcTypesguid   = '1E065244-0F86-4774-87F2-2BED53B6C7BA',
            @ShowGroup      = 0,
            @MatGUID        = @MatGUID,
            @GroupGUID      = @GroupGUID,
            @StoreGUID      = @StoreGUID,
            @CustGUID       = @CustGUID,
            @AccGUID        = @NIL,
            @Lang           = @Lang,
            @MatCondGuid    = @NIL,
            @CostGUID       = @NIL,
            @SnStartWith    = @SN;
    END TRY
    BEGIN CATCH
        DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
        THROW;
    END CATCH

    DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
END
GO

PRINT '[✓] Step 5b: Wrapper ameenbill_GetSnMovements created.';
GO

-- ─────────────────────────────────────────────────────────────────────
--  Wrapper #3: ameenbill_GetCostCenterLedger
--  Underlying: RepCostGl
--  Purpose: general ledger for one cost center.
--
--  This wrapper NEEDS WITH EXECUTE AS OWNER because RepCostGl calls
--  fnGetUserSec internally. Without it, the reader gets
--  "EXECUTE permission was denied on the object 'fnGetUserSec'".
-- ─────────────────────────────────────────────────────────────────────
IF OBJECT_ID('dbo.ameenbill_GetCostCenterLedger', 'P') IS NOT NULL
    DROP PROCEDURE dbo.ameenbill_GetCostCenterLedger;
GO

CREATE PROCEDURE dbo.ameenbill_GetCostCenterLedger
    @CostGUID     uniqueidentifier,
    @AccGUID      uniqueidentifier = NULL,
    @StartDate    datetime,
    @EndDate      datetime,
    @PrevBal      bit = 1,
    @Posted       int = 1,
    @SumCosts     int = 0,
    @ShowMainCost bit = 0,
    @Lang         bit = 0
WITH EXECUTE AS OWNER
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @NIL uniqueidentifier = '00000000-0000-0000-0000-000000000000';
    SET @AccGUID = ISNULL(@AccGUID, @NIL);

    -- Auto-discover base currency
    DECLARE @CurrencyGUID uniqueidentifier;
    DECLARE @CurrencyVal  float;
    SELECT TOP 1 @CurrencyGUID = GUID, @CurrencyVal = CurrencyVal
    FROM dbo.my000 WHERE CurrencyVal = 1;
    IF @CurrencyGUID IS NULL
    BEGIN
        SET @CurrencyGUID = 'AC056455-2676-4DEE-9F77-DAE474FD6734';
        SET @CurrencyVal  = 1;
    END

    -- Auto-discover admin user
    DECLARE @UserGUID uniqueidentifier;
    SELECT TOP 1 @UserGUID = UserGUID
    FROM dbo.Connections
    WHERE BranchMask = 9223372036854775807
      AND UserGUID IS NOT NULL
      AND UserGUID <> @NIL
      AND HostId <> HOST_ID()
    ORDER BY login_time DESC;

    IF @UserGUID IS NULL
    BEGIN
        DECLARE @errMsg nvarchar(4000) =
            N'No Alameen admin session found in Connections. '
          + N'Please log in to Alameen at least once as an administrator.';
        RAISERROR(@errMsg, 16, 1);
        RETURN;
    END

    -- Ensure RepSrcs has this report's namespace
    IF NOT EXISTS (SELECT 1 FROM dbo.RepSrcs
                   WHERE IdTbl = '25DAF4D1-7F61-46E4-A5C0-C6F5FD4BDD0E')
    BEGIN
        INSERT INTO dbo.RepSrcs (IdTbl, IdType, IdSubType)
        SELECT '25DAF4D1-7F61-46E4-A5C0-C6F5FD4BDD0E', GUID, 2
        FROM dbo.bt000;
    END

    DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
    INSERT INTO dbo.Connections (UserGUID, BranchMask, UserNumber)
    VALUES (@UserGUID, 9223372036854775807, 1);

    BEGIN TRY
        DECLARE @ToPostDate datetime =
            DATEADD(second, 86399, CAST(CAST(@EndDate AS date) AS datetime));

        EXEC dbo.RepCostGl
            @AccPtr         = @AccGUID,
            @CostPtr        = @CostGUID,
            @Class          = N'',
            @StartDate      = @StartDate,
            @EndDate        = @EndDate,
            @CurGUID        = @CurrencyGUID,
            @CurVal         = @CurrencyVal,
            @Lang           = @Lang,
            @EntryUserGuid  = @NIL,
            @PrevBal        = @PrevBal,
            @Posted         = @Posted,
            @SumCosts       = @SumCosts,
            @FromPostDate   = @StartDate,
            @ToPostDate     = @ToPostDate,
            @SrcGuid        = '25DAF4D1-7F61-46E4-A5C0-C6F5FD4BDD0E',
            @DocumentStr    = N'',
            @ShowMainCost   = @ShowMainCost;
    END TRY
    BEGIN CATCH
        DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
        THROW;
    END CATCH

    DELETE FROM dbo.Connections WHERE HostId = HOST_ID() AND HostName = HOST_NAME();
END
GO

PRINT '[✓] Step 5c: Wrapper ameenbill_GetCostCenterLedger created.';
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 6 — EXECUTE grants on wrapper procedures
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];
GO

IF OBJECT_ID('dbo.ameenbill_GetMatMovements', 'P') IS NOT NULL
BEGIN
    GRANT EXECUTE ON dbo.ameenbill_GetMatMovements TO [alameenbill_reader];
    PRINT '[✓] Step 6a: EXECUTE on ameenbill_GetMatMovements.';
END

IF OBJECT_ID('dbo.ameenbill_GetSnMovements', 'P') IS NOT NULL
BEGIN
    GRANT EXECUTE ON dbo.ameenbill_GetSnMovements TO [alameenbill_reader];
    PRINT '[✓] Step 6b: EXECUTE on ameenbill_GetSnMovements.';
END

IF OBJECT_ID('dbo.ameenbill_GetCostCenterLedger', 'P') IS NOT NULL
BEGIN
    GRANT EXECUTE ON dbo.ameenbill_GetCostCenterLedger TO [alameenbill_reader];
    PRINT '[✓] Step 6c: EXECUTE on ameenbill_GetCostCenterLedger.';
END

-- Add future wrappers here:
-- IF OBJECT_ID('dbo.ameenbill_GetSomething', 'P') IS NOT NULL
-- BEGIN
--     GRANT EXECUTE ON dbo.ameenbill_GetSomething TO [alameenbill_reader];
--     PRINT '[✓] Step 6d: EXECUTE on ameenbill_GetSomething.';
-- END
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 7 — Populate RepSrcs for wrapped reports
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];
GO

DECLARE @srcMat uniqueidentifier = '4737E1CE-4983-487F-9DA9-503B8171AE4B';
DECLARE @srcSn  uniqueidentifier = '1E065244-0F86-4774-87F2-2BED53B6C7BA';
DECLARE @srcCC  uniqueidentifier = '25DAF4D1-7F61-46E4-A5C0-C6F5FD4BDD0E';

IF OBJECT_ID('dbo.RepSrcs', 'U') IS NOT NULL
BEGIN
    DELETE FROM RepSrcs WHERE IdTbl = @srcMat;
    INSERT INTO RepSrcs (IdTbl, IdType, IdSubType)
    SELECT @srcMat, GUID, 0 FROM bt000;
    PRINT '[✓] Step 7a: RepSrcs populated for material movements ('
        + CAST(@@ROWCOUNT AS varchar(10)) + ' rows).';

    DELETE FROM RepSrcs WHERE IdTbl = @srcSn;
    INSERT INTO RepSrcs (IdTbl, IdType, IdSubType)
    SELECT @srcSn, GUID, 0 FROM bt000;
    PRINT '[✓] Step 7b: RepSrcs populated for SN movements ('
        + CAST(@@ROWCOUNT AS varchar(10)) + ' rows).';

    DELETE FROM RepSrcs WHERE IdTbl = @srcCC;
    INSERT INTO RepSrcs (IdTbl, IdType, IdSubType)
    SELECT @srcCC, GUID, 2 FROM bt000;
    PRINT '[✓] Step 7c: RepSrcs populated for cost center ledger ('
        + CAST(@@ROWCOUNT AS varchar(10)) + ' rows).';
END
ELSE
    PRINT '[·] Step 7: RepSrcs table not present (skip).';
GO

-- ═════════════════════════════════════════════════════════════════════
--  STEP 8 — Verification
-- ═════════════════════════════════════════════════════════════════════
USE [AlbassaDB2026];
GO

PRINT '';
PRINT '════════════════════════════════════════════════════════════';
PRINT '  VERIFICATION — all rows should read PASS';
PRINT '════════════════════════════════════════════════════════════';

SELECT 'user exists in DB' AS test,
       CASE WHEN EXISTS (SELECT 1 FROM sys.database_principals WHERE name = 'alameenbill_reader')
            THEN 'PASS' ELSE 'FAIL' END AS result
UNION ALL
SELECT 'in db_datareader',
       CASE WHEN IS_ROLEMEMBER('db_datareader', 'alameenbill_reader') = 1
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'can SELECT bu000',
       CASE WHEN EXISTS (
            SELECT 1 FROM sys.database_permissions pe
            WHERE pe.grantee_principal_id = USER_ID('alameenbill_reader')
              AND pe.major_id = OBJECT_ID('dbo.bu000')
              AND pe.permission_name = 'SELECT'
              AND pe.state_desc = 'GRANT')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'wrapper movements exists',
       CASE WHEN OBJECT_ID('dbo.ameenbill_GetMatMovements', 'P') IS NOT NULL
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'wrapper SN exists',
       CASE WHEN OBJECT_ID('dbo.ameenbill_GetSnMovements', 'P') IS NOT NULL
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'wrapper cost center exists',
       CASE WHEN OBJECT_ID('dbo.ameenbill_GetCostCenterLedger', 'P') IS NOT NULL
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'EXECUTE on movements',
       CASE WHEN EXISTS (
            SELECT 1 FROM sys.database_permissions pe
            WHERE pe.grantee_principal_id = USER_ID('alameenbill_reader')
              AND pe.major_id = OBJECT_ID('dbo.ameenbill_GetMatMovements')
              AND pe.permission_name = 'EXECUTE'
              AND pe.state_desc = 'GRANT')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'EXECUTE on SN',
       CASE WHEN EXISTS (
            SELECT 1 FROM sys.database_permissions pe
            WHERE pe.grantee_principal_id = USER_ID('alameenbill_reader')
              AND pe.major_id = OBJECT_ID('dbo.ameenbill_GetSnMovements')
              AND pe.permission_name = 'EXECUTE'
              AND pe.state_desc = 'GRANT')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'EXECUTE on cost center',
       CASE WHEN EXISTS (
            SELECT 1 FROM sys.database_permissions pe
            WHERE pe.grantee_principal_id = USER_ID('alameenbill_reader')
              AND pe.major_id = OBJECT_ID('dbo.ameenbill_GetCostCenterLedger')
              AND pe.permission_name = 'EXECUTE'
              AND pe.state_desc = 'GRANT')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'RepSrcs: material movements',
       CASE WHEN EXISTS (
            SELECT 1 FROM RepSrcs
            WHERE IdTbl = '4737E1CE-4983-487F-9DA9-503B8171AE4B')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'RepSrcs: SN movements',
       CASE WHEN EXISTS (
            SELECT 1 FROM RepSrcs
            WHERE IdTbl = '1E065244-0F86-4774-87F2-2BED53B6C7BA')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'RepSrcs: cost center ledger',
       CASE WHEN EXISTS (
            SELECT 1 FROM RepSrcs
            WHERE IdTbl = '25DAF4D1-7F61-46E4-A5C0-C6F5FD4BDD0E')
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'no DENY for reader',
       CASE WHEN NOT EXISTS (
            SELECT 1 FROM sys.database_permissions pe
            WHERE pe.grantee_principal_id = USER_ID('alameenbill_reader')
              AND pe.state_desc = 'DENY')
            THEN 'PASS' ELSE 'FAIL' END;
GO

-- Final: effective permissions check via impersonation
EXECUTE AS USER = 'alameenbill_reader';
SELECT 'can EXECUTE movements' AS test,
       CASE WHEN HAS_PERMS_BY_NAME('dbo.ameenbill_GetMatMovements', 'OBJECT', 'EXECUTE') = 1
            THEN 'PASS' ELSE 'FAIL' END AS result
UNION ALL
SELECT 'can EXECUTE SN',
       CASE WHEN HAS_PERMS_BY_NAME('dbo.ameenbill_GetSnMovements', 'OBJECT', 'EXECUTE') = 1
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'can EXECUTE cost center',
       CASE WHEN HAS_PERMS_BY_NAME('dbo.ameenbill_GetCostCenterLedger', 'OBJECT', 'EXECUTE') = 1
            THEN 'PASS' ELSE 'FAIL' END
UNION ALL
SELECT 'can SELECT bu000',
       CASE WHEN HAS_PERMS_BY_NAME('dbo.bu000', 'OBJECT', 'SELECT') = 1
            THEN 'PASS' ELSE 'FAIL' END;
REVERT;
GO

PRINT '';
PRINT '════════════════════════════════════════════════════════════';
PRINT '  Setup complete.';
PRINT '';
PRINT '  NOTE: after this script runs, log into the Alameen client';
PRINT '  at least once as an administrator (e.g. "مدير"). This';
PRINT '  creates the admin session in dbo.Connections, which the';
PRINT '  wrappers read to determine which user to impersonate.';
PRINT '════════════════════════════════════════════════════════════';
GO
-- ═════════════════════════════════════════════════════════════════════
--  Check the Wrappers installed
-- ═════════════════════════════════════════════════════════════════════
SELECT name FROM sys.procedures
WHERE name IN ('repMatMoveMultiProduct', 'SNMove');

-- What SrcTypesguids already exist in this install?
SELECT IdTbl, COUNT(*) AS n
FROM RepSrcs
GROUP BY IdTbl
ORDER BY n DESC;