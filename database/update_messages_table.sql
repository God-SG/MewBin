ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0;

UPDATE messages SET is_read = 1 WHERE id = ?;
