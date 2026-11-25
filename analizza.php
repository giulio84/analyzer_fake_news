<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PUNTEGGIO_BASE = 40;
const SOGLIA_AFFIDABILE = 65;
const SOGLIA_DUBBIO = 40;

/* ====================== Validazione dell'input ====================== */

function normalizzaUrl(string $url): ?string
{
    if ($url === '' || strlen($url) > 2048) {
        return null;
    }
    // Schemi diversi da http/https (ftp://, file://, javascript: ...) non sono ammessi
    if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) && !preg_match('#^https?://#i', $url)) {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $p = parse_url($url);
    if ($p === false || empty($p['host']) || empty($p['scheme'])) {
        return null;
    }
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return null;
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    return $url;
}

/* ============== Rete: download con protezione SSRF ============== */

/** Restituisce gli IPv4 dell'host solo se TUTTI sono pubblici, altrimenti null. */
function ipPubblici(string $host): ?array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = gethostbynamel($host);
        if (!$ips) {
            return null;
        }
    }
    foreach ($ips as $ip) {
        $valido = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
        if ($valido === false) {
            return null;
        }
    }
    return $ips;
}

function risolviUrl(string $base, string $rel): string
{
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $p = parse_url($base);
    $origine = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($rel, '//') === 0) {
        return $p['scheme'] . ':' . $rel;
    }
    if (strpos($rel, '/') === 0) {
        return $origine . $rel;
    }
    $percorso = $p['path'] ?? '/';
    $cartella = preg_replace('#/[^/]*$#', '/', $percorso);
    return $origine . $cartella . $rel;
}

function scaricaPagina(string $url): array
{
    $esito = ['ok' => false, 'codice' => 0, 'html' => '', 'tipo' => '', 'errore' => null];

    for ($salto = 0; $salto <= 3; $salto++) {
        $p = parse_url($url);
        $schema = strtolower($p['scheme'] ?? '');
        $host = strtolower($p['host'] ?? '');
        $porta = $p['port'] ?? ($schema === 'https' ? 443 : 80);

        if (!in_array($schema, ['http', 'https'], true) || $host === '' || !in_array($porta, [80, 443], true)) {
            $esito['errore'] = 'Indirizzo non consentito (solo http/https sulle porte standard).';
            return $esito;
        }
        $ips = ipPubblici($host);
        if ($ips === null) {
            $esito['errore'] = 'Host non raggiungibile oppure non consentito.';
            return $esito;
        }

        $corpo = '';
        $location = null;
        $troncato = false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,            // i redirect li gestiamo noi, ricontrollando l'host
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE        => [$host . ':' . $porta . ':' . $ips[0]], // blocca il DNS sull'IP verificato
            CURLOPT_USERAGENT      => 'VerificaNewsBot/1.0 (progetto didattico)',
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: it,en;q=0.8'],
            CURLOPT_HEADERFUNCTION => function ($h, string $riga) use (&$location): int {
                if (stripos($riga, 'location:') === 0) {
                    $location = trim(substr($riga, 9));
                }
                return strlen($riga);
            },
            CURLOPT_WRITEFUNCTION  => function ($h, string $dati) use (&$corpo, &$troncato): int {
                $corpo .= $dati;
                if (strlen($corpo) > MAX_BYTE_PAGINA) {
                    $troncato = true;
                    return 0; // interrompe il download
                }
                return strlen($dati);
            },
        ]);

        $ok = curl_exec($ch);
        $codice = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tipo = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);

        if ($ok === false && !$troncato) {
            $esito['errore'] = 'Impossibile scaricare la pagina' . ($err !== '' ? ' (' . $err . ').' : '.');
            return $esito;
        }
        if ($codice >= 300 && $codice < 400 && $location) {
            $url = risolviUrl($url, $location);
            continue;
        }

        $esito['codice'] = $codice;
        $esito['tipo'] = $tipo;
        $esito['html'] = $corpo;
        if ($codice < 200 || $codice >= 300) {
            $esito['errore'] = 'Il sito ha risposto con codice HTTP ' . $codice . '.';
        } elseif ($tipo !== '' && stripos($tipo, 'html') === false) {
            $esito['errore'] = 'Il contenuto non è una pagina web HTML (' . $tipo . ').';
        } else {
            $esito['ok'] = true;
        }
        return $esito;
    }

    $esito['errore'] = 'Troppi reindirizzamenti.';
    return $esito;
}

