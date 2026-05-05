-- Atomically INCR + EXPIREAT a (aggregate, per-connection) counter pair.
-- Replaces the four-command foreach in RecordJobProcessed / RecordJobFailed
-- so a listener crash between the aggregate and per-connection write can
-- no longer leave the pair out of sync. Both INCRs land or neither does.
--
-- KEYS[1] = aggregate counter key (e.g. processed:{class}:{bucket})
-- KEYS[2] = per-connection counter key (e.g. processed:{class}:{conn}:{bucket})
-- ARGV[1] = expire-at unix timestamp (matches retention.*_counters_days)
redis.call('INCR', KEYS[1])
redis.call('EXPIREAT', KEYS[1], ARGV[1])
redis.call('INCR', KEYS[2])
redis.call('EXPIREAT', KEYS[2], ARGV[1])
return 1
