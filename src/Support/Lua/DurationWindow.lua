-- Sum the hourly duration bucket hashes passed as KEYS into one window:
-- returns {count, sum_ms, max_ms}. sum_ms and max_ms come back as strings
-- (Lua numbers would be truncated to integers); max_ms is false when no
-- bucket holds a run.
local count = 0
local sum = 0
local max = nil

for i = 1, #KEYS do
    local fields = redis.call('HMGET', KEYS[i], 'count', 'sum_ms', 'max_ms')
    local c = tonumber(fields[1])
    if c ~= nil and c > 0 then
        count = count + c
        sum = sum + (tonumber(fields[2]) or 0)
        local m = tonumber(fields[3])
        if m ~= nil and (max == nil or m > max) then
            max = m
        end
    end
end

return {count, tostring(sum), max ~= nil and tostring(max) or false}
