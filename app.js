const CAT_COLORS = {
  'Quincaillerie':   '#1A7A56',
  'Finition':        '#B8720A',
  'Visserie':        '#6B3EAC',
  'Accessoires':     '#B83030'
};
const CATS = Object.keys(CAT_COLORS);
const FALLBACK_CAT_COLORS = ['#1A5FA8', '#667085', '#0E7C7B', '#8A4B0F', '#9A3412', '#475467'];

function officialCategory(cat) {
  return CATS.includes(cat) ? cat : 'Accessoires';
}

function normalizeMaterial(item) {
  return { ...item, cat: officialCategory(item?.cat) };
}

function setItems(rawItems = []) {
  items = rawItems.map(normalizeMaterial);
}

function catColor(cat) {
  const official = officialCategory(cat);
  if (CAT_COLORS[official]) return CAT_COLORS[official];
  const key = String(cat || 'Autres');
  const hash = key.split('').reduce((sum, ch) => sum + ch.charCodeAt(0), 0);
  return FALLBACK_CAT_COLORS[hash % FALLBACK_CAT_COLORS.length];
}

function allMaterialCategories() {
  return CATS;
}

const BESOINS = {
  commode:     { 'Quincaillerie': 8,  'Visserie': 40, 'Finition': 0.5, 'Accessoires': 4 },
  armoire:     { 'Quincaillerie': 12, 'Visserie': 60, 'Finition': 1.0, 'Accessoires': 6 },
  etagere:     { 'Quincaillerie': 4,  'Visserie': 20, 'Finition': 0.3, 'Accessoires': 2 },
  bibliotheque:{ 'Quincaillerie': 6,  'Visserie': 35, 'Finition': 0.6, 'Accessoires': 3 },
  meuble_tv:   { 'Quincaillerie': 6,  'Visserie': 30, 'Finition': 0.4, 'Accessoires': 3 }
};
const UNITS_BESOIN = {
  'Quincaillerie':   'pièce(s)',
  'Visserie':        'pièce(s)',
  'Finition':        'litre(s)',
  'Accessoires':     'pièce(s)'
};

let items = [];
let orders = [];
let movements = [];
let stockHistory = [];
let suppliers = [];
let currentUser = null;
let editingItemId = null;
let editingSupplierId = null;
let nextId = 1;
let params = { atelier: 'Mon Atelier Rangement', devise: 'FCFA' };
let lastSimulationResults = null;
let quoteRowSeq = 0;
let lastProjectQuote = null;
let movementRowSeq = 0;
let orderRowSeq = 0;
const API_BASE = 'api';
const ACTIVE_PAGE_KEY = 'gestionStockActivePage';
const NAVIGABLE_PAGES = new Set([
  'dashboard',
  'stock',
  'sorties',
  'historique',
  'ajout',
  'commandes',
  'stats',
  'simulation',
  'parametres'
]);
const ROLE_LABELS = {
  admin: 'Admin',
  moderateur_stock: 'Moderateur stock',
  gestionnaire_projet: 'Gestionnaire projet'
};
const ROLE_PAGES = {
  admin: ['dashboard', 'stock', 'sorties', 'historique', 'ajout', 'commandes', 'stats', 'simulation', 'parametres'],
  moderateur_stock: ['stock', 'sorties'],
  gestionnaire_projet: ['simulation']
};
const ROLE_DEFAULT_PAGE = {
  admin: 'dashboard',
  moderateur_stock: 'stock',
  gestionnaire_projet: 'simulation'
};

async function apiRequest(path, options = {}) {
  const headers = {
    'Accept': 'application/json',
    ...(options.headers || {})
  };

  if (options.body && !headers['Content-Type']) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(`${API_BASE}/${path}`, {
    ...options,
    headers,
    credentials: 'same-origin'
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok || data.ok === false) {
    const error = new Error(data.error || data.detail || 'Erreur serveur.');
    error.status = response.status;
    error.data = data;
    if (response.status === 401 && !path.startsWith('auth.php')) {
      showLogin(data.error || 'Connexion requise.');
    }
    throw error;
  }

  return data;
}

function syncParamsForm() {
  const atelier = document.getElementById('p-atelier');
  const devise = document.getElementById('p-devise');

  if (atelier) atelier.value = params.atelier || 'Mon Atelier Rangement';
  if (devise) devise.value = params.devise || 'FCFA';
}

async function loadData() {
  const data = await apiRequest('bootstrap.php');
  currentUser = data.user || currentUser;
  setItems(data.items || []);
  orders = data.orders || [];
  movements = data.movements || [];
  stockHistory = data.history || [];
  suppliers = data.suppliers || [];
  params = data.params || params;
  nextId = items.reduce((max, item) => Math.max(max, Number(item.id) || 0), 0) + 1;
  syncParamsForm();
}

function currentRole() {
  return currentUser?.role || '';
}

function rolePages(role = currentRole()) {
  return ROLE_PAGES[role] || [];
}

function defaultPageForRole(role = currentRole()) {
  return ROLE_DEFAULT_PAGE[role] || 'dashboard';
}

function isAllowedPage(name) {
  return rolePages().includes(name);
}

function isAdmin() {
  return currentRole() === 'admin';
}

function canEditMaterialFull() {
  return isAdmin();
}

function canEditMaterialQuantity() {
  return isAdmin() || currentRole() === 'moderateur_stock';
}

function canRecordMovement() {
  return isAdmin() || currentRole() === 'moderateur_stock';
}

function canDeleteMovement() {
  return isAdmin();
}

function showLogin(message = '') {
  currentUser = null;
  document.body.classList.remove('is-authenticated');
  document.body.classList.add('auth-pending');
  const error = document.getElementById('login-error');
  if (error) error.textContent = message;
  const password = document.getElementById('login-password');
  if (password) password.value = '';
  document.getElementById('login-username')?.focus();
}

function showApp() {
  document.body.classList.remove('auth-pending');
  document.body.classList.add('is-authenticated');
}

function applyRoleUi() {
  showApp();

  const label = ROLE_LABELS[currentRole()] || currentRole();
  const userBadge = document.getElementById('user-badge');
  if (userBadge) {
    userBadge.textContent = `${currentUser?.name || currentUser?.username || 'Utilisateur'} · ${label}`;
    userBadge.style.display = '';
  }

  const exportBtn = document.getElementById('export-csv-btn');
  if (exportBtn) exportBtn.style.display = isAdmin() ? '' : 'none';
  const healthBadge = document.getElementById('h-badge');
  if (healthBadge) healthBadge.style.display = currentRole() === 'gestionnaire_projet' ? 'none' : '';
  const stockSub = document.querySelector('#p-stock .page-sub');
  if (stockSub) {
    stockSub.textContent = currentRole() === 'moderateur_stock'
      ? 'Modifiez uniquement les quantites et enregistrez les sorties de materiaux'
      : 'Ajoutez, modifiez ou supprimez vos materiaux';
  }

  document.querySelectorAll('.nav-item[data-page]').forEach(btn => {
    btn.style.display = isAllowedPage(btn.dataset.page) ? '' : 'none';
  });
}

async function login(event) {
  event?.preventDefault();

  const username = document.getElementById('login-username')?.value.trim() || '';
  const password = document.getElementById('login-password')?.value || '';
  const error = document.getElementById('login-error');
  if (error) error.textContent = '';

  try {
    const data = await apiRequest('auth.php', {
      method: 'POST',
      body: JSON.stringify({ username, password })
    });

    currentUser = data.user;
    await loadData();
    renderAll();
    applyRoleUi();
    showPage(savedPage());
    toast('Connexion reussie.');
  } catch (err) {
    if (error) error.textContent = err.message || 'Connexion impossible.';
  }
}

async function logout() {
  try {
    await apiRequest('auth.php', { method: 'DELETE' });
  } catch (error) {
    console.error(error);
  }

  items = [];
  orders = [];
  movements = [];
  stockHistory = [];
  suppliers = [];
  showLogin('');
}

async function refreshData() {
  await loadData();
  renderAll();
  if (currentUser) applyRoleUi();
}

function isValidPage(name) {
  return NAVIGABLE_PAGES.has(name) && isAllowedPage(name) && Boolean(document.getElementById('p-' + name));
}

function savedPage() {
  const hashPage = window.location.hash.replace('#', '').trim();
  if (isValidPage(hashPage)) return hashPage;

  const storedPage = localStorage.getItem(ACTIVE_PAGE_KEY);
  if (isValidPage(storedPage)) return storedPage;

  return defaultPageForRole();
}

function showDbError(error) {
  if (error?.status === 401 || error?.status === 403) {
    toast(error.message || 'Acces refuse.');
    return;
  }

  console.error(error);
  const hbadge = document.getElementById('h-badge');

  if (hbadge) {
    hbadge.style.background = 'var(--red-bg)';
    hbadge.style.color = 'var(--red-text)';
    hbadge.textContent = 'Base non connectée';
  }

  toast('Connexion à la base de données impossible.');
}

function saveData() {
  // Les donnees sont maintenant sauvegardees par l'API PHP/MySQL.
}

function status(item) {
  if (item.qty === 0) return 'alert';
  if (item.qty <= item.min * 0.5) return 'alert';
  if (item.qty <= item.min) return 'warn';
  return 'ok';
}

function pct(item) {
  return Math.min(100, Math.round((item.qty / Math.max(item.min * 2, 1)) * 100));
}

function badgeHtml(s) {
  if (s === 'ok') return '<span class="badge badge-ok">OK</span>';
  if (s === 'warn') return '<span class="badge badge-warn">Faible</span>';
  return '<span class="badge badge-alert">Critique</span>';
}

function fmt(n) { return Math.round(n).toLocaleString('fr-FR'); }

function wholeNumberFromValue(value, fallback = NaN) {
  const raw = String(value ?? '').trim();
  if (raw === '') return fallback;
  if (!/^-?\d+$/.test(raw)) return NaN;
  return Number(raw);
}

function wholeNumberField(id, fallback = NaN) {
  return wholeNumberFromValue(document.getElementById(id)?.value, fallback);
}

function isInvalidWholeNumber(value) {
  return !Number.isFinite(value) || !Number.isInteger(value);
}

function normalizedUnit(unit) {
  return String(unit ?? '').trim().toLowerCase().replace('m²', 'm2');
}

function quantityAllowsDecimal(unit) {
  return ['m2', 'ml', 'kg', 'litre(s)', 'litre', 'litres'].includes(normalizedUnit(unit));
}

function numberFromValue(value, fallback = NaN) {
  const raw = String(value ?? '').trim().replace(',', '.');
  if (raw === '') return fallback;
  const number = Number(raw);
  return Number.isFinite(number) ? number : NaN;
}

function quantityFromValue(value, unit, fallback = NaN) {
  const number = numberFromValue(value, fallback);
  if (!Number.isFinite(number)) return NaN;
  if (!quantityAllowsDecimal(unit) && !Number.isInteger(number)) return NaN;
  return number;
}

function quantityField(id, unit, fallback = NaN) {
  return quantityFromValue(document.getElementById(id)?.value, unit, fallback);
}

function invalidQuantity(value, unit) {
  return !Number.isFinite(value) || (!quantityAllowsDecimal(unit) && !Number.isInteger(value));
}

function quantityStep(unit) {
  return quantityAllowsDecimal(unit) ? '0.01' : '1';
}

function quantityRuleText(unit) {
  return quantityAllowsDecimal(unit)
    ? 'La quantite doit etre un nombre positif.'
    : 'La quantite doit etre un nombre entier, sans virgule.';
}

function setQuantityStep(input, unit) {
  if (input) input.step = quantityStep(unit);
}

function materialById(id) {
  return items.find(item => String(item.id) === String(id));
}

function updateAddMaterialQuantitySteps() {
  const unit = document.getElementById('f-unit')?.value || '';
  ['f-qty', 'f-min', 'f-conso'].forEach(id => setQuantityStep(document.getElementById(id), unit));
}

function updateEditMaterialQuantitySteps() {
  const unit = document.getElementById('e-unit')?.value || '';
  ['e-qty', 'e-min', 'e-conso'].forEach(id => setQuantityStep(document.getElementById(id), unit));
}

function updateStockEntryQuantityStep() {
  const item = materialById(document.getElementById('in-item')?.value);
  setQuantityStep(document.getElementById('in-qty'), item?.unit);
}

function updateSingleMovementQuantityStep() {
  const item = materialById(document.getElementById('m-item')?.value);
  setQuantityStep(document.getElementById('m-qty'), item?.unit);
}

function updateMovementRowStep(select) {
  const row = select?.closest('.movement-row');
  const item = materialById(select?.value);
  setQuantityStep(row?.querySelector('.movement-qty'), item?.unit);
}

function updateOrderRowStep(select) {
  const row = select?.closest('.order-row');
  const item = materialById(select?.value);
  setQuantityStep(row?.querySelector('.order-qty'), item?.unit);
}

function updateQuoteRowStep(select) {
  const row = select?.closest('.quote-row');
  const item = materialById(select?.value);
  setQuantityStep(row?.querySelector('.quote-qty'), item?.unit);
}

function roundedQuantity(value, unit) {
  if (!quantityAllowsDecimal(unit)) return Math.ceil(value);
  return Math.round(value * 100) / 100;
}

function formatQuantity(value, unit = '') {
  const decimals = quantityAllowsDecimal(unit) ? 2 : 0;
  return (Number(value) || 0).toLocaleString('fr-FR', {
    minimumFractionDigits: 0,
    maximumFractionDigits: decimals
  });
}

function formatOptionalQuantity(value, unit = '') {
  return value === null || value === undefined ? '—' : formatQuantity(value, unit);
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[ch]));
}

function materialImage(item) {
  return String(item?.image || '').trim();
}

function materialThumbHtml(item) {
  const image = materialImage(item);
  if (!image) {
    return '<span class="material-thumb material-thumb-empty" title="Aucune image"><i class="fa-solid fa-image"></i></span>';
  }

  return `<button class="material-thumb" type="button" onclick="openMaterialImage(${item.id})" title="Voir l'image de ${escapeHtml(item.name)}">
    <img src="${escapeHtml(image)}" alt="${escapeHtml(item.name)}">
  </button>`;
}

