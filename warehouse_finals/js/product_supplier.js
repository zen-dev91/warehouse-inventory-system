
var allLinks = [];
var filteredLinks = [];
var linkCurrentPage = 1;
var linkPageSize = 10;
var modalProducts = [];

async function loadLinks() {
  var results = await Promise.all([
    axios.get(API + 'product_supplier.php'),
    axios.get(API + 'product.php'),
    axios.get(API + 'supplier.php')
  ]);
  var linkRes = results[0];
  var prodRes = results[1];
  var supRes  = results[2];

  var failed = [linkRes, prodRes, supRes].find(function (r) { return r.status !== 200; });
  if (failed) {
    showToast('Failed to load supplier links', true);
    return;
  }

  cache.products  = (prodRes.data && prodRes.data.data) || [];
  cache.suppliers = (supRes.data && supRes.data.data) || [];
  allLinks = (linkRes.data && linkRes.data.data) || [];

  populateLinkDropdowns();
  applyLinkFilters();
}

function populateLinkDropdowns() {
  populateFilterDropdowns();
  populateModalSupplierDropdown();
}

function populateFilterDropdowns() {
  var activeSuppliers = (cache.suppliers || []).filter(function (s) { return parseInt(s.is_active) === 1; });
  var activeProducts  = (cache.products || []).filter(function (p) { return parseInt(p.is_active) === 1; });

  var supSelect = document.getElementById('filterSupplier');
  if (supSelect) {
    var saved = supSelect.value;
    var html = '<option value="">-- All Suppliers --</option>';
    for (var i = 0; i < activeSuppliers.length; i++) {
      html += '<option value="' + activeSuppliers[i].supplier_id + '">' + escapeHtml(activeSuppliers[i].supplier_name) + '</option>';
    }
    supSelect.innerHTML = html;
    supSelect.value = saved;
  }

  var prodSelect = document.getElementById('filterProduct');
  if (prodSelect) {
    var saved2 = prodSelect.value;
    var html2 = '<option value="">-- All Products --</option>';
    for (var j = 0; j < activeProducts.length; j++) {
      html2 += '<option value="' + activeProducts[j].product_id + '">' + escapeHtml(activeProducts[j].product_name) + '</option>';
    }
    prodSelect.innerHTML = html2;
    prodSelect.value = saved2;
  }
}

function populateModalSupplierDropdown() {
  var activeSuppliers = (cache.suppliers || []).filter(function (s) { return parseInt(s.is_active) === 1; });
  var select = document.getElementById('link_supplier_id');
  if (!select) return;
  var currentVal = select.value;
  var html = '<option value="">-- Select Supplier --</option>';
  for (var i = 0; i < activeSuppliers.length; i++) {
    html += '<option value="' + activeSuppliers[i].supplier_id + '">' + escapeHtml(activeSuppliers[i].supplier_name) + '</option>';
  }
  select.innerHTML = html;
  if (currentVal) select.value = currentVal;
}

function applyLinkFilters() {
  var supVal    = (document.getElementById('filterSupplier') || {}).value || '';
  var prodVal   = (document.getElementById('filterProduct')  || {}).value || '';
  var searchVal = ((document.getElementById('filterSearch')  || {}).value || '').toLowerCase().trim();

  filteredLinks = allLinks.filter(function (link) {
    if (supVal  && String(link.supplier_id) !== supVal) return false;
    if (prodVal && String(link.product_id)  !== prodVal) return false;
    if (searchVal) {
      var combined = (link.product_name + ' ' + link.supplier_name).toLowerCase();
      if (combined.indexOf(searchVal) === -1) return false;
    }
    return true;
  });

  linkCurrentPage = 1;
  renderLinks(filteredLinks);
}

function renderFilteredLinks() {
  renderLinks(filteredLinks);
}

