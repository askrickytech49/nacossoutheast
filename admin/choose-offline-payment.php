<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
admin_require_login();

admin_page_start('Choose Offline Registration Type');
?>

<style>
.select-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px;
  margin: 28px 0 36px;
}
.select-card {
  background: #ffffff;
  border: 1.5px solid #dce5dc;
  border-radius: 14px;
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-decoration: none;
  color: inherit;
  transition: all 200ms ease;
  box-shadow: 0 4px 14px rgba(18, 45, 22, 0.04);
  position: relative;
}
.select-card:hover {
  border-color: #0b6623;
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(11, 102, 35, 0.12);
}
.select-card-icon {
  width: 54px;
  height: 54px;
  border-radius: 12px;
  background: #eef6ed;
  color: #0b6623;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 18px;
}
.select-card-icon svg {
  width: 28px;
  height: 28px;
}
.select-card-title {
  font-size: 19px;
  font-weight: 750;
  color: #073b12;
  margin: 0 0 8px;
  letter-spacing: -0.02em;
}
.select-card-desc {
  font-size: 13.5px;
  line-height: 1.6;
  color: #556858;
  margin: 0 0 18px;
  flex-grow: 1;
}
.select-card-badges {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 22px;
}
.select-badge {
  font-size: 11px;
  font-weight: 650;
  color: #176128;
  background: #e8f4e8;
  padding: 4px 10px;
  border-radius: 999px;
}
.select-badge-muted {
  font-size: 11px;
  font-weight: 600;
  color: #4b5d4f;
  background: #f0f3f0;
  padding: 4px 10px;
  border-radius: 999px;
}
.select-card-btn {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 18px;
  border-radius: 8px;
  font-size: 13.5px;
  font-weight: 700;
  background: #0b6623;
  color: #ffffff;
  border: 0;
  cursor: pointer;
  transition: background 150ms ease;
  text-decoration: none;
}
.select-card-btn:hover {
  background: #073b12;
  color: #ffffff;
}
.select-card-btn-alt {
  background: #073b12;
}
.select-card-btn-alt:hover {
  background: #05290c;
}
@media(max-width: 720px) {
  .select-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<div class="subhead">
  <div>
    <a class="button button-light" href="offline-payers.php" style="width:auto;min-height:36px;padding:8px 12px">Back to Offline Payers</a>
    <h1 style="margin-top:12px">Add offline payer</h1>
    <p class="muted">Select whether you are recording a single individual registration or a bulk convention delegation.</p>
  </div>
</div>

<div class="select-grid">
  <!-- Single Registration Box -->
  <div class="select-card">
    <div class="select-card-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </div>
    <h2 class="select-card-title">Single Registration</h2>
    <p class="select-card-desc">
      Record an individual payment confirmed offline for a single person. Supports all event forms including Convention, Sponsorship, or Mr & Miss NACOS pageant.
    </p>
    <div class="select-card-badges">
      <span class="select-badge">Individual Attendee</span>
      <span class="select-badge-muted">Convention</span>
      <span class="select-badge-muted">Sponsorship</span>
      <span class="select-badge-muted">Mr & Miss NACOS</span>
    </div>
    <a class="select-card-btn" href="add-offline-payment.php">
      Continue with Single Registration
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
  </div>

  <!-- Bulk Student Registration Box -->
  <div class="select-card">
    <div class="select-card-icon" style="background:#e8f1e8;color:#073b12">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
    </div>
    <h2 class="select-card-title">Bulk Student Registration</h2>
    <p class="select-card-desc">
      Record a group registration for a university or polytechnic chapter delegation. Multiple students are registered together under one payment reference with individual certificates.
    </p>
    <div class="select-card-badges">
      <span class="select-badge" style="background:#e5f2e6;color:#073b12">Multiple Students</span>
      <span class="select-badge-muted">Convention Only</span>
      <span class="select-badge-muted">Institutional Delegation</span>
    </div>
    <a class="select-card-btn select-card-btn-alt" href="add-bulk-offline-payment.php">
      Continue with Bulk Registration
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
  </div>
</div>

<?php
admin_page_end();
