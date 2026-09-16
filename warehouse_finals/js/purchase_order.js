
async function loadPurchaseOrders() {
  const res = await axios.get(API + 'purchase_order.php');
  switch (res.status) {
    case 200:
      renderPurchaseOrders(res.data.data);
      break;
    default:
      showToast('Failed to load purchase orders', true);
  }
}

function renderPurchaseOrders(rows) {
  const body = document.getElementById('poTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No purchase orders yet.', 7);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-po');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-supplier').textContent = r.supplier_name;
    clone.querySelector('.row-by').textContent = r.ordered_by;
    clone.querySelector('.row-date').textContent = r.order_date;
    clone.querySelector('.row-items').textContent = r.item_count;
    clone.querySelector('.row-cost').textContent = peso(r.total_cost);
    clone.querySelector('.row-status').appendChild(createPoStatusBadge(r.status));

    const actionsCell = clone.querySelector('.row-actions');
    if (r.status === 'Pending' && can('purchase_order', 'manage')) {
      const cancelClone = document.getElementById('tmpl-btn-cancel-po').content.cloneNode(true);
      const cancelBtn = cancelClone.querySelector('button');
      cancelBtn.addEventListener('click', function () {
        cancelPurchaseOrder(r.po_id);
      });
      actionsCell.appendChild(cancelClone);
    } else {
      actionsCell.appendChild(document.getElementById('tmpl-txt-view-only').content.cloneNode(true));
    }

    body.appendChild(clone);
  }
}

async function cancelPurchaseOrder(poId) {
  const ok = await showConfirm('Cancel Purchase Order?', 'Are you sure you want to cancel this pending purchase order?', 'Yes, Cancel', 'No, Keep');
  if (!ok) return;
  const res = await axios.put(API + 'purchase_order.php', { po_id: poId, status: 'Cancelled' });
  switch (res.status) {
    case 200:
      showToast(res.data.message || 'Purchase order cancelled');
      loadPurchaseOrders();
      break;
    default:
      showToast((res.data && res.data.message) || 'Failed to cancel purchase order', true);
  }
}

async function savePurchaseOrder(e) {
  e.preventDefault();
  if (txItems.po.length === 0) {
    showToast('Add at least one item to the purchase order', true);
    return;
  }

  const payload = {
    supplier_id: document.getElementById('po_supplier_id').value,
    order_date: document.getElementById('po_order_date').value,
    items: txItems.po.map(function (it) {
      return { product_id: it.product_id, quantity_ordered: it.quantity, unit_cost: it.unit_cost };
    })
  };

  const res = await axios.post(API + 'purchase_order.php', payload);
  switch (res.status) {
    case 200:
      handleFormResult(res, 'poModal', loadPurchaseOrders);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