function setPreviewButton(button, image, label = 'Image du materiau') {
  if (!button) return;
  const cleanImage = String(image || '').trim();
  button.dataset.image = cleanImage;
  button.title = cleanImage ? 'Voir l\'image' : 'Aucune image';
  button.innerHTML = cleanImage
    ? `<img src="${escapeHtml(cleanImage)}" alt="${escapeHtml(label)}">`
    : '<i class="fa-solid fa-image"></i>';
  button.classList.toggle('material-thumb-empty', !cleanImage);
}

function openImagePreview(image, title = 'Image du materiau') {
  const cleanImage = String(image || '').trim();
  if (!cleanImage) {
    toast('Aucune image pour ce materiau.');
    return;
  }

  const modal = document.getElementById('material-image-modal');
  const img = document.getElementById('material-image-full');
  const heading = document.getElementById('material-image-title');
  if (!modal || !img) return;

  img.src = cleanImage;
  img.alt = title;
  if (heading) heading.textContent = title;
  modal.classList.add('show');
}

function openMaterialImage(id) {
  const item = items.find(i => i.id === id);
  if (!item) return;
  openImagePreview(materialImage(item), item.name);
}

function closeMaterialImage() {
  const modal = document.getElementById('material-image-modal');
  const img = document.getElementById('material-image-full');
  if (img) img.src = '';
  modal?.classList.remove('show');
}

function readImageInput(id) {
  const input = document.getElementById(id);
  const file = input?.files?.[0];
  if (!file) return Promise.resolve('');

  if (!file.type.startsWith('image/')) {
    return Promise.reject(new Error('Veuillez choisir un fichier image.'));
  }

  if (file.size > 2 * 1024 * 1024) {
    return Promise.reject(new Error('Image trop lourde : choisissez une image de moins de 2 Mo.'));
  }

  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = () => reject(new Error('Lecture de l\'image impossible.'));
    reader.readAsDataURL(file);
  });
}

function ensureMaterialImageUi() {
  const stockHead = document.querySelector('#p-stock table thead tr');
  if (stockHead && !stockHead.dataset.imageReady) {
    stockHead.innerHTML = '<th>Image</th><th>Materiau</th><th>Categorie</th><th>Quantite</th><th>Seuil</th><th>Statut</th><th>Niveau</th><th>Valeur</th><th>Fournisseur</th><th>Actions</th>';
    stockHead.dataset.imageReady = '1';
  }

  const addName = document.getElementById('f-name')?.closest('.form-group');
  if (addName && !document.getElementById('f-image')) {
    addName.insertAdjacentHTML('afterend', '<div class="form-group"><label class="form-label">Image</label><input type="file" id="f-image" accept="image/*"><span class="form-note">Image du materiau affichee en miniature dans le stock.</span></div>');
  }

  const editName = document.getElementById('e-name')?.closest('.form-group');
  if (editName && !document.getElementById('e-image')) {
    editName.insertAdjacentHTML('afterend', `
      <div class="form-group">
        <label class="form-label">Image</label>
        <div class="image-edit-row">
          <button class="material-thumb material-thumb-large material-thumb-empty" id="e-image-preview" type="button" onclick="previewEditingImage()" title="Aucune image">
            <i class="fa-solid fa-image"></i>
          </button>
          <div>
            <input type="file" id="e-image" accept="image/*">
            <input type="hidden" id="e-image-remove" value="0">
            <button class="btn btn-sm" type="button" onclick="removeEditingImage()"><i class="fa-solid fa-trash"></i> Supprimer l'image</button>
          </div>
        </div>
      </div>
    `);
    document.getElementById('e-image')?.addEventListener('change', async () => {
      try {
        const image = await readImageInput('e-image');
        document.getElementById('e-image-remove').value = '0';
        setPreviewButton(document.getElementById('e-image-preview'), image, document.getElementById('e-name')?.value || 'Image');
      } catch (error) {
        toast(error.message);
      }
    });
  }

  if (!document.getElementById('material-image-modal')) {
    document.body.insertAdjacentHTML('beforeend', `
      <div class="modal-backdrop" id="material-image-modal" onclick="if(event.target===this) closeMaterialImage()">
        <div class="modal-panel image-modal-panel">
          <div class="modal-head">
            <div>
              <h2 id="material-image-title">Image du materiau</h2>
              <p>Apercu en taille reelle</p>
            </div>
            <button class="btn btn-sm btn-icon" onclick="closeMaterialImage()" title="Fermer"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <img id="material-image-full" class="material-image-full" src="" alt="">
        </div>
      </div>
    `);
  }
}

function previewEditingImage() {
  const button = document.getElementById('e-image-preview');
  openImagePreview(button?.dataset.image || '', document.getElementById('e-name')?.value || 'Image du materiau');
}

function removeEditingImage() {
  const input = document.getElementById('e-image');
  if (input) input.value = '';
  const remove = document.getElementById('e-image-remove');
  if (remove) remove.value = '1';
  setPreviewButton(document.getElementById('e-image-preview'), '', 'Image');
}

function historyTypeMeta(type) {
  const metas = {
    entree: { label: 'Entrée', cls: 'badge-ok', icon: 'fa-arrow-down' },
    sortie: { label: 'Sortie', cls: 'badge-alert', icon: 'fa-arrow-up' },
    correction: { label: 'Correction', cls: 'badge-warn', icon: 'fa-pen' },
    annulation: { label: 'Annulation', cls: 'badge-gray', icon: 'fa-ban' }
  };

  return metas[type] || { label: type || 'Historique', cls: 'badge-blue', icon: 'fa-clock-rotate-left' };
}

function signedQty(value, unit) {
  const sign = value > 0 ? '+' : '';
  return `${sign}${formatQuantity(value, unit)} ${unit}`;
}

function parseFrDate(value) {
  if (!value) return null;
  const parts = String(value).split('/');
  if (parts.length !== 3) return null;
  const [day, month, year] = parts.map(Number);
  if (!day || !month || !year) return null;
  return new Date(year, month - 1, day);
}

function formatDateFr(date) {
  return date.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit', year:'numeric'});
}

function todayInputValue() {
  return new Date().toISOString().slice(0, 10);
}

function isInLastDays(frDate, days) {
  const date = parseFrDate(frDate);
  if (!date) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  date.setHours(0, 0, 0, 0);

  const diff = Math.floor((today - date) / 86400000);
  return diff >= 0 && diff <= days;
}

function orderDueInfo(order) {
  const start = parseFrDate(order.date);
  if (!start) return { label: `${order.delay} j`, overdue: false, daysLate: 0 };
  const due = new Date(start);
  due.setDate(due.getDate() + (Number(order.delay) || 0));

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  due.setHours(0, 0, 0, 0);

  const diff = Math.floor((today - due) / 86400000);
  return {
    due,
    label: formatDateFr(due),
    overdue: order.status === 'en cours' && diff > 0,
    daysLate: Math.max(0, diff)
  };
}

/* ── Dashboard ── */
function renderDashboard() {
  const today = new Date().toLocaleDateString('fr-FR', {weekday:'long',day:'numeric',month:'long',year:'numeric'});
  document.getElementById('d-date').textContent = today;

  document.getElementById('d-total').textContent = items.length;
  const alerts = items.filter(i => status(i) !== 'ok');
  const lateOrders = orders.filter(o => orderDueInfo(o).overdue);
  const monthMovements = movements.filter(m => isInLastDays(m.date, 30));
  document.getElementById('d-alerts').textContent = alerts.length;
  document.getElementById('d-orders').textContent = orders.filter(o => o.status === 'en cours').length;
  const dm = document.getElementById('d-movements');
  if (dm) dm.textContent = monthMovements.length;
  const val = items.reduce((s, i) => s + i.qty * i.price, 0);
  document.getElementById('d-value').textContent = fmt(val);

  const nb = document.getElementById('nb-alert');
  if (alerts.length > 0) { nb.textContent = alerts.length; nb.style.display = ''; }
  else nb.style.display = 'none';
  const nbo = document.getElementById('nb-orders');
  const oc = orders.filter(o => o.status === 'en cours').length;
  if (lateOrders.length > 0) { nbo.textContent = lateOrders.length + '!'; nbo.style.display = ''; }
  else if (oc > 0) { nbo.textContent = oc; nbo.style.display = ''; }
  else nbo.style.display = 'none';

  const hbadge = document.getElementById('h-badge');
  if (alerts.length === 0) { hbadge.style.background = 'var(--green-bg)'; hbadge.style.color = 'var(--green-text)'; hbadge.textContent = 'Stock sain'; }
  else { hbadge.style.background = 'var(--red-bg)'; hbadge.style.color = 'var(--red-text)'; hbadge.textContent = alerts.length + ' alerte(s)'; }

  const al = document.getElementById('alert-list');
  if (alerts.length === 0) {
    al.innerHTML = '<div class="card" style="text-align:center;color:var(--text2);padding:1.5rem;"><i class="fa-solid fa-circle-check" style="color:var(--green);font-size:20px;display:block;margin-bottom:6px;"></i>Aucune alerte — votre stock est sain !</div>';
  } else {
    al.innerHTML = alerts.map(i => {
      const s = status(i);
      return `<div class="alert-item ${s}">
        <i class="fa-solid ${s==='alert'?'fa-triangle-exclamation':'fa-circle-exclamation'} alert-icon"></i>
        <div class="alert-info">
          <div class="alert-name">${i.name}</div>
          <div class="alert-detail">${formatQuantity(i.qty, i.unit)} ${i.unit} restant(s) — seuil minimum : ${formatQuantity(i.min, i.unit)} ${i.unit}</div>
        </div>
        <button class="btn btn-sm" onclick="quickOrder(${i.id})"><i class="fa-solid fa-truck"></i> Commander</button>
      </div>`;
    }).join('');
  }

  const ml = document.getElementById('movement-list');
  if (ml) {
    const recent = movements.slice(0, 5);

    if (!recent.length) {
      ml.innerHTML = '<div class="card" style="text-align:center;color:var(--text2);padding:1.25rem;"><i class="fa-solid fa-arrow-right-from-bracket" style="color:var(--blue);font-size:18px;display:block;margin-bottom:6px;"></i>Aucune sortie interne enregistrée.</div>';
    } else {
      ml.innerHTML = recent.map(m => `
        <div class="alert-item movement">
          <i class="fa-solid fa-arrow-right-from-bracket alert-icon"></i>
          <div class="alert-info">
            <div class="alert-name">${m.name}</div>
            <div class="alert-detail">${formatQuantity(m.qty, m.unit)} ${m.unit} utilisé(s) le ${m.date}${m.destination ? ' — ' + m.destination : ''}${m.requester ? ' · ' + m.requester : ''}</div>
          </div>
        </div>`).join('');
    }
  }

  const top = [...items].sort((a,b) => b.conso - a.conso).slice(0, 5);
  const maxC = top[0]?.conso || 1;
  const cc = document.getElementById('conso-chart');
  if (!top.length || top.every(i => !i.conso)) {
    cc.innerHTML = '<p style="color:var(--text2);font-size:13px;">Renseignez la consommation hebdo. de vos matériaux dans l\'onglet Stock.</p>';
  } else {
    cc.innerHTML = top.map(i => `
      <div class="conso-item">
        <span class="conso-name" title="${i.name}">${i.name}</span>
        <div class="bar-bg" style="flex:1;"><div class="bar-fill bar-blue" style="width:${Math.round(i.conso/maxC*100)}%;background:${catColor(i.cat)};"></div></div>
        <span class="conso-val">${formatQuantity(i.conso, i.unit)} ${i.unit}/sem</span>
      </div>`).join('');
  }
}

/* ── Stock ── */
function renderStock() {
  const q = (document.getElementById('s-search')?.value||'').toLowerCase();
  const fc = document.getElementById('s-cat')?.value||'';
  const fs = document.getElementById('s-status')?.value||'';
  const filtered = items.filter(i => {
    if (q && !i.name.toLowerCase().includes(q)) return false;
    if (fc && i.cat !== fc) return false;
    if (fs && status(i) !== fs) return false;
    return true;
  });
  const sc = document.getElementById('s-count');
  if (sc) sc.textContent = filtered.length + ' résultat(s)';
  const tb = document.getElementById('stock-tbody');
  if (!filtered.length) { tb.innerHTML = `<tr><td colspan="10"><div class="empty"><i class="fa-solid fa-box-open"></i>Aucun materiau trouve</div></td></tr>`; return; }
  if (!filtered.length) { tb.innerHTML = `<tr><td colspan="9"><div class="empty"><i class="fa-solid fa-box-open"></i>Aucun matériau trouvé</div></td></tr>`; return; }
  tb.innerHTML = filtered.map(i => {
    const s = status(i); const p = pct(i);
    const actions = [];
    if (canEditMaterialQuantity()) {
      actions.push(`<button class="btn btn-sm btn-icon" title="${canEditMaterialFull() ? 'Modifier la fiche' : 'Modifier la quantite'}" onclick="editItem(${i.id})"><i class="fa-solid fa-pen-to-square"></i></button>`);
    }
    if (canEditMaterialFull()) {
      actions.push(`<button class="btn btn-sm btn-icon btn-danger" title="Supprimer" onclick="deleteItem(${i.id})"><i class="fa-solid fa-trash"></i></button>`);
    }

    return `<tr>
      <td data-label="Image">${materialThumbHtml(i)}</td>
      <td class="td-name" data-label="Matériau">${i.name}</td>
      <td data-label="Catégorie"><span class="badge" style="background:${catColor(i.cat)}18;color:${catColor(i.cat)};">${i.cat}</span></td>
      <td class="td-mono" data-label="Quantité">${formatQuantity(i.qty, i.unit)} ${i.unit}</td>
      <td class="td-mono" data-label="Seuil" style="color:var(--text2);">${formatQuantity(i.min, i.unit)} ${i.unit}</td>
      <td data-label="Statut">${badgeHtml(s)}</td>
      <td data-label="Niveau" style="min-width:90px;">
        <div class="bar-wrap">
          <div class="bar-bg"><div class="bar-fill bar-${s}" style="width:${p}%;"></div></div>
          <span style="font-size:10px;color:var(--text2);min-width:28px;text-align:right;">${p}%</span>
        </div>
      </td>
      <td class="td-mono" data-label="Valeur">${fmt(i.qty * i.price)} F</td>
      <td data-label="Fournisseur" style="color:var(--text2);font-size:12px;">${i.supplier||'—'}</td>
      <td data-label="Actions">
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
          ${actions.join('')}
        </div>
      </td>
    </tr>`;
  }).join('');
}

