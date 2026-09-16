
async function loadStocksReturns() {
  const res = await axios.get(API + 'stocks_return.php');
  switch (res.status) {
    case 200:
      renderStocksReturns(res.data.data);
      break;
    default:
      showToast('Failed to load stocks returns', true);
  }
}

function renderStocksReturns(rows) {
  const body = document.getElementById('sreturnTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No stocks returns yet.', 5);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-sreturn');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-by-customer').textContent = r.requested_by;
    clone.querySelector('.row-by-staff').textContent = r.processed_by;
    clone.querySelector('.row-date').textContent = r.return_date;
    clone.querySelector('.row-reason').textContent = r.reason;
    clone.querySelector('.row-items').textContent = r.item_count;
    body.appendChild(clone);
  }
}

async function saveStocksReturn(e) {
  e.preventDefault();
  if (txItems.sreturn.length === 0) {
    showToast('Add at least one item to the return', true);
    return;
  }

  const payload = {
    requested_by: document.getElementById('sreturn_requested_by').value.trim(),
    return_date: document.getElementById('sreturn_date').value,
    reason: document.getElementById('sreturn_reason').value.trim(),
    items: txItems.sreturn.map(function (it) {
      return { product_id: it.product_id, quantity_returned: it.quantity };
    })
  };

  const res = await axios.post(API + 'stocks_return.php', payload);
  switch (res.status) {
    case 200:
      handleFormResult(res, 'sreturnModal', loadStocksReturns);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

