
async function loadAdjustments() {
  try {
    const res = await axios.get(API + 'adjustment.php');
    switch (res.status) {
      case 200:
        renderAdjustments(res.data.data);
        break;
      case 403:
        showToast('Unauthorized: You do not have permission to view adjustments.', true);
        break;
      default:
        showToast('Failed to load adjustments', true);
    }
  } catch (error) {
    showToast('Failed to load adjustments', true);
  }
}

function renderAdjustments(rows) {
  const body = document.getElementById('adjustmentTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No adjustments yet.', 3);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-adjustment');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-product').textContent = r.product_name;
    const typeCell = clone.querySelector('.row-type');
    if (r.adjustment_type === 'Addition') {
      typeCell.innerHTML = '<span class="badge bg-success">Addition</span>';
    } else {
      typeCell.innerHTML = '<span class="badge bg-warning text-dark">Deduction</span>';
    }
    clone.querySelector('.row-qty').textContent = r.quantity;
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
    reason: (document.getElementById('adjustment_reason').value || '').trim(),
    items: txItems.adjustment.map(function (it) {
      return { product_id: it.product_id, quantity: it.quantity, adjustment_type: it.adjustment_type };
    })
  };

  try {
    const res = await axios.post(API + 'adjustment.php', payload);
    switch (res.status) {
      case 200:
        handleFormResult(res, 'adjustmentModal', loadAdjustments);
        break;
      case 403:
        showToast('Unauthorized: You do not have permission to perform adjustments.', true);
        break;
      case 409:
        showToast((res.data && res.data.message) || 'Insufficient stock for deduction.', true);
        break;
      case 422:
      case 400:
        showToast((res.data && res.data.message) || 'Validation Error: Please check your inputs.', true);
        break;
      default:
        showToast((res.data && res.data.message) || 'Request failed', true);
    }
  } catch (error) {
    if (error.response) {
      const msg = (error.response.data && error.response.data.message) ? error.response.data.message : 'Request failed.';
      showToast(msg, true);
    } else {
      showToast((error && error.message) || 'Request failed.', true);
    }
  }
}

