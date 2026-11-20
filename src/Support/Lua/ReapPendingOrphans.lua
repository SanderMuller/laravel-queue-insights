-- Remove pending-zset members and their pending:{uuid} hashes, but only
-- members still in the zset: a worker that moved one to in-flight between
-- the candidate read and this script keeps its (now in-flight) hash.
--
-- KEYS[1]    = pending-zset:{conn}:{queue}
-- KEYS[2..n] = pending:{uuid} hash for ARGV[i]
-- ARGV[i]    = uuid
local reaped = 0
for i, uuid in ipairs(ARGV) do
    if redis.call('ZREM', KEYS[1], uuid) == 1 then
        redis.call('DEL', KEYS[i + 1])
        reaped = reaped + 1
    end
end

return reaped
