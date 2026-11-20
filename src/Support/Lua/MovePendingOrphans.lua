-- Move pending-zset members to the orphan set, keeping their score and
-- their pending:{uuid} hash. Only members still in the pending zset move:
-- one a worker moved to in-flight in the meantime stays where it is.
--
-- KEYS[1]    = pending-zset:{conn}:{queue}
-- KEYS[2]    = pending-orphans:{conn}:{queue}
-- ARGV[1]    = ttl seconds for the orphan set
-- ARGV[2..n] = uuid
local moved = 0
for i = 2, #ARGV do
    local score = redis.call('ZSCORE', KEYS[1], ARGV[i])
    if score and redis.call('ZREM', KEYS[1], ARGV[i]) == 1 then
        redis.call('ZADD', KEYS[2], score, ARGV[i])
        moved = moved + 1
    end
end

if moved > 0 then
    redis.call('EXPIRE', KEYS[2], ARGV[1])
end

return moved
