<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    errore('Metodo non consentito.', 405);
}

function parametro(string $nome): string
{
    return (isset($_GET[$nome]) && is_string($_GET[$nome])) ? trim($_GET[$nome]) : '';
}

const PER_PAGINA = 10;
const ESITI_VALIDI = ['affidabile', 'dubbio', 'sospetta', 'satirico'];

$esito = parametro('esito');
if ($esito !== '' && !in_array($esito, ESITI_VALIDI, true)) {
    errore('Filtro non valido.');
}
$ricerca = mb_substr(parametro('q'), 0, 100);
$pagina = max(1, (int) parametro('pagina'));

try {
    $pdo = getPDO();

    // Filtri (sempre con parametri preparati)
    $where = [];
    $par = [];
    if ($esito !== '') {
        $where[] = 'esito = :esito';
        $par[':esito'] = $esito;
    }
    if ($ricerca !== '') {
        $where[] = '(url LIKE :q1 OR titolo LIKE :q2)';
        $like = '%' . addcslashes($ricerca, '\\%_') . '%';
        $par[':q1'] = $like;
        $par[':q2'] = $like;
    }
    $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM analisi $sqlWhere");
    $cnt->execute($par);
    $totaleFiltrato = (int) $cnt->fetchColumn();
    $pagineTotali = max(1, (int) ceil($totaleFiltrato / PER_PAGINA));
    $pagina = min($pagina, $pagineTotali);

    $st = $pdo->prepare(
        "SELECT id, url, dominio, titolo, punteggio, esito, dettagli, creato_il
         FROM analisi $sqlWhere
         ORDER BY creato_il DESC, id DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($par as $chiave => $valore) {
        $st->bindValue($chiave, $valore);
    }
    $st->bindValue(':lim', PER_PAGINA, PDO::PARAM_INT);
    $st->bindValue(':off', ($pagina - 1) * PER_PAGINA, PDO::PARAM_INT);
    $st->execute();

    $elementi = [];
    foreach ($st->fetchAll() as $riga) {
        $dettagli = json_decode((string) ($riga['dettagli'] ?? '[]'), true);
        $elementi[] = [
            'id'        => (int) $riga['id'],
            'url'       => $riga['url'],
            'dominio'   => $riga['dominio'],
            'titolo'    => $riga['titolo'],
            'punteggio' => (int) $riga['punteggio'],
            'esito'     => $riga['esito'],
            'checks'    => is_array($dettagli) ? $dettagli : [],
            'creato_il' => $riga['creato_il'],
        ];
    }

    // Statistiche globali (indipendenti dai filtri)
    $riepilogo = $pdo->query('SELECT COUNT(*) AS totale, ROUND(AVG(punteggio), 1) AS media FROM analisi')->fetch();
    $perEsito = array_fill_keys(ESITI_VALIDI, 0);
    foreach ($pdo->query('SELECT esito, COUNT(*) AS n FROM analisi GROUP BY esito') as $riga) {
        $perEsito[$riga['esito']] = (int) $riga['n'];
    }

    $topDomini = [];
    $qt = $pdo->query(
        'SELECT dominio, COUNT(*) AS n, ROUND(AVG(punteggio)) AS media
         FROM analisi GROUP BY dominio ORDER BY n DESC, dominio ASC LIMIT 5'
    );
    foreach ($qt as $riga) {
        $topDomini[] = ['dominio' => $riga['dominio'], 'n' => (int) $riga['n'], 'media' => (int) $riga['media']];
    }

    rispondi([
        'elementi'       => $elementi,
        'pagina'         => $pagina,
        'pagine_totali'  => $pagineTotali,
        'totale_filtrato' => $totaleFiltrato,
        'statistiche'    => [
            'totale'    => (int) $riepilogo['totale'],
            'media'     => $riepilogo['media'] !== null ? (float) $riepilogo['media'] : 0,
            'per_esito' => $perEsito,
        ],
        'top_domini'     => $topDomini,
    ]);
} catch (PDOException $e) {
    error_log('[VerificaNews] DB: ' . $e->getMessage());
    errore('Errore del database. Controlla la configurazione in api/config.php.', 500);
} catch (Throwable $e) {
    error_log('[VerificaNews] ' . $e->getMessage());
    errore('Errore interno.', 500);
}
