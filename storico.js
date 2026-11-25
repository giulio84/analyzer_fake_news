'use strict';

/* Pagina 2: riepilogo degli URL consultati (dati letti dal database MySQL) */
(function () {
  const stato = { pagina: 1, esito: '', q: '' };
  let elementi = [];
  let timer = null;

  const corpoTabella = document.getElementById('tabella-corpo');
  const zonaStat = document.getElementById('statistiche');
  const zonaTop = document.getElementById('top-domini');
  const zonaPag = document.getElementById('paginazione');
  const conteggio = document.getElementById('conteggio');
  const avviso = document.getElementById('avviso-errore');
  const filtroEsito = document.getElementById('filtro-esito');
  const filtroTesto = document.getElementById('filtro-testo');
  const btnReset = document.getElementById('btn-reset');

  function mostraErrore(messaggio) {
    avviso.textContent = messaggio;
    avviso.classList.remove('d-none');
  }

  async function carica() {
    avviso.classList.add('d-none');
    const params = new URLSearchParams({ pagina: String(stato.pagina) });
    if (stato.esito) params.set('esito', stato.esito);
    if (stato.q) params.set('q', stato.q);

    try {
      const risposta = await fetch('api/storico.php?' + params.toString());
      const dati = await risposta.json().catch(function () { return null; });
      if (!risposta.ok || !dati || dati.errore) {
        throw new Error(dati && dati.errore ? dati.errore : 'Errore del server (' + risposta.status + ').');
      }
      elementi = dati.elementi;
      stato.pagina = dati.pagina;
      disegnaStatistiche(dati.statistiche);
      disegnaTopDomini(dati.top_domini);
      disegnaTabella(dati.elementi);
      disegnaPaginazione(dati.pagina, dati.pagine_totali);
      conteggio.textContent = dati.totale_filtrato + (dati.totale_filtrato === 1 ? ' risultato' : ' risultati');
    } catch (e) {
      corpoTabella.replaceChildren();
      mostraErrore(e.message || 'Impossibile contattare il server.');
    }
  }

  function cartaStat(titolo, valore, classe) {
    const col = el('div', 'col-6 col-md-4 col-xl-2');
    const carta = el('div', 'card stat-card h-100');
    const corpo = el('div', 'card-body text-center');
    corpo.appendChild(el('div', 'numero ' + (classe || ''), valore));
    corpo.appendChild(el('div', 'small text-muted', titolo));
    carta.appendChild(corpo);
    col.appendChild(carta);
    return col;
  }

  function disegnaStatistiche(s) {
    zonaStat.replaceChildren(
      cartaStat('URL analizzati', s.totale),
      cartaStat('Punteggio medio', s.totale ? s.media : '–'),
      cartaStat(ESITI.affidabile.etichetta, s.per_esito.affidabile, 'text-success'),
      cartaStat(ESITI.dubbio.etichetta, s.per_esito.dubbio, 'text-warning'),
      cartaStat(ESITI.sospetta.etichetta, s.per_esito.sospetta, 'text-danger'),
      cartaStat(ESITI.satirico.etichetta, s.per_esito.satirico, 'text-info')
    );
  }

  function disegnaTopDomini(lista) {
    zonaTop.replaceChildren();
    if (!lista.length) {
      zonaTop.appendChild(el('li', 'list-group-item text-muted', 'Nessun dato ancora.'));
      return;
    }
    lista.forEach(function (d) {
      const li = el('li', 'list-group-item d-flex justify-content-between align-items-center');
      li.appendChild(el('span', 'text-truncate me-2', d.dominio));
      const dx = el('span', 'd-flex gap-2 flex-shrink-0');
      dx.appendChild(el('span', 'badge text-bg-light border', d.n + (d.n === 1 ? ' analisi' : ' analisi')));
      dx.appendChild(el('span', 'badge text-bg-secondary', 'media ' + d.media));
      li.appendChild(dx);
      zonaTop.appendChild(li);
    });
  }

  function disegnaTabella(lista) {
    corpoTabella.replaceChildren();
    if (!lista.length) {
      const tr = el('tr');
      const td = el('td', 'text-center text-muted py-5', 'Nessuna analisi trovata.');
      td.colSpan = 5;
      tr.appendChild(td);
      corpoTabella.appendChild(tr);
      return;
    }

    lista.forEach(function (r) {
      const info = infoEsito(r.esito);
      const tr = el('tr');

      tr.appendChild(el('td', 'text-nowrap small', formattaData(r.creato_il)));

      const tdSito = el('td', 'cella-sito');
      tdSito.appendChild(el('div', 'fw-semibold text-truncate', r.titolo || r.dominio));
      const link = el('a', 'small d-block text-truncate', r.url);
      link.href = urlSicuro(r.url);
      link.target = '_blank';
      link.rel = 'noopener noreferrer nofollow';
      tdSito.appendChild(link);
      tr.appendChild(tdSito);

      const tdPunti = el('td');
      const riga = el('div', 'd-flex align-items-center gap-2');
      riga.appendChild(el('span', 'fw-semibold', r.punteggio));
      riga.appendChild(creaBarra(r.punteggio, r.esito, true));
      tdPunti.appendChild(riga);
      tr.appendChild(tdPunti);

      const tdEsito = el('td');
      tdEsito.appendChild(el('span', 'badge ' + info.classe, info.etichetta));
      tr.appendChild(tdEsito);

      const tdAzioni = el('td', 'text-end');
      const btn = el('button', 'btn btn-sm btn-outline-primary', 'Dettagli');
      btn.type = 'button';
      btn.dataset.id = String(r.id);
      tdAzioni.appendChild(btn);
      tr.appendChild(tdAzioni);

      corpoTabella.appendChild(tr);
    });
  }

  function voceNav(testo, pagina, attiva, disabilitata) {
    const li = el('li', 'page-item' + (attiva ? ' active' : '') + (disabilitata ? ' disabled' : ''));
    const a = el('a', 'page-link', testo);
    a.href = '#';
    if (!disabilitata) a.dataset.pagina = String(pagina);
    li.appendChild(a);
    return li;
  }

  function disegnaPaginazione(corrente, totali) {
    zonaPag.replaceChildren();
    if (totali <= 1) return;
    zonaPag.appendChild(voceNav('«', corrente - 1, false, corrente === 1));
    const da = Math.max(1, corrente - 2);
    const a = Math.min(totali, da + 4);
    for (let p = Math.max(1, a - 4); p <= a; p++) {
      zonaPag.appendChild(voceNav(String(p), p, p === corrente, false));
    }
    zonaPag.appendChild(voceNav('»', corrente + 1, false, corrente === totali));
  }

  function apriDettagli(id) {
    const r = elementi.find(function (x) { return x.id === id; });
    if (!r) return;
    const info = infoEsito(r.esito);

    document.getElementById('modal-titolo').textContent = r.titolo || r.dominio;
    const link = document.getElementById('modal-url');
    link.textContent = r.url;
    link.href = urlSicuro(r.url);
    document.getElementById('modal-data').textContent = formattaData(r.creato_il);
    document.getElementById('modal-punteggio').textContent = r.punteggio;
    const badge = document.getElementById('modal-esito');
    badge.className = 'badge ' + info.classe;
    badge.textContent = info.etichetta;
    document.getElementById('modal-barra').replaceChildren(creaBarra(r.punteggio, r.esito, false));
    disegnaChecks(document.getElementById('modal-checks'), r.checks);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-dettagli')).show();
  }

  /* ---- Eventi ---- */
  corpoTabella.addEventListener('click', function (ev) {
    const btn = ev.target.closest('button[data-id]');
    if (btn) apriDettagli(parseInt(btn.dataset.id, 10));
  });

  zonaPag.addEventListener('click', function (ev) {
    const a = ev.target.closest('a[data-pagina]');
    if (!a) { ev.preventDefault(); return; }
    ev.preventDefault();
    stato.pagina = parseInt(a.dataset.pagina, 10);
    carica();
  });

  filtroEsito.addEventListener('change', function () {
    stato.esito = filtroEsito.value;
    stato.pagina = 1;
    carica();
  });

  filtroTesto.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      stato.q = filtroTesto.value.trim();
      stato.pagina = 1;
      carica();
    }, 350);
  });

  btnReset.addEventListener('click', function () {
    filtroEsito.value = '';
    filtroTesto.value = '';
    stato.esito = '';
    stato.q = '';
    stato.pagina = 1;
    carica();
  });

  carica();
})();