/** Converte l'HTML in UTF-8 valido, usando il charset dichiarato. */
function inUtf8(string $html, string $tipo): string
{
    $charset = 'UTF-8';
    if (preg_match('/charset=["\']?([\w\-]+)/i', $tipo, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($html, 0, 4096), $m)) {
        $charset = $m[1];
    }
    $charset = strtoupper($charset);
    $supportati = array_map('strtoupper', mb_list_encodings());
    if ($charset !== 'UTF-8' && $charset !== 'UTF8' && in_array($charset, $supportati, true)) {
        return mb_convert_encoding($html, 'UTF-8', $charset);
    }
    return mb_convert_encoding($html, 'UTF-8', 'UTF-8'); // elimina i byte non validi
}

/* ============================ Analisi ============================ */

function pulisci(string $s): string
{
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

function dominioSospetto(string $host): ?string
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return 'Il sito usa un indirizzo IP invece di un nome di dominio.';
    }
    $tldSospetti = ['xyz', 'top', 'click', 'loan', 'gq', 'ml', 'cf', 'tk', 'buzz', 'icu'];
    $ultimoPunto = strrchr($host, '.');
    $tld = $ultimoPunto === false ? '' : substr($ultimoPunto, 1);
    if (in_array($tld, $tldSospetti, true)) {
        return 'L\'estensione .' . $tld . ' è spesso usata da siti di bassa qualità.';
    }
    if (substr_count($host, '-') >= 3) {
        return 'Il nome del dominio contiene molti trattini.';
    }
    if (preg_match('/\d{4,}/', $host)) {
        return 'Il nome del dominio contiene lunghe sequenze di numeri.';
    }
    if (strpos($host, 'xn--') !== false) {
        return 'Il dominio usa caratteri internazionali (punycode): potrebbe imitare un altro sito.';
    }
    return null;
}

function cercaDominio(PDO $pdo, string $host): ?array
{
    $parti = explode('.', $host);
    $candidati = [];
    for ($i = 0; $i < count($parti) - 1; $i++) {
        $candidati[] = implode('.', array_slice($parti, $i));
    }
    if (!$candidati) {
        return null;
    }
    $segnaposto = implode(',', array_fill(0, count($candidati), '?'));
    $q = $pdo->prepare(
        "SELECT dominio, categoria, nota FROM domini
         WHERE dominio IN ($segnaposto) ORDER BY CHAR_LENGTH(dominio) DESC LIMIT 1"
    );
    $q->execute($candidati);
    $riga = $q->fetch();
    return $riga ?: null;
}

