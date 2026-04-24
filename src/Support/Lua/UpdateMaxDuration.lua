-- Atomically update a duration hash's max_ms field.
-- KEYS[1] = hash key (e.g. {prefix}duration:{class})
-- ARGV[1] = candidate duration in ms (number as string)
-- Writes ARGV[1] to max_ms iff the new value is larger than current (or field missing).
-- Returns 1 if updated, 0 if unchanged.
local current = redis.call('HGET', KEYS[1], 'max_ms')
local candidate = tonumber(ARGV[1])
if candidate == nil then
    return 0
end
if current == false or tonumber(current) < candidate then
    redis.call('HSET', KEYS[1], 'max_ms', candidate)
    return 1
end
return 0