function renderLinks(rows) {
  var body = document.getElementById('linkTable');
  if (!body) return;

  var items = rows || filteredLinks || [];
  var total = items.length;
  var start = (linkCurrentPage - 1) * linkPageSize;
  var end   = Math.min(start + linkPageSize, total);
  var pageRows = items.slice(start, end);

  if (total === 0) {
    renderEmptyRow(body, 'No supplier links found.', 5);
    updateLinkPagination(0, 0, 0);
    return;
  }

  body.innerHTML = '';
  var tmpl = document.getElementById('tmpl-row-link');

  for (var i = 0; i < pageRows.length; i++) {
    var r = pageRows[i];
    var clone;

    if (tmpl && tmpl.content) {
      clone = tmpl.content.cloneNode(true);
      var numEl = clone.querySelector('.row-num');
      if (numEl) numEl.textContent = String(start + i + 1);

      var prodEl = clone.querySelector('.row-product');
      if (prodEl) prodEl.textContent = r.product_name;

      var supEl = clone.querySelector('.row-supplier');
      if (supEl) supEl.textContent = r.supplier_name;

      var costEl = clone.querySelector('.row-cost');
      if (costEl) costEl.textContent = parseFloat(r.supplier_unit_cost).toFixed(2);

      var actCell = clone.querySelector('.row-actions');
      if (actCell) {
        if (can('product_supplier', 'manage')) {
          var btnTmpl = document.getElementById('tmpl-btn-delete-link');
          if (btnTmpl && btnTmpl.content) {
            var btnClone = btnTmpl.content.cloneNode(true);
            var btn = btnClone.querySelector('button');
            (function (pid, sid) {
              btn.addEventListener('click', function () { deleteLink(pid, sid); });
            })(r.product_id, r.supplier_id);
            actCell.appendChild(btnClone);
          } else {
            var dBtn = document.createElement('button');
            dBtn.type = 'button';
            dBtn.className = 'btn btn-sm btn-outline-danger';
            dBtn.textContent = 'Unlink';
            (function (pid, sid) {
              dBtn.addEventListener('click', function () { deleteLink(pid, sid); });
            })(r.product_id, r.supplier_id);
            actCell.appendChild(dBtn);
          }
        } else {
          actCell.appendChild(document.getElementById('tmpl-txt-view-only').content.cloneNode(true));
        }
      }
    } else {

      var tr = document.createElement('tr');
      tr.innerHTML = '<td>' + (start + i + 1) + '</td>' +
                     '<td><strong>' + escapeHtml(r.product_name) + '</strong></td>' +
                     '<td>' + escapeHtml(r.supplier_name) + '</td>' +
                     '<td>' + parseFloat(r.supplier_unit_cost).toFixed(2) + '</td>' +
                     '<td class="text-end"></td>';
      var aCell = tr.querySelector('td:last-child');
      if (can('product_supplier', 'manage')) {
        var fbBtn = document.createElement('button');
        fbBtn.type = 'button';
        fbBtn.className = 'btn btn-sm btn-outline-danger';
        fbBtn.textContent = 'Unlink';
        (function (pid, sid) {
          fbBtn.addEventListener('click', function () { deleteLink(pid, sid); });
        })(r.product_id, r.supplier_id);
        aCell.appendChild(fbBtn);
      } else {
        aCell.appendChild(document.getElementById('tmpl-txt-view-only').content.cloneNode(true));
      }
      clone = tr;
    }

    body.appendChild(clone);
  }

  updateLinkPagination(start + 1, end, total);
}

function updateLinkPagination(from, to, total) {
  var info = document.getElementById('linkPaginationInfo');
  if (info) {
    info.textContent = total === 0
      ? 'Showing 0 entries'
      : 'Showing ' + from + ' to ' + to + ' of ' + total + ' entries';
  }

  var nav = document.getElementById('linkPagination');
  if (!nav) return;

  var totalPages = Math.max(Math.ceil(total / linkPageSize), 1);
  var html = '';

  html += '<li class="page-item' + (linkCurrentPage <= 1 ? ' disabled' : '') + '">';
  html += '<a class="page-link" href="#" data-link-page="' + (linkCurrentPage - 1) + '">Previous</a></li>';

  var startPage = Math.max(1, linkCurrentPage - 3);
  var endPage   = Math.min(totalPages, startPage + 6);
  startPage     = Math.max(1, endPage - 6);

  for (var p = startPage; p <= endPage; p++) {
    html += '<li class="page-item' + (p === linkCurrentPage ? ' active' : '') + '">';
    html += '<a class="page-link" href="#" data-link-page="' + p + '">' + p + '</a></li>';
  }

  html += '<li class="page-item' + (linkCurrentPage >= totalPages ? ' disabled' : '') + '">';
  html += '<a class="page-link" href="#" data-link-page="' + (linkCurrentPage + 1) + '">Next</a></li>';

  nav.innerHTML = html;
}

function handleLinkPageClick(e) {
  e.preventDefault();
  var target = e.target.closest('[data-link-page]');
  if (!target) return;
  var page = parseInt(target.getAttribute('data-link-page'));
  var totalPages = Math.max(Math.ceil(filteredLinks.length / linkPageSize), 1);
  if (page < 1 || page > totalPages) return;
  linkCurrentPage = page;
  renderLinks(filteredLinks);
}