function analizza(string $url, string $dominio, array $pag, PDO $pdo): array
{
    $checks = [];
    $punti = 0;
    $forzaEsito = null;
    $titolo = '';

    $aggiungi = function (string $id, string $nome, string $stato, int $p, string $testo) use (&$checks, &$punti): void {
        $checks[] = ['id' => $id, 'nome' => $nome, 'stato' => $stato, 'punti' => $p, 'dettaglio' => $testo];
        $punti += $p;
    };

    // 1. HTTPS
    if (stripos($url, 'https://') === 0) {
        $aggiungi('https', 'Connessione sicura (HTTPS)', 'ok', 5, 'Il sito usa una connessione cifrata.');
    } else {
        $aggiungi('https', 'Connessione sicura (HTTPS)', 'ko', -10, 'Il sito usa HTTP, senza cifratura.');
    }

    // 2. Forma del dominio
    $motivo = dominioSospetto($dominio);
    if ($motivo === null) {
        $aggiungi('dominio', 'Aspetto del dominio', 'ok', 0, 'Nessuna anomalia nel nome del dominio.');
    } else {
        $aggiungi('dominio', 'Aspetto del dominio', 'ko', -15, $motivo);
    }

    // 3. Reputazione nel database
    $voce = cercaDominio($pdo, $dominio);
    $nota = ($voce !== null && !empty($voce['nota'])) ? ' (' . $voce['nota'] . ')' : '';
    if ($voce === null) {
        $aggiungi('reputazione', 'Reputazione del dominio', 'info', 0, 'Il dominio non è presente nel nostro elenco: nessun giudizio disponibile.');
    } elseif ($voce['categoria'] === 'affidabile') {
        $aggiungi('reputazione', 'Reputazione del dominio', 'ok', 25, 'Il dominio è nell\'elenco delle fonti note' . $nota . '.');
    } elseif ($voce['categoria'] === 'satirico') {
        $aggiungi('reputazione', 'Reputazione del dominio', 'info', -40, 'Sito satirico' . $nota . ': i contenuti non vanno letti come notizie reali.');
        $forzaEsito = 'satirico';
    } else {
        $aggiungi('reputazione', 'Reputazione del dominio', 'ko', -35, 'Il dominio è segnalato come inaffidabile' . $nota . '.');
        $forzaEsito = 'sospetta';
    }

    // 4. Analisi dei contenuti
    if (!$pag['ok']) {
        $aggiungi(
            'contenuto',
            'Analisi del contenuto',
            'warn',
            -5,
            ($pag['errore'] ?? 'Pagina non analizzabile.') . ' L\'analisi dei contenuti è stata saltata.'
        );
    } else {
        $html = inUtf8($pag['html'], $pag['tipo']);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        // Dati strutturati (JSON-LD), da leggere prima di togliere gli <script>
        $ld = '';
        foreach ($xp->query('//script[@type="application/ld+json"]') as $nodo) {
            $ld .= $nodo->textContent;
        }

        // Titolo
        $titolo = pulisci((string) $xp->evaluate('string(//title)'));
        if ($titolo === '') {
            $titolo = pulisci((string) $xp->evaluate('string(//meta[@property="og:title"]/@content)'));
        }
        $titolo = mb_substr($titolo, 0, 255);

        if ($titolo === '') {
            $aggiungi('titolo', 'Titolo della pagina', 'warn', -3, 'Titolo non trovato.');
        } else {
            $clickbait = [
                'shock', 'shoccante', 'sconvolgente', 'incredibile', 'non crederai', 'non ci crederai',
                'ecco cosa', 'scandalo', 'bufera', 'svelato il segreto', 'nascondono', 'censurato',
                'urgente', "you won't believe", 'shocking', 'miracle', 'what happens next',
            ];
            $titoloMin = mb_strtolower($titolo);
            $segnali = 0;
            foreach ($clickbait as $frase) {
                if (mb_strpos($titoloMin, $frase) !== false) {
                    $segnali++;
                }
            }
            if (strpos($titolo, '!') !== false) {
                $segnali++;
            }
            if (preg_match_all('/\b\p{Lu}{4,}\b/u', $titolo) >= 2) {
                $segnali++;
            }
            if ($segnali >= 2) {
                $aggiungi('titolo', 'Titolo sensazionalistico', 'ko', -15, 'Il titolo usa toni da "clickbait" (' . $segnali . ' segnali).');
            } elseif ($segnali === 1) {
                $aggiungi('titolo', 'Titolo sensazionalistico', 'warn', -7, 'Il titolo contiene un segnale di sensazionalismo.');
            } else {
                $aggiungi('titolo', 'Titolo sensazionalistico', 'ok', 5, 'Il titolo ha un tono neutro.');
            }
        }

        // Autore
        $haAutore = $xp->evaluate(
            'count(//meta[@name="author"] | //meta[@property="article:author"] | //*[@rel="author"] | //*[@itemprop="author"] | //*[contains(@class,"byline")])'
        ) > 0 || stripos($ld, '"author"') !== false;
        if ($haAutore) {
            $aggiungi('autore', 'Autore indicato', 'ok', 8, 'La pagina indica chi ha scritto l\'articolo.');
        } else {
            $aggiungi('autore', 'Autore indicato', 'warn', -5, 'Non è stato trovato nessun autore.');
        }

        // Data di pubblicazione
        $haData = $xp->evaluate(
            'count(//meta[@property="article:published_time"] | //meta[@itemprop="datePublished"] | //meta[@name="date"] | //time[@datetime])'
        ) > 0 || stripos($ld, '"datePublished"') !== false;
        if ($haData) {
            $aggiungi('data', 'Data di pubblicazione', 'ok', 7, 'La pagina riporta una data di pubblicazione.');
        } else {
            $aggiungi('data', 'Data di pubblicazione', 'warn', -5, 'Non è stata trovata una data di pubblicazione.');
        }

        // Link verso altri siti (fonti)
        $esterni = [];
        foreach ($xp->query('//a[@href]') as $a) {
            $href = $a->getAttribute('href');
            if (!preg_match('#^https?://#i', $href)) {
                continue;
            }
            $h = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($href, PHP_URL_HOST)));
            if ($h !== '' && $h !== $dominio && !str_ends_with($h, '.' . $dominio)) {
                $esterni[$h] = true;
            }
        }
        $nEsterni = count($esterni);
        if ($nEsterni >= 3) {
            $aggiungi('fonti', 'Collegamenti ad altri siti', 'ok', 8, 'La pagina rimanda a ' . $nEsterni . ' siti esterni (possibili fonti).');
        } elseif ($nEsterni >= 1) {
            $aggiungi('fonti', 'Collegamenti ad altri siti', 'warn', 0, 'Pochi collegamenti esterni (' . $nEsterni . '): controlla se le fonti sono citate.');
        } else {
            $aggiungi('fonti', 'Collegamenti ad altri siti', 'ko', -6, 'Nessun collegamento esterno: le affermazioni non citano fonti.');
        }

        // Metadati
        $haMeta = $xp->evaluate('count(//meta[@property="og:title"] | //meta[@name="description"])') > 0;
        if ($haMeta) {
            $aggiungi('meta', 'Metadati della pagina', 'ok', 3, 'Descrizione e/o metadati social presenti.');
        } else {
            $aggiungi('meta', 'Metadati della pagina', 'warn', -2, 'Metadati assenti o incompleti.');
        }

        // Tono del testo
        foreach (iterator_to_array($xp->query('//script | //style | //noscript')) as $nodo) {
            $nodo->parentNode->removeChild($nodo);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        $testo = $body ? pulisci($body->textContent) : '';
        if (strlen($testo) < 200) {
            $aggiungi('tono', 'Tono del testo', 'info', 0, 'Testo troppo breve (o caricato via JavaScript) per valutare il tono.');
        } else {
            $parole = (int) preg_match_all('/\p{L}{4,}/u', $testo);
            $maiuscole = (int) preg_match_all('/\b\p{Lu}{4,}\b/u', $testo);
            $rapportoMai = $parole > 0 ? $maiuscole / $parole : 0;
            $esclamazioniPerK = substr_count($testo, '!') / max(1, strlen($testo) / 1000);
            if ($rapportoMai > 0.08 || $esclamazioniPerK > 3) {
                $aggiungi('tono', 'Tono del testo', 'warn', -6, 'Molte parole in MAIUSCOLO o punti esclamativi.');
            } else {
                $aggiungi('tono', 'Tono del testo', 'ok', 4, 'Il testo ha un tono misurato.');
            }
        }
    }

    $punteggio = max(0, min(100, PUNTEGGIO_BASE + $punti));
    if ($forzaEsito !== null) {
        $esito = $forzaEsito;
    } elseif ($punteggio >= SOGLIA_AFFIDABILE) {
        $esito = 'affidabile';
    } elseif ($punteggio >= SOGLIA_DUBBIO) {
        $esito = 'dubbio';
    } else {
        $esito = 'sospetta';
    }

    return ['titolo' => $titolo, 'punteggio' => $punteggio, 'esito' => $esito, 'checks' => $checks];
}

