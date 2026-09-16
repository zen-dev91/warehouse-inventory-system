
async function loadPurchaseReturns() {
  const res = await axios.get(API + 'purchase_return.php');
  switch (res.status) {
    case 200:
      renderPurchaseReturns(res.data.data);
      break;
    default:
      showToast('Failed to load purchase returns', true);
  }
}

function renderPurchaseReturns(rows) {
  const body = document.getElementById('preturnTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No purchase returns yet.', 5);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-preturn');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-supplier').textContent = r.supplier_name;
    clone.querySelector('.row-by').textContent = r.returned_by;
    clone.querySelector('.row-date').textContent = r.return_date;
    clone.querySelector('.row-reason').textContent = r.reason;
    clone.querySelector('.row-items').textContent = r.item_count;
    body.appendChild(clone);
  }
}

async function savePurchaseReturn(e) {
  e.preventDefault();
  if (txItems.preturn.length === 0) {
    showToast('Add at least one item to the return', true);
    return;
  }

  const payload = {
    supplier_id: document.getElementById('preturn_supplier_id').value,
    return_date: document.getElementById('preturn_date').value,
    reason: document.getElementById('preturn_reason').value.trim(),
    items: txItems.preturn.map(function (it) {
      return { product_id: it.product_id, quantity_returned: it.quantity };
    })
  };

  const res = await axios.post(API + 'purchase_return.php', payload);
  switch (res.status) {
    case 200:
      handleFormResult(res, 'preturnModal', loadPurchaseReturns);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

