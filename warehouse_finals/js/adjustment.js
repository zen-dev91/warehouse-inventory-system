
async function loadAdjustments() {
  const res = await axios.get(API + 'adjustment.php');
  switch (res.status) {
    case 200:
      renderAdjustments(res.data.data);
      break;
    default:
      showToast('Failed to load adjustments', true);
  }
}

function renderAdjustments(rows) {
  const body = document.getElementById('adjustmentTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No adjustments yet.', 4);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-adjustment');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-by').textContent = r.adjusted_by;
    clone.querySelector('.row-date').textContent = r.adjustment_date;
    clone.querySelector('.row-reason').textContent = r.reason;
    clone.querySelector('.row-items').textContent = r.item_count;
    body.appendChild(clone);
  }
}

async function saveAdjustment(e) {
  e.preventDefault();
  if (txItems.adjustment.length === 0) {
    showToast('Add at least one item to the adjustment', true);
    return;
  }

  const payload = {
    adjustment_date: document.getElementById('adjustment_date').value,
    reason: document.getElementById('adjustment_reason').value.trim(),
    items: txItems.adjustment.map(function (it) {
      return { product_id: it.product_id, quantity: it.quantity, adjustment_type: it.adjustment_type };
    })
  };

  const res = await axios.post(API + 'adjustment.php', payload);
  switch (res.status) {
    case 200:
      handleFormResult(res, 'adjustmentModal', loadAdjustments);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

