
async function loadStockReceiving() {
  const res = await axios.get(API + 'stock_receiving.php');
  switch (res.status) {
    case 200:
      renderStockReceiving(res.data.data);
      break;
    default:
      showToast('Failed to load stock receiving records', true);
  }
}

function renderStockReceiving(rows) {
  const body = document.getElementById('receivingTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No stock receiving records yet.', 4);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-receiving');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-supplier').textContent = r.supplier_name;
    clone.querySelector('.row-by').textContent = r.received_by;
    clone.querySelector('.row-date').textContent = r.receiving_date;
    clone.querySelector('.row-items').textContent = r.item_count;
    body.appendChild(clone);
  }
}

async function loadPendingPurchaseOrders() {
  const res = await axios.get(API + 'purchase_order.php?mode=pending');
  switch (res.status) {
    case 200: {
      const select = document.getElementById('receiving_po_id');
      const list = res.data.data || [];
      let html = '<option value="">Select a purchase order...</option>';
      for (let i = 0; i < list.length; i++) {
        html += '<option value="' + list[i].po_id + '">' + escapeHtml(list[i].supplier_name + ' (' + list[i].order_date + ')') + '</option>';
      }
      select.innerHTML = html;
      renderEmptyRow(document.getElementById('receiving_items_table'), 'Select a purchase order first.', 3);
      if (list.length === 1) {
        select.value = String(list[0].po_id);
        await loadPoItemsForReceiving();
      }
      break;
    }
    default:
      showToast('Failed to load pending purchase orders', true);
  }
}

async function loadPoItemsForReceiving() {
  const poId = document.getElementById('receiving_po_id').value;
  const body = document.getElementById('receiving_items_table');

  if (!poId) {
    renderEmptyRow(body, 'Select a purchase order first.', 3);
    return;
  }

  const res = await axios.get(API + 'stock_receiving.php?po_id=' + poId);
  switch (res.status) {
    case 200: {
      const items = res.data.data;
      if (!items || items.length === 0) {
        renderEmptyRow(body, 'This purchase order has no line items.', 3);
        return;
      }

      body.innerHTML = '';
      const tmpl = document.getElementById('tmpl-row-receiving-item');
      for (let i = 0; i < items.length; i++) {
        const it = items[i];
        const clone = tmpl.content.cloneNode(true);
        const tr = clone.querySelector('.receiving-row');
        tr.setAttribute('data-product-id', it.product_id);
        clone.querySelector('.row-product-name').textContent = it.product_name;
        clone.querySelector('.row-qty-ordered').textContent = it.quantity_ordered;
        const input = clone.querySelector('.row-qty-input');
        input.value = it.quantity_ordered;
        input.id = 'receiving_qty_' + it.product_id;
        body.appendChild(clone);
      }
      break;
    }
    default:
      showToast('Failed to load purchase order items', true);
  }
}

function showReceivingNotification(message) {
  const alertEl = document.getElementById('receivingSuccessAlert');
  if (alertEl) {
    const msgEl = document.getElementById('receivingSuccessMsg');
    if (msgEl) msgEl.textContent = message || 'Stock received successfully.';
    alertEl.hidden = false;
    setTimeout(function () {
      alertEl.hidden = true;
    }, 5000);
  }
}

async function saveStockReceiving(e) {
  e.preventDefault();
  const poId = document.getElementById('receiving_po_id').value;
  if (!poId) {
    showToast('Select a purchase order', true);
    return;
  }

  const rows = document.querySelectorAll('#receiving_items_table tr[data-product-id]');
  if (rows.length === 0) {
    showToast('This purchase order has no items to receive', true);
    return;
  }

  const items = [];
  for (let i = 0; i < rows.length; i++) {
    const productId = rows[i].getAttribute('data-product-id');
    const input = document.getElementById('receiving_qty_' + productId);
    const qty = input ? parseInt(input.value) : 0;
    if (qty && qty > 0) {
      items.push({ product_id: productId, quantity_received: qty });
    }
  }

  if (items.length === 0) {
    showToast('Enter at least one quantity received', true);
    return;
  }

  const payload = {
    po_id: poId,
    receiving_date: document.getElementById('receiving_date').value,
    items: items
  };

  try {
    const res = await axios.post(API + 'stock_receiving.php', payload);
    switch (res.status) {
      case 200: {
        const msg = (res.data && res.data.message) ? res.data.message : 'Stock received successfully.';
        showReceivingNotification(msg);
        handleFormResult(res, 'receivingModal', loadStockReceiving);
        break;
      }
      case 403:
        showToast('Unauthorized: You do not have permission to record stock receiving.', true);
        break;
      case 422:
        showToast('Validation Error: Please check your input fields.', true);
        break;
      case 500:
        showToast('Server Error: Please try again later.', true);
        break;
      default: {
        const msg = (res.data && res.data.message) ? res.data.message : 'Request failed.';
        showToast('Error ' + res.status + ': ' + msg, true);
        break;
      }
    }
  } catch (error) {
    if (error.response) {
      switch (error.response.status) {
        case 403:
          showToast('Unauthorized: You do not have permission to record stock receiving.', true);
          break;
        case 422:
          showToast('Validation Error: Please check your input fields.', true);
          break;
        case 500:
          showToast('Server Error: Please try again later.', true);
          break;
        default: {
          const msg = (error.response.data && error.response.data.message) ? error.response.data.message : 'Request failed.';
          showToast('Error ' + error.response.status + ': ' + msg, true);
          break;
        }
      }
    } else {
      showToast((error && error.message) || 'Request failed.', true);
    }
  }
}

