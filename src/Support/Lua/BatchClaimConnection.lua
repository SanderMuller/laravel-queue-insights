-- Atomically claim a batchId for a given connection and, on the winning
-- write, stamp the per-connection batches roster.
--
-- Heterogeneous batches (Bus::batch with jobs on multiple connections)
-- need a single arbiter: the FIRST JobQueued event determines which
-- connection's per-connection index gets the batchId. Without this Lua,
-- two concurrent JobQueued events on different connections both observe
-- an empty :connection pointer and both ZADD into their own per-conn
-- index, fanning the batch out to every connection it touches — the
-- opposite of first-write-wins.
--
-- KEYS[1] = qi:batch:{id}:connection      (string pointer; arbiter)
-- KEYS[2] = qi:batches:index:{connection} (per-connection zset roster)
-- KEYS[3] = qi:batch-uuid-conn:{uuid}     (member uuid -> connection)
-- ARGV[1] = connection name (pointer + uuid-conn value)
-- ARGV[2] = ttl seconds (matches batches.ttl_seconds)
-- ARGV[3] = roster score (now timestamp)
-- ARGV[4] = batchId (zset member)
-- ARGV[5] = roster prune cutoff (now - ttl)
--
-- Returns 1 when this caller is the winning connection, 0 otherwise.
local set_result = redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2])
if set_result then
    redis.call('ZADD', KEYS[2], ARGV[3], ARGV[4])
    redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', ARGV[5])
else
    -- Pointer existed — refresh its TTL so it doesn't age out while the
    -- batch is still actively dispatching jobs. Otherwise the pointer's
    -- lifetime is bounded by the FIRST JobQueued's SET TTL while the
    -- per-connection roster keeps getting bumped, breaking
    -- BatchScopeFilter's ownership read once the pointer expires.
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end

-- Per-uuid side-key for the heterogeneous-batch detail-view scope filter.
-- Always written (not just on the winning claim) so a foreign-connection
-- member of the same batch records its own connection here, letting the
-- scope filter keep dropping it after JobProcessed deletes the pending
-- hash. Saves a Redis round-trip vs a separate SETEX in PHP.
redis.call('SETEX', KEYS[3], ARGV[2], ARGV[1])

if set_result then
    return 1
end
return 0
