<?php
/** @var array $user */
/** @var array $branches */
/** @var array $nvrs */
/** @var array $managed_users  each: id,username,full_name,is_active,branches[],nvrs[] */
$title      = 'Boshqaruv — HikCentral Monitor';
$page_title = '<i class="bi bi-gear me-2"></i>Boshqaruv paneli';

ob_start(); ?>
<style>
  .manage-card { background:#fff; border-radius:10px; padding:1.5rem; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:1rem; }
</style>
<?php $head_html = ob_get_clean();

ob_start(); ?>
<ul class="nav nav-tabs mb-3" id="manageTabs">
  <li class="nav-item">
    <a class="nav-link active" data-bs-toggle="tab" href="#tab-nvrs">
      <i class="bi bi-hdd-network me-1"></i>NVR va Kameralar
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab-users">
      <i class="bi bi-people me-1"></i>Sub-adminlar
    </a>
  </li>
</ul>

<div class="tab-content">

<!-- ── NVR va KAMERALAR ── -->
<div class="tab-pane fade show active" id="tab-nvrs">
  <?php if ($nvrs): foreach ($nvrs as $n): ?>
  <div class="manage-card">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="mb-0">
        <i class="bi bi-hdd-network me-2 text-primary"></i>
        <input type="text" class="form-control form-control-sm d-inline-block" id="nvr-name-<?= (int) $n['id'] ?>"
               value="<?= e($n['name'] !== '' ? $n['name'] : $n['hik_code']) ?>" style="max-width:300px;">
      </h6>
      <div>
        <code><?= e($n['ip']) ?></code>
        <button class="btn btn-sm btn-outline-primary ms-2" onclick="saveNvrName(<?= (int) $n['id'] ?>)">
          <i class="bi bi-check-lg"></i>
        </button>
      </div>
    </div>
    <div class="ms-2">
      <button class="btn btn-sm btn-outline-secondary" onclick="toggleCameras(<?= (int) $n['id'] ?>)">
        <i class="bi bi-chevron-down me-1"></i>Kameralar
      </button>
      <div class="mt-2" id="nvr-cameras-<?= (int) $n['id'] ?>" style="display:none;">
        <div class="text-muted" id="nvr-cam-loading-<?= (int) $n['id'] ?>">Yuklanmoqda...</div>
      </div>
    </div>
  </div>
  <?php endforeach; else: ?>
  <div class="manage-card text-center text-muted">NVR topilmadi</div>
  <?php endif; ?>
</div>

<!-- ── SUB-ADMINLAR ── -->
<div class="tab-pane fade" id="tab-users">
  <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalSubAdmin">
    <i class="bi bi-person-plus me-1"></i>Yangi sub-admin
  </button>
  <div class="manage-card overflow-auto">
    <table class="table mb-0">
      <thead class="table-light">
        <tr><th>Login</th><th>Ism</th><th>Filial</th><th>NVRlar</th><th>Holat</th><th></th></tr>
      </thead>
      <tbody id="subadmin-tbody">
        <?php if ($managed_users): foreach ($managed_users as $u): ?>
        <tr id="sa-<?= (int) $u['id'] ?>">
          <td class="fw-semibold"><?= e($u['username']) ?></td>
          <td><?= e($u['full_name']) ?></td>
          <td>
            <?php foreach ($u['branches'] as $ub): ?>
            <span class="badge bg-light text-dark border"><?= e($ub['name']) ?></span>
            <?php endforeach; ?>
          </td>
          <td>
            <?php if ($u['nvrs']): foreach ($u['nvrs'] as $un): ?>
            <span class="badge bg-info text-dark"><?= e($un['name'] !== '' ? $un['name'] : $un['hik_code']) ?></span>
            <?php endforeach; else: ?>
            <small class="text-muted">Barcha</small>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($u['is_active']): ?>
            <span class="badge bg-success">Faol</span>
            <?php else: ?>
            <span class="badge bg-secondary">Bloklangan</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="editSubAdmin(<?= (int) $u['id'] ?>, '<?= e(addslashes($u['username'])) ?>', '<?= e(addslashes($u['full_name'])) ?>', <?= (int) $u['is_active'] ?>, [<?= implode(',', array_map(fn($x) => (int) $x['id'], $u['nvrs'])) ?>])">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ((int) $u['id'] !== (int) $user['id']): ?>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteSubAdmin(<?= (int) $u['id'] ?>, '<?= e(addslashes($u['username'])) ?>')">
              <i class="bi bi-trash"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="6" class="text-center text-muted">Sub-admin yo'q</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- ── SUB-ADMIN MODAL ── -->
<div class="modal fade" id="modalSubAdmin" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sa-modal-title">Yangi sub-admin</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="sa-id" value="">
        <div class="mb-2">
          <label class="form-label">Login *</label>
          <input type="text" id="sa-username" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">Parol <small class="text-muted">(tahrirlashda bo'sh qoldirsa o'zgarmaydi)</small></label>
          <input type="password" id="sa-password" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">Ism familiya</label>
          <input type="text" id="sa-fullname" class="form-control">
        </div>
        <div class="mb-2">
          <label class="form-label">NVRlar <small class="text-muted">(bo'sh = barchasi)</small></label>
          <div class="dropdown">
            <button class="form-select form-select-sm text-start d-flex justify-content-between align-items-center"
                    type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" id="sa-nvrs-btn">
              <span id="sa-nvrs-label" class="text-muted">Barcha NVRlar</span>
              <i class="bi bi-chevron-down text-muted"></i>
            </button>
            <div class="dropdown-menu p-2" style="min-width:280px; max-height:240px; overflow:auto;">
              <?php foreach ($nvrs as $n): ?>
              <div class="form-check">
                <input class="form-check-input sa-nvr-cb" type="checkbox" value="<?= (int) $n['id'] ?>" id="sa-n-<?= (int) $n['id'] ?>" onchange="updateNvrLabel()">
                <label class="form-check-label" for="sa-n-<?= (int) $n['id'] ?>"><?= e($n['name'] !== '' ? $n['name'] : $n['hik_code']) ?> <code class="text-muted small"><?= e($n['ip']) ?></code></label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="mb-2">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="sa-active" checked>
            <label class="form-check-label">Faol</label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Bekor</button>
        <button class="btn btn-primary" onclick="saveSubAdmin()">Saqlash</button>
      </div>
    </div>
  </div>
</div>
<?php $content_html = ob_get_clean();

ob_start(); ?>
<script>
const saModal = new bootstrap.Modal(document.getElementById('modalSubAdmin'));
const ALL_BRANCH_IDS = [<?= implode(',', array_map(fn($b) => (int) $b['id'], $branches)) ?>];

function saveNvrName(nvrId) {
  const name = document.getElementById(`nvr-name-${nvrId}`).value.trim();
  if (!name) return;
  fetch(`/api/nvrs/${nvrId}`, {
    method: 'PUT',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({name}),
  }).then(r => { if (r.ok) alert('Saqlandi'); });
}

async function toggleCameras(nvrId) {
  const el = document.getElementById(`nvr-cameras-${nvrId}`);
  if (el.style.display !== 'none') {
    el.style.display = 'none';
    return;
  }
  el.style.display = 'block';
  document.getElementById(`nvr-cam-loading-${nvrId}`).textContent = 'Yuklanmoqda...';
  // Filial bo'yicha filtr YO'Q — backend allaqachon foydalanuvchi ko'rish
  // doirasini qo'llaydi; bu yerda faqat NVR bo'yicha ajratamiz.
  const r = await fetch('/api/cameras');
  const d = await r.json();
  const cams = (d.cameras || []).filter(c => c.nvr_id == nvrId);
  let html = '';
  cams.forEach(c => {
    const sc = c.status === 1 ? 'text-success' : c.status === 2 ? 'text-danger' : 'text-muted';
    html += `<div class="d-flex justify-content-between align-items-center py-1 border-bottom">
      <div>
        <a href="/camera/${c.id}" class="fw-semibold text-decoration-none">${c.name}</a>
        <code class="ms-2 text-muted">${c.ip || '—'}</code>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <span class="${sc}" style="font-size:.8rem;">${c.status === 1 ? 'Online' : c.status === 2 ? 'Offline' : 'Noma\'lum'}</span>
      </div>
    </div>`;
  });
  if (!html) html = '<div class="text-muted">Kamera topilmadi</div>';
  el.innerHTML = html;
}

function updateNvrLabel() {
  const cbs = document.querySelectorAll('.sa-nvr-cb:checked');
  const label = document.getElementById('sa-nvrs-label');
  if (cbs.length === 0) { label.textContent = 'Barcha NVRlar'; label.className = 'text-muted'; }
  else if (cbs.length <= 2) {
    label.textContent = Array.from(cbs).map(c => c.parentElement.querySelector('label').textContent.trim().split(' ')[0]).join(', ');
    label.className = '';
  } else { label.textContent = cbs.length + ' ta NVR tanlandi'; label.className = ''; }
}

function editSubAdmin(id, username, fullname, isActive, nvrIds) {
  document.getElementById('sa-modal-title').textContent = 'Sub-adminni tahrirlash';
  document.getElementById('sa-id').value = id;
  document.getElementById('sa-username').value = username;
  document.getElementById('sa-username').disabled = true;
  document.getElementById('sa-password').value = '';
  document.getElementById('sa-fullname').value = fullname;
  document.getElementById('sa-active').checked = !!isActive;
  document.querySelectorAll('.sa-nvr-cb').forEach(cb => {
    cb.checked = nvrIds.includes(parseInt(cb.value));
  });
  updateNvrLabel();
  saModal.show();
}

document.getElementById('modalSubAdmin').addEventListener('hidden.bs.modal', () => {
  document.getElementById('sa-id').value = '';
  document.getElementById('sa-username').disabled = false;
  document.getElementById('sa-username').value = '';
  document.getElementById('sa-password').value = '';
  document.getElementById('sa-fullname').value = '';
  document.getElementById('sa-active').checked = true;
  document.querySelectorAll('.sa-nvr-cb').forEach(cb => cb.checked = false);
  updateNvrLabel();
  document.getElementById('sa-modal-title').textContent = 'Yangi sub-admin';
});

async function saveSubAdmin() {
  const id = document.getElementById('sa-id').value;
  const nids = [...document.querySelectorAll('.sa-nvr-cb:checked')].map(c => parseInt(c.value));
  const data = {
    username: document.getElementById('sa-username').value,
    password: document.getElementById('sa-password').value,
    full_name: document.getElementById('sa-fullname').value,
    is_active: document.getElementById('sa-active').checked,
    nvr_ids: nids,
    branch_ids: ALL_BRANCH_IDS,
  };
  const url = id ? `/api/manage/users/${id}` : '/api/manage/users';
  const method = id ? 'PUT' : 'POST';
  const r = await fetch(url, {method, headers:{'Content-Type':'application/json'}, body: JSON.stringify(data)});
  if (!r.ok) { const d = await r.json(); alert(d.detail || 'Xato'); return; }
  saModal.hide();
  location.reload();
}

async function deleteSubAdmin(id, username) {
  if (!confirm(`"${username}" ni o'chirish?`)) return;
  const r = await fetch(`/api/manage/users/${id}`, {method:'DELETE'});
  if (r.ok) { document.getElementById(`sa-${id}`)?.remove(); }
}
</script>
<?php $scripts_html = ob_get_clean();
require __DIR__ . '/_layout.php';
