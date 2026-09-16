
async function loadCategories() {
  const res = await axios.get(API + 'category.php');
  switch (res.status) {
    case 200:
      cache.categories = res.data.data;
      renderCategories(cache.categories);
      break;
    default:
      showToast('Failed to load categories', true);
  }
}

function renderCategories(rows) {
  const body = document.getElementById('categoryTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No categories yet. Add one to get started.', 4);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-category');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-name').textContent = r.category_name;
    clone.querySelector('.row-desc').textContent = r.description || '—';
    clone.querySelector('.row-status').appendChild(createStatusBadge(r.is_active));
    clone.querySelector('.row-actions').appendChild(createRowActions('category', r.category_id, r.is_active));
    body.appendChild(clone);
  }
}

function editCategory(id) {
  const item = findById(cache.categories, 'category_id', id);
  if (!item) return;
  document.getElementById('category_id').value = item.category_id;
  document.getElementById('category_name').value = item.category_name;
  document.getElementById('category_description').value = item.description || '';
  document.getElementById('categoryModalTitle').textContent = 'Edit Category';
  openModal('categoryModal');
}

async function saveCategory(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const id = document.getElementById('category_id').value;
  const name = (document.getElementById('category_name').value || '').trim();
  const desc = (document.getElementById('category_description').value || '').trim();

  let res;
  if (id) {
    res = await axios.put(API + 'category.php', {
      category_id: parseInt(id),
      category_name: name,
      description: desc
    });
  } else {
    formData.set('category_name', name);
    formData.set('description', desc);
    res = await axios.post(API + 'category.php', formData);
  }

  switch (res.status) {
    case 200:
      handleFormResult(res, 'categoryModal', loadCategories);
      break;
    default:
      showToast((res.data && res.data.message) || 'Request failed', true);
  }
}