async function loadProductsForSupplier() {
  var select    = document.getElementById('link_supplier_id');
  var supplierId = select ? select.value : '';
  var listBody  = document.getElementById('linkProductList');
  var searchInput = document.getElementById('linkSearchProducts');

  if (searchInput) searchInput.value = '';

  if (!supplierId) {
    modalProducts = [];
    if (listBody) listBody.innerHTML = '<tr><td colspan="3" class="text-muted fst-italic">Select a supplier to view products.</td></tr>';
    updateSelectedCount();
    return;
  }

  var res = await axios.get(API + 'product_supplier.php?supplier_id=' + encodeURIComponent(supplierId));
  if (res.status !== 200 || !res.data || !res.data.success) {
    showToast((res.data && res.data.message) || 'Failed to load products for supplier', true);
    return;
  }

  modalProducts = res.data.data || [];
  renderModalProducts();
}

function renderModalProducts() {
  var listBody = document.getElementById('linkProductList');
  if (!listBody) return;

  var searchInput = document.getElementById('linkSearchProducts');
  var searchVal = searchInput ? searchInput.value.toLowerCase().trim() : '';

  listBody.innerHTML = '';
  var visibleCount = 0;

  for (var i = 0; i < modalProducts.length; i++) {
    var p = modalProducts[i];

    if (searchVal && p.product_name.toLowerCase().indexOf(searchVal) === -1) continue;

    visibleCount++;
    var tr = document.createElement('tr');

    var tdCheck = document.createElement('td');
    var cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.setAttribute('data-product-id', p.product_id);
    cb.checked = parseInt(p.is_linked) === 1;
    cb.addEventListener('change', updateSelectedCount);
    tdCheck.appendChild(cb);
    tr.appendChild(tdCheck);

    var tdName = document.createElement('td');
    tdName.textContent = p.product_name;
    tr.appendChild(tdName);

    var tdCost = document.createElement('td');
    var costInput = document.createElement('input');
    costInput.type = 'number';
    costInput.className = 'form-control form-control-sm';
    costInput.step = '0.01';
    costInput.min = '0';
    costInput.setAttribute('data-cost-product-id', p.product_id);
    costInput.value = parseFloat(p.supplier_unit_cost).toFixed(2);
    tdCost.appendChild(costInput);
    tr.appendChild(tdCost);

    listBody.appendChild(tr);
  }

  if (visibleCount === 0 && modalProducts.length > 0) {
    listBody.innerHTML = '<tr><td colspan="3" class="text-muted fst-italic">No products match your search.</td></tr>';
  } else if (visibleCount === 0) {
    listBody.innerHTML = '<tr><td colspan="3" class="text-muted fst-italic">No active products found.</td></tr>';
  }

  updateSelectedCount();
  updateHeaderCheckbox();
}

function updateSelectedCount() {
  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  var count = 0;
  for (var i = 0; i < checkboxes.length; i++) {
    if (checkboxes[i].checked) count++;
  }
  var el = document.getElementById('selectedCount');
  if (el) el.textContent = 'Selected Products: ' + count;
  updateHeaderCheckbox();
}

function updateHeaderCheckbox() {
  var headerCb = document.getElementById('checkAllHeader');
  if (!headerCb) return;
  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  var total = checkboxes.length;
  var checked = 0;
  for (var i = 0; i < checkboxes.length; i++) {
    if (checkboxes[i].checked) checked++;
  }
  headerCb.checked       = total > 0 && checked === total;
  headerCb.indeterminate = checked > 0 && checked < total;
}

function checkAllProducts() {
  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  for (var i = 0; i < checkboxes.length; i++) checkboxes[i].checked = true;
  updateSelectedCount();
}

function uncheckAllProducts() {
  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  for (var i = 0; i < checkboxes.length; i++) checkboxes[i].checked = false;
  updateSelectedCount();
}

function toggleAllFromHeader() {
  var headerCb = document.getElementById('checkAllHeader');
  if (!headerCb) return;
  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  for (var i = 0; i < checkboxes.length; i++) checkboxes[i].checked = headerCb.checked;
  updateSelectedCount();
}

function searchModalProducts() {
  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  for (var i = 0; i < checkboxes.length; i++) {
    var pid = parseInt(checkboxes[i].getAttribute('data-product-id'));
    for (var j = 0; j < modalProducts.length; j++) {
      if (parseInt(modalProducts[j].product_id) === pid) {
        modalProducts[j].is_linked = checkboxes[i].checked ? 1 : 0;
        break;
      }
    }
  }
  var costInputs = document.querySelectorAll('#linkProductList input[type="number"]');
  for (var k = 0; k < costInputs.length; k++) {
    var cpid = parseInt(costInputs[k].getAttribute('data-cost-product-id'));
    for (var m = 0; m < modalProducts.length; m++) {
      if (parseInt(modalProducts[m].product_id) === cpid) {
        modalProducts[m].supplier_unit_cost = costInputs[k].value;
        break;
      }
    }
  }
  renderModalProducts();
}

