
async function loadDashboard() {
  const grid = document.getElementById('statGrid');
  grid.replaceChildren(document.getElementById('tmpl-stat-card-loading').content.cloneNode(true));

  const catReq = axios.get(API + 'category.php');
  const prodReq = axios.get(API + 'product.php');
  const supReq = can('supplier', 'view') ? axios.get(API + 'supplier.php') : Promise.resolve({ status: 200, data: { data: null } });
  const userReq = can('users', 'view') ? axios.get(API + 'users.php') : Promise.resolve({ status: 200, data: { data: null } });

  const [catRes, prodRes, supRes, userRes] = await Promise.all([catReq, prodReq, supReq, userReq]);
  const failed = [catRes, prodRes, supRes, userRes].find(function (r) { return r.status !== 200; });

  switch (failed ? failed.status : 200) {
    case 200:
      renderDashboard(catRes.data.data, prodRes.data.data, supRes.data.data, userRes.data.data);
      break;
    default:
      grid.replaceChildren(document.getElementById('tmpl-stat-card-error').content.cloneNode(true));
  }
}

function countActive(list) {
  if (!list) return 0;
  let total = 0;
  for (let i = 0; i < list.length; i++) {
    if (parseInt(list[i].is_active) === 1) total++;
  }
  return total;
}

function countLowStockCandidates(products) {
  if (!products) return 0;
  let total = 0;
  for (let i = 0; i < products.length; i++) {
    const stock = parseInt(products[i].stock_qty) || 0;
    const reorder = parseInt(products[i].reorder_level) || 0;
    if (parseInt(products[i].is_active) === 1 && stock <= reorder) total++;
  }
  return total;
}

function renderDashboard(categories, products, suppliers, users) {
  const grid = document.getElementById('statGrid');
  const cards = [
    { icon: 'bi-diagram-3', value: countActive(categories), label: 'Active Categories' },
    { icon: 'bi-box-seam', value: countActive(products), label: 'Active Products' }
  ];

  if (suppliers !== null) {
    cards.push({ icon: 'bi-truck', value: countActive(suppliers), label: 'Active Suppliers' });
  }
  if (users !== null) {
    cards.push({ icon: 'bi-people', value: countActive(users), label: 'Active Users' });
  }

  cards.push({ icon: 'bi-exclamation-triangle', value: countLowStockCandidates(products), label: 'Products At/Below Reorder Level*' });
  cards.push({ icon: 'bi-archive', value: products ? products.length : 0, label: 'Total Products On File' });

  grid.innerHTML = '';
  const tmpl = document.getElementById('tmpl-stat-card');
  for (let i = 0; i < cards.length; i++) {
    const c = cards[i];
    const clone = tmpl.content.cloneNode(true);
    clone.querySelector('.stat-icon').classList.add(c.icon);
    clone.querySelector('.stat-value').textContent = c.value;
    clone.querySelector('.stat-label').textContent = c.label;
    grid.appendChild(clone);
  }
}

