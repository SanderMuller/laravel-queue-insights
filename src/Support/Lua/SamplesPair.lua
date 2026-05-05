-- Atomically RPUSH + LTRIM + EXPIRE both the (class) and (class, connection)
-- duration-samples lists.
--
-- KEYS[1] = aggregate samples list (duration:samples:{class})
-- KEYS[2] = per-connection samples list (duration:samples:{class}:{connection})
-- ARGV[1] = duration in ms (number as string — pushed verbatim, list values are strings)
-- ARGV[2] = cap (max retained samples per list)
-- ARGV[3] = whole-key TTL in seconds
local cap = tonumber(ARGV[2])
if cap == nil or cap < 1 then
    return 0
end

for i = 1, 2 do
    redis.call('RPUSH', KEYS[i], ARGV[1])
    redis.call('LTRIM', KEYS[i], -cap, -1)
    redis.call('EXPIRE', KEYS[i], ARGV[3])
end

return 1
