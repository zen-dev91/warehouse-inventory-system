
async function loadUsers() {
  const [userRes, roleRes] = await Promise.all([
    axios.get(API + 'users.php'),
    axios.get(API + 'role.php')
  ]);
  const failed = [userRes, roleRes].find(function (r) { return r.status !== 200; });

  switch (failed ? failed.status : 200) {
    case 200:
      userCache = userRes.data.data;
      cache.roles = roleRes.data.data;
      populateUserRoleDropdown();
      renderUsers(userCache);
      break;
    default:
      showToast('Failed to load users', true);
  }
}

function populateUserRoleDropdown() {
  const select = document.getElementById('user_role_id');
  if (!select) return;
  const activeRoles = cache.roles.filter(function (r) { return parseInt(r.is_active) === 1; });
  let html = '<option value="">Select Role</option>';
  for (let i = 0; i < activeRoles.length; i++) {
    html += '<option value="' + activeRoles[i].role_id + '">' + escapeHtml(activeRoles[i].role_name) + '</option>';
  }
  select.innerHTML = html;
}

function renderUsers(rows) {
  const body = document.getElementById('userTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No users yet. Add one to get started.', 6);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-user');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-username').textContent = r.username;
    clone.querySelector('.row-name').textContent = r.first_name + ' ' + r.last_name;
    clone.querySelector('.row-role').textContent = r.role_name;
    clone.querySelector('.row-email').textContent = r.contact_number || '—';
    clone.querySelector('.row-status').appendChild(createStatusBadge(r.is_active));

    const actionsCell = clone.querySelector('.row-actions');
    if (can('users', 'manage')) {
      const resetClone = document.getElementById('tmpl-btn-reset-password').content.cloneNode(true);
      const resetBtn = resetClone.querySelector('button');
      resetBtn.addEventListener('click', function () {
        openResetPassword(r.user_id);
      });
      actionsCell.appendChild(resetClone);

      const rowActionsFrag = createRowActions('user', r.user_id, r.is_active);
      actionsCell.appendChild(rowActionsFrag);
    } else {
      actionsCell.appendChild(document.getElementById('tmpl-txt-view-only').content.cloneNode(true));
    }
    body.appendChild(clone);
  }
}

function editUser(id) {
  const row = findById(userCache, 'user_id', id);
  if (!row) return;
  document.getElementById('userModalTitle').textContent = 'Edit User';
  document.getElementById('user_id').value = row.user_id;
  document.getElementById('first_name').value = row.first_name;
  document.getElementById('last_name').value = row.last_name;
  document.getElementById('username').value = row.username;
  document.getElementById('user_role_id').value = row.role_id;
  document.getElementById('contact_number').value = row.contact_number || '';
  document.getElementById('password').value = '';
  document.getElementById('password').removeAttribute('required');
  document.getElementById('passwordFieldLabel').style.display = 'none';
  openModal('userModal');
}

function openResetPassword(id) {
  document.getElementById('reset_user_id').value = id;
  document.getElementById('new_password').value = '';
  openModal('resetPwdModal');
}

async function saveUser(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const id = document.getElementById('user_id').value;
  const firstName = (document.getElementById('first_name').value || '').trim();
  const lastName = (document.getElementById('last_name').value || '').trim();
  const username = (document.getElementById('username').value || '').trim();
  const roleId = document.getElementById('user_role_id').value;
  const contact = (document.getElementById('contact_number').value || '').trim();

  let res;
  if (id) {
    res = await axios.put(API + 'users.php', {
      user_id: parseInt(id),
      action: 'update_details',
      first_name: firstName,
      last_name: lastName,
      username: username,
      role_id: parseInt(roleId),
      contact_number: contact
    });
  } else {
    formData.set('first_name', firstName);
    formData.set('last_name', lastName);
    formData.set('username', username);
    formData.set('role_id', roleId);
    formData.set('contact_number', contact);
    formData.set('password', document.getElementById('password').value);
    res = await axios.post(API + 'users.php', formData);
  }

  switch (res.status) {
    case 200:
      handleFormResult(res, 'userModal', loadUsers);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

async function saveResetPassword(e) {
  e.preventDefault();
  const payload = {
    user_id: document.getElementById('reset_user_id').value,
    action: 'reset_password',
    password: document.getElementById('new_password').value
  };

  const res = await axios.put(API + 'users.php', payload);
  switch (res.status) {
    case 200:
      handleFormResult(res, 'resetPwdModal', loadUsers);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

