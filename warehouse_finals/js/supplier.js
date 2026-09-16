
async function loadSuppliers() {
  const res = await axios.get(API + 'supplier.php');
  switch (res.status) {
    case 200:
      cache.suppliers = res.data.data;
      renderSuppliers(cache.suppliers);
      break;
    default:
      showToast('Failed to load suppliers', true);
  }
}

function renderSuppliers(rows) {
  const body = document.getElementById('supplierTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No suppliers yet. Add one to get started.', 6);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-supplier');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-name').textContent = r.supplier_name;
    clone.querySelector('.row-contact').textContent = r.contact_person || '—';
    clone.querySelector('.row-phone').textContent = r.phone || '—';
    clone.querySelector('.row-email').textContent = r.email || '—';
    clone.querySelector('.row-status').appendChild(createStatusBadge(r.is_active));
    clone.querySelector('.row-actions').appendChild(createRowActions('supplier', r.supplier_id, r.is_active));
    body.appendChild(clone);
  }
}

function editSupplier(id) {
  const item = findById(cache.suppliers, 'supplier_id', id);
  if (!item) return;
  document.getElementById('supplier_id').value = item.supplier_id;
  document.getElementById('supplier_name').value = item.supplier_name;
  document.getElementById('contact_person').value = item.contact_person || '';
  document.getElementById('phone').value = item.phone || '';
  document.getElementById('email').value = item.email || '';
  document.getElementById('address').value = item.address || '';
  document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
  openModal('supplierModal');
}

async function saveSupplier(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const id = document.getElementById('supplier_id').value;
  const name = (document.getElementById('supplier_name').value || '').trim();
  const contact = (document.getElementById('contact_person').value || '').trim();
  const phone = (document.getElementById('phone').value || '').trim();
  const email = (document.getElementById('email').value || '').trim();
  const address = (document.getElementById('address').value || '').trim();

  let res;
  if (id) {
    res = await axios.put(API + 'supplier.php', {
      supplier_id: parseInt(id),
      supplier_name: name,
      contact_person: contact,
      phone: phone,
      email: email,
      address: address
    });
  } else {
    formData.set('supplier_name', name);
    formData.set('contact_person', contact);
    formData.set('phone', phone);
    formData.set('email', email);
    formData.set('address', address);
    res = await axios.post(API + 'supplier.php', formData);
  }

  switch (res.status) {
    case 200:
      handleFormResult(res, 'supplierModal', loadSuppliers);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

