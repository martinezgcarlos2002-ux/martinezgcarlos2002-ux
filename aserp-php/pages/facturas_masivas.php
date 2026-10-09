<?php
require_login();
$db = get_db();

$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta))  $hasta = date('Y-m-d');

$prods = $db->query("SELECT id, nombre FROM productores ORDER BY nombre ASC")->fetchAll();

layout_start('Facturas masivas — Productores', 'productores');
?>
<style>
  .fm-toolbar { background:#fff8e1; border:1px solid #e0c97a; border-radius:.5rem;
                padding:.7rem 1rem; margin-bottom:.8rem; display:flex; flex-wrap:wrap;
                gap:.6rem; align-items:center; }
  .fm-toolbar .lbl { font-weight:600; color:#5a3e00; }
  .fm-toolbar input[type=date] { width:150px; }
  .fm-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
             gap:.5rem; }
  .fm-card { background:#fff; border:1px solid #d6d6d6; border-radius:.45rem;
             padding:.5rem .6rem; display:flex; align-items:center; gap:.5rem;
             font-size:.85rem; }
  .fm-card.excl { opacity:.4; background:#f5f5f5; text-decoration:line-through; }
  .fm-card .nm { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .fm-card .btn-x { color:#b91c1c; background:none; border:0; font-weight:700;
                    cursor:pointer; padding:0 .3rem; }
  .fm-progress { display:none; margin-top:.6rem; }
  .fm-progress.active { display:block; }
  .fm-progress .bar { height:18px; background:#e9ecef; border-radius:9px; overflow:hidden; }
  .fm-progress .bar > div { height:100%; background:#198754; width:0%; transition:width .2s; }
  .fm-progress .msg { font-size:.8rem; color:#5a3e00; margin-top:.2rem; }
  .fm-count { background:#198754; color:#fff; padding:.2rem .6rem; border-radius:.3rem;
              font-weight:700; font-size:.8rem; }
</style>

<div class="fm-toolbar">
  <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
    <input type="hidden" name="p" value="facturas_masivas">
    <span class="lbl">Período:</span>
    <input type="date" name="desde" value="<?= h($desde) ?>" class="form-control form-control-sm">
    <span>—</span>
    <input type="date" name="hasta" value="<?= h($hasta) ?>" class="form-control form-control-sm">
    <button class="btn btn-sm btn-primary">Aplicar</button>
  </form>
  <div class="vr"></div>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fmToggleAll(true)">Incluir todos</button>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="fmToggleAll(false)">Excluir todos</button>
  <span class="fm-count"><span id="fmCount">0</span> seleccionados</span>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <button type="button" class="btn btn-sm btn-dark" onclick="fmRun('print')">🖨 Imprimir todas</button>
    <button type="button" class="btn btn-sm btn-danger" onclick="fmRun('pdf')">📄 Descargar PDFs (ZIP)</button>
    <button type="button" class="btn btn-sm btn-success" onclick="fmRun('img')">🖼 Descargar Imágenes (ZIP)</button>
  </div>
</div>

<div class="fm-progress" id="fmProg">
  <div class="bar"><div id="fmBar"></div></div>
  <div class="msg" id="fmMsg"></div>
</div>

<div class="fm-grid" id="fmGrid">
  <?php foreach ($prods as $p): ?>
    <div class="fm-card" data-id="<?= (int)$p['id'] ?>" data-nombre="<?= h($p['nombre']) ?>">
      <input type="checkbox" class="fm-chk" checked onchange="fmUpdateCount()">
      <span class="nm" title="<?= h($p['nombre']) ?>"><?= h($p['nombre']) ?></span>
      <button type="button" class="btn-x" title="Excluir" onclick="fmExclude(this)">✕</button>
    </div>
  <?php endforeach ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
<script>
const FM_DESDE = <?= json_encode($desde) ?>;
const FM_HASTA = <?= json_encode($hasta) ?>;

function fmUpdateCount(){
  const n = document.querySelectorAll('#fmGrid .fm-card .fm-chk:checked').length;
  document.getElementById('fmCount').textContent = n;
}
function fmToggleAll(state){
  document.querySelectorAll('#fmGrid .fm-card').forEach(c=>{
    const chk = c.querySelector('.fm-chk');
    chk.checked = state;
    c.classList.toggle('excl', !state);
  });
  fmUpdateCount();
}
function fmExclude(btn){
  const card = btn.closest('.fm-card');
  const chk = card.querySelector('.fm-chk');
  chk.checked = false;
  card.classList.add('excl');
  fmUpdateCount();
}
document.querySelectorAll('#fmGrid .fm-card .fm-chk').forEach(chk=>{
  chk.addEventListener('change', e=>{
    chk.closest('.fm-card').classList.toggle('excl', !chk.checked);
  });
});
fmUpdateCount();

function fmSanitize(name){
  return (name||'sin_nombre').replace(/[\\/:*?"<>|]+/g,'_').replace(/\s+/g,'_').slice(0,80);
}

function fmGetSelected(){
  const out = [];
  document.querySelectorAll('#fmGrid .fm-card').forEach(c=>{
    if (c.querySelector('.fm-chk').checked){
      out.push({id: c.dataset.id, nombre: c.dataset.nombre});
    }
  });
  return out;
}

async function fmCapture(prod){
  return new Promise((resolve, reject) => {
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;left:-99999px;top:0;width:900px;height:1400px;border:0';
    iframe.src = `?p=productor_factura&id=${prod.id}&desde=${encodeURIComponent(FM_DESDE)}&hasta=${encodeURIComponent(FM_HASTA)}`;
    iframe.addEventListener('load', async () => {
      try {
        await new Promise(r => setTimeout(r, 700));
        const doc = iframe.contentDocument.querySelector('.doc');
        if (!doc) throw new Error('Documento no encontrado');
        const canvas = await html2canvas(doc, {scale: 2, backgroundColor: '#ffffff', useCORS: true, logging: false});
        document.body.removeChild(iframe);
        resolve(canvas);
      } catch(err){
        try { document.body.removeChild(iframe); } catch(e){}
        reject(err);
      }
    });
    iframe.addEventListener('error', () => {
      try { document.body.removeChild(iframe); } catch(e){}
      reject(new Error('Error al cargar iframe'));
    });
    document.body.appendChild(iframe);
  });
}

function fmShowProgress(show, pct, msg){
  const p = document.getElementById('fmProg');
  p.classList.toggle('active', !!show);
  if (pct !== undefined) document.getElementById('fmBar').style.width = pct + '%';
  if (msg !== undefined) document.getElementById('fmMsg').textContent = msg;
}

async function fmRun(mode){
  const sel = fmGetSelected();
  if (!sel.length){ alert('No hay productores seleccionados.'); return; }
  if (!confirm(`Procesar ${sel.length} factura(s)?`)) return;

  fmShowProgress(true, 0, 'Iniciando...');
  const zip = (mode === 'print') ? null : new JSZip();
  let printWin = null;
  if (mode === 'print'){
    printWin = window.open('', '_blank');
    if (!printWin){ alert('Permita ventanas emergentes para imprimir.'); fmShowProgress(false); return; }
    printWin.document.write('<!doctype html><html><head><meta charset="UTF-8"><title>Facturas</title>'+
      '<style>@page{size:letter portrait;margin:5mm} body{margin:0;font-family:sans-serif}'+
      '.pg{page-break-after:always;text-align:center;padding:4mm 0} .pg:last-child{page-break-after:auto}'+
      '.pg img{max-width:200mm;max-height:255mm} .nm{font-size:11pt;font-weight:700;margin-top:3mm}</style>'+
      '</head><body></body></html>');
    printWin.document.close();
  }

  let ok = 0, fail = 0;
  for (let i = 0; i < sel.length; i++){
    const prod = sel[i];
    const pct = Math.round(((i) / sel.length) * 100);
    fmShowProgress(true, pct, `Procesando ${i+1}/${sel.length}: ${prod.nombre}`);
    try {
      const canvas = await fmCapture(prod);
      const fname = fmSanitize(prod.nombre);
      if (mode === 'img'){
        const blob = await new Promise(r => canvas.toBlob(r, 'image/png'));
        zip.file(`${fname}.png`, blob);
      } else if (mode === 'pdf'){
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({orientation:'portrait', unit:'mm', format:'letter'});
        const pw = pdf.internal.pageSize.getWidth();
        const ph = pdf.internal.pageSize.getHeight();
        const margin = 6;
        const maxW = pw - margin*2;
        const ratio = canvas.height / canvas.width;
        let imgW = maxW;
        let imgH = imgW * ratio;
        if (imgH > ph - margin*2){
          imgH = ph - margin*2;
          imgW = imgH / ratio;
        }
        const x = (pw - imgW)/2;
        const y = margin;
        pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', x, y, imgW, imgH);
        // Nombre debajo
        pdf.setFontSize(10);
        pdf.text(prod.nombre, pw/2, y + imgH + 5, {align:'center'});
        zip.file(`${fname}.pdf`, pdf.output('blob'));
      } else if (mode === 'print'){
        const img = printWin.document.createElement('img');
        img.src = canvas.toDataURL('image/png');
        const div = printWin.document.createElement('div');
        div.className = 'pg';
        div.appendChild(img);
        const nm = printWin.document.createElement('div');
        nm.className = 'nm';
        nm.textContent = prod.nombre;
        div.appendChild(nm);
        printWin.document.body.appendChild(div);
      }
      ok++;
    } catch(err){
      console.error('Error', prod, err);
      fail++;
    }
  }

  fmShowProgress(true, 100, `Listo. ${ok} ok, ${fail} con error. Generando archivo...`);

  if (mode === 'print'){
    setTimeout(()=>{ try { printWin.print(); } catch(e){} }, 600);
    fmShowProgress(true, 100, `Listo. ${ok} factura(s) enviadas a impresión.`);
  } else {
    const out = await zip.generateAsync({type:'blob'});
    const label = mode === 'pdf' ? 'pdfs' : 'imagenes';
    saveAs(out, `facturas_${label}_${FM_DESDE}_${FM_HASTA}.zip`);
    fmShowProgress(true, 100, `Listo. ZIP descargado (${ok} archivo(s)).`);
  }
  setTimeout(()=>fmShowProgress(false), 4000);
}
</script>

<?php layout_end();
