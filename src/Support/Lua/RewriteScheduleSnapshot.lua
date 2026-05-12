-- Atomically rewrite the schedule snapshot.
--
-- The PHP-side `ScheduleSnapshotter::rebuild()` previously issued
-- `DEL tasks`, `DEL order`, N × (`HSET`, `RPUSH`), `SET hash`, `SET at`
-- as separate round-trips. Two concurrent boots (FPM + queue workers
-- after a deploy) could interleave their DELs and RPUSHes, producing
-- duplicate entries in the order list — observed on staging as 157
-- captured tasks for ~20 unique task_keys.
--
-- Running the rewrite inside a single Lua script makes it atomic
-- against other clients: concurrent rebuilders become last-writer-wins
-- instead of interleaved-corruption.
--
-- KEYS[1] = tasks hash key
-- KEYS[2] = order list key
-- KEYS[3] = snapshot hash key
-- KEYS[4] = snapshot timestamp key
-- ARGV[1] = snapshot hash value
-- ARGV[2] = snapshot timestamp value
-- ARGV[3..] = alternating task_key, json_summary pairs
redis.call('DEL', KEYS[1])
redis.call('DEL', KEYS[2])

local i = 3
while i <= #ARGV do
    redis.call('HSET', KEYS[1], ARGV[i], ARGV[i + 1])
    redis.call('RPUSH', KEYS[2], ARGV[i])
    i = i + 2
end

redis.call('SET', KEYS[3], ARGV[1])
redis.call('SET', KEYS[4], ARGV[2])

return 1