/* ========================= Flusso principale ========================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    errore('Metodo non consentito.', 405);
}
// Richiedere application/json protegge anche da richieste cross-site (CSRF): un form esterno non può inviarlo
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    errore('Content-Type non valido.', 415);
}

$corpoRichiesta = (string) file_get_contents('php://input', false, null, 0, 4096);
$input = json_decode($corpoRichiesta, true);
$urlGrezzo = (is_array($input) && isset($input['url']) && is_string($input['url'])) ? trim($input['url']) : '';
if ($urlGrezzo === '') {
    errore('Inserisci un URL da verificare.');
}
$url = normalizzaUrl($urlGrezzo);
if ($url === null) {
    errore('URL non valido. Usa un indirizzo che inizi con http:// o https://');
}

try {
    $pdo = getPDO();

    // Limite di richieste al minuto per visitatore (IP in forma di hash)
    $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . APP_SALT);
    $q = $pdo->prepare('SELECT COUNT(*) FROM analisi WHERE ip_hash = :h AND creato_il > :t');
    $q->execute([':h' => $ipHash, ':t' => date('Y-m-d H:i:s', time() - 60)]);
    if ((int) $q->fetchColumn() >= LIMITE_RICHIESTE_MINUTO) {
        errore('Troppe richieste: attendi un minuto e riprova.', 429);
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $dominio = (string) preg_replace('/^www\./', '', $host);

    $pagina = scaricaPagina($url);
    $r = analizza($url, $dominio, $pagina, $pdo);

    $ora = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        'INSERT INTO analisi (url, dominio, titolo, punteggio, esito, dettagli, ip_hash, creato_il)
         VALUES (:url, :dom, :tit, :pun, :esi, :det, :ip, :ora)'
    );
    $ins->execute([
        ':url' => $url,
        ':dom' => $dominio,
        ':tit' => $r['titolo'] !== '' ? $r['titolo'] : null,
        ':pun' => $r['punteggio'],
        ':esi' => $r['esito'],
        ':det' => json_encode($r['checks'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ':ip'  => $ipHash,
        ':ora' => $ora,
    ]);

    rispondi([
        'id'        => (int) $pdo->lastInsertId(),
        'url'       => $url,
        'dominio'   => $dominio,
        'titolo'    => $r['titolo'],
        'punteggio' => $r['punteggio'],
        'esito'     => $r['esito'],
        'checks'    => $r['checks'],
        'creato_il' => $ora,
    ]);
} catch (PDOException $e) {
    error_log('[VerificaNews] DB: ' . $e->getMessage());
    errore('Errore del database. Controlla la configurazione in api/config.php.', 500);
} catch (Throwable $e) {
    error_log('[VerificaNews] ' . $e->getMessage());
    errore('Errore interno durante l\'analisi.', 500);
}
