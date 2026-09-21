
async function loadProducts() {
  const [prodRes, catRes] = await Promise.all([
    axios.get(API + 'product.php'),
    axios.get(API + 'category.php')
  ]);
  const failed = [prodRes, catRes].find(function (r) { return r.status !== 200; });

  switch (failed ? failed.status : 200) {
    case 200:
      cache.products = prodRes.data.data;
      cache.categories = catRes.data.data;
      populateCategoryDropdown();
      renderProducts(cache.products);
      break;
    default:
      showToast('Failed to load products', true);
  }
}

function populateCategoryDropdown() {
  const select = document.getElementById('product_category_id');
  if (!select) return;
  const activeCategories = cache.categories.filter(function (c) { return parseInt(c.is_active) === 1; });
  let html = '<option value="">Select Category</option>';
  for (let i = 0; i < activeCategories.length; i++) {
    html += '<option value="' + activeCategories[i].category_id + '">' + escapeHtml(activeCategories[i].category_name) + '</option>';
  }
  select.innerHTML = html;
}

function renderProducts(rows) {
  const body = document.getElementById('productTable');
  if (!rows || rows.length === 0) {
    renderEmptyRow(body, 'No products yet. Add one to get started.', 8);
    return;
  }

  body.innerHTML = '';
  const tmpl = document.getElementById('tmpl-row-product');
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.row-name').textContent = r.product_name;
    clone.querySelector('.row-cat').textContent = r.category_name;
    clone.querySelector('.row-unit').textContent = r.unit_measure;
    clone.querySelector('.row-price').textContent = peso(r.unit_price);
    const stock = parseInt(r.stock_qty) || 0;
    const reorder = parseInt(r.reorder_level) || 0;
    const stockCell = clone.querySelector('.row-stock');
    if (stock <= reorder) {
      stockCell.innerHTML = '<span class="badge bg-danger text-white">' + stock + '</span>';
    } else {
      stockCell.textContent = stock;
    }
    clone.querySelector('.row-reorder').textContent = r.reorder_level;
    clone.querySelector('.row-status').appendChild(createStatusBadge(r.is_active));
    clone.querySelector('.row-actions').appendChild(createRowActions('product', r.product_id, r.is_active));
    body.appendChild(clone);
  }
}

function editProduct(id) {
  const item = findById(cache.products, 'product_id', id);
  if (!item) return;
  document.getElementById('product_id').value = item.product_id;
  document.getElementById('product_category_id').value = item.category_id;
  document.getElementById('product_name').value = item.product_name;
  document.getElementById('unit_measure').value = item.unit_measure;
  document.getElementById('unit_price').value = item.unit_price;
  document.getElementById('reorder_level').value = item.reorder_level;
  document.getElementById('productModalTitle').textContent = 'Edit Product';
  openModal('productModal');
}

async function saveProduct(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const id = document.getElementById('product_id').value;
  const categoryId = document.getElementById('product_category_id').value;
  const name = (document.getElementById('product_name').value || '').trim();
  const unit = (document.getElementById('unit_measure').value || '').trim();
  const price = document.getElementById('unit_price').value;
  const reorder = document.getElementById('reorder_level').value;

  try {
    let res;
    if (id) {
      res = await axios.put(API + 'product.php', {
        product_id: parseInt(id),
        category_id: parseInt(categoryId),
        product_name: name,
        unit_measure: unit,
        unit_price: parseFloat(price),
        reorder_level: parseInt(reorder)
      });
    } else {
      formData.set('category_id', categoryId);
      formData.set('product_name', name);
      formData.set('unit_measure', unit);
      formData.set('unit_price', price);
      formData.set('reorder_level', reorder);
      res = await axios.post(API + 'product.php', formData);
    }

    switch (res.status) {
      case 200:
        handleFormResult(res, 'productModal', loadProducts);
        break;
      case 403:
        showToast('Unauthorized: You do not have permission to manage products.', true);
        break;
      case 409:
        showToast((res.data && res.data.message) || 'A product with this name already exists.', true);
        break;
      case 422:
      case 400:
        showToast((res.data && res.data.message) || 'Validation Error: Please check your input fields.', true);
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
      const msg = (error.response.data && error.response.data.message) ? error.response.data.message : 'Request failed.';
      showToast(msg, true);
    } else {
      showToast((error && error.message) || 'Request failed.', true);
    }
  }
}