/* ── Sorties internes ── */
function renderMovements() {
  const sel = document.getElementById('m-item');
  if (sel) {
    sel.innerHTML = items.map(i => `<option value="${i.id}">${i.name} (${formatQuantity(i.qty, i.unit)} ${i.unit} dispo.)</option>`).join('');
    sel.onchange = updateSingleMovementQuantityStep;
    updateSingleMovementQuantityStep();
  }

  // Initialiser les dates d'aujourd'hui
  ['m-date', 'm-date-global'].forEach(id => {
    const el = document.getElementById(id);
    if (el && !el.value) el.value = todayInputValue();
  });

  // Initialiser le formulaire multi-sorties avec une ligne
  const movementRows = document.getElementById('movements-rows');
  if (movementRows && movementRows.children.length === 0) {
    addMovementRow();
  }

  const tb = document.getElementById('movements-tbody');
  if (!tb) return;

  if (!movements.length) {
    tb.innerHTML = `<tr><td colspan="8"><div class="empty"><i class="fa-solid fa-arrow-right-from-bracket"></i>Aucune sortie interne enregistrée</div></td></tr>`;
    return;
  }

  tb.innerHTML = movements.map((m, idx) => {
    // Déterminer si c'est un mouvement groupé
    // Un mouvement est groupé si le suivant/précédent a le même destination et requester
    let isBulk = false;
    if (idx > 0) {
      const prev = movements[idx - 1];
      if (prev.destination === m.destination && prev.requester === m.requester) {
        isBulk = true;
      }
    }
    if (idx < movements.length - 1) {
      const next = movements[idx + 1];
      if (next.destination === m.destination && next.requester === m.requester) {
        isBulk = true;
      }
    }
    
    const bulkBadge = isBulk ? '<span class="badge" style="background:#E6F5EE;color:#0E4E36; margin-left: 6px;"><i class="fa-solid fa-link"></i> Groupé</span>' : '';
    
    return `<tr>
    <td class="td-name" data-label="Matériau">${m.name}</td>
    <td class="td-mono" data-label="Quantité">${formatQuantity(m.qty, m.unit)} ${m.unit}</td>
    <td data-label="Destination">${m.destination || '—'}</td>
    <td data-label="Demandeur">${m.requester || '—'}${bulkBadge}</td>
    <td data-label="Date" style="color:var(--text2);font-size:12px;">${m.date}</td>
    <td data-label="Notes" style="color:var(--text2);font-size:12px;">${m.notes || '—'}</td>
    <td data-label="Type"><span class="badge badge-blue">usage interne</span></td>
    <td data-label="Actions"><div class="btn-row" style="gap:4px;"><button class="btn btn-sm btn-icon" title="Télécharger PDF" onclick="downloadMovementPDF(${m.id})"><i class="fa-solid fa-download"></i></button>${canDeleteMovement() ? `<button class="btn btn-sm btn-icon btn-danger" title="Annuler la sortie" onclick="deleteMovement(${m.id})"><i class="fa-solid fa-trash"></i></button>` : ''}</div></td>
  </tr>`;
  }).join('');
}

/* ── Entrées directes ── */
function renderEntries() {
  const sel = document.getElementById('in-item');
  if (sel) {
    sel.innerHTML = items.map(i => `<option value="${i.id}">${i.name} (${formatQuantity(i.qty, i.unit)} ${i.unit} en stock)</option>`).join('');
    sel.onchange = updateStockEntryQuantityStep;
    updateStockEntryQuantityStep();
  }

  const tb = document.getElementById('entries-tbody');
  if (!tb) return;

  const directEntries = stockHistory.filter(h => h.type === 'entree' && h.sourceType === 'entree_directe');
  if (!directEntries.length) {
    tb.innerHTML = `<tr><td colspan="7"><div class="empty"><i class="fa-solid fa-arrow-down"></i>Aucune entrée directe enregistrée</div></td></tr>`;
    return;
  }

  tb.innerHTML = directEntries.map(h => `<tr>
    <td data-label="Date" style="color:var(--text2);font-size:12px;">${h.date}</td>
    <td class="td-name" data-label="Matériau">${h.name}</td>
    <td class="td-mono history-plus" data-label="Quantité">${signedQty(h.delta, h.unit)}</td>
    <td class="td-mono" data-label="Avant">${formatOptionalQuantity(h.before, h.unit)}</td>
    <td class="td-mono" data-label="Après">${formatOptionalQuantity(h.after, h.unit)}</td>
    <td data-label="Destination">${h.destination || '—'}</td>
    <td data-label="Notes" style="color:var(--text2);font-size:12px;">${h.notes || '—'}</td>
  </tr>`).join('');
}

/* ── Historique complet ── */
function renderHistory() {
  const q = (document.getElementById('h-search')?.value || '').toLowerCase();
  const ft = document.getElementById('h-type')?.value || '';
  const filtered = stockHistory.filter(h => {
    const haystack = [h.name, h.destination, h.requester, h.actorName, h.actorRole, h.notes, h.sourceType].join(' ').toLowerCase();
    if (q && !haystack.includes(q)) return false;
    if (ft && h.type !== ft) return false;
    return true;
  });

  const count = document.getElementById('h-count');
  if (count) count.textContent = `${filtered.length} ligne(s)`;

  const tb = document.getElementById('history-tbody');
  if (!tb) return;

  if (!filtered.length) {
    tb.innerHTML = `<tr><td colspan="11"><div class="empty"><i class="fa-solid fa-clock-rotate-left"></i>Aucun historique trouvé</div></td></tr>`;
    return;
  }

  tb.innerHTML = filtered.map(h => {
    const meta = historyTypeMeta(h.type);
    const deltaClass = h.delta > 0 ? 'history-plus' : h.delta < 0 ? 'history-minus' : '';
    return `<tr>
      <td data-label="Date" style="color:var(--text2);font-size:12px;">${h.date}</td>
      <td data-label="Type"><span class="badge ${meta.cls}"><i class="fa-solid ${meta.icon}"></i> ${meta.label}</span></td>
      <td class="td-name" data-label="Matériau">${h.name}</td>
      <td class="td-mono ${deltaClass}" data-label="Quantité">${signedQty(h.delta, h.unit)}</td>
      <td class="td-mono" data-label="Avant">${formatOptionalQuantity(h.before, h.unit)}</td>
      <td class="td-mono" data-label="Après">${formatOptionalQuantity(h.after, h.unit)}</td>
      <td data-label="Destination">${h.destination || '—'}</td>
      <td data-label="Demandeur">${h.requester || '—'}</td>
      <td data-label="Utilisateur">${escapeHtml(h.actorName || '—')}</td>
      <td data-label="Source" style="color:var(--text2);font-size:12px;">${h.sourceType || '—'}</td>
      <td data-label="Notes" style="color:var(--text2);font-size:12px;">${h.notes || '—'}</td>
    </tr>`;
  }).join('');
}

/* ── Fournisseurs ── */
function renderSupplierOptions() {
  const dl = document.getElementById('supplier-list');
  if (dl) {
    dl.innerHTML = suppliers.map(s => `<option value="${s.name}"></option>`).join('');
  }
}

function renderSuppliers() {
  renderSupplierOptions();
  const tb = document.getElementById('suppliers-tbody');
  if (!tb) return;

  const count = document.getElementById('suppliers-count');
  if (count) count.textContent = `${suppliers.length} fournisseur(s)`;

  if (!suppliers.length) {
    tb.innerHTML = `<tr><td colspan="8"><div class="empty"><i class="fa-solid fa-address-book"></i>Aucun fournisseur enregistré</div></td></tr>`;
    return;
  }

  tb.innerHTML = suppliers.map(s => `<tr>
    <td class="td-name" data-label="Nom">${s.name}</td>
    <td data-label="Contact">${s.contact || '—'}</td>
    <td data-label="Téléphone">${s.phone || '—'}</td>
    <td data-label="Email">${s.email || '—'}</td>
    <td class="td-mono" data-label="Délai">${s.leadTime} j</td>
    <td data-label="Produits" style="color:var(--text2);font-size:12px;">${s.products || '—'}</td>
    <td data-label="Notes" style="color:var(--text2);font-size:12px;">${s.notes || '—'}</td>
    <td data-label="Actions">
      <div style="display:flex;gap:4px;">
        <button class="btn btn-sm btn-icon" title="Modifier" onclick="editSupplier(${s.id})"><i class="fa-solid fa-pen-to-square"></i></button>
        <button class="btn btn-sm btn-icon btn-danger" title="Supprimer" onclick="deleteSupplier(${s.id})"><i class="fa-solid fa-trash"></i></button>
      </div>
    </td>
  </tr>`).join('');
}

