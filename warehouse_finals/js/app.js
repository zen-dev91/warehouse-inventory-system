
const API = 'api/';
let csrfToken = null;

axios.defaults.validateStatus = function () { return true; };

axios.interceptors.request.use(function (config) {
  const method = (config.method || 'get').toUpperCase();
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method) && csrfToken) {
    config.headers = config.headers || {};
    config.headers['X-CSRF-Token'] = csrfToken;
  }
  return config;
});

axios.interceptors.response.use(
  function (response) { return response; },
  function () {
    return Promise.resolve({
      status: 0,
      data: { success: false, message: 'Network error. Please check your connection.' }
    });
  }
);

const cache = {
  categories: [],
  products: [],
  suppliers: [],
  roles: []
};

let userCache = [];
let authUser = null;
let permissions = null;

function can(module, action) {
  return Boolean(permissions && permissions[module] && permissions[module][action]);
}

let toastTimer = null;
function showToast(message, isError = false) {
  if (window.Swal) {
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: isError ? 'error' : 'success',
      title: message,
      showConfirmButton: false,
      timer: 2800,
      timerProgressBar: true
    });
    return;
  }

  const successEl = document.getElementById('toastSuccess');
  const errorEl = document.getElementById('toastError');
  const target = isError ? errorEl : successEl;
  const other = isError ? successEl : errorEl;
  const body = document.getElementById(isError ? 'toastErrorBody' : 'toastSuccessBody');

  if (other) other.hidden = true;
  if (body) body.textContent = message;
  if (target) {
    target.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      target.hidden = true;
    }, 2800);
  }
}

async function showConfirm(title, text = '', confirmText = 'Yes', cancelText = 'Cancel') {
  if (window.Swal) {
    const res = await Swal.fire({
      title: title,
      text: text,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#212529',
      cancelButtonColor: '#6c757d',
      confirmButtonText: confirmText,
      cancelButtonText: cancelText
    });
    return res.isConfirmed;
  }
  return confirm(text ? (title + '\n' + text) : title);
}

function activateTab(tabName) {
  if (tabName === 'dashboard' && !can('dashboard', 'view')) return;
  if (tabName !== 'dashboard' && !can(tabName, 'view')) return;

  sessionStorage.setItem('activeTab', tabName);

  const sidebarEl = document.getElementById('sidebarOffcanvas');
  if (sidebarEl && window.bootstrap && bootstrap.Offcanvas) {
    const oc = bootstrap.Offcanvas.getInstance(sidebarEl);
    if (oc) oc.hide();
  }

  const tabTitles = {
    dashboard: 'Dashboard',
    category: 'Categories',
    product: 'Products',
    supplier: 'Suppliers',
    product_supplier: 'Product ↔ Supplier',
    role: 'Roles',
    users: 'Users',
    purchase_order: 'Purchase Orders',
    stock_receiving: 'Stock Receiving',
    stock_releasing: 'Stock Releasing',
    purchase_return: 'Purchase Returns',
    stocks_return: 'Stocks Returns',
    adjustment: 'Adjustments'
  };
  const mobileTitle = document.getElementById('mobilePageTitle');
  if (mobileTitle) {
    mobileTitle.textContent = tabTitles[tabName] || 'Warehouse';
  }

  const navItems = document.querySelectorAll('.nav-link[data-tab]');
  const panels = document.querySelectorAll('.panel');

  for (let i = 0; i < navItems.length; i++) {
    const isCurrent = navItems[i].getAttribute('data-tab') === tabName;
    navItems[i].classList.toggle('active', isCurrent);
  }

  for (let i = 0; i < panels.length; i++) {
    panels[i].hidden = (panels[i].id !== 'panel-' + tabName);
  }

  if (tabName === 'dashboard') loadDashboard();
  else if (tabName === 'category') loadCategories();
  else if (tabName === 'product') loadProducts();
  else if (tabName === 'supplier') loadSuppliers();
  else if (tabName === 'product_supplier') loadLinks();
  else if (tabName === 'role') loadRoles();
  else if (tabName === 'users') loadUsers();
  else if (tabName === 'purchase_order') loadPurchaseOrders();
  else if (tabName === 'stock_receiving') loadStockReceiving();
  else if (tabName === 'stock_releasing') loadStockReleasing();
  else if (tabName === 'purchase_return') loadPurchaseReturns();
  else if (tabName === 'stocks_return') loadStocksReturns();
  else if (tabName === 'adjustment') loadAdjustments();
}

