-- Atomically transition a uuid from pending → in-flight.
-- Either every write lands or none do — protects the dashboard from
-- intermediate states where a running job is missing from both groups
-- (pending zset stripped + inflight zset add failed) or stuck in both.
--
-- KEYS[1] = pending:{uuid} hash
-- KEYS[2] = pending-zset:{conn}:{queue}
-- KEYS[3] = inflight-zset:{conn}:{queue}
-- ARGV[1] = uuid
-- ARGV[2] = started_at (unix seconds)
-- ARGV[3] = ttl seconds (applied to hash + inflight zset)
redis.call('HSET', KEYS[1], 'state', 'in_flight', 'started_at', ARGV[2])
redis.call('EXPIRE', KEYS[1], ARGV[3])

redis.call('ZREM', KEYS[2], ARGV[1])

redis.call('ZADD', KEYS[3], ARGV[2], ARGV[1])
redis.call('EXPIRE', KEYS[3], ARGV[3])

return 1
