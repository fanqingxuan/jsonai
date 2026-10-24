-- Explicit opt-in initialization. All runtime state and both memory kinds share this table.
CREATE TABLE IF NOT EXISTS agent_state (
  id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  namespace VARBINARY(128) NOT NULL,
  state_key VARBINARY(512) NOT NULL,
  payload LONGTEXT NULL,
  INDEX agent_state_namespace_idx (namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
