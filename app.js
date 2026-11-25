'use strict';

/* Pagina 1: inserimento dell'URL e visualizzazione del risultato */
(function () {
  const form = document.getElementById('form-analisi');
  const input = document.getElementById('url');
  const bottone = document.getElementById('btn-analizza');
  const spinner = document.getElementById('spinner');
  const testoBottone = document.getElementById('testo-bottone');
  const avviso = document.getElementById('avviso-errore');
  const risultato = document.getElementById('risultato');

  function mostraErrore(messaggio) {
    avviso.textContent = messaggio;
    avviso.classList.remove('d-none');
  }

  function nascondiErrore() {
    avviso.classList.add('d-none');
    avviso.textContent = '';
  }

  function inCaricamento(attivo) {
    bottone.disabled = attivo;
    input.disabled = attivo;
    spinner.classList.toggle('d-none', !attivo);
    testoBottone.textContent = attivo ? 'Analisi in corso…' : 'Analizza';
  }

  /** Controllo preliminare lato browser (quello vero lo fa comunque il server). */
  function urlValido(testo) {
    try {
      const completo = /^https?:\/\//i.test(testo) ? testo : 'https://' + testo;
      const u = new URL(completo);
      return (u.protocol === 'http:' || u.protocol === 'https:') && u.hostname.indexOf('.') > 0;
    } catch (e) {
      return false;
    }
  }

  function mostraRisultato(d) {
    const info = infoEsito(d.esito);

    document.getElementById('ris-titolo').textContent = d.titolo || 'Titolo non disponibile';
    const link = document.getElementById('ris-url');
    link.textContent = d.url;
    link.href = urlSicuro(d.url);
    document.getElementById('ris-dominio').textContent = d.dominio;
    document.getElementById('ris-punteggio').textContent = d.punteggio;

    const badge = document.getElementById('ris-esito');
    badge.className = 'badge fs-6 ' + info.classe;
    badge.textContent = info.etichetta;

    const zonaBarra = document.getElementById('ris-barra');
    zonaBarra.replaceChildren(creaBarra(d.punteggio, d.esito, false));

    disegnaChecks(document.getElementById('ris-checks'), d.checks);

    risultato.classList.remove('d-none');
    risultato.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    nascondiErrore();
    risultato.classList.add('d-none');

    const url = input.value.trim();
    if (!url) {
      mostraErrore('Inserisci un URL da verificare.');
      return;
    }
    if (!urlValido(url)) {
      mostraErrore('L\'indirizzo non sembra valido. Esempio: https://www.esempio.it/articolo');
      return;
    }

    inCaricamento(true);
    try {
      const risposta = await fetch('api/analizza.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: url })
      });
      const dati = await risposta.json().catch(function () { return null; });
      if (!risposta.ok || !dati || dati.errore) {
        throw new Error(dati && dati.errore ? dati.errore : 'Errore del server (' + risposta.status + ').');
      }
      mostraRisultato(dati);
    } catch (e) {
      mostraErrore(e.message || 'Impossibile contattare il server.');
    } finally {
      inCaricamento(false);
    }
  });
})();