/* ── Commandes ── */
function renderOrders() {
  const orderRows = document.getElementById('orders-rows');
  if (orderRows && orderRows.children.length === 0) addOrderRow();
  orderRows?.querySelectorAll('.order-item').forEach(select => {
    const selected = select.value;
    select.innerHTML = `<option value="">-- Sélectionner un matériau --</option>${items.map(item => `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}`;
    fillOrderUnitCost(select, false);
    updateOrderRowStep(select);
  });
  const date = document.getElementById('o-date');
  if (date && !date.value) date.value = todayInputValue();

  const tb = document.getElementById('orders-tbody');
  if (!orders.length) { tb.innerHTML = `<tr><td colspan="8"><div class="empty"><i class="fa-solid fa-truck"></i>Aucune commande enregistrée</div></td></tr>`; return; }
  const groups = [];
  const byKey = new Map();
  orders.forEach(o => {
    const key = o.groupId || `order-${o.id}`;
    if (!byKey.has(key)) {
      const group = { ...o, groupKey: key, lines: [], qtyTotal: 0, costTotal: 0 };
      byKey.set(key, group);
      groups.push(group);
    }
    const group = byKey.get(key);
    group.lines.push(o);
    group.qtyTotal += Number(o.qty) || 0;
    group.costTotal += Number(o.cost) || 0;
  });

  tb.innerHTML = groups.map(group => {
    const due = orderDueInfo(group);
    const names = group.lines.map(line => line.name).join(', ');
    const units = new Set(group.lines.map(line => line.unit));
    const quantityLabel = units.size === 1
      ? `${formatQuantity(group.qtyTotal, group.lines[0]?.unit)} ${group.lines[0]?.unit || ''}`.trim()
      : `${group.lines.length} lignes`;
    const statuses = new Set(group.lines.map(line => line.status));
    const statusLabel = statuses.size === 1 ? group.status : 'mixte';
    const isOpen = group.lines.some(line => line.status === 'en cours');
    const actionId = group.groupId ? `'${group.groupId}'` : group.id;
    const deleteAction = group.groupId ? `deleteOrderGroup('${group.groupId}')` : `deleteOrder(${group.id})`;
    const receiveAction = group.groupId ? `receiveOrderGroup('${group.groupId}')` : `receiveOrder(${group.id})`;
    return `<tr>
    <td class="td-name" data-label="Matériau">${escapeHtml(names)}${group.lines.length > 1 ? ` <span class="badge" style="background:#E6F5EE;color:#0E4E36;margin-left:6px;"><i class="fa-solid fa-link"></i> ${group.lines.length} articles</span>` : ''}</td>
    <td class="td-mono" data-label="Quantité">${escapeHtml(quantityLabel)}</td>
    <td data-label="Fournisseur">${escapeHtml(group.supplier||'—')}</td>
    <td data-label="Date" style="color:var(--text2);font-size:12px;">${group.date}</td>
    <td data-label="Délai">
      <span class="td-mono">${due.label}</span>
      ${due.overdue ? `<span class="badge badge-alert" title="Commande en retard" style="margin-left:6px;">+${due.daysLate} j</span>` : ''}
    </td>
    <td class="td-mono" data-label="Coût">${group.costTotal ? fmt(group.costTotal)+' F' : '—'}</td>
    <td data-label="Statut"><span class="badge ${statusLabel==='en cours' ? 'badge-warn' : statusLabel==='reçu' ? 'badge-ok' : 'badge-alert'}">${due.overdue && isOpen ? 'en retard' : statusLabel}</span></td>
    <td data-label="Actions">
      <button class="btn btn-sm btn-icon" title="Télécharger le bon de commande" onclick="downloadSupplierOrderPDF(${actionId})"><i class="fa-solid fa-download"></i></button>
      ${isOpen ? `<button class="btn btn-sm" onclick="${receiveAction}"><i class="fa-solid fa-check"></i> Réceptionner</button>` : ''}
      <button class="btn btn-sm btn-icon btn-danger" onclick="${deleteAction}"><i class="fa-solid fa-trash"></i></button>
    </td>
  </tr>`;
  }).join('');
}

/* ── Bilans ── */
function dateInputValueFromDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function parseInputDate(value) {
  if (!value) return null;
  const parts = String(value).split('-').map(Number);
  if (parts.length !== 3 || parts.some(part => !part)) return null;
  return new Date(parts[0], parts[1] - 1, parts[2]);
}

function parseAppDate(value) {
  return parseFrDate(value) || parseInputDate(value);
}

function normalizeDate(date) {
  const copy = new Date(date);
  copy.setHours(0, 0, 0, 0);
  return copy;
}

function addDays(date, amount) {
  const copy = new Date(date);
  copy.setDate(copy.getDate() + amount);
  return copy;
}

function isDateInRange(value, range) {
  const date = parseAppDate(value);
  if (!date) return false;
  const current = normalizeDate(date).getTime();
  return current >= range.start.getTime() && current <= range.end.getTime();
}

function formatRangeLabel(range) {
  return `Bilan du ${formatDateFr(range.start)} au ${formatDateFr(range.end)}`;
}

function getBilanRange() {
  const period = document.getElementById('bilan-period')?.value || '30';
  const startInput = document.getElementById('bilan-start');
  const endInput = document.getElementById('bilan-end');
  const today = normalizeDate(new Date());
  let start = addDays(today, -29);
  let end = today;

  if (period === 'today') {
    start = today;
  } else if (period === '7') {
    start = addDays(today, -6);
  } else if (period === 'month') {
    start = new Date(today.getFullYear(), today.getMonth(), 1);
  } else if (period === 'previous-month') {
    start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    end = new Date(today.getFullYear(), today.getMonth(), 0);
  } else if (period === 'custom') {
    start = parseInputDate(startInput?.value) || start;
    end = parseInputDate(endInput?.value) || end;
    if (start > end) [start, end] = [end, start];
  }

  start = normalizeDate(start);
  end = normalizeDate(end);
  if (startInput && period !== 'custom') startInput.value = dateInputValueFromDate(start);
  if (endInput && period !== 'custom') endInput.value = dateInputValueFromDate(end);

  return { start, end, period };
}

function previousBilanRange(range) {
  const days = Math.max(1, Math.floor((range.end - range.start) / 86400000) + 1);
  const end = addDays(range.start, -1);
  const start = addDays(end, -(days - 1));
  return { start: normalizeDate(start), end: normalizeDate(end), period: 'previous' };
}

function summarizeRange(range) {
  const rangeMovements = movements.filter(m => isDateInRange(m.date, range));
  const rangeHistory = stockHistory.filter(h => isDateInRange(h.date, range));
  const rangeEntries = rangeHistory.filter(h => h.type === 'entree' && Number(h.delta) > 0);
  const rangeValue = rangeMovements.reduce((sum, movement) => {
    const item = itemForLine(movement);
    return sum + (Number(movement.qty) || 0) * (item?.price || 0);
  }, 0);

  return {
    entries: rangeEntries.length,
    movements: rangeMovements.length,
    value: rangeValue
  };
}

function setBilanCustomPeriod() {
  const period = document.getElementById('bilan-period');
  if (period) period.value = 'custom';
  renderStats();
}

function itemForLine(line) {
  return items.find(item => String(item.id) === String(line.itemId)) || items.find(item => item.name === line.name);
}

function movementKey(line) {
  return line.itemId ? `id:${line.itemId}` : `name:${line.name}`;
}

function weeksRemaining(item) {
  if (!item || !item.conso) return Infinity;
  return item.qty / item.conso;
}

function weeksLabel(value) {
  if (!Number.isFinite(value)) return 'Non calculable';
  if (value <= 0) return 'Rupture';
  if (value < 1) return '< 1 semaine';
  return `${Math.round(value * 10) / 10} sem.`;
}

function recommendedOrderQty(item) {
  const target = Math.max(Number(item.min) * 2, Number(item.conso) * 4, Number(item.min));
  return Math.max(0, roundedQuantity(target - Number(item.qty), item.unit));
}

function deltaLabel(current, previous, suffix = '') {
  const diff = current - previous;
  const sign = diff > 0 ? '+' : '';
  return `${sign}${fmt(diff)}${suffix}`;
}

function actorLabel(history) {
  return history.actorName || history.requester || 'Non renseigné';
}

function bilanEmpty(message, icon = 'fa-circle-info') {
  return `<div class="empty empty-compact"><i class="fa-solid ${icon}"></i>${message}</div>`;
}

function bilanBarList(rows, valueKey, labelFn, valueFn, colorFn) {
  if (!rows.length) return bilanEmpty('Aucune donnée pour cette période.');
  const max = Math.max(...rows.map(row => Number(row[valueKey]) || 0), 1);
  return rows.map(row => {
    const color = colorFn ? colorFn(row) : 'var(--blue)';
    const value = Number(row[valueKey]) || 0;
    return `<div class="bilan-list-item">
      <div class="bilan-list-head">
        <span title="${escapeHtml(labelFn(row))}">${escapeHtml(labelFn(row))}</span>
        <strong>${escapeHtml(valueFn(row))}</strong>
      </div>
      <div class="bar-bg"><div class="bar-fill" style="width:${Math.round(value / max * 100)}%;background:${color};"></div></div>
    </div>`;
  }).join('');
}

function buildBilanData() {
  const range = getBilanRange();
  const previousRange = previousBilanRange(range);
  const previousSummary = summarizeRange(previousRange);
  const currency = params.devise || 'FCFA';
  const periodMovements = movements.filter(m => isDateInRange(m.date, range));
  const periodHistory = stockHistory.filter(h => isDateInRange(h.date, range));
  const periodEntries = periodHistory.filter(h => h.type === 'entree' && Number(h.delta) > 0);
  const periodOrders = orders.filter(o => isDateInRange(o.date, range));
  const entryRefs = new Set(periodEntries.map(entry => movementKey(entry))).size;
  const movementRefs = new Set(periodMovements.map(movement => movementKey(movement))).size;
  const openOrders = orders.filter(o => o.status === 'en cours');
  const lateOrders = openOrders.filter(o => orderDueInfo(o).overdue);
  const alerts = items.filter(item => status(item) !== 'ok');
  const totalStockValue = items.reduce((sum, item) => sum + item.qty * item.price, 0);
  const movementValue = periodMovements.reduce((sum, m) => {
    const item = itemForLine(m);
    return sum + m.qty * (item?.price || 0);
  }, 0);

  const byMaterial = new Map();
  const ensureMaterialRow = line => {
    const key = movementKey(line);
    if (!byMaterial.has(key)) {
      const item = itemForLine(line);
      byMaterial.set(key, {
        key,
        item,
        name: item?.name || line.name,
        unit: item?.unit || line.unit || '',
        cat: item?.cat || 'Autres',
        entered: 0,
        outgoing: 0,
        outValue: 0,
        stock: item?.qty ?? null,
        lastDate: null
      });
    }
    return byMaterial.get(key);
  };

  periodEntries.forEach(entry => {
    const row = ensureMaterialRow(entry);
    row.entered += Number(entry.delta) || 0;
    const date = parseAppDate(entry.date);
    if (date && (!row.lastDate || date > row.lastDate)) row.lastDate = date;
  });

  periodMovements.forEach(movement => {
    const row = ensureMaterialRow(movement);
    const item = itemForLine(movement);
    row.outgoing += Number(movement.qty) || 0;
    row.outValue += (Number(movement.qty) || 0) * (item?.price || 0);
    const date = parseAppDate(movement.date);
    if (date && (!row.lastDate || date > row.lastDate)) row.lastDate = date;
  });

  const materialRows = [...byMaterial.values()].sort((a, b) =>
    (b.outValue - a.outValue) || (b.outgoing - a.outgoing) || a.name.localeCompare(b.name)
  );
  const topProducts = materialRows.filter(row => row.outgoing > 0).slice(0, 5);

  const destinationsMap = new Map();
  periodMovements.forEach(movement => {
    const label = movement.destination || 'Non renseigné';
    const item = itemForLine(movement);
    const current = destinationsMap.get(label) || { label, count: 0, value: 0 };
    current.count += 1;
    current.value += (Number(movement.qty) || 0) * (item?.price || 0);
    destinationsMap.set(label, current);
  });
  const destinations = [...destinationsMap.values()].sort((a, b) => b.value - a.value || b.count - a.count).slice(0, 5);

  const actorsMap = new Map();
  periodHistory.forEach(history => {
    const label = actorLabel(history);
    const current = actorsMap.get(label) || { label, count: 0, entries: 0, sorties: 0, corrections: 0 };
    current.count += 1;
    if (history.type === 'entree') current.entries += 1;
    else if (history.type === 'sortie') current.sorties += 1;
    else current.corrections += 1;
    actorsMap.set(label, current);
  });
  const actors = [...actorsMap.values()].sort((a, b) => b.count - a.count).slice(0, 5);

  const reorder = items
    .map(item => ({ item, weeks: weeksRemaining(item), state: status(item), recommended: recommendedOrderQty(item) }))
    .filter(row => row.state !== 'ok' || row.weeks <= 4 || row.recommended > 0)
    .sort((a, b) => {
      const rank = { alert: 0, warn: 1, ok: 2 };
      return (rank[a.state] - rank[b.state]) || (a.weeks - b.weeks);
    })
    .slice(0, 12);

  const now = normalizeDate(new Date());
  const dormant = items.map(item => {
    const related = movements
      .filter(m => String(m.itemId) === String(item.id) || m.name === item.name)
      .map(m => parseAppDate(m.date))
      .filter(Boolean)
      .sort((a, b) => b - a);
    const last = related[0] || null;
    const days = last ? Math.floor((now - normalizeDate(last)) / 86400000) : Infinity;
    return { item, last, days, value: item.qty * item.price };
  })
    .filter(row => row.item.qty > 0 && (!row.last || row.days >= 60))
    .sort((a, b) => b.value - a.value)
    .slice(0, 5);

  const categoryValues = allMaterialCategories().map(cat => ({
    label: cat,
    value: items.filter(item => item.cat === cat).reduce((sum, item) => sum + item.qty * item.price, 0)
  })).filter(row => row.value > 0).sort((a, b) => b.value - a.value);

  const anomalies = [];
  const noSupplier = items.filter(item => !String(item.supplier || '').trim()).length;
  const noPrice = items.filter(item => !item.price).length;
  const noConso = items.filter(item => !item.conso).length;
  const noMin = items.filter(item => !item.min).length;
  const customCats = items.filter(item => item.cat && !CAT_COLORS[item.cat]).length;
  const ruptureSoon = reorder.filter(row => Number.isFinite(row.weeks) && row.weeks <= 2).length;
  const dormant90 = dormant.filter(row => !row.last || row.days >= 90).length;
  const movementSpike = previousSummary.movements > 0 && periodMovements.length >= previousSummary.movements * 1.3;
  if (alerts.length) anomalies.push({ level: 'red', text: `${alerts.length} article(s) sous seuil ou critique.` });
  if (ruptureSoon) anomalies.push({ level: 'red', text: `${ruptureSoon} article(s) avec risque de rupture sous 2 semaines.` });
  if (lateOrders.length) anomalies.push({ level: 'red', text: `${lateOrders.length} commande(s) fournisseur en retard.` });
  if (dormant90) anomalies.push({ level: 'amber', text: `${dormant90} article(s) dormant(s) depuis au moins 90 jours.` });
  if (movementSpike) anomalies.push({ level: 'amber', text: `Les sorties augmentent fortement vs période précédente (${deltaLabel(periodMovements.length, previousSummary.movements)}).` });
  if (noSupplier) anomalies.push({ level: 'amber', text: `${noSupplier} article(s) sans fournisseur renseigné.` });
  if (noPrice) anomalies.push({ level: 'amber', text: `${noPrice} article(s) sans prix unitaire.` });
  if (noConso) anomalies.push({ level: 'amber', text: `${noConso} article(s) sans consommation hebdomadaire.` });
  if (noMin) anomalies.push({ level: 'amber', text: `${noMin} article(s) sans seuil d'alerte.` });
  if (customCats) anomalies.push({ level: 'blue', text: `${customCats} article(s) avec une catégorie hors liste standard.` });

  return {
    range,
    previousRange,
    previousSummary,
    currency,
    periodMovements,
    periodEntries,
    periodOrders,
    openOrders,
    lateOrders,
    alerts,
    totalStockValue,
    movementValue,
    entryRefs,
    movementRefs,
    materialRows,
    topProducts,
    destinations,
    actors,
    reorder,
    dormant,
    categoryValues,
    anomalies
  };
}

function renderStats() {
  const data = buildBilanData();
  const currency = data.currency;
  const summary = document.getElementById('bilan-summary');
  const rangeLabel = document.getElementById('bilan-range-label');
  if (rangeLabel) rangeLabel.textContent = formatRangeLabel(data.range);

  if (summary) {
    summary.innerHTML = `
      <div class="metric green"><div class="metric-label">Entrées matières</div><div class="metric-value">${data.periodEntries.length}</div><div class="metric-sub">${data.entryRefs} référence(s) alimentée(s)</div></div>
      <div class="metric amber"><div class="metric-label">Sorties matières</div><div class="metric-value">${data.periodMovements.length}</div><div class="metric-sub">${data.movementRefs} référence(s) utilisée(s)</div></div>
      <div class="metric amber"><div class="metric-label">Valeur consommée</div><div class="metric-value">${fmt(data.movementValue)}</div><div class="metric-sub">${escapeHtml(currency)} estimés sur les sorties</div></div>
      <div class="metric blue"><div class="metric-label">Commandes</div><div class="metric-value">${data.periodOrders.length}</div><div class="metric-sub">${data.openOrders.length} en cours, ${data.lateOrders.length} retard</div></div>
      <div class="metric red"><div class="metric-label">Alertes</div><div class="metric-value">${data.alerts.length}</div><div class="metric-sub">à traiter maintenant</div></div>
      <div class="metric green"><div class="metric-label">Stock valorisé</div><div class="metric-value">${fmt(data.totalStockValue)}</div><div class="metric-sub">${escapeHtml(currency)} estimés en stock</div></div>
    `;
  }

  const movementBody = document.getElementById('bilan-movements-tbody');
  if (movementBody) {
    if (!data.materialRows.length) {
      movementBody.innerHTML = `<tr><td colspan="6">${bilanEmpty('Aucun mouvement sur cette période.', 'fa-box-open')}</td></tr>`;
    } else {
      movementBody.innerHTML = data.materialRows.map(row => `
        <tr>
          <td class="td-name" data-label="Produit">${escapeHtml(row.name)}</td>
          <td class="td-mono history-plus" data-label="Entrées">${row.entered ? '+' + formatQuantity(row.entered, row.unit) + ' ' + escapeHtml(row.unit) : '0'}</td>
          <td class="td-mono history-minus" data-label="Sorties">${row.outgoing ? '-' + formatQuantity(row.outgoing, row.unit) + ' ' + escapeHtml(row.unit) : '0'}</td>
          <td class="td-mono" data-label="Stock actuel">${row.stock === null ? '—' : formatQuantity(row.stock, row.unit) + ' ' + escapeHtml(row.unit)}</td>
          <td class="td-mono" data-label="Valeur sortie">${fmt(row.outValue)} ${escapeHtml(currency)}</td>
          <td data-label="Dernier mouvement" style="color:var(--text2);font-size:12px;">${row.lastDate ? formatDateFr(row.lastDate) : '—'}</td>
        </tr>`).join('');
    }
  }

  const topProducts = document.getElementById('bilan-top-products');
  if (topProducts) {
    topProducts.innerHTML = bilanBarList(
      data.topProducts,
      'outValue',
      row => row.name,
      row => `${formatQuantity(row.outgoing, row.unit)} ${row.unit} - ${fmt(row.outValue)} ${currency}`,
      row => catColor(row.cat)
    );
  }

  const destinations = document.getElementById('bilan-destinations');
  if (destinations) {
    destinations.innerHTML = bilanBarList(
      data.destinations,
      'value',
      row => row.label,
      row => `${row.count} sortie(s) - ${fmt(row.value)} ${currency}`,
      () => 'var(--blue)'
    );
  }

  const comparison = document.getElementById('bilan-comparison');
  if (comparison) {
    comparison.innerHTML = `
      <div class="bilan-number-row"><span>Entrées matières</span><strong>${data.periodEntries.length} <small>${escapeHtml(deltaLabel(data.periodEntries.length, data.previousSummary.entries))}</small></strong></div>
      <div class="bilan-number-row"><span>Sorties matières</span><strong>${data.periodMovements.length} <small>${escapeHtml(deltaLabel(data.periodMovements.length, data.previousSummary.movements))}</small></strong></div>
      <div class="bilan-number-row"><span>Valeur consommée</span><strong>${fmt(data.movementValue)} ${escapeHtml(currency)} <small>${escapeHtml(deltaLabel(data.movementValue, data.previousSummary.value, ' ' + currency))}</small></strong></div>
      <p class="form-note">Comparé au ${formatDateFr(data.previousRange.start)} - ${formatDateFr(data.previousRange.end)}.</p>
    `;
  }

  const actors = document.getElementById('bilan-actors');
  if (actors) {
    actors.innerHTML = data.actors.length
      ? data.actors.map(actor => `
        <div class="bilan-alert-row">
          <div>
            <strong>${escapeHtml(actor.label)}</strong>
            <span>${actor.entries} entrée(s), ${actor.sorties} sortie(s), ${actor.corrections} correction(s)</span>
          </div>
          <span class="td-mono">${actor.count}</span>
        </div>`).join('')
      : bilanEmpty('Aucune action utilisateur sur cette période.');
  }

  const reorderBody = document.getElementById('bilan-reorder-tbody');
  if (reorderBody) {
    if (!data.reorder.length) {
      reorderBody.innerHTML = `<tr><td colspan="8">${bilanEmpty('Aucun article prioritaire à commander.', 'fa-circle-check')}</td></tr>`;
    } else {
      reorderBody.innerHTML = data.reorder.map(row => `
        <tr>
          <td class="td-name" data-label="Produit">${escapeHtml(row.item.name)} ${badgeHtml(row.state)}</td>
          <td class="td-mono" data-label="Stock">${formatQuantity(row.item.qty, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
          <td class="td-mono" data-label="Seuil">${formatQuantity(row.item.min, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
          <td class="td-mono" data-label="Conso./sem.">${formatQuantity(row.item.conso, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
          <td data-label="Semaines restantes"><span class="badge ${row.weeks <= 1 ? 'badge-alert' : row.weeks <= 4 ? 'badge-warn' : 'badge-blue'}">${escapeHtml(weeksLabel(row.weeks))}</span></td>
          <td class="td-mono" data-label="Qté conseillée">${formatQuantity(row.recommended, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
          <td data-label="Fournisseur">${escapeHtml(row.item.supplier || '—')}</td>
          <td data-label="Action"><button class="btn btn-sm" onclick="quickOrder(${row.item.id})"><i class="fa-solid fa-truck"></i> Commander</button></td>
        </tr>`).join('');
    }
  }

  const finance = document.getElementById('bilan-finance');
  if (finance) {
    const topStockValue = [...items].sort((a, b) => (b.qty * b.price) - (a.qty * a.price)).slice(0, 5);
    finance.innerHTML = `
      <div class="bilan-number-row"><span>Stock actuel valorisé</span><strong>${fmt(data.totalStockValue)} ${escapeHtml(currency)}</strong></div>
      <div class="bilan-number-row"><span>Entrées matières période</span><strong class="history-plus">${data.periodEntries.length} mouvement(s)</strong></div>
      <div class="bilan-number-row"><span>Sorties consommées période</span><strong class="history-minus">${fmt(data.movementValue)} ${escapeHtml(currency)}</strong></div>
      <div class="bilan-divider"></div>
      ${bilanBarList(
        topStockValue.map(item => ({ item, value: item.qty * item.price })),
        'value',
        row => row.item.name,
        row => `${fmt(row.value)} ${currency}`,
        row => catColor(row.item.cat)
      )}
    `;
  }

  const dormant = document.getElementById('bilan-dormant');
  if (dormant) {
    dormant.innerHTML = data.dormant.length
      ? data.dormant.map(row => `
        <div class="bilan-alert-row">
          <div>
            <strong>${escapeHtml(row.item.name)}</strong>
            <span>${row.last ? 'Dernière sortie : ' + formatDateFr(row.last) + ' (' + row.days + ' j)' : 'Aucune sortie enregistrée'} · stock ${formatQuantity(row.item.qty, row.item.unit)} ${escapeHtml(row.item.unit)}</span>
          </div>
          <span class="td-mono">${fmt(row.value)} ${escapeHtml(currency)}</span>
        </div>`).join('')
      : bilanEmpty('Aucun stock dormant détecté.', 'fa-circle-check');
  }

  const anomalies = document.getElementById('bilan-anomalies');
  if (anomalies) {
    anomalies.innerHTML = data.anomalies.length
      ? data.anomalies.map(item => `<div class="bilan-alert-row ${item.level}"><i class="fa-solid fa-circle-exclamation"></i><span>${escapeHtml(item.text)}</span></div>`).join('')
      : bilanEmpty('Aucune anomalie importante détectée.', 'fa-circle-check');
  }

  renderInventoryForm();
}

function renderInventoryForm() {
  const select = document.getElementById('inventory-item');
  if (!select) return;

  const selected = select.value;
  select.innerHTML = `<option value="">-- Sélectionner un matériau --</option>${items.map(item => `
    <option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${escapeHtml(item.name)}</option>
  `).join('')}`;
  if (selected && !select.value) select.value = selected;
  updateInventoryQuantityStep();
}

function updateInventoryQuantityStep() {
  const item = materialById(document.getElementById('inventory-item')?.value);
  const counted = document.getElementById('inventory-counted');
  setQuantityStep(counted, item?.unit);
  const current = document.getElementById('inventory-current-stock');
  if (current) {
    current.textContent = item
      ? `Stock système : ${formatQuantity(item.qty, item.unit)} ${item.unit}. Saisissez le stock réellement compté.`
      : 'Sélectionnez un matériau pour voir le stock système.';
  }
}

async function savePhysicalInventory() {
  const item = materialById(document.getElementById('inventory-item')?.value);
  if (!item) {
    toast('Veuillez sélectionner un matériau.');
    return;
  }

  const counted = quantityField('inventory-counted', item.unit, NaN);
  if (invalidQuantity(counted, item.unit) || counted < 0) {
    toast(quantityRuleText(item.unit));
    return;
  }

  const diff = counted - item.qty;
  if (diff === 0) {
    toast('Aucun écart à enregistrer.');
    return;
  }

  const note = document.getElementById('inventory-notes')?.value.trim() || '';

  try {
    await apiRequest('materials.php', {
      method: 'PATCH',
      body: JSON.stringify({
        id: item.id,
        qty: counted,
        sourceType: 'inventaire_physique',
        notes: `Inventaire physique. Stock système: ${formatQuantity(item.qty, item.unit)} ${item.unit}. Stock compté: ${formatQuantity(counted, item.unit)} ${item.unit}.${note ? ' ' + note : ''}`
      })
    });

    document.getElementById('inventory-counted').value = '';
    document.getElementById('inventory-notes').value = '';
    await refreshData();
    toast(`Inventaire enregistré (${diff > 0 ? '+' : ''}${formatQuantity(diff, item.unit)} ${item.unit}).`);
  } catch (error) {
    showDbError(error);
  }
}

function exportBilanCSV() {
  const data = buildBilanData();
  const rows = data.materialRows.map(row => [
    row.name,
    `${formatQuantity(row.entered, row.unit)} ${row.unit}`.trim(),
    `${formatQuantity(row.outgoing, row.unit)} ${row.unit}`.trim(),
    row.stock === null ? '' : `${formatQuantity(row.stock, row.unit)} ${row.unit}`.trim(),
    row.outValue,
    row.lastDate ? formatDateFr(row.lastDate) : ''
  ]);
  const headers = ['Produit', 'Entrees', 'Sorties', 'Stock actuel', 'Valeur sortie', 'Dernier mouvement'];
  const csv = [headers, ...rows]
    .map(line => line.map(value => `"${String(value ?? '').replace(/"/g, '""')}"`).join(';'))
    .join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent('\ufeff' + csv);
  a.download = 'bilan_stock_' + dateInputValueFromDate(data.range.start) + '_' + dateInputValueFromDate(data.range.end) + '.csv';
  a.click();
  toast('Bilan CSV téléchargé.');
}

function printBilan() {
  window.print();
}

/* ── Simulation ── */
function simulate() {
  const type = document.getElementById('sim-type').value;
  const qty = wholeNumberField('sim-qty', 1);
  const finish = document.getElementById('sim-finish').value;
  const finMult = finish.includes('2 couches') ? 2 : 1;
  const b = {...BESOINS[type]};

  if (isInvalidWholeNumber(qty) || qty <= 0) {
    toast('La quantite du projet doit etre un nombre entier, sans virgule.');
    return;
  }

  b['Finition'] = roundedQuantity(b['Finition'] * finMult, UNITS_BESOIN.Finition);

  const catStock = {};
  CATS.forEach(c => { catStock[c] = items.filter(i=>i.cat===c).reduce((s,i)=>s+i.qty,0); });

  const rows = CATS.map(cat => {
    const need = roundedQuantity(b[cat] * qty, UNITS_BESOIN[cat]);
    const avail = catStock[cat] || 0;
    const ok = avail >= need;
    const manque = ok ? 0 : roundedQuantity(need - avail, UNITS_BESOIN[cat]);
    return { cat, need, avail, ok, manque };
  });

  lastSimulationResults = { rows, type, qty, finish };

  const allOk = rows.every(r => r.ok);
  const meuble = {commode:'Commode',armoire:'Armoire',etagere:'Étagère',bibliotheque:'Bibliothèque',meuble_tv:'Meuble TV'}[type];

  document.getElementById('sim-result').innerHTML = `
    <div class="card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem;">
        <span class="badge ${allOk?'badge-ok':'badge-alert'}" style="font-size:12px;padding:4px 12px;">
          <i class="fa-solid ${allOk?'fa-circle-check':'fa-circle-xmark'}"></i>
          ${allOk ? 'Stock suffisant pour ce projet' : 'Stock insuffisant — approvisionnement nécessaire'}
        </span>
        <span style="font-size:13px;color:var(--text2);">${qty} × ${meuble} · ${finish}</span>
      </div>
      ${rows.map(r => `
        <div class="sim-row">
          <div class="sim-status ${r.ok?'sim-ok':'sim-bad'}"><i class="fa-solid ${r.ok?'fa-check':'fa-xmark'}" style="font-size:8px;"></i></div>
          <span style="font-size:13px;flex:1;">${r.cat}</span>
          <span style="font-size:12px;color:var(--text2);">Besoin : <strong>${formatQuantity(r.need, UNITS_BESOIN[r.cat])} ${UNITS_BESOIN[r.cat]}</strong></span>
          <span style="font-size:12px;color:var(--text2);">Dispo : ${formatQuantity(r.avail, UNITS_BESOIN[r.cat])} ${UNITS_BESOIN[r.cat]}</span>
          <span style="font-size:12px;font-weight:600;color:${r.ok?'var(--green-text)':'var(--red-text)'};">${r.ok ? 'OK' : '−'+formatQuantity(r.manque, UNITS_BESOIN[r.cat])+' '+UNITS_BESOIN[r.cat]}</span>
        </div>`).join('')}
      ${!allOk ? `<button class="btn btn-primary" style="margin-top:1rem;" onclick="createMissingOrders()"><i class="fa-solid fa-truck"></i> Créer les commandes manquantes</button>` : ''}
    </div>`;
}

async function createMissingOrders() {
  if (!lastSimulationResults) return;
  
  const { rows } = lastSimulationResults;
  let createdCount = 0;
  let skippedCount = 0;
  const defaultSupplier = suppliers.length > 0 ? suppliers[0].name : 'Fournisseur';
  
  for (const row of rows) {
    if (!row.ok) {
      const itemsInCat = items.filter(i => i.cat === row.cat);
      
      // Vérifier d'abord si un produit existe dans cette catégorie
      if (itemsInCat.length > 0) {
        try {
          await apiRequest('orders.php', {
            method: 'POST',
            body: JSON.stringify({
              itemId: itemsInCat[0].id,
              qty: row.manque,
              supplier: defaultSupplier,
              delay: 7,
              cost: 0,
              notes: `Auto-généré par simulation - manquent ${row.manque} ${UNITS_BESOIN[row.cat]}`
            })
          });
          createdCount++;
        } catch (error) {
          console.error(`Erreur création commande pour ${row.cat}:`, error);
        }
      } else {
        // Aucun produit dans cette catégorie
        skippedCount++;
        console.log(`Catégorie "${row.cat}" ignorée - aucun produit existant`);
      }
    }
  }
  
  await refreshData();
  let message = `${createdCount} commande(s) créée(s)`;
  if (skippedCount > 0) {
    message += ` · ${skippedCount} catégorie(s) ignorée(s) (aucun produit existant)`;
  }
  toast(message);
  showPage('commandes', document.querySelectorAll('.nav-item[data-page="commandes"]')[0]);
}

/* Projets / Devis */
function quoteMaterialOptions(selectedId = '') {
  if (!items.length) {
    return '<option value="">Aucun materiau disponible</option>';
  }

  return items.map(item => {
    const selected = String(item.id) === String(selectedId) ? 'selected' : '';
    return `<option value="${item.id}" ${selected}>${escapeHtml(item.name)} - ${fmt(item.price)} ${params.devise || 'FCFA'} / ${escapeHtml(item.unit)}</option>`;
  }).join('');
}

function renderQuoteBuilder() {
  const rows = document.getElementById('quote-rows');
  if (!rows) return;

  if (!rows.children.length) {
    addQuoteRow();
    return;
  }

  rows.querySelectorAll('.quote-material').forEach(select => {
    const selected = select.value;
    select.innerHTML = quoteMaterialOptions(selected);
    updateQuoteRowStep(select);
  });
}

function addQuoteRow(data = {}) {
  const rows = document.getElementById('quote-rows');
  if (!rows) return;

  quoteRowSeq += 1;
  const row = document.createElement('div');
  row.className = 'quote-row';
  row.dataset.rowId = String(quoteRowSeq);
  row.innerHTML = `
    <select class="quote-material" aria-label="Materiau" onchange="updateQuoteRowStep(this); calculateProjectQuote()">
      ${quoteMaterialOptions(data.itemId || '')}
    </select>
    <input class="quote-qty" type="number" min="0" step="1" value="${data.qtyPerPiece ?? 1}" aria-label="Quantite par piece" oninput="calculateProjectQuote()">
    <span class="quote-unit">par piece</span>
    <button class="btn btn-sm btn-icon" type="button" title="Retirer" onclick="removeQuoteRow(this)"><i class="fa-solid fa-xmark"></i></button>
  `;
  rows.appendChild(row);
  updateQuoteRowStep(row.querySelector('.quote-material'));
}

function removeQuoteRow(button) {
  button.closest('.quote-row')?.remove();
  calculateProjectQuote();
}

function quoteRowsData(pieces) {
  return [...document.querySelectorAll('#quote-rows .quote-row')].map(row => {
    const itemId = parseInt(row.querySelector('.quote-material')?.value, 10) || 0;
    const item = items.find(i => i.id === itemId);
    const qtyPerPiece = quantityFromValue(row.querySelector('.quote-qty')?.value, item?.unit);
    if (!item || invalidQuantity(qtyPerPiece, item.unit) || qtyPerPiece <= 0) return null;

    const totalQty = qtyPerPiece * pieces;
    const subtotal = totalQty * (Number(item.price) || 0);

    return {
      item,
      qtyPerPiece,
      totalQty,
      subtotal,
      enoughStock: Number(item.qty) >= totalQty,
      missing: Math.max(0, Math.ceil(totalQty - Number(item.qty)))
    };
  }).filter(Boolean);
}

function generateQuoteHash(quoteData) {
  const content = JSON.stringify({
    project: quoteData.project,
    client: quoteData.client,
    pieces: quoteData.pieces,
    rows: quoteData.rows.map(r => ({
      itemId: r.item.id,
      qtyPerPiece: r.qtyPerPiece
    }))
  });
  
  // Créer un hash simple basé sur le contenu
  let hash = 0;
  for (let i = 0; i < content.length; i++) {
    const char = content.charCodeAt(i);
    hash = ((hash << 5) - hash) + char;
    hash = hash & hash; // Convert to 32bit integer
  }
  return Math.abs(hash).toString(16).padStart(16, '0');
}

function calculateProjectQuote() {
  const result = document.getElementById('quote-result');
  if (!result) return;

  const project = document.getElementById('quote-project')?.value.trim() || 'Projet';
  const client = document.getElementById('quote-client')?.value.trim() || '';
  const pieces = wholeNumberField('quote-pieces', 1);

  if (isInvalidWholeNumber(pieces) || pieces <= 0) {
    result.innerHTML = `<div class="card empty"><i class="fa-solid fa-triangle-exclamation"></i>Le nombre de pieces doit etre un nombre entier, sans virgule.</div>`;
    lastProjectQuote = null;
    return;
  }

  const hasInvalidMaterialQty = [...document.querySelectorAll('#quote-rows .quote-qty')].some(input => {
    const item = materialById(input.closest('.quote-row')?.querySelector('.quote-material')?.value);
    const qty = quantityFromValue(input.value, item?.unit);
    return !item || invalidQuantity(qty, item.unit) || qty <= 0;
  });

  if (hasInvalidMaterialQty) {
    result.innerHTML = `<div class="card empty"><i class="fa-solid fa-triangle-exclamation"></i>Verifiez les quantites : pieces/paquets en entier, kg/litre/m²/ml avec decimales possibles.</div>`;
    lastProjectQuote = null;
    return;
  }

  const rows = quoteRowsData(pieces);

  if (!rows.length) {
    result.innerHTML = `<div class="card empty"><i class="fa-solid fa-file-invoice"></i>Selectionnez au moins un materiau pour calculer le devis.</div>`;
    lastProjectQuote = null;
    return;
  }

  const total = rows.reduce((sum, row) => sum + row.subtotal, 0);
  lastProjectQuote = {
    project,
    client,
    pieces,
    rows,
    total,
    date: new Date().toLocaleDateString('fr-FR'),
    isSaved: false,
    quoteHash: null
  };

  // Générer le hash du devis
  lastProjectQuote.quoteHash = generateQuoteHash(lastProjectQuote);

  result.innerHTML = `
    <div class="card quote-print" id="quote-print">
      <div class="quote-head">
        <div>
          <p class="quote-kicker">${escapeHtml(params.atelier || 'Atelier')}</p>
          <h2>Devis - ${escapeHtml(project)}</h2>
          <p>${client ? 'Client : ' + escapeHtml(client) + ' | ' : ''}Date : ${lastProjectQuote.date} | Pieces : ${pieces}</p>
        </div>
        <div class="quote-total">${fmt(total)} ${escapeHtml(params.devise || 'FCFA')}</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr>
            <th>Materiau</th><th>Qt/piece</th><th>Pieces</th><th>Total matiere</th><th>PU</th><th>Total</th><th>Stock</th>
          </tr></thead>
          <tbody>
            ${rows.map(row => `
              <tr>
                <td class="td-name">${escapeHtml(row.item.name)}</td>
                <td class="td-mono">${formatQuantity(row.qtyPerPiece, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
                <td class="td-mono">${pieces}</td>
                <td class="td-mono">${formatQuantity(row.totalQty, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
                <td class="td-mono">${fmt(row.item.price)} ${escapeHtml(params.devise || 'FCFA')}</td>
                <td class="td-mono">${fmt(row.subtotal)} ${escapeHtml(params.devise || 'FCFA')}</td>
                <td>${row.enoughStock ? '<span class="badge badge-ok">OK</span>' : `<span class="badge badge-alert">Manque ${formatQuantity(row.missing, row.item.unit)} ${escapeHtml(row.item.unit)}</span>`}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>
      <div class="quote-summary">
        <span>Total general</span>
        <strong>${fmt(total)} ${escapeHtml(params.devise || 'FCFA')}</strong>
      </div>
    </div>`;
}

async function printProjectQuote() {
  if (!lastProjectQuote) {
    toast('Créez d\'abord un devis.');
    return;
  }

  // Enregistrer le devis sans lancer l'impression automatiquement.
  if (!lastProjectQuote.isSaved) {
    try {
      const response = await apiRequest('quotes.php', {
        method: 'POST',
        body: JSON.stringify({
          project: lastProjectQuote.project,
          client: lastProjectQuote.client,
          pieces: lastProjectQuote.pieces,
          total: lastProjectQuote.total,
          quoteData: JSON.stringify(lastProjectQuote),
          quoteHash: lastProjectQuote.quoteHash
        })
      });
      lastProjectQuote.isSaved = true;
      lastProjectQuote.id = response.quoteId;
      toast('Devis enregistré avec succès.');
      await loadQuotesHistory();
    } catch (error) {
      if (error.status === 409) {
        toast('Ce devis est déjà enregistré.');
        lastProjectQuote.isSaved = true;
      } else {
        console.error('Erreur lors de la sauvegarde du devis:', error);
        toast('Erreur lors de l\'enregistrement du devis.');
        return;
      }
    }
  } else {
    toast('Ce devis est déjà enregistré.');
  }
}

async function saveQuote() {
  if (!lastProjectQuote) {
    toast('Aucun devis à enregistrer.');
    return;
  }

  if (lastProjectQuote.isSaved) {
    toast('Ce devis est déjà enregistré.');
    return;
  }

  try {
    const response = await apiRequest('quotes.php', {
      method: 'POST',
      body: JSON.stringify({
        project: lastProjectQuote.project,
        client: lastProjectQuote.client,
        pieces: lastProjectQuote.pieces,
        total: lastProjectQuote.total,
        quoteData: JSON.stringify(lastProjectQuote),
        quoteHash: lastProjectQuote.quoteHash
      })
    });
    lastProjectQuote.isSaved = true;
    lastProjectQuote.id = response.quoteId;
    toast('Devis enregistré avec succès.');
    await loadQuotesHistory();
  } catch (error) {
    if (error.status === 409) {
      toast('Ce devis est déjà enregistré.');
      lastProjectQuote.isSaved = true;
      lastProjectQuote.id = error.data?.quoteId || lastProjectQuote.id;
    } else {
      console.error('Erreur lors de la sauvegarde du devis:', error);
      toast('Erreur lors de la sauvegarde du devis.');
    }
  }
}

async function loadQuotesHistory() {
  try {
    const data = await apiRequest('quotes.php');
    const container = document.getElementById('quotes-history');
    if (!container) return;

    if (!data.quotes || data.quotes.length === 0) {
      container.innerHTML = `<div class="card empty"><i class="fa-solid fa-history"></i>Aucun devis enregistré.</div>`;
      return;
    }

    container.innerHTML = data.quotes.map(quote => `
      <div class="alert">
        <div style="flex:1;">
          <p style="font-weight:600;margin-bottom:4px;">
            <i class="fa-solid fa-file-invoice" style="margin-right:6px;color:var(--blue);"></i>
            ${escapeHtml(quote.project_name)}
          </p>
          <p style="font-size:12px;color:var(--text2);margin-bottom:4px;">
            ${quote.client_name ? 'Client : ' + escapeHtml(quote.client_name) + ' | ' : ''}
            ${quote.pieces_count} pièce(s) | 
            ${fmt(quote.total_amount)} ${escapeHtml(params.devise || 'FCFA')} | 
            ${new Date(quote.created_at).toLocaleDateString('fr-FR')}
          </p>
        </div>
        <div class="btn-row" style="gap:6px;">
          <button class="btn btn-sm" onclick="downloadQuotePDF('${quote.id}')" title="Télécharger PDF"><i class="fa-solid fa-download"></i></button>
          ${currentUser?.role === 'admin' ? `<button class="btn btn-sm btn-danger" onclick="deleteQuote('${quote.id}')" title="Supprimer"><i class="fa-solid fa-trash"></i></button>` : ''}
        </div>
      </div>
    `).join('');
  } catch (error) {
    console.error('Erreur lors du chargement de l\'historique des devis:', error);
  }
}

async function loadQuotePreview(quoteId) {
  try {
    const data = await apiRequest(`quotes.php?id=${quoteId}`);
    if (!data.quote) return;

    const quoteData = JSON.parse(data.quote.quote_data);
    const result = document.getElementById('quote-result');
    if (!result) return;

    const project = quoteData.project;
    const client = quoteData.client;
    const pieces = quoteData.pieces;
    const rows = quoteData.rows;
    const total = quoteData.total;
    const date = quoteData.date;

    result.innerHTML = `
      <div class="card quote-print" id="quote-print">
        <div class="quote-head">
          <div>
            <p class="quote-kicker">${escapeHtml(params.atelier || 'Atelier')}</p>
            <h2>Devis - ${escapeHtml(project)}</h2>
            <p>${client ? 'Client : ' + escapeHtml(client) + ' | ' : ''}Date : ${date} | Pieces : ${pieces}</p>
          </div>
          <div class="quote-total">${fmt(total)} ${escapeHtml(params.devise || 'FCFA')}</div>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr>
              <th>Materiau</th><th>Qt/piece</th><th>Pieces</th><th>Total matiere</th><th>PU</th><th>Total</th>
            </tr></thead>
            <tbody>
              ${rows.map(row => `
                <tr>
                  <td class="td-name">${escapeHtml(row.item.name)}</td>
                  <td class="td-mono">${formatQuantity(row.qtyPerPiece, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
                  <td class="td-mono">${pieces}</td>
                  <td class="td-mono">${formatQuantity(row.totalQty, row.item.unit)} ${escapeHtml(row.item.unit)}</td>
                  <td class="td-mono">${fmt(row.item.price)} ${escapeHtml(params.devise || 'FCFA')}</td>
                  <td class="td-mono">${fmt(row.subtotal)} ${escapeHtml(params.devise || 'FCFA')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
        <div class="quote-summary">
          <span>Total general</span>
          <strong>${fmt(total)} ${escapeHtml(params.devise || 'FCFA')}</strong>
        </div>
      </div>`;
    
    // Scroll vers le résultat
    result.scrollIntoView({ behavior: 'smooth' });
  } catch (error) {
    console.error('Erreur lors du chargement du devis:', error);
    toast('Erreur lors du chargement du devis.');
  }
}

function viewQuote(quoteId) {
  // Ouvrir la page du bon de devis complète
  window.open(`${API_BASE}/bon-devis.php?id=${quoteId}`, '_blank');
}

async function printQuoteById(quoteId) {
  // Ouvrir la page et déclencher l'impression
  const printWindow = window.open(`${API_BASE}/bon-devis.php?id=${quoteId}`, '_blank');
  if (printWindow) {
    printWindow.addEventListener('load', function() {
      setTimeout(() => {
        printWindow.print();
      }, 500);
    });
  }
}

function downloadQuotePDF(quoteId) {
  // Récupérer la page du bon et la convertir en PDF
  const pdfUrl = `${API_BASE}/bon-devis.php?id=${quoteId}&pdf=true`;
  window.open(pdfUrl, '_blank');
}

function downloadSupplierOrderPDF(orderId) {
  const param = typeof orderId === 'string'
    ? `groupId=${encodeURIComponent(orderId)}`
    : `id=${encodeURIComponent(orderId)}`;
  const pdfUrl = `${API_BASE}/bon-commande.php?${param}&pdf=true`;
  window.open(pdfUrl, '_blank');
}

async function deleteQuote(quoteId) {
  if (currentUser?.role !== 'admin') {
    toast('Seul l\'administrateur peut supprimer les devis.');
    return;
  }

  if (!confirm('Êtes-vous sûr de vouloir supprimer ce devis ?')) return;

  try {
    await apiRequest('quotes.php', {
      method: 'DELETE',
      body: JSON.stringify({ id: quoteId })
    });
    toast('Devis supprimé.');
    await loadQuotesHistory();
  } catch (error) {
    console.error('Erreur lors de la suppression du devis:', error);
    toast('Erreur lors de la suppression du devis.');
  }
}

/* ── Actions ── */
function validateAddForm() {
  const n = document.getElementById('f-name').value.trim();
  const unit = document.getElementById('f-unit').value;
  const qty = quantityField('f-qty', unit, 0);
  const min = quantityField('f-min', unit, 0);
  const price = parseFloat(document.getElementById('f-price').value) || 0;
  const conso = quantityField('f-conso', unit, 0);

  if (!n) {
    toast('Veuillez saisir un nom de matériau.');
    document.getElementById('f-name').focus();
    return false;
  }
  if ([qty, min, conso].some(value => invalidQuantity(value, unit))) {
    toast(quantityRuleText(unit));
    return false;
  }
  if (qty < 0 || min < 0 || price < 0 || conso < 0) {
    toast('Les valeurs doivent être positives ou nulles.');
    return false;
  }

  return true;
}

async function addItem() {
  if (!validateAddForm()) return;
  const n = document.getElementById('f-name').value.trim();
  const unit = document.getElementById('f-unit').value;

  try {
    const image = await readImageInput('f-image');
    await apiRequest('materials.php', {
      method: 'POST',
      body: JSON.stringify({
        name: n,
        cat: document.getElementById('f-cat').value,
        qty: quantityField('f-qty', unit, 0),
        min: quantityField('f-min', unit, 0),
        unit,
        price: parseFloat(document.getElementById('f-price').value) || 0,
        conso: quantityField('f-conso', unit, 0),
        supplier: document.getElementById('f-supplier').value.trim(),
        image
      })
    });

    clearForm();
    await refreshData();
    toast('Matériau ajouté avec succès !');
  } catch (error) {
    if (!error?.status) { toast(error.message || 'Image impossible a lire.'); return; }
    showDbError(error);
  }
}

function clearForm() {
  ['f-name','f-supplier'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  ['f-qty','f-min','f-price','f-conso'].forEach(id => { const el=document.getElementById(id); if(el) el.value='0'; });
  const image = document.getElementById('f-image');
  if (image) image.value = '';
  document.getElementById('f-name')?.focus();
}

async function addStockEntry() {
  const iid = parseInt(document.getElementById('in-item').value);
  const item = items.find(i => i.id === iid);
  const qty = quantityField('in-qty', item?.unit);

  if (!item) return;
  if (invalidQuantity(qty, item.unit) || qty <= 0) {
    toast(quantityRuleText(item.unit));
    return;
  }

  try {
    const data = await apiRequest('entries.php', {
      method: 'POST',
      body: JSON.stringify({
        itemId: iid,
        qty,
        supplier: document.getElementById('in-supplier').value.trim(),
        reference: document.getElementById('in-reference').value.trim(),
        date: document.getElementById('in-date').value || todayInputValue(),
        notes: ''
      })
    });

    setItems(data.items || items);
    stockHistory = data.history || stockHistory;
    clearEntryForm();
    renderAll();
    toast('Entrée de stock enregistrée.');
  } catch (error) {
    console.error(error);
    toast(error.message || 'Entrée impossible.');
  }
}

function clearEntryForm() {
  ['in-supplier','in-reference'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  const qty = document.getElementById('in-qty');
  if (qty) qty.value = '1';
  const date = document.getElementById('in-date');
  if (date) date.value = todayInputValue();
}

async function addMovement() {
  const iid = parseInt(document.getElementById('m-item').value);
  const item = items.find(i => i.id === iid);
  const qty = quantityField('m-qty', item?.unit);
  const requester = document.getElementById('m-requester').value.trim();
  const destination = document.getElementById('m-destination').value.trim();

  if (!item) return;
  if (invalidQuantity(qty, item.unit) || qty <= 0) {
    toast(quantityRuleText(item.unit));
    return;
  }
  if (!requester) {
    toast('Veuillez saisir le nom de la personne qui demande.');
    document.getElementById('m-requester').focus();
    return;
  }
  if (!destination) {
    toast('Veuillez saisir le service ou projet.');
    document.getElementById('m-destination').focus();
    return;
  }

  try {
    const data = await apiRequest('movements.php', {
      method: 'POST',
      body: JSON.stringify({
        itemId: iid,
        qty,
        destination: document.getElementById('m-destination').value.trim(),
        requester: document.getElementById('m-requester').value.trim(),
        date: document.getElementById('m-date').value || todayInputValue(),
        notes: ''
      })
    });

    setItems(data.items || items);
    movements = data.movements || movements;
    stockHistory = data.history || stockHistory;
    clearMovementForm();
    renderAll();
    toast('Sortie interne enregistrée.');
  } catch (error) {
    console.error(error);
    toast(error.message || 'Sortie impossible.');
  }
}

function clearMovementForm() {
  ['m-destination','m-requester'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  ['m-destination-global','m-requester-global'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  const qty = document.getElementById('m-qty');
  if (qty) qty.value = '1';
  const date = document.getElementById('m-date');
  if (date) date.value = todayInputValue();
  const dateGlobal = document.getElementById('m-date-global');
  if (dateGlobal) dateGlobal.value = todayInputValue();
  const rows = document.getElementById('movements-rows');
  if (rows) rows.innerHTML = '';
}

function addMovementRow(data = {}) {
  const rows = document.getElementById('movements-rows');
  if (!rows) return;

  movementRowSeq += 1;
  const row = document.createElement('div');
  row.className = 'movement-row';
  row.dataset.rowId = String(movementRowSeq);
  row.innerHTML = `
    <select class="movement-item" aria-label="Materiau" onchange="updateMovementRowStep(this)">
      <option value="">-- Sélectionner un matériau --</option>
      ${items.map(item => `<option value="${item.id}" ${String(item.id) === String(data.itemId || '') ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
    </select>
    <input class="movement-qty" type="number" min="0" step="1" value="${data.qty ?? 1}" placeholder="Quantité" aria-label="Quantite">
    <button class="btn btn-sm btn-icon" type="button" title="Retirer" onclick="removeMovementRow(this)"><i class="fa-solid fa-xmark"></i></button>
  `;
  rows.appendChild(row);
  updateMovementRowStep(row.querySelector('.movement-item'));
}

function removeMovementRow(button) {
  button.closest('.movement-row')?.remove();
}

async function saveBulkMovements() {
  const destination = document.getElementById('m-destination-global')?.value.trim();
  const requester = document.getElementById('m-requester-global')?.value.trim();
  const dateVal = document.getElementById('m-date-global')?.value;

  if (!destination) {
    toast('Veuillez saisir le service ou projet.');
    return;
  }
  if (!requester) {
    toast('Veuillez saisir le nom de la personne qui demande.');
    return;
  }

  const rows = document.querySelectorAll('#movements-rows .movement-row');
  if (!rows.length) {
    toast('Ajoutez au moins un matériau.');
    return;
  }

  const bulk_movements = [];
  for (const row of rows) {
    const itemId = parseInt(row.querySelector('.movement-item')?.value, 10) || 0;
    const item = items.find(i => i.id === itemId);
    const qty = quantityFromValue(row.querySelector('.movement-qty')?.value, item?.unit);

    if (!item) {
      toast('Veuillez selectionner un materiau sur chaque ligne.');
      return;
    }
    if (invalidQuantity(qty, item.unit) || qty <= 0) {
      toast(`Quantite invalide pour ${item.name}. ${quantityRuleText(item.unit)}`);
      return;
    }

    bulk_movements.push({
      itemId: item.id,
      qty
    });
  }

  if (!bulk_movements.length) {
    toast('Aucun matériau valide. Vérifiez les quantités.');
    return;
  }

  try {
    const response = await apiRequest('movements.php', {
      method: 'POST',
      body: JSON.stringify({
        bulk_movements,
        destination,
        requester,
        date: dateVal || todayInputValue()
      })
    });
    
    toast(`${bulk_movements.length} sortie(s) enregistrée(s) avec succès!`);
    clearMovementForm();
    await loadData();
    renderMovements();
  } catch (error) {
    console.error('Erreur lors de l\'enregistrement des sorties:', error);
    const errorMsg = error?.message || 'Erreur inconnue';
    toast(`Erreur: ${errorMsg}`);
  }
}

async function deleteMovement(id) {
  if (!confirm('Annuler cette sortie ? La quantité sera remise en stock.')) return;

  try {
    const data = await apiRequest(`movements.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    setItems(data.items || items);
    movements = data.movements || movements;
    stockHistory = data.history || stockHistory;
    renderAll();
    toast('Sortie annulée et stock corrigé.');
  } catch (error) {
    console.error(error);
    toast(error.message || 'Annulation impossible.');
  }
}

function viewMovement(id) {
  const movement = movements.find(m => m.id === id);
  if (!movement) {
    toast('Sortie introuvable.');
    return;
  }
  
  // Déterminer si c'est une sortie groupée
  const isGrouped = movement.groupId && movement.groupId.length > 0;
  const url = isGrouped
    ? `${API_BASE}/bon-sortie-groupee.php?id=${encodeURIComponent(movement.groupId)}`
    : `${API_BASE}/bon-sortie.php?id=${id}`;
  
  window.open(url, '_blank');
}

function printMovement(id) {
  const movement = movements.find(m => m.id === id);
  if (!movement) {
    toast('Sortie introuvable.');
    return;
  }
  
  // Déterminer si c'est une sortie groupée
  const isGrouped = movement.groupId && movement.groupId.length > 0;
  const url = isGrouped
    ? `${API_BASE}/bon-sortie-groupee.php?id=${encodeURIComponent(movement.groupId)}`
    : `${API_BASE}/bon-sortie.php?id=${id}`;
  
  const printWindow = window.open(url, '_blank');
  if (printWindow) {
    printWindow.addEventListener('load', function() {
      setTimeout(() => {
        printWindow.print();
      }, 500);
    });
  }
}

function downloadMovementPDF(id) {
  const movement = movements.find(m => m.id === id);
  if (!movement) {
    toast('Sortie introuvable.');
    return;
  }
  
  // Déterminer si c'est une sortie groupée
  const isGrouped = movement.groupId && movement.groupId.length > 0;
  const pdfUrl = isGrouped
    ? `${API_BASE}/bon-sortie-groupee.php?id=${encodeURIComponent(movement.groupId)}&pdf=true`
    : `${API_BASE}/bon-sortie.php?id=${id}&pdf=true`;
  
  window.open(pdfUrl, '_blank');
}

function quickMovement(id) {
  showPage('sorties', document.querySelector('.sidebar .nav-item[data-page="sorties"]'));
  setTimeout(() => {
    const sel = document.getElementById('m-item');
    if (sel) {
      sel.value = id;
      updateSingleMovementQuantityStep();
    }
  }, 50);
}

async function deleteItem(id) {
  if (!confirm('Supprimer ce matériau ?')) return;

  try {
    await apiRequest(`materials.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    await refreshData();
    toast('Matériau supprimé.');
  } catch (error) {
    showDbError(error);
  }
}

function editItem(id) {
  const item = items.find(i => i.id === id);
  if (!item) return;
  if (!canEditMaterialQuantity()) {
    toast('Acces refuse pour ce role.');
    return;
  }

  editingItemId = id;
  const fullEdit = canEditMaterialFull();
  document.getElementById('e-name').value = item.name;
  document.getElementById('e-cat').value = item.cat;
  document.getElementById('e-qty').value = item.qty;
  document.getElementById('e-unit').value = item.unit;
  document.getElementById('e-min').value = item.min;
  document.getElementById('e-price').value = item.price;
  document.getElementById('e-conso').value = item.conso;
  document.getElementById('e-supplier').value = item.supplier || '';
  const editImage = document.getElementById('e-image');
  if (editImage) editImage.value = '';
  const removeImage = document.getElementById('e-image-remove');
  if (removeImage) removeImage.value = '0';
  setPreviewButton(document.getElementById('e-image-preview'), materialImage(item), item.name);
  document.querySelector('[onclick="removeEditingImage()"]')?.toggleAttribute('disabled', !fullEdit);
  ['e-name','e-cat','e-unit','e-min','e-price','e-conso','e-supplier','e-image'].forEach(fieldId => {
    const field = document.getElementById(fieldId);
    if (field) field.disabled = !fullEdit;
  });
  const qtyField = document.getElementById('e-qty');
  if (qtyField) qtyField.disabled = false;
  updateEditMaterialQuantitySteps();
  document.getElementById('edit-item-modal').classList.add('show');
  (fullEdit ? document.getElementById('e-name') : document.getElementById('e-qty'))?.focus();
}

function closeEditItemForm() {
  editingItemId = null;
  ['e-name','e-cat','e-qty','e-unit','e-min','e-price','e-conso','e-supplier','e-image'].forEach(fieldId => {
    const field = document.getElementById(fieldId);
    if (field) field.disabled = false;
  });
  document.getElementById('edit-item-modal')?.classList.remove('show');
}

async function saveEditItem() {
  const id = editingItemId;
  const item = items.find(i => i.id === id);
  if (!item) return;

  const name = document.getElementById('e-name').value.trim();
  const cat = document.getElementById('e-cat').value;
  const unit = document.getElementById('e-unit').value.trim();
  const editedQty = quantityField('e-qty', unit || item.unit, 0);

  if (canEditMaterialFull() && !name) {
    toast('Le nom du materiau est obligatoire.');
    return;
  }

  if (canEditMaterialFull() && !CATS.includes(cat)) {
    toast('Categorie inconnue.');
    return;
  }

  if (invalidQuantity(editedQty, unit || item.unit) || editedQty < 0) {
    toast(quantityRuleText(unit || item.unit));
    return;
  }

  const editedMin = canEditMaterialFull() ? quantityField('e-min', unit || item.unit, 0) : item.min;
  const editedConso = canEditMaterialFull() ? quantityField('e-conso', unit || item.unit, 0) : item.conso;
  const editedPrice = parseFloat(document.getElementById('e-price').value) || 0;

  if (canEditMaterialFull() && ([editedMin, editedConso].some(value => invalidQuantity(value, unit || item.unit)) || editedMin < 0 || editedConso < 0 || editedPrice < 0)) {
    toast(`Le seuil et la conso sont invalides. ${quantityRuleText(unit || item.unit)}`);
    return;
  }

  try {
    const payload = canEditMaterialFull()
      ? {
          id,
          name,
          cat,
          qty: editedQty,
          unit: unit || item.unit,
          min: editedMin,
          price: editedPrice,
          conso: editedConso,
          supplier: document.getElementById('e-supplier').value.trim()
        }
      : {
          id,
          qty: editedQty,
          sourceType: 'correction_stock',
          notes: 'Correction quantite par moderateur stock.'
        };

    if (canEditMaterialFull()) {
      const newImage = await readImageInput('e-image');
      if (newImage) {
        payload.image = newImage;
      } else if (document.getElementById('e-image-remove')?.value === '1') {
        payload.image = '';
      }
    }

    await apiRequest('materials.php', {
      method: 'PATCH',
      body: JSON.stringify(payload)
    });

    closeEditItemForm();
    await refreshData();
    toast('Fiche materiau mise a jour.');
  } catch (error) {
    if (error?.status) showDbError(error);
    else toast(error.message || 'Image impossible a lire.');
  }
}

async function addOrder() {
  await saveBulkOrders();
}

function clearOrderForm() {
  ['o-supplier'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  const delay = document.getElementById('o-delay');
  if (delay) delay.value = '7';
  const date = document.getElementById('o-date');
  if (date) date.value = todayInputValue();
  const rows = document.getElementById('orders-rows');
  if (rows) rows.innerHTML = '';
  addOrderRow();
}

function addOrderRow(data = {}) {
  const rows = document.getElementById('orders-rows');
  if (!rows) return;

  orderRowSeq += 1;
  const row = document.createElement('div');
  row.className = 'order-row';
  row.dataset.rowId = String(orderRowSeq);
  row.innerHTML = `
    <select class="order-item" aria-label="Materiau" onchange="fillOrderUnitCost(this); updateOrderRowStep(this)" required>
      <option value="">-- Sélectionner un matériau --</option>
      ${items.map(item => `<option value="${item.id}" ${String(item.id) === String(data.itemId || '') ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
    </select>
    <input class="order-qty" type="number" min="0" step="1" value="${data.qty ?? 1}" placeholder="Quantité" aria-label="Quantite" required>
    <input class="order-unit-cost" type="number" min="0" step="0.01" value="${data.unitCost ?? ''}" placeholder="Coût unitaire" aria-label="Cout unitaire" required>
    <button class="btn btn-sm btn-icon" type="button" title="Retirer" onclick="removeOrderRow(this)"><i class="fa-solid fa-xmark"></i></button>
  `;
  rows.appendChild(row);
  fillOrderUnitCost(row.querySelector('.order-item'), false);
  updateOrderRowStep(row.querySelector('.order-item'));
}

function removeOrderRow(button) {
  button.closest('.order-row')?.remove();
}

function fillOrderUnitCost(select, overwrite = true) {
  const row = select?.closest('.order-row');
  if (!row) return;

  const item = items.find(i => String(i.id) === String(select.value));
  const costInput = row.querySelector('.order-unit-cost');
  if (!costInput || !item) return;

  if (overwrite || costInput.value === '') {
    costInput.value = Number(item.price) || 0;
  }
}

async function saveBulkOrders() {
  const supplier = document.getElementById('o-supplier')?.value.trim();
  const delayRaw = document.getElementById('o-delay')?.value;
  const delay = parseInt(delayRaw, 10);
  const date = document.getElementById('o-date')?.value;

  if (!supplier) {
    toast('Veuillez saisir le fournisseur.');
    return;
  }
  if (!date) {
    toast('Veuillez saisir la date de commande.');
    return;
  }
  if (!delayRaw || !Number.isFinite(delay) || delay <= 0) {
    toast('Veuillez saisir un délai valide.');
    return;
  }

  const rows = document.querySelectorAll('#orders-rows .order-row');
  if (!rows.length) {
    toast('Ajoutez au moins un matériau.');
    return;
  }

  const bulk_orders = [];
  for (const row of rows) {
    const itemId = parseInt(row.querySelector('.order-item')?.value, 10) || 0;
    const qtyRaw = row.querySelector('.order-qty')?.value;
    const unitCostRaw = row.querySelector('.order-unit-cost')?.value;
    const unitCost = parseFloat(unitCostRaw);
    const item = items.find(i => i.id === itemId);
    const qty = quantityFromValue(qtyRaw, item?.unit);

    if (!item) {
      toast('Veuillez sélectionner un matériau sur chaque ligne.');
      return;
    }
    if (!qtyRaw || invalidQuantity(qty, item.unit) || qty <= 0) {
      toast(`Quantite invalide pour ${item.name}. ${quantityRuleText(item.unit)}`);
      return;
    }
    if (unitCostRaw === '' || !Number.isFinite(unitCost) || unitCost < 0) {
      toast('Veuillez saisir un coût unitaire valide sur chaque ligne.');
      return;
    }

    bulk_orders.push({ itemId: item.id, qty, cost: +(qty * unitCost).toFixed(2), unitCost });
  }

  if (!bulk_orders.length) {
    toast('Aucun matériau valide. Vérifiez les quantités.');
    return;
  }

  try {
    await apiRequest('orders.php', {
      method: 'POST',
      body: JSON.stringify({ bulk_orders, supplier, delay, date, notes: '' })
    });

    clearOrderForm();
    await refreshData();
    toast(`${bulk_orders.length} article(s) commandé(s).`);
  } catch (error) {
    showDbError(error);
  }
}

async function receiveOrder(oid) {
  try {
    await apiRequest('orders.php', {
      method: 'PATCH',
      body: JSON.stringify({ id: oid, status: 'reçu' })
    });

    await refreshData();
    toast('Commande réceptionnée — stock mis à jour !');
  } catch (error) {
    showDbError(error);
  }
}

async function receiveOrderGroup(groupId) {
  try {
    await apiRequest('orders.php', {
      method: 'PATCH',
      body: JSON.stringify({ groupId, status: 'reçu' })
    });

    await refreshData();
    toast('Commande groupée réceptionnée — stock mis à jour !');
  } catch (error) {
    showDbError(error);
  }
}

async function deleteOrder(oid) {
  if (!confirm('Supprimer cette commande ?')) return;

  try {
    await apiRequest(`orders.php?id=${encodeURIComponent(oid)}`, { method: 'DELETE' });
    await refreshData();
    toast('Commande supprimée.');
  } catch (error) {
    showDbError(error);
  }
}

async function deleteOrderGroup(groupId) {
  if (!confirm('Supprimer cette commande groupée ?')) return;

  try {
    await apiRequest(`orders.php?groupId=${encodeURIComponent(groupId)}`, { method: 'DELETE' });
    await refreshData();
    toast('Commande groupée supprimée.');
  } catch (error) {
    showDbError(error);
  }
}

function quickOrder(id) {
  showPage('commandes', document.querySelector('.sidebar .nav-item[data-page="commandes"]'));
  setTimeout(() => {
    const sel = document.querySelector('#orders-rows .order-item');
    if (sel) {
      sel.value = id;
      fillOrderUnitCost(sel);
      updateOrderRowStep(sel);
    }
  }, 50);
}

function supplierPayload() {
  return {
    id: editingSupplierId,
    name: document.getElementById('sp-name').value.trim(),
    contact: document.getElementById('sp-contact').value.trim(),
    phone: document.getElementById('sp-phone').value.trim(),
    email: document.getElementById('sp-email').value.trim(),
    address: document.getElementById('sp-address').value.trim(),
    leadTime: parseInt(document.getElementById('sp-delay').value) || 7,
    products: document.getElementById('sp-products').value.trim(),
    notes: ''
  };
}

async function saveSupplier() {
  const payload = supplierPayload();
  if (!payload.name) {
    toast('Le nom du fournisseur est obligatoire.');
    return;
  }

  try {
    const data = await apiRequest('suppliers.php', {
      method: editingSupplierId ? 'PUT' : 'POST',
      body: JSON.stringify(payload)
    });

    suppliers = data.suppliers || suppliers;
    clearSupplierForm();
    renderSuppliers();
    toast('Fournisseur sauvegardé.');
  } catch (error) {
    console.error(error);
    toast(error.message || 'Sauvegarde fournisseur impossible.');
  }
}

function editSupplier(id) {
  const supplier = suppliers.find(s => s.id === id);
  if (!supplier) return;

  editingSupplierId = id;
  document.getElementById('sp-name').value = supplier.name;
  document.getElementById('sp-contact').value = supplier.contact;
  document.getElementById('sp-phone').value = supplier.phone;
  document.getElementById('sp-email').value = supplier.email;
  document.getElementById('sp-address').value = supplier.address;
  document.getElementById('sp-delay').value = supplier.leadTime;
  document.getElementById('sp-products').value = supplier.products;
  document.getElementById('sp-save-label').textContent = 'Mettre à jour';
}

function clearSupplierForm() {
  editingSupplierId = null;
  ['sp-name','sp-contact','sp-phone','sp-email','sp-address','sp-products'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const delay = document.getElementById('sp-delay');
  if (delay) delay.value = '7';
  const label = document.getElementById('sp-save-label');
  if (label) label.textContent = 'Enregistrer';
}

async function deleteSupplier(id) {
  if (!confirm('Supprimer ce fournisseur ?')) return;

  try {
    const data = await apiRequest(`suppliers.php?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    suppliers = data.suppliers || suppliers;
    renderSuppliers();
    toast('Fournisseur supprimé.');
  } catch (error) {
    console.error(error);
    toast(error.message || 'Suppression fournisseur impossible.');
  }
}

async function saveParams() {
  params.atelier = document.getElementById('p-atelier').value || 'Mon Atelier';
  params.devise = document.getElementById('p-devise').value;

  try {
    const data = await apiRequest('settings.php', {
      method: 'PUT',
      body: JSON.stringify(params)
    });

    params = data.params || params;
    syncParamsForm();
    toast('Paramètres sauvegardés.');
  } catch (error) {
    showDbError(error);
  }
}

async function resetData() {
  if (!confirm('Réinitialiser toutes les données ? Cette action est irréversible.')) return;

  try {
    const data = await apiRequest('reset.php', { method: 'POST' });
    setItems(data.items || []);
    orders = data.orders || [];
    movements = data.movements || [];
    stockHistory = data.history || [];
    suppliers = data.suppliers || [];
    params = data.params || params;
    syncParamsForm();
    renderAll();
    toast('Données réinitialisées.');
  } catch (error) {
    showDbError(error);
  }
}

function exportCSV() {
  const headers = ['Nom','Catégorie','Quantité','Unité','Seuil','Prix unitaire','Valeur','Fournisseur','Statut'];
  const rows = items.map(i => [i.name,i.cat,i.qty,i.unit,i.min,i.price,i.qty*i.price,i.supplier||'',status(i)]);
  const csv = [headers, ...rows].map(r => r.map(v => `"${v}"`).join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
  a.download = 'stock_atelier_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  toast('Export CSV téléchargé !');
}

async function clearHistory() {
  if (!confirm('Vider tout l\'historique ? Le stock actuel ne sera pas modifié.')) return;

  try {
    const data = await apiRequest('history.php', { method: 'DELETE' });
    stockHistory = data.history || [];
    renderHistory();
    renderEntries();
    toast('Historique vidé.');
  } catch (error) {
    console.error(error);
    toast(error.message || 'Suppression de l\'historique impossible.');
  }
}

/* ── Navigation ── */
function showPage(name, btn) {
  if (!isValidPage(name)) name = defaultPageForRole();

  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.getElementById('p-' + name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
  const navMatches = document.querySelectorAll(`.nav-item[data-page="${name}"]`);
  navMatches.forEach(b => b.classList.add('active'));
  if (btn) btn.classList.add('active');

  localStorage.setItem(ACTIVE_PAGE_KEY, name);
  if (window.location.hash !== '#' + name) {
    history.replaceState(null, '', '#' + name);
  }

  if (name === 'ajout') {
    document.getElementById('f-name')?.focus();
  }
  if (name === 'stats') renderStats();
  if (name === 'commandes') renderOrders();
  if (name === 'entrees') renderEntries();
  if (name === 'sorties') renderMovements();
  if (name === 'historique') renderHistory();
  if (name === 'fournisseurs') renderSuppliers();
  if (name === 'simulation') {
    renderQuoteBuilder();
    loadQuotesHistory();
  }
}

window.addEventListener('hashchange', () => {
  const target = window.location.hash.replace('#', '').trim();
  if (isValidPage(target)) showPage(target);
});

function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

function renderAll() {
  renderDashboard();
  renderStock();
  renderEntries();
  renderMovements();
  renderHistory();
  renderSuppliers();
  renderOrders();
  renderQuoteBuilder();
  if (document.getElementById('p-stats')?.classList.contains('active')) renderStats();
  if (document.getElementById('p-simulation')?.classList.contains('active')) loadQuotesHistory();
}

/* ── Init ── */
async function init() {
  ensureMaterialImageUi();
  updateAddMaterialQuantitySteps();
  updateEditMaterialQuantitySteps();
  document.getElementById('d-date').textContent = new Date().toLocaleDateString('fr-FR', {weekday:'long',day:'numeric',month:'long',year:'numeric'});
  clearMovementForm();
  clearEntryForm();
  clearSupplierForm();

  try {
    const auth = await apiRequest('auth.php');
    if (!auth.user) {
      showLogin('');
      return;
    }

    currentUser = auth.user;
    await loadData();
    renderAll();
    applyRoleUi();
    showPage(savedPage());
  } catch (error) {
    if (error?.status === 401) {
      showLogin('');
      return;
    }

    showLogin(error.message || 'Connexion impossible.');
  }
}

init();
