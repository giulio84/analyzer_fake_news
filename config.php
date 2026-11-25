<?php
declare(strict_types=1);

/*
 * Configurazione. In locale (XAMPP/MAMP) i valori predefiniti funzionano così come sono.
 * In produzione imposta le variabili d'ambiente DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_SALT
 * e NON usare l'utente "root".
 */
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'fakenews_checker');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('APP_SALT', getenv('APP_SALT') ?: 'cambia-questa-stringa-casuale');

const LIMITE_RICHIESTE_MINUTO = 10;   // analisi massime al minuto per ogni visitatore
const MAX_BYTE_PAGINA = 1500000;      // dimensione massima della pagina scaricata (1,5 MB)

date_default_timezone_set('Europe/Rome');
