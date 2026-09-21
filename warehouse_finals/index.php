<?php
require_once __DIR__ . '/config/db.php';
$sessionUser = currentUser();
$isLoggedIn = !empty($sessionUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Warehouse Inventory — Master Files</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>[hidden] { display: none !important; }</style>
</head>
<body class="bg-light">

<?php include __DIR__ . '/views/auth.html'; ?>

<div id="appShell" <?php echo $isLoggedIn ? '' : 'hidden'; ?>>
  <div class="d-flex flex-column vh-100 overflow-hidden">
    <header class="d-md-none navbar navbar-dark bg-dark px-3 py-2 border-bottom border-secondary flex-shrink-0">
      <div class="container-fluid px-0 d-flex align-items-center">
        <button class="navbar-toggler border-0 me-2 p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas" aria-controls="sidebarOffcanvas" aria-label="Toggle navigation" aria-expanded="false">
          <i class="bi bi-list fs-4"></i>
        </button>
        <i class="bi bi-box-seam-fill text-warning me-2"></i>
        <span class="navbar-brand mb-0 h6 text-white text-truncate" id="mobilePageTitle">Dashboard</span>
      </div>
    </header>

    <div class="d-flex flex-grow-1 overflow-hidden">
      <?php include __DIR__ . '/views/sidebar.html'; ?>

      <main class="flex-grow-1 p-3 p-md-4 overflow-auto">

        <?php include __DIR__ . '/views/dashboard.html'; ?>

        <?php include __DIR__ . '/views/category.html'; ?>
        <?php include __DIR__ . '/views/product.html'; ?>
        <?php include __DIR__ . '/views/supplier.html'; ?>
        <?php include __DIR__ . '/views/product_supplier.html'; ?>
        <?php include __DIR__ . '/views/role.html'; ?>
        <?php include __DIR__ . '/views/users.html'; ?>

        <?php include __DIR__ . '/views/purchase_order.html'; ?>
        <?php include __DIR__ . '/views/stock_receiving.html'; ?>
        <?php include __DIR__ . '/views/stock_releasing.html'; ?>
        <?php include __DIR__ . '/views/purchase_return.html'; ?>
        <?php include __DIR__ . '/views/stocks_return.html'; ?>
        <?php include __DIR__ . '/views/adjustment.html'; ?>
      </main>
    </div>
  </div>
</div>

<?php include __DIR__ . '/views/common_templates.html'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.6.7/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php $ver = time(); ?>
<script src="js/app.js?v=<?= $ver ?>"></script>
<script src="js/dashboard.js?v=<?= $ver ?>"></script>
<script src="js/category.js?v=<?= $ver ?>"></script>
<script src="js/product.js?v=<?= $ver ?>"></script>
<script src="js/supplier.js?v=<?= $ver ?>"></script>
<script src="js/product_supplier.js?v=<?= $ver ?>"></script>
<script src="js/role.js?v=<?= $ver ?>"></script>
<script src="js/users.js?v=<?= $ver ?>"></script>
<script src="js/purchase_order.js?v=<?= $ver ?>"></script>
<script src="js/stock_receiving.js?v=<?= $ver ?>"></script>
<script src="js/stock_releasing.js?v=<?= $ver ?>"></script>
<script src="js/purchase_return.js?v=<?= $ver ?>"></script>
<script src="js/stocks_return.js?v=<?= $ver ?>"></script>
<script src="js/adjustment.js?v=<?= $ver ?>"></script>
</body>
</html>

