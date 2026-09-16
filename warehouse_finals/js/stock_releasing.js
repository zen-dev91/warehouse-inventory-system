
async function loadStockReleasing() {
  const res = await axios.get(API + 'stock_releasing.php');
  switch (res.status) {
    case 200:
      renderStockReleasing(res.data.data);
      break;
    default:
      showToast('Failed to load stock releasing records', true);
  }
}

function renderStockReleasing(rows) {
  const body = document.getElementById('releasingTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No stock releasing records yet.', 4);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-releasing');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-to').textContent = r.requested_by;
    clone.querySelector('.row-by').textContent = r.released_by;
    clone.querySelector('.row-date').textContent = r.releasing_date;
    clone.querySelector('.row-items').textContent = r.item_count;
    body.appendChild(clone);
  }
}

async function saveStockReleasing(e) {
  e.preventDefault();
  if (txItems.releasing.length === 0) {
    showToast('Add at least one item to release', true);
    return;
  }

  const payload = {
    requested_by: document.getElementById('releasing_requested_by').value.trim(),
    releasing_date: document.getElementById('releasing_date').value,
    items: txItems.releasing.map(function (it) {
      return { product_id: it.product_id, quantity_released: it.quantity };
    })
  };

  const res = await axios.post(API + 'stock_releasing.php', payload);
  switch (res.status) {
    case 200:
      handleFormResult(res, 'releasingModal', loadStockReleasing);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