function openModal(id) {
  const trigger = document.getElementById('modalTrigger');
  if (trigger) {
    trigger.setAttribute('data-bs-target', '#' + id);
    trigger.click();
  }
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if (modal) {
    const dismissBtn = modal.querySelector('[data-bs-dismiss="modal"]');
    if (dismissBtn) {
      dismissBtn.click();
    }
  }
}

function peso(amount) {
  const value = parseFloat(amount);
  return 'PHP ' + (isNaN(value) ? 0 : value).toFixed(2);
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text === null || text === undefined ? '' : text;
  return div.innerHTML;
}

function capitalize(word) {
  return word ? word.charAt(0).toUpperCase() + word.slice(1) : '';
}

function findById(list, key, id) {
  if (!list) return null;
  for (let i = 0; i < list.length; i++) {
    if (String(list[i][key]) === String(id)) return list[i];
  }
  return null;
}

function createStatusBadge(isActive) {
  const tmplId = parseInt(isActive) === 1 ? 'tmpl-badge-active' : 'tmpl-badge-inactive';
  return document.getElementById(tmplId).content.cloneNode(true);
}

function createPoStatusBadge(status) {
  if (status === 'Pending') return document.getElementById('tmpl-badge-pending').content.cloneNode(true);
  if (status === 'Received') return document.getElementById('tmpl-badge-received').content.cloneNode(true);
  if (status === 'Cancelled') return document.getElementById('tmpl-badge-cancelled').content.cloneNode(true);
  const clone = document.getElementById('tmpl-badge-status-generic').content.cloneNode(true);
  clone.querySelector('span').textContent = status;
  return clone;
}

const entityModuleMap = {
  category: 'category',
  product: 'product',
  supplier: 'supplier',
  role: 'role',
  user: 'users'
};

function createRowActions(entity, id, isActive) {
  const module = entityModuleMap[entity];
  const fragment = document.createDocumentFragment();

  if (!can(module, 'manage')) {
    fragment.appendChild(document.getElementById('tmpl-txt-view-only').content.cloneNode(true));
    return fragment;
  }

  const editClone = document.getElementById('tmpl-btn-edit').content.cloneNode(true);
  const editBtn = editClone.querySelector('button');
  editBtn.addEventListener('click', function () {
    if (entity === 'category') editCategory(id);
    else if (entity === 'product') editProduct(id);
    else if (entity === 'supplier') editSupplier(id);
    else if (entity === 'role') editRole(id);
    else if (entity === 'user') editUser(id);
  });
  fragment.appendChild(editClone);

  const toggleTmplId = parseInt(isActive) === 1 ? 'tmpl-btn-deactivate' : 'tmpl-btn-reactivate';
  const toggleClone = document.getElementById(toggleTmplId).content.cloneNode(true);
  const toggleBtn = toggleClone.querySelector('button');
  const newStatus = parseInt(isActive) === 1 ? 0 : 1;
  toggleBtn.addEventListener('click', function () {
    toggleStatus(entity, id, newStatus);
  });
  fragment.appendChild(toggleClone);

  return fragment;
}

function renderEmptyRow(tableBody, message, colSpan = 5) {
  if (!tableBody) return;
  const clone = document.getElementById('tmpl-row-empty').content.cloneNode(true);
  const td = clone.querySelector('.row-empty-cell');
  td.colSpan = colSpan;
  td.textContent = message;
  tableBody.replaceChildren(clone);
}

