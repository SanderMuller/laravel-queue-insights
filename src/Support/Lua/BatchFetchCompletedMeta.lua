-- Bulk-fetch completed-stream entries for a list of stream IDs in one
-- round-trip. Replaces a per-ID XRANGE pipeline so the batch-modal item
-- enrichment stays O(1) RTT even on Redis Cluster connections (where the
-- package's RedisPipeline helper transparently downgrades to eager
-- per-command execution via EagerCommandCollector — N round-trips for N
-- pipelined commands).
--
-- KEYS[1] = stream key (qi:completed), single-slot via the package's
--           hash-tag prefix so this script is cluster-safe.
-- ARGV[1..N] = stream IDs.
--
-- Returns: a list parallel to ARGV. Each element is the matching entry's
-- field list (Redis Lua flat list: {field, value, field, value, ...}) or
-- an empty list `{}` when the entry has been trimmed out of the stream.
local out = {}
for i = 1, #ARGV do
    local entries = redis.call('XRANGE', KEYS[1], ARGV[i], ARGV[i])
    -- entries[1] is `{id, {field, value, ...}}`. Caller already has the
    -- id (it's ARGV[i]) so only the field list is interesting.
    if entries[1] then
        out[i] = entries[1][2]
    else
        out[i] = {}
    end
end
return out
