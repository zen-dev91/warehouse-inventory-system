const RBAC_MODULES = [
  'dashboard',
  'category',
  'product',
  'supplier',
  'product_supplier',
  'role',
  'users',
  'purchase_order',
  'stock_receiving',
  'stock_releasing',
  'purchase_return',
  'stocks_return',
  'adjustment'
];

async function loadRoles() {
  const res = await axios.get(API + 'role.php');
  switch (res.status) {
    case 200:
      cache.roles = res.data.data;
      renderRoles(cache.roles);
      break;
    default:
      showToast('Failed to load roles', true);
  }
}

function renderRoles(rows) {
  const body = document.getElementById('roleTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No roles yet. Add one to get started.', 3);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-role');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-name').textContent = r.role_name;
    clone.querySelector('.row-status').appendChild(createStatusBadge(r.is_active));
    clone.querySelector('.row-actions').appendChild(createRowActions('role', r.role_id, r.is_active));
    body.appendChild(clone);
  }
}

function editRole(id) {
  const item = findById(cache.roles, 'role_id', id);
  if (!item) return;

  document.getElementById('role_id').value = item.role_id;
  const nameInput = document.getElementById('role_name');
  nameInput.value = item.role_name;
  document.getElementById('roleModalTitle').textContent = 'Edit Role';

  const isAdmin = (item.role_name === 'Administrator' || parseInt(item.role_id) === 1);
  nameInput.readOnly = isAdmin;

  const perms = item.permissions || {};
  for (let i = 0; i < RBAC_MODULES.length; i++) {
    const mod = RBAC_MODULES[i];
    const viewCb = document.getElementById('perm_view_' + mod);
    const manageCb = document.getElementById('perm_manage_' + mod);
    const modPerm = perms[mod] || {};

    if (viewCb) {
      viewCb.checked = isAdmin || Boolean(modPerm.view || modPerm.manage);
      viewCb.disabled = isAdmin;
    }
    if (manageCb) {
      manageCb.checked = isAdmin || Boolean(modPerm.manage);
      manageCb.disabled = isAdmin;
    }
  }

  openModal('roleModal');
}

async function saveRole(e) {
  e.preventDefault();
  const id = document.getElementById('role_id').value;
  const permissions = {};

  for (let i = 0; i < RBAC_MODULES.length; i++) {
    const mod = RBAC_MODULES[i];
    const viewCb = document.getElementById('perm_view_' + mod);
    const manageCb = document.getElementById('perm_manage_' + mod);
    const canManage = manageCb ? manageCb.checked : false;
    const canView = viewCb ? (viewCb.checked || canManage) : false;
    permissions[mod] = {
      view: canView,
      manage: canManage
    };
  }

  const payload = {
    role_name: document.getElementById('role_name').value.trim(),
    permissions: permissions
  };

  let res;
  if (id) {
    payload.role_id = parseInt(id);
    res = await axios.put(API + 'role.php', payload);
  } else {
    res = await axios.post(API + 'role.php', payload);
  }

  switch (res.status) {
    case 200:
      handleFormResult(res, 'roleModal', async function () {
        await loadRoles();
        if (authUser && (String(authUser.role_id) === String(id) || authUser.role_name === payload.role_name)) {
          await checkSession();
        }
      });
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

document.addEventListener('change', function (e) {
  if (!e.target || !e.target.classList.contains('perm-check')) return;

  const mod = e.target.getAttribute('data-module');
  if (!mod) return;

  if (e.target.classList.contains('perm-manage') && e.target.checked) {
    const viewCb = document.getElementById('perm_view_' + mod);
    if (viewCb) viewCb.checked = true;
  } else if (e.target.classList.contains('perm-view') && !e.target.checked) {
    const manageCb = document.getElementById('perm_manage_' + mod);
    if (manageCb) manageCb.checked = false;
  }
});