function handleFormResult(res, modalId, reloadFn) {
  if (res.data && res.data.success) {
    showToast(res.data.message || 'Saved successfully');
    closeModal(modalId);
    if (typeof reloadFn === 'function') reloadFn();
  } else {
    showToast((res.data && res.data.message) || 'Operation failed', true);
  }
}

const entityFileMap = {
  category: 'category.php',
  product: 'product.php',
  supplier: 'supplier.php',
  role: 'role.php',
  user: 'users.php'
};

async function toggleStatus(entity, id, newStatus) {
  const confirmTitle = newStatus === 1 ? 'Reactivate record?' : 'Deactivate record?';
  const confirmText = newStatus === 1
    ? 'This record will become available again.'
    : 'This record will be hidden from active use but kept for historical transactions.';

  const ok = await showConfirm(confirmTitle, confirmText, newStatus === 1 ? 'Reactivate' : 'Deactivate');
  if (!ok) return;

  const endpoint = entityFileMap[entity];
  const url = API + endpoint + (endpoint.includes('?') ? '&' : '?') + 'id=' + id + (newStatus === 1 ? '&restore=1' : '');

  const res = await axios.delete(url);
  switch (res.status) {
    case 200:
      showToast(res.data.message || 'Status updated');
      if (entity === 'category') loadCategories();
      else if (entity === 'product') loadProducts();
      else if (entity === 'supplier') loadSuppliers();
      else if (entity === 'role') loadRoles();
      else if (entity === 'user') loadUsers();
      break;
    default:
      showToast((res.data && res.data.message) || 'Failed to update status', true);
  }
}

function showAuthScreen() {
  authUser = null;
  permissions = null;
  sessionStorage.removeItem('isLoggedIn');
  sessionStorage.removeItem('activeTab');
  const sidebarEl = document.getElementById('sidebarOffcanvas');
  if (sidebarEl && window.bootstrap && bootstrap.Offcanvas) {
    const oc = bootstrap.Offcanvas.getInstance(sidebarEl);
    if (oc) oc.hide();
  }
  document.getElementById('authScreen').hidden = false;
  document.getElementById('appShell').hidden = true;
}

function showApp(user, perms, token) {
  authUser = user;
  permissions = perms;
  if (token) csrfToken = token;

  sessionStorage.setItem('isLoggedIn', '1');

  document.getElementById('authScreen').hidden = true;
  document.getElementById('appShell').hidden = false;

  document.getElementById('sidebarUserName').textContent = user.first_name + ' ' + user.last_name;
  document.getElementById('sidebarUserRole').textContent = user.role_name;

  applyRBAC();

  const savedTab = sessionStorage.getItem('activeTab');
  if (savedTab && (savedTab === 'dashboard' ? can('dashboard', 'view') : can(savedTab, 'view'))) {
    activateTab(savedTab);
  } else if (can('dashboard', 'view')) {
    activateTab('dashboard');
  } else {
    const firstAllowed = document.querySelector('.nav-link[data-tab][data-module]:not([hidden])');
    if (firstAllowed) {
      activateTab(firstAllowed.getAttribute('data-tab'));
    }
  }
}

