-- Push a parent UUID onto a chain-claim list and refresh the TTL.
-- Atomic so a write that succeeds is guaranteed to be addressable
-- by `chain_lineage.claim_ttl_seconds`; a non-atomic LPUSH+EXPIRE
-- could leak a key without TTL on a crash between commands.
--
-- KEYS[1] = claim list key
-- ARGV[1] = parent uuid
-- ARGV[2] = ttl seconds
redis.call('LPUSH', KEYS[1], ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[2])
return 1
