-- Remove zset members whose pending:{uuid} hash is gone. A member whose hash
-- still exists is kept, so a write path with a longer hash TTL never loses
-- a live row.
--
-- KEYS[1]    = pending-zset / inflight-zset / pending-orphans key
-- KEYS[2..n] = pending:{uuid} hash for ARGV[i]
-- ARGV[i]    = uuid
local reaped = 0
for i, uuid in ipairs(ARGV) do
    if redis.call('EXISTS', KEYS[i + 1]) == 0 then
        reaped = reaped + redis.call('ZREM', KEYS[1], uuid)
    end
end

return reaped