function applyRBAC() {
  const dashboard = document.querySelector('.nav-link[data-tab="dashboard"]');
  if (dashboard) {
    dashboard.hidden = !can('dashboard', 'view');
  }

  const overviewHdr = document.getElementById('sidebarHeaderOverview');
  if (overviewHdr) {
    overviewHdr.hidden = !can('dashboard', 'view');
  }

  const navItems = document.querySelectorAll('.nav-link[data-module]');
  for (let i = 0; i < navItems.length; i++) {
    const module = navItems[i].getAttribute('data-module');
    navItems[i].hidden = !can(module, 'view');
  }

  const lookupModules = ['category', 'product', 'supplier', 'product_supplier', 'role', 'users'];
  const hasLookup = lookupModules.some(function (m) { return can(m, 'view'); });
  const lookupHdr = document.getElementById('sidebarHeaderLookup');
  if (lookupHdr) {
    lookupHdr.hidden = !hasLookup;
  }

  const txModules = ['purchase_order', 'stock_receiving', 'stock_releasing', 'purchase_return', 'stocks_return', 'adjustment'];
  const hasTx = txModules.some(function (m) { return can(m, 'view'); });
  const txHdr = document.getElementById('sidebarHeaderTransactions');
  if (txHdr) {
    txHdr.hidden = !hasTx;
  }

  const openers = document.querySelectorAll('[data-bs-target][data-module]');
  for (let i = 0; i < openers.length; i++) {
    const m = openers[i].getAttribute('data-module');
    openers[i].hidden = !can(m, 'manage');
  }
}

async function checkSession() {
  const res = await axios.get(API + 'auth.php?action=me');
  switch (res.status) {
    case 200:
      if (res.data && res.data.data && res.data.data.csrf_token) {
        csrfToken = res.data.data.csrf_token;
      }
      if (res.data && res.data.success && res.data.data && res.data.data.user) {
        showApp(res.data.data.user, res.data.data.permissions, csrfToken);
      } else {
        showAuthScreen();
      }
      break;
    default:
      showAuthScreen();
  }
}

async function handleLogin(e) {
  e.preventDefault();
  const alertEl = document.getElementById('loginErrorAlert');
  if (alertEl) alertEl.hidden = true;

  const form = e.target;
  const formData = new FormData(form);
  const username = (formData.get('username') || document.getElementById('login_username').value || '').trim();
  const password = formData.get('password') || document.getElementById('login_password').value || '';

  if (!username || !password) {
    if (alertEl) {
      alertEl.textContent = 'Please enter both username and password.';
      alertEl.hidden = false;
    }
    showToast('Please enter both username and password.', true);
    return;
  }

  formData.set('username', username);
  formData.set('password', password);

  const res = await axios.post(API + 'auth.php?action=login', formData);

  switch (res.status) {
    case 200:
      if (alertEl) alertEl.hidden = true;
      showToast(res.data.message || 'Login successful');
      document.getElementById('loginForm').reset();
      csrfToken = (res.data.data && res.data.data.csrf_token) || csrfToken;
      showApp(res.data.data.user, res.data.data.permissions, csrfToken);
      break;
    case 401: {
      const msg = (res.data && res.data.message) || 'Invalid username or password.';
      if (alertEl) {
        alertEl.textContent = msg;
        alertEl.hidden = false;
      }
      showToast(msg, true);
      break;
    }
    case 403: {
      await checkSession();
      const msg = (res.data && res.data.message) || 'Session synchronized. Please try logging in again.';
      if (alertEl) {
        alertEl.textContent = msg;
        alertEl.hidden = false;
      }
      showToast(msg, true);
      break;
    }
    default: {
      const msg = (res.data && res.data.message) || 'Login failed';
      if (alertEl) {
        alertEl.textContent = msg;
        alertEl.hidden = false;
      }
      showToast(msg, true);
    }
  }
}

async function handleLogout() {
  const res = await axios.post(API + 'auth.php?action=logout');
  if (res.data && res.data.data && res.data.data.csrf_token) {
    csrfToken = res.data.data.csrf_token;
  } else {
    csrfToken = null;
  }
  showAuthScreen();
  const loginForm = document.getElementById('loginForm');
  if (loginForm) loginForm.reset();
  const alertEl = document.getElementById('loginErrorAlert');
  if (alertEl) alertEl.hidden = true;
}

