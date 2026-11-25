-- =====================================================
--  VerificaNews - schema database MySQL / MariaDB
--  Importa questo file da phpMyAdmin (scheda "Importa")
--  oppure da terminale:  mysql -u root -p < database.sql
-- =====================================================

CREATE DATABASE IF NOT EXISTS fakenews_checker
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fakenews_checker;

-- Elenco di domini con una reputazione nota (modificabile da phpMyAdmin)
CREATE TABLE IF NOT EXISTS domini (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dominio    VARCHAR(190) NOT NULL UNIQUE,
  categoria  ENUM('affidabile','satirico','inaffidabile') NOT NULL,
  nota       VARCHAR(255) NULL,
  creato_il  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Storico delle analisi effettuate
CREATE TABLE IF NOT EXISTS analisi (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url        VARCHAR(2048) NOT NULL,
  dominio    VARCHAR(255)  NOT NULL,
  titolo     VARCHAR(255)  NULL,
  punteggio  TINYINT UNSIGNED NOT NULL,
  esito      ENUM('affidabile','dubbio','sospetta','satirico') NOT NULL,
  dettagli   JSON NULL,
  ip_hash    CHAR(64) NOT NULL,          -- hash dell'IP, serve solo per limitare le richieste
  creato_il  DATETIME NOT NULL,
  INDEX idx_dominio (dominio),
  INDEX idx_creato (creato_il),
  INDEX idx_esito (esito),
  INDEX idx_ip_tempo (ip_hash, creato_il)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Esempi iniziali: NON sono un giudizio editoriale, aggiungi/rimuovi i domini che vuoi.
INSERT IGNORE INTO domini (dominio, categoria, nota) VALUES
  ('ansa.it',      'affidabile', 'Agenzia di stampa italiana'),
  ('reuters.com',  'affidabile', 'Agenzia di stampa internazionale'),
  ('apnews.com',   'affidabile', 'Agenzia di stampa internazionale'),
  ('bbc.com',      'affidabile', 'Emittente pubblica britannica'),
  ('bbc.co.uk',    'affidabile', 'Emittente pubblica britannica'),
  ('who.int',      'affidabile', 'Organizzazione Mondiale della Sanita'),
  ('europa.eu',    'affidabile', 'Istituzioni dell''Unione Europea'),
  ('istat.it',     'affidabile', 'Istituto nazionale di statistica'),
  ('lercio.it',    'satirico',   'Sito di satira'),
  ('theonion.com', 'satirico',   'Sito di satira');

-- (Consigliato in produzione) utente dedicato con permessi minimi:
-- CREATE USER 'fakenews'@'localhost' IDENTIFIED BY 'metti-una-password-lunga';
-- GRANT SELECT, INSERT ON fakenews_checker.* TO 'fakenews'@'localhost';
