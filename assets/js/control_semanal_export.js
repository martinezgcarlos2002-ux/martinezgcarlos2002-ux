/**
 * Exporta la tabla principal del Control Semanal a Excel (SheetJS) o PDF (jsPDF + autotable).
 * Expande colspan para que la fila de Totales (tfoot) alinee cada monto con su columna.
 */
(function () {
  'use strict';

  function cfg() {
    var t = document.getElementById('tblMasiva');
    return {
      table: t,
      title: (t && t.getAttribute('data-export-title')) || 'Control Semanal de Productores',
      subtitle: (t && t.getAttribute('data-export-subtitle')) || '',
      filename: (t && t.getAttribute('data-export-filename')) || 'Control_Semanal_Productores'
    };
  }

  function visible(el) {
    return el && !el.classList.contains('d-none') && el.style.display !== 'none';
  }

  function cellValue(cell) {
    var inp = cell.querySelector('input, select, textarea');
    if (inp) return String(inp.value || '').trim();
    return String(cell.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function spanOf(cell) {
    var n = parseInt(cell.getAttribute('colspan') || '1', 10);
    return n > 0 ? n : 1;
  }

  function rowValues(tr) {
    var row = [];
    tr.querySelectorAll('th, td').forEach(function (cell) {
      var val = cellValue(cell);
      var span = spanOf(cell);
      row.push(val);
      for (var i = 1; i < span; i++) row.push('');
    });
    return row;
  }

  function rowsOf(section, skipHidden) {
    var rows = [];
    if (!section) return rows;
    section.querySelectorAll('tr').forEach(function (tr) {
      if (skipHidden && !visible(tr)) return;
      var row = rowValues(tr);
      if (row.length) rows.push(row);
    });
    return rows;
  }

  function tableSections(table) {
    return {
      head: rowsOf(table.querySelector('thead'), false),
      body: rowsOf(table.querySelector('tbody'), true),
      foot: rowsOf(table.querySelector('tfoot'), false)
    };
  }

  function tableMatrix(table) {
    var s = tableSections(table);
    return s.head.concat(s.body, s.foot);
  }

  function aviso(msg) {
    window.alert(msg);
  }

  function exportarExcel() {
    if (typeof XLSX === 'undefined') {
      aviso('Cargando librería de Excel. Intenta de nuevo en un momento.');
      return;
    }
    var c = cfg();
    var principal = tableMatrix(c.table);
    if (!principal.length) {
      aviso('No hay datos para exportar.');
      return;
    }
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(principal), 'Control semanal');
    var resumen = document.getElementById('tblResumenLeche');
    if (resumen) {
      XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(tableMatrix(resumen)), 'Resumen diario');
    }
    XLSX.writeFile(wb, c.filename + '.xlsx');
  }

  function exportarPdf() {
    if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
      aviso('Cargando librería de PDF. Intenta de nuevo en un momento.');
      return;
    }
    var c = cfg();
    var s = tableSections(c.table);
    if (!s.head.length && !s.body.length) {
      aviso('No hay datos para exportar.');
      return;
    }
    var JsPDF = window.jspdf.jsPDF;
    var doc = new JsPDF({ orientation: 'landscape', unit: 'mm', format: 'a3' });
    doc.setFontSize(13);
    doc.setTextColor(30, 58, 95);
    doc.text(c.title, 14, 14);
    if (c.subtitle) {
      doc.setFontSize(9);
      doc.setTextColor(100);
      doc.text(c.subtitle, 14, 20);
    }
    doc.autoTable({
      head: s.head,
      body: s.body,
      foot: s.foot,
      showFoot: 'lastPage',
      startY: c.subtitle ? 24 : 18,
      styles: { fontSize: 6, cellPadding: 1.2, overflow: 'linebreak' },
      headStyles: { fillColor: [30, 58, 95], textColor: 255, fontSize: 6, fontStyle: 'bold' },
      footStyles: { fillColor: [30, 58, 95], textColor: 255, fontSize: 6, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: [240, 246, 255] },
      margin: { left: 10, right: 10 }
    });
    var resumen = document.getElementById('tblResumenLeche');
    if (resumen) {
      var res = tableSections(resumen);
      if (res.head.length) {
        var y = (doc.lastAutoTable && doc.lastAutoTable.finalY ? doc.lastAutoTable.finalY : 30) + 8;
        doc.setFontSize(11);
        doc.setTextColor(30, 58, 95);
        doc.text('Resumen Diario de Leche', 14, y);
        doc.autoTable({
          head: res.head,
          body: res.body,
          foot: res.foot,
          showFoot: 'lastPage',
          startY: y + 3,
          styles: { fontSize: 7, cellPadding: 1.4 },
          headStyles: { fillColor: [30, 58, 95], textColor: 255, fontSize: 7 },
          footStyles: { fillColor: [30, 58, 95], textColor: 255, fontSize: 7, fontStyle: 'bold' },
          margin: { left: 10, right: 10 }
        });
      }
    }
    doc.save(c.filename + '.pdf');
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.js-export-xlsx, .js-export-pdf');
    if (!btn) return;
    ev.preventDefault();
    if (btn.classList.contains('js-export-xlsx')) exportarExcel();
    else exportarPdf();
  });
})();