async function resetModalForAdd(modalId) {
  if (modalId === 'categoryModal') {
    document.getElementById('categoryModalTitle').textContent = 'Add Category';
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = '';
  } else if (modalId === 'productModal') {
    document.getElementById('productModalTitle').textContent = 'Add Product';
    document.getElementById('productForm').reset();
    document.getElementById('product_id').value = '';
  } else if (modalId === 'supplierModal') {
    document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
    document.getElementById('supplierForm').reset();
    document.getElementById('supplier_id').value = '';
  } else if (modalId === 'linkModal') {
    document.getElementById('linkModalTitle').textContent = 'Link Products to Supplier';
    var linkSupSel = document.getElementById('link_supplier_id');
    if (linkSupSel) linkSupSel.value = '';
    var linkSearchEl = document.getElementById('linkSearchProducts');
    if (linkSearchEl) linkSearchEl.value = '';
    var linkListEl = document.getElementById('linkProductList');
    if (linkListEl) linkListEl.innerHTML = '<tr><td colspan="3" class="text-muted fst-italic">Select a supplier to view products.</td></tr>';
    var selCountEl = document.getElementById('selectedCount');
    if (selCountEl) selCountEl.textContent = 'Selected Products: 0';
    var hdrCb = document.getElementById('checkAllHeader');
    if (hdrCb) { hdrCb.checked = false; hdrCb.indeterminate = false; }
  } else if (modalId === 'roleModal') {
    document.getElementById('roleModalTitle').textContent = 'Add Role';
    document.getElementById('roleForm').reset();
    document.getElementById('role_id').value = '';
    const nameInput = document.getElementById('role_name');
    if (nameInput) nameInput.readOnly = false;
    const cbs = document.querySelectorAll('#roleForm .perm-check');
    for (let i = 0; i < cbs.length; i++) {
      cbs[i].checked = false;
      cbs[i].disabled = false;
    }
  } else if (modalId === 'userModal') {
    document.getElementById('userModalTitle').textContent = 'Add User';
    document.getElementById('userForm').reset();
    document.getElementById('user_id').value = '';
    document.getElementById('passwordFieldLabel').style.display = 'block';
    document.getElementById('password').setAttribute('required', 'required');
  } else if (modalId === 'poModal') {
    document.getElementById('poForm').reset();
    txItems.po = [];
    renderLineItemsTable('po');
    await ensureMasterDataLoaded();
    fillDropdownOptions('po_supplier_id', getActiveSuppliers(), 'supplier_id', function (s) { return s.supplier_name; });
    setCurrentUserSelect('po_user_id');
    fillDropdownOptions('po_item_product', getActiveProductsList(), 'product_id', function (p) { return p.product_name; });
  } else if (modalId === 'receivingModal') {
    document.getElementById('receivingForm').reset();
    renderEmptyRow(document.getElementById('receiving_items_table'), 'Select a purchase order first.', 3);
    await ensureMasterDataLoaded();
    setCurrentUserSelect('receiving_user_id');
    await loadPendingPurchaseOrders();
  } else if (modalId === 'releasingModal') {
    document.getElementById('releasingForm').reset();
    txItems.releasing = [];
    renderLineItemsTable('releasing');
    await ensureMasterDataLoaded();
    setCurrentUserSelect('releasing_user_id');
    fillDropdownOptions('releasing_item_product', getActiveProductsList(), 'product_id', function (p) { return p.product_name; });
  } else if (modalId === 'preturnModal') {
    document.getElementById('preturnForm').reset();
    txItems.preturn = [];
    renderLineItemsTable('preturn');
    await ensureMasterDataLoaded();
    fillDropdownOptions('preturn_supplier_id', getActiveSuppliers(), 'supplier_id', function (s) { return s.supplier_name; });
    setCurrentUserSelect('preturn_user_id');
    fillDropdownOptions('preturn_item_product', getActiveProductsList(), 'product_id', function (p) { return p.product_name; });
  } else if (modalId === 'sreturnModal') {
    document.getElementById('sreturnForm').reset();
    txItems.sreturn = [];
    renderLineItemsTable('sreturn');
    await ensureMasterDataLoaded();
    setCurrentUserSelect('sreturn_user_id');
    fillDropdownOptions('sreturn_item_product', getActiveProductsList(), 'product_id', function (p) { return p.product_name; });
  } else if (modalId === 'adjustmentModal') {
    document.getElementById('adjustmentForm').reset();
    txItems.adjustment = [];
    renderLineItemsTable('adjustment');
    await ensureMasterDataLoaded();
    setCurrentUserSelect('adjustment_user_id');
    fillDropdownOptions('adjustment_item_product', getActiveProductsList(), 'product_id', function (p) { return p.product_name; });
  }
}

