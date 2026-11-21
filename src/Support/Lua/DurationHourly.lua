-- Add one run to each hourly duration bucket hash (count, sum_ms, max_ms)
-- in one round trip: the aggregate bucket, plus the per-connection one
-- when a second key is passed.
--
-- KEYS[n] = duration:hourly:{class}[:{connection}]:{bucket}
-- ARGV[1] = duration in ms (number as string)
-- ARGV[2] = expire-at unix timestamp
local candidate = tonumber(ARGV[1])
if candidate == nil then
    return 0
end

for i = 1, #KEYS do
    redis.call('HINCRBY', KEYS[i], 'count', 1)
    redis.call('HINCRBYFLOAT', KEYS[i], 'sum_ms', candidate)
    local current = redis.call('HGET', KEYS[i], 'max_ms')
    if current == false or tonumber(current) < candidate then
        redis.call('HSET', KEYS[i], 'max_ms', candidate)
    end
    redis.call('EXPIREAT', KEYS[i], ARGV[2])
end

return 1
