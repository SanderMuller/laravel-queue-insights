-- Atomically update both the (class) and (class, connection) duration
-- hashes in one round-trip. Inlines UpdateMaxDuration.lua's CAS twice so
-- the aggregate and per-connection rows can't drift apart on a crash.
--
-- KEYS[1] = aggregate duration hash (duration:{class})
-- KEYS[2] = per-connection duration hash (duration:{class}:{connection})
-- ARGV[1] = duration in ms (number as string)
-- ARGV[2] = whole-key TTL in seconds
local candidate = tonumber(ARGV[1])
if candidate == nil then
    return 0
end

for i = 1, 2 do
    redis.call('HINCRBY', KEYS[i], 'count', 1)
    redis.call('HINCRBYFLOAT', KEYS[i], 'sum_ms', candidate)
    local current = redis.call('HGET', KEYS[i], 'max_ms')
    if current == false or tonumber(current) < candidate then
        redis.call('HSET', KEYS[i], 'max_ms', candidate)
    end
    redis.call('EXPIRE', KEYS[i], ARGV[2])
end

return 1
