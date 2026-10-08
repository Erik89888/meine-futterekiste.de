// Holt die Öffnungszeiten aus dem Google-Unternehmensprofil (Places API New) und schreibt hours.json.
// weekly: reguläre Zeiten je Wochentag (0 = Sonntag), days: tatsächliche Zeiten der nächsten 7 Tage
// inkl. Sonderzeiten (Urlaub, Feiertage), null = geschlossen. Zeiten in Minuten ab Mitternacht.
const fs = require('fs');

const KEY = process.env.GOOGLE_PLACES_KEY, PLACE = process.env.GOOGLE_PLACE_ID;
if (!KEY || !PLACE) { console.error('GOOGLE_PLACES_KEY oder GOOGLE_PLACE_ID fehlt'); process.exit(1); }

const pad = n => String(n).padStart(2, '0');
const toMin = p => p.hour * 60 + (p.minute || 0);
const span = per => [toMin(per.open), per.close && per.close.day === per.open.day ? toMin(per.close) : 1440];
// Mehrere Zeiträume an einem Tag werden zu "frühestens auf bis spätestens zu" zusammengefasst
const merge = (map, k, r) => { const o = map[k]; map[k] = o ? [Math.min(o[0], r[0]), Math.max(o[1], r[1])] : r; };

(async () => {
  const res = await fetch('https://places.googleapis.com/v1/places/' + PLACE, {
    headers: { 'X-Goog-Api-Key': KEY, 'X-Goog-FieldMask': 'regularOpeningHours,currentOpeningHours' }
  });
  if (!res.ok) { console.error('Google API', res.status, await res.text()); process.exit(1); }
  const g = await res.json();

  const reg = g.regularOpeningHours && g.regularOpeningHours.periods;
  if (!reg || !reg.length) { console.error('Keine regulären Öffnungszeiten erhalten'); process.exit(1); }
  const weekly = {};
  reg.forEach(p => merge(weekly, p.open.day, span(p)));

  // Ohne currentOpeningHours bleibt days leer, die Seite nimmt dann die regulären Zeiten
  const days = {};
  const cur = g.currentOpeningHours && g.currentOpeningHours.periods;
  if (cur && cur.length) {
    const today = Date.parse(new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' }).format(new Date()) + 'T00:00:00Z');
    for (let i = 0; i < 7; i++) days[new Date(today + i * 864e5).toISOString().slice(0, 10)] = null;
    cur.forEach(p => {
      if (!p.open || !p.open.date) return;
      const k = p.open.date.year + '-' + pad(p.open.date.month) + '-' + pad(p.open.date.day);
      if (k in days) merge(days, k, span(p));
    });
  }

  fs.writeFileSync(process.env.HOURS_FILE || 'hours.json', JSON.stringify({ updated: new Date().toISOString(), weekly, days }, null, 2) + '\n');
  console.log('hours.json geschrieben', JSON.stringify({ weekly, days }));
})();
