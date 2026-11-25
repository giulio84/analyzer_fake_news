'use strict';

/* Funzioni condivise dalle due pagine */

const ESITI = {
  affidabile: { etichetta: 'Pochi segnali di allarme', classe: 'bg-success', barra: 'bg-success' },
  dubbio:     { etichetta: 'Da verificare',            classe: 'bg-warning text-dark', barra: 'bg-warning' },
  sospetta:   { etichetta: 'Molti segnali di allarme', classe: 'bg-danger', barra: 'bg-danger' },
  satirico:   { etichetta: 'Contenuto satirico',       classe: 'bg-info text-dark', barra: 'bg-info' }
};

const STATI_CHECK = {
  ok:   { simbolo: '✔', classe: 'success' },
  warn: { simbolo: '!', classe: 'warning' },
  ko:   { simbolo: '✖', classe: 'danger' },
  info: { simbolo: 'i', classe: 'secondary' }
};

/** Crea un elemento. Il testo viene inserito con textContent (nessun rischio XSS). */
function el(tag, classe, testo) {
  const nodo = document.createElement(tag);
  if (classe) nodo.className = classe;
  if (testo !== undefined && testo !== null) nodo.textContent = String(testo);
  return nodo;
}

function infoEsito(esito) {
  return ESITI[esito] || ESITI.dubbio;
}

/** "2026-11-25 10:30:00" -> "25/11/2026 10:30" */
function formattaData(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(s || '');
  return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : (s || '');
}

/** Usa un URL come link solo se è http(s). */
function urlSicuro(u) {
  return /^https?:\/\//i.test(u || '') ? u : '#';
}

/** Barra di avanzamento Bootstrap per il punteggio. */
function creaBarra(punteggio, esito, sottile) {
  const contenitore = el('div', 'progress' + (sottile ? ' sottile' : ''));
  contenitore.setAttribute('role', 'progressbar');
  contenitore.setAttribute('aria-valuemin', '0');
  contenitore.setAttribute('aria-valuemax', '100');
  contenitore.setAttribute('aria-valuenow', String(punteggio));
  const barra = el('div', 'progress-bar ' + infoEsito(esito).barra);
  barra.style.width = Math.max(0, Math.min(100, punteggio)) + '%';
  contenitore.appendChild(barra);
  return contenitore;
}

/** Elenco dei controlli effettuati (lista Bootstrap). */
function disegnaChecks(contenitore, checks) {
  contenitore.replaceChildren();
  (checks || []).forEach(function (c) {
    const stato = STATI_CHECK[c.stato] || STATI_CHECK.info;
    const li = el('li', 'list-group-item d-flex align-items-start gap-3');
    li.appendChild(el('span', 'simbolo text-bg-' + stato.classe, stato.simbolo));

    const corpo = el('div', 'flex-grow-1');
    corpo.appendChild(el('div', 'fw-semibold', c.nome));
    corpo.appendChild(el('div', 'small text-muted', c.dettaglio));
    li.appendChild(corpo);

    if (c.punti) {
      const segno = c.punti > 0 ? '+' : '−';
      const colore = c.punti > 0 ? 'text-success' : 'text-danger';
      li.appendChild(el('span', 'punti fw-semibold ' + colore, segno + Math.abs(c.punti)));
    }
    contenitore.appendChild(li);
  });
}
