-- Atomically SETEX both the (class) and (class, connection) variants of a
-- string key. Used for last_run:{class} and last_run:{class}:{connection}.
--
-- KEYS[1] = aggregate key (last_run:{class})
-- KEYS[2] = per-connection key (last_run:{class}:{connection})
-- ARGV[1] = TTL in seconds
-- ARGV[2] = value (e.g. ISO8601 timestamp)
redis.call('SETEX', KEYS[1], ARGV[1], ARGV[2])
redis.call('SETEX', KEYS[2], ARGV[1], ARGV[2])
return 1
