-- Atomically ZADD a class into both the global roster and the per-connection
-- roster, refreshing the per-connection roster's TTL.
--
-- The aggregate `qi:classes` zset deliberately has no whole-key TTL — it is
-- the eviction-by-snapshot-command roster (30 d cutoff handled by
-- QueueInsightsSnapshotCommand). The per-connection roster carries an EXPIRE
-- bumped on every event so dormant connections fall off without needing the
-- snapshot command to enumerate them.
--
-- KEYS[1] = aggregate classes zset (qi:classes)
-- KEYS[2] = per-connection classes zset (qi:classes:{connection})
-- ARGV[1] = score (now ts as string)
-- ARGV[2] = class member
-- ARGV[3] = per-connection roster TTL in seconds
redis.call('ZADD', KEYS[1], ARGV[1], ARGV[2])
redis.call('ZADD', KEYS[2], ARGV[1], ARGV[2])
redis.call('EXPIRE', KEYS[2], ARGV[3])
return 1
