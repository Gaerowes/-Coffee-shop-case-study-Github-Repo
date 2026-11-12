<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
?>

<nav class="navbar navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="index.php">Coffee Shop</a>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <?php if (!is_logged_in() || is_customer()): ?>
        <a class="btn btn-outline-light btn-sm" href="cart.php">Cart</a>
      <?php endif; ?>

      <?php if (!empty($_SESSION['user_id'])): ?>
        <?php if (is_customer()): ?>
          <a class="btn btn-outline-light btn-sm" href="my_orders.php">My Orders</a>
        <?php endif; ?>

        <?php if (is_cashier()): ?>
          <a class="btn btn-outline-light btn-sm" href="cashier_orders.php">Order Queue</a>
        <?php endif; ?>

        <?php if (is_admin()): ?>
          <a class="btn btn-outline-light btn-sm" href="owner_dashboard.php">Admin Dashboard</a>
          <a class="btn btn-outline-light btn-sm" href="users.php">Users</a>
        <?php endif; ?>

        <span class="text-white small">
          <?= e($_SESSION['username'] ?? '') ?>
          <span class="badge bg-secondary"><?= e($_SESSION['role'] ?? '') ?></span>
        </span>
        <form method="post" action="logout.php" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-danger btn-sm">Logout</button></form>
      <?php else: ?>
        <a class="btn btn-outline-light btn-sm" href="login.php">Login</a>
        <a class="btn btn-success btn-sm" href="register.php">Register</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