const txItems = {
  po: [],
  releasing: [],
  preturn: [],
  sreturn: [],
  adjustment: []
};

async function ensureMasterDataLoaded() {
  if (cache.products.length === 0) {
    const res = await axios.get(API + 'product.php');
    switch (res.status) {
      case 200:
        cache.products = res.data.data;
        break;
      default:
        showToast('Failed to load reference data', true);
        return;
    }
  }
  if (cache.suppliers.length === 0) {
    const res = await axios.get(API + 'supplier.php');
    switch (res.status) {
      case 200:
        cache.suppliers = res.data.data;
        break;
      default:
        showToast('Failed to load reference data', true);
    }
  }
}

function getActiveSuppliers() {
  return cache.suppliers.filter(function (s) { return parseInt(s.is_active) === 1; });
}

function getActiveProductsList() {
  return cache.products.filter(function (p) { return parseInt(p.is_active) === 1; });
}

function fillDropdownOptions(selectId, list, valueKey, labelFn) {
  const select = document.getElementById(selectId);
  if (!select || !list) return;

  let html = '';
  for (let i = 0; i < list.length; i++) {
    html += '<option value="' + list[i][valueKey] + '">' + escapeHtml(labelFn(list[i])) + '</option>';
  }
  select.innerHTML = html;
}

function setCurrentUserSelect(selectId) {
  const select = document.getElementById(selectId);
  if (!select || !authUser) return;
  select.innerHTML = '<option value="' + authUser.user_id + '">' +
    escapeHtml(authUser.first_name + ' ' + authUser.last_name) + '</option>';
  select.value = String(authUser.user_id);
}

function productName(productId) {
  const p = findById(cache.products, 'product_id', productId);
  return p ? p.product_name : 'Product';
}

function addLineItem(prefix) {
  const productSelect = document.getElementById(prefix + '_item_product');
  const qtyInput = document.getElementById(prefix + '_item_qty');

  if (!productSelect || !productSelect.value) {
    showToast('Select a product first', true);
    return;
  }

  const qty = parseInt(qtyInput.value);
  if (!qty || qty <= 0) {
    showToast('Enter a quantity greater than zero', true);
    return;
  }

  const productId = parseInt(productSelect.value);
  const item = {
    product_id: productId,
    product_name: productName(productId),
    quantity: qty
  };

  if (prefix === 'po') {
    const costInput = document.getElementById('po_item_cost');
    let cost = parseFloat(costInput.value);
    if (isNaN(cost) || cost < 0) cost = 0;
    item.unit_cost = cost;
    costInput.value = '';
  }

  if (prefix === 'adjustment') {
    item.adjustment_type = document.getElementById('adjustment_item_type').value;
  }

  txItems[prefix].push(item);
  renderLineItemsTable(prefix);
  qtyInput.value = 1;
}

function removeLineItem(prefix, index) {
  txItems[prefix].splice(index, 1);
  renderLineItemsTable(prefix);
}