async function saveLink(e) {
  if (e && e.preventDefault) e.preventDefault();
  return saveBulkLinks(e);
}

async function saveBulkLinks(e) {
  if (e && e.preventDefault) e.preventDefault();

  var supplierSelect = document.getElementById('link_supplier_id');
  var supplierId = supplierSelect ? parseInt(supplierSelect.value) : 0;

  if (!supplierId) {
    showToast('Please select a supplier.', true);
    return;
  }

  var checkboxes = document.querySelectorAll('#linkProductList input[type="checkbox"]');
  var links = [];
  var hasError = false;

  for (var i = 0; i < checkboxes.length; i++) {
    if (!checkboxes[i].checked) continue;
    var pid = parseInt(checkboxes[i].getAttribute('data-product-id'));
    var costInput = document.querySelector('#linkProductList input[data-cost-product-id="' + pid + '"]');
    var cost = costInput ? parseFloat(costInput.value) : 0;

    if (isNaN(cost) || cost < 0) {
      hasError = true;
      break;
    }

    links.push({
      product_id: pid,
      supplier_unit_cost: cost
    });
  }

  if (hasError) {
    showToast('Supplier unit cost cannot be negative.', true);
    return;
  }

  var payload = {
    supplier_id: supplierId,
    links: links
  };

  var res = await axios.post(API + 'product_supplier.php?action=bulk_save', payload);
  switch (res.status) {
    case 200:
      if (res.data && res.data.success) {
        showToast(res.data.message || 'Links saved successfully.');
        closeModal('linkModal');
        loadLinks();
      } else {
        showToast((res.data && res.data.message) || 'Failed to save links.', true);
      }
      break;
    default:
      showToast((res.data && res.data.message) || 'Failed to save links.', true);
  }
}

async function deleteLink(productId, supplierId) {
  var ok = await showConfirm('Remove Link?', 'Remove this product-supplier link?', 'Yes, Remove', 'Cancel');
  if (!ok) return;
  var res = await axios.delete(API + 'product_supplier.php?product_id=' + productId + '&supplier_id=' + supplierId);
  switch (res.status) {
    case 200:
      showToast(res.data.message || 'Link removed');
      loadLinks();
      break;
    default:
      showToast((res.data && res.data.message) || 'Failed to remove link', true);
  }
}

function initLinkEvents() {
  var el;

  el = document.getElementById('filterSupplier');
  if (el) el.addEventListener('change', applyLinkFilters);

  el = document.getElementById('filterProduct');
  if (el) el.addEventListener('change', applyLinkFilters);

  el = document.getElementById('filterSearch');
  if (el) el.addEventListener('input', applyLinkFilters);

  el = document.getElementById('linkPageSize');
  if (el) el.addEventListener('change', function () {
    linkPageSize = parseInt(this.value) || 10;
    linkCurrentPage = 1;
    renderLinks(filteredLinks);
  });

  el = document.getElementById('btnRefreshLinks');
  if (el) el.addEventListener('click', loadLinks);

  el = document.getElementById('linkPagination');
  if (el) el.addEventListener('click', handleLinkPageClick);

  el = document.getElementById('link_supplier_id');
  if (el) {
    el.addEventListener('change', loadProductsForSupplier);
    el.addEventListener('input', loadProductsForSupplier);
  }

  el = document.getElementById('linkSearchProducts');
  if (el) el.addEventListener('input', searchModalProducts);

  el = document.getElementById('btnCheckAll');
  if (el) el.addEventListener('click', checkAllProducts);

  el = document.getElementById('btnUncheckAll');
  if (el) el.addEventListener('click', uncheckAllProducts);

  el = document.getElementById('checkAllHeader');
  if (el) el.addEventListener('change', toggleAllFromHeader);

  el = document.getElementById('linkForm');
  if (el) el.addEventListener('submit', saveBulkLinks);

  el = document.getElementById('btnSaveLinks');
  if (el) el.addEventListener('click', function (e) {
    var f = document.getElementById('linkForm');
    if (f) {
      if (typeof f.requestSubmit === 'function') {
        f.requestSubmit();
      } else {
        saveBulkLinks(e);
      }
    } else {
      saveBulkLinks(e);
    }
  });

  var linkModalEl = document.getElementById('linkModal');
  if (linkModalEl) {

    linkModalEl.addEventListener('shown.bs.modal', function () {
      var s = document.getElementById('link_supplier_id');
      if (s && s.value) {
        loadProductsForSupplier();
      }
    });

    linkModalEl.addEventListener('hide.bs.modal', function () {
      if (document.activeElement && linkModalEl.contains(document.activeElement)) {
        document.activeElement.blur();
      }
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initLinkEvents);
} else {
  initLinkEvents();
}

