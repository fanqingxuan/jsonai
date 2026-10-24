-- Explicit opt-in initialization. All runtime state and both memory kinds share this table.
CREATE TABLE IF NOT EXISTS agent_state (
  id CHAR(64) NOT NULL PRIMARY KEY,
  namespace VARCHAR(128) NOT NULL,
  state_key VARCHAR(512) NOT NULL,
  payload TEXT NULL
);
CREATE INDEX IF NOT EXISTS agent_state_namespace_idx ON agent_state (namespace);
