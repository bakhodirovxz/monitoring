// Shared utility – imported by templates
function fmtDur(sec) {
  if (!sec) return '—';
  const s = Math.round(sec);
  if (s < 60) return `${s} son.`;
  const m = Math.floor(s / 60), r = s % 60;
  if (m < 60) return `${m} daq. ${r} son.`;
  const h = Math.floor(m / 60);
  return `${h} soat ${m % 60} daq.`;
}

function fmtDt(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('ru-RU');
}
