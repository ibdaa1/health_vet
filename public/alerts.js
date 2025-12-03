// alerts.js
// Fetch alerts API and render full details into alerts.html
(function(){
  const API = '/health_vet/api/alerts/get_low_stock_and_expiring.php';
  const btnFetch = document.getElementById('btnFetch');
  const btnPrint = document.getElementById('btnPrint');
  const daysInput = document.getElementById('days');
  const thresholdInput = document.getElementById('threshold');
  const debugPre = document.getElementById('debugPre');
  const badgeWrap = document.getElementById('badge');

  const lowTableBody = document.querySelector('#lowTable tbody');
  const expTableBody = document.querySelector('#expTable tbody');
  const lowCountEl = document.getElementById('lowCount');
  const expCountEl = document.getElementById('expCount');

  const modal = document.getElementById('detailModal');
  const modalBody = document.getElementById('modalBody');
  const closeModal = document.getElementById('closeModal');

  function escapeHtml(s){ return (s==null)?'':String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  async function fetchAndRender(debug=false){
    const days = parseInt(daysInput.value||30,10);
    const thresholdVal = thresholdInput.value;
    const threshold = thresholdVal === '' ? '' : '&threshold=' + encodeURIComponent(thresholdVal);
    const url = `${API}?days=${encodeURIComponent(days)}${threshold}${debug? '&debug=1':''}`;
    try {
      const res = await fetch(url, { credentials:'same-origin' });
      const text = await res.text();
      let j;
      try { j = JSON.parse(text); } catch(e) {
        debugPre.textContent = 'API لم يرجع JSON صالح:\n' + text;
        return;
      }
      // show debug raw
      debugPre.textContent = JSON.stringify(j, null, 2);

      if (!j.success) {
        lowCountEl.textContent = 'خطأ في جلب التنبيهات: ' + (j.message || '');
        expCountEl.textContent = '';
        setBadge(0);
        return;
      }

      renderLow(j.low_stock || []);
      renderExp(j.expiring || []);
      const total = (j.low_stock ? j.low_stock.length : 0) + (j.expiring ? j.expiring.length : 0);
      setBadge(total);

    } catch (err) {
      debugPre.textContent = 'خطأ في الاتصال: ' + err.message;
    }
  }

  function setBadge(count){
    if (!badgeWrap) return;
    badgeWrap.innerHTML = count > 0 ? `<span class="badge">${count}</span>` : '';
  }

  function renderLow(items){
    lowTableBody.innerHTML = '';
    lowCountEl.textContent = `عدد العناصر: ${items.length}`;
    if (!items.length) {
      lowTableBody.innerHTML = '<tr><td colspan="9" class="small">لا توجد عناصر منخفضة المخزون</td></tr>';
      return;
    }
    for (const it of items) {
      const tr = document.createElement('tr');
      const img = it.ProductImage ? `<img src="/health_vet/uploads/ProductImage/${escapeHtml(it.ProductImage)}" class="thumb">` : '-';
      const prodName = escapeHtml(it.ProductName || '');
      const code = escapeHtml(it.Product_Code || '');
      const sku = escapeHtml(it.SKU || '');
      const comp_ar = escapeHtml(it.Composition_ar || it.Composition || '');
      const comp_en = escapeHtml(it.Composition_en || '');
      tr.innerHTML = `<td>${img}</td>
                      <td>${prodName}</td>
                      <td>${code}</td>
                      <td>${sku}</td>
                      <td>${comp_ar}</td>
                      <td>${comp_en}</td>
                      <td>${it.Quantity}</td>
                      <td>${it.MinQuantity}</td>
                      <td><button class="button" data-variant="${it.VariantID}">عرض</button></td>`;
      const btn = tr.querySelector('button');
      btn.addEventListener('click', ()=> openDetail(it));
      lowTableBody.appendChild(tr);
    }
  }

  function renderExp(items){
    expTableBody.innerHTML = '';
    expCountEl.textContent = `عدد العناصر: ${items.length}`;
    if (!items.length) {
      expTableBody.innerHTML = '<tr><td colspan="9" class="small">لا توجد نسخ قريبة من الانتهاء</td></tr>';
      return;
    }
    for (const it of items) {
      const tr = document.createElement('tr');
      const img = it.ProductImage ? `<img src="/health_vet/uploads/ProductImage/${escapeHtml(it.ProductImage)}" class="thumb">` : '-';
      const prodName = escapeHtml(it.ProductName || '');
      const code = escapeHtml(it.Product_Code || '');
      const sku = escapeHtml(it.SKU || '');
      const comp_ar = escapeHtml(it.Composition_ar || it.Composition || '');
      const expiry = escapeHtml(it.ExpiryDate || '');
      const days = (it.DaysLeft === null || it.DaysLeft === undefined) ? '' : String(it.DaysLeft);
      tr.innerHTML = `<td>${img}</td>
                      <td>${prodName}</td>
                      <td>${code}</td>
                      <td>${sku}</td>
                      <td>${comp_ar}</td>
                      <td>${expiry}</td>
                      <td>${days}</td>
                      <td>${it.Quantity}</td>
                      <td><button class="button" data-variant="${it.VariantID}">عرض</button></td>`;
      const btn = tr.querySelector('button');
      btn.addEventListener('click', ()=> openDetail(it));
      expTableBody.appendChild(tr);
    }
  }

  function openDetail(item){
    // show modal with full info (composition both languages, raw optionids hidden)
    modalBody.innerHTML = '';
    const html = [];
    html.push(`<div class="row">`);
    html.push(`<div class="col"><strong>المنتج:</strong><div>${escapeHtml(item.ProductName||'')}</div><div class="small">الكود: ${escapeHtml(item.Product_Code||'')}</div></div>`);
    html.push(`<div style="width:120px">${item.ProductImage? `<img src="/health_vet/uploads/ProductImage/${escapeHtml(item.ProductImage)}" style="width:100px;height:100px;object-fit:cover;border-radius:6px">` : ''}</div>`);
    html.push(`</div>`);
    html.push(`<div style="margin-top:8px"><strong>SKU:</strong> ${escapeHtml(item.SKU||'')}</div>`);
    html.push(`<div style="margin-top:6px"><strong>تركيب (AR):</strong> ${escapeHtml(item.Composition_ar||'')}</div>`);
    if (item.Composition_en) html.push(`<div style="margin-top:6px"><strong>Composition (EN):</strong> ${escapeHtml(item.Composition_en)}</div>`);
    if (item.Quantity !== undefined) html.push(`<div style="margin-top:6px"><strong>الكمية:</strong> ${item.Quantity} · <strong>الحد الأدنى:</strong> ${item.MinQuantity || 0}</div>`);
    if (item.ExpiryDate) html.push(`<div style="margin-top:6px"><strong>تاريخ الصلاحية:</strong> ${escapeHtml(item.ExpiryDate)} · <strong>بقي (يوم):</strong> ${escapeHtml(item.DaysLeft !== undefined ? String(item.DaysLeft) : '')}</div>`);
    html.push(`<div style="margin-top:8px"><small>VariantID: ${escapeHtml(String(item.VariantID || ''))}</small></div>`);
    modalBody.innerHTML = html.join('');
    modal.classList.add('show');
  }

  closeModal.addEventListener('click', ()=> modal.classList.remove('show'));
  modal.addEventListener('click', (e)=> { if (e.target === modal) modal.classList.remove('show'); });

  btnFetch.addEventListener('click', ()=> fetchAndRender(true));
  btnPrint.addEventListener('click', ()=> {
    // open printable window with both lists
    const days = daysInput.value || '30';
    const threshold = thresholdInput.value || '';
    const url = `${API}?days=${encodeURIComponent(days)}${threshold ? '&threshold='+encodeURIComponent(threshold):''}`;
    // fetch fresh and print content
    fetch(url, {credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      const html = [];
      html.push('<html><head><meta charset="utf-8"><title>طباعة تنبيهات المخزون</title>');
      html.push('<style>body{font-family:Arial,sans-serif;padding:18px}h2{margin-top:0}.item{border:1px solid #ddd;padding:8px;margin-bottom:8px;border-radius:6px}</style>');
      html.push('</head><body>');
      html.push(`<h1>تنبيهات المخزون</h1>`);
      html.push(`<h2>مخزون منخفض (${(j.low_stock||[]).length})</h2>`);
      (j.low_stock||[]).forEach(it=>{
        html.push('<div class="item">');
        html.push(`<strong>${escapeHtml(it.ProductName||it.Product_Code||'')}</strong> — SKU: ${escapeHtml(it.SKU||'')}`);
        html.push(`<div>الكمية: ${it.Quantity} · الحد الأدنى: ${it.MinQuantity || 0}</div>`);
        html.push(`<div>تركيب: ${escapeHtml(it.Composition_ar||'')}</div>`);
        html.push('</div>');
      });
      html.push(`<h2>قريبة الانتهاء (${(j.expiring||[]).length})</h2>`);
      (j.expiring||[]).forEach(it=>{
        html.push('<div class="item">');
        html.push(`<strong>${escapeHtml(it.ProductName||it.Product_Code||'')}</strong> — SKU: ${escapeHtml(it.SKU||'')}`);
        html.push(`<div>انتهاء: ${escapeHtml(it.ExpiryDate||'')} · بقي: ${it.DaysLeft}</div>`);
        html.push(`<div>تركيب: ${escapeHtml(it.Composition_ar||'')}</div>`);
        html.push('</div>');
      });
      html.push('</body></html>');
      const w = window.open('', '_blank');
      w.document.open();
      w.document.write(html.join(''));
      w.document.close();
      w.focus();
      setTimeout(()=> w.print(), 600);
    }).catch(err=> alert('خطأ في جلب بيانات الطباعة: ' + err.message));
  });

  // initial load
  fetchAndRender(true);

  // also poll every 5 minutes
  setInterval(()=> fetchAndRender(false), 5 * 60 * 1000);
})();