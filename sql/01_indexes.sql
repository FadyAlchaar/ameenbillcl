/* ------------------------------------------------------------------
   AmeenBill — supporting indexes.

   Run once against AlbassaDB2026.

   Why: sse_bills.php runs
       SELECT MAX(CreateDate), MAX(LastUpdateDate) FROM bu000
   every 2 seconds, PER CONNECTED TAB. Without these indexes that is two
   full scans of bu000 each time, and the cost grows with both the table
   size and the number of open dashboards. With them, MAX() becomes a
   single-row seek at the end of the index.

   The CreateDate index also serves the dashboard's
       ORDER BY b.CreateDate DESC ... OFFSET/FETCH
   pagination, which otherwise sorts the whole table on every page load.

   These are read-path indexes on a table Alameen writes to. They add a
   small cost to each INSERT/UPDATE. Create them during a quiet period
   and keep ONLINE = ON if the edition supports it.
------------------------------------------------------------------ */

IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_bu000_CreateDate'
                 AND object_id = OBJECT_ID('dbo.bu000'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_bu000_CreateDate
        ON dbo.bu000 (CreateDate DESC);
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_bu000_LastUpdateDate'
                 AND object_id = OBJECT_ID('dbo.bu000'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_bu000_LastUpdateDate
        ON dbo.bu000 (LastUpdateDate DESC);
END
GO

/* Bill detail lookups: bi000 is read by ParentGUID on every bill click
   and again every 10s while a bill is open. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes
               WHERE name = 'IX_bi000_ParentGUID'
                 AND object_id = OBJECT_ID('dbo.bi000'))
BEGIN
    CREATE NONCLUSTERED INDEX IX_bi000_ParentGUID
        ON dbo.bi000 (ParentGUID);
END
GO