function renderLineItemsTable(prefix) {
  const body = document.getElementById(prefix + '_items_table');
  if (!body) return;

  const items = txItems[prefix];
  if (!items || items.length === 0) {
    const colspan = (prefix === 'po') ? 5 : (prefix === 'adjustment' ? 4 : 3);
    const clone = document.getElementById('tmpl-line-item-empty').content.cloneNode(true);
    clone.querySelector('.line-empty-cell').colSpan = colspan;
    body.replaceChildren(clone);
    return;
  }

  body.innerHTML = '';
  for (let i = 0; i < items.length; i++) {
    const it = items[i];
    let clone;
    if (prefix === 'po') {
      clone = document.getElementById('tmpl-line-item-po').content.cloneNode(true);
      clone.querySelector('.line-product').textContent = it.product_name;
      clone.querySelector('.line-qty').textContent = it.quantity;
      clone.querySelector('.line-cost').textContent = peso(it.unit_cost);
      clone.querySelector('.line-subtotal').textContent = peso(it.quantity * it.unit_cost);
    } else if (prefix === 'adjustment') {
      clone = document.getElementById('tmpl-line-item-adjustment').content.cloneNode(true);
      clone.querySelector('.line-product').textContent = it.product_name;
      clone.querySelector('.line-qty').textContent = it.quantity;
      clone.querySelector('.line-type').textContent = it.adjustment_type;
    } else {
      clone = document.getElementById('tmpl-line-item-simple').content.cloneNode(true);
      clone.querySelector('.line-product').textContent = it.product_name;
      clone.querySelector('.line-qty').textContent = it.quantity;
    }

    const btnClone = document.getElementById('tmpl-btn-remove-line').content.cloneNode(true);
    const btn = btnClone.querySelector('button');
    btn.addEventListener('click', function () {
      removeLineItem(prefix, i);
    });
    clone.querySelector('.line-action').appendChild(btnClone);

    body.appendChild(clone);
  }
}

document.addEventListener('DOMContentLoaded', function () {

  const navItems = document.querySelectorAll('.nav-link[data-tab]');
  for (let i = 0; i < navItems.length; i++) {
    navItems[i].addEventListener('click', function () {
      activateTab(this.getAttribute('data-tab'));
    });
  }

  document.addEventListener('click', function (e) {
    const opener = e.target.closest('[data-bs-toggle="modal"]');
    if (opener) {
      const target = opener.getAttribute('data-bs-target');
      if (target && target.startsWith('#')) {
        const modalId = target.slice(1);
        if (opener.hasAttribute('data-module')) {
          resetModalForAdd(modalId);
        }
      }
    }
  });

  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', handleLogout);
  }

  const forms = [
    { id: 'loginForm', handler: handleLogin },
    { id: 'categoryForm', handler: saveCategory },
    { id: 'productForm', handler: saveProduct },
    { id: 'supplierForm', handler: saveSupplier },

    { id: 'roleForm', handler: saveRole },
    { id: 'userForm', handler: saveUser },
    { id: 'resetPwdForm', handler: saveResetPassword },
    { id: 'poForm', handler: savePurchaseOrder },
    { id: 'receivingForm', handler: saveStockReceiving },
    { id: 'releasingForm', handler: saveStockReleasing },
    { id: 'preturnForm', handler: savePurchaseReturn },
    { id: 'sreturnForm', handler: saveStocksReturn },
    { id: 'adjustmentForm', handler: saveAdjustment }
  ];

  for (let f = 0; f < forms.length; f++) {
    const el = document.getElementById(forms[f].id);
    if (el) {
      el.addEventListener('submit', forms[f].handler);
    }
  }

  const receivingPoSelect = document.getElementById('receiving_po_id');
  if (receivingPoSelect) {
    receivingPoSelect.addEventListener('change', loadPoItemsForReceiving);
  }

  checkSession();
});

window.addEventListener('pageshow', function (e) {
  if (e.persisted) {
    checkSession();
  }
});

window.addEventListener('popstate', function () {
  checkSession();
});

