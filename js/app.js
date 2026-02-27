/**
 * GSB Reservation - Application Frontend
 * @version 2.0.0
 */

'use strict';

// ========================================
// Configuration et variables globales
// ========================================

const API_BASE = 'api';
let currentUser = null;
let rooms = [];
let bookings = [];
let users = [];
let buildings = [];
let currentWeekOffset = 0;
let currentFilter = 'all';
let csrfToken = null;
let logs = [];
let logsPage = 1;
let logsTotal = 0;

// ========================================
// Utilitaires API
// ========================================

async function api(endpoint, method = 'GET', data = null) {
    // Envoyer PUT/DELETE via POST avec _method pour compatibilite hebergeurs
    let actualMethod = method;
    if (method === 'PUT' || method === 'DELETE') {
        actualMethod = 'POST';
        data = data || {};
        data._method = method;
    }

    const config = {
        method: actualMethod,
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'include'
    };

    if (csrfToken) {
        config.headers['X-CSRF-Token'] = csrfToken;
    }

    if (data) {
        config.body = JSON.stringify(data);
    }

    try {
        const res = await fetch(`${API_BASE}/${endpoint}`, config);
        const result = await res.json();

        if (!res.ok) {
            throw new Error(result.error || 'Erreur inconnue');
        }

        if (result.csrf_token) {
            csrfToken = result.csrf_token;
        }

        return result;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

// ========================================
// Gestion de l'authentification
// ========================================

function showAuthForm(formType) {
    document.querySelectorAll('.auth-form').forEach(form => {
        form.classList.add('hidden');
        form.setAttribute('aria-hidden', 'true');
    });

    const targetForm = document.getElementById(formType + 'Form');
    if (targetForm) {
        targetForm.classList.remove('hidden');
        targetForm.setAttribute('aria-hidden', 'false');
        targetForm.querySelector('input')?.focus();
    }
}

async function handleLogin(event) {
    event.preventDefault();

    const btn = document.getElementById('loginBtn');
    const email = document.getElementById('loginEmail').value;
    const password = document.getElementById('loginPassword').value;

    setButtonLoading(btn, true);

    try {
        const result = await api('auth.php?action=login', 'POST', { email, password });

        if (result.success) {
            currentUser = result.user;
            csrfToken = result.csrf_token;
            showToast('Connexion reussie', 'success');
            showApp();
            initApp();
        }
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        setButtonLoading(btn, false, 'Se connecter');
    }
}

async function handleRegister(event) {
    event.preventDefault();

    const btn = document.getElementById('registerBtn');
    const nom = document.getElementById('registerNom').value;
    const prenom = document.getElementById('registerPrenom').value;
    const email = document.getElementById('registerEmail').value;
    const password = document.getElementById('registerPassword').value;

    setButtonLoading(btn, true);

    try {
        const result = await api('auth.php?action=register', 'POST', {
            nom, prenom, email, password
        });

        if (result.success) {
            currentUser = result.user;
            csrfToken = result.csrf_token;
            showToast('Compte cree avec succes', 'success');
            showApp();
            initApp();
        }
    } catch (error) {
        if (error.message.includes('Mot de passe')) {
            showToast('Mot de passe trop faible. Minimum 12 caracteres avec majuscule, minuscule, chiffre et caractere special.', 'error');
        } else {
            showToast(error.message, 'error');
        }
    } finally {
        setButtonLoading(btn, false, 'Creer mon compte');
    }
}

async function handleLogout() {
    try {
        await api('auth.php?action=logout', 'POST');
    } catch (error) {
        console.error('Logout error:', error);
    }

    currentUser = null;
    csrfToken = null;
    // Cacher le menu admin au logout
    document.getElementById('adminNav').classList.add('hidden');
    showAuth();
    showToast('Deconnexion reussie', 'success');
}

async function checkSession() {
    try {
        const result = await api('auth.php?action=session');

        if (result.authenticated) {
            currentUser = result.user;
            csrfToken = result.csrf_token;
            showApp();
            initApp();
        }
    } catch (error) {
        console.error('Session check error:', error);
    }
}

// ========================================
// Navigation et affichage
// ========================================

function showAuth() {
    document.getElementById('appContainer').classList.add('hidden');
    document.getElementById('appContainer').setAttribute('aria-hidden', 'true');
    document.getElementById('authContainer').classList.remove('hidden');
    document.getElementById('authContainer').setAttribute('aria-hidden', 'false');
}

function showApp() {
    document.getElementById('authContainer').classList.add('hidden');
    document.getElementById('authContainer').setAttribute('aria-hidden', 'true');
    document.getElementById('appContainer').classList.remove('hidden');
    document.getElementById('appContainer').setAttribute('aria-hidden', 'false');
}

function showPage(pageName) {
    // Protection : bloquer l'acces aux pages admin pour les non-admins
    const adminPages = ['admin-rooms', 'admin-users', 'admin-buildings', 'admin-logs'];
    if (adminPages.includes(pageName) && (!currentUser || currentUser.role !== 'Admin')) {
        showToast('Acces reserve aux administrateurs', 'error');
        showPage('dashboard');
        return;
    }

    // Masquer toutes les pages
    document.querySelectorAll('.page').forEach(page => {
        page.classList.remove('active');
        page.setAttribute('aria-hidden', 'true');
    });

    // Afficher la page cible
    const targetPage = document.getElementById('page-' + pageName);
    if (targetPage) {
        targetPage.classList.add('active');
        targetPage.setAttribute('aria-hidden', 'false');
    }

    // Mettre a jour la navigation
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
        item.setAttribute('aria-current', 'false');
    });

    const activeNavItem = document.querySelector(`[data-page="${pageName}"]`);
    if (activeNavItem) {
        activeNavItem.classList.add('active');
        activeNavItem.setAttribute('aria-current', 'page');
    }

    // Mettre a jour le titre
    const titles = {
        dashboard: ['Dashboard', `Bienvenue, ${currentUser?.prenom || ''}`],
        rooms: ['Salles', 'Nos espaces de reunion'],
        calendar: ['Planning', 'Visualisez les disponibilites'],
        mybookings: ['Mes reservations', 'Gerez vos reservations'],
        'admin-rooms': ['Gestion des salles', 'Administration'],
        'admin-users': ['Utilisateurs', 'Administration'],
        'admin-buildings': ['Batiments', 'Administration'],
        'admin-logs': ['Logs', 'Journal d\'activite']
    };

    if (titles[pageName]) {
        document.getElementById('pageTitle').textContent = titles[pageName][0];
        document.getElementById('pageSubtitle').textContent = titles[pageName][1];
    }

    // Charger les donnees specifiques
    switch (pageName) {
        case 'rooms':
            renderRooms();
            break;
        case 'calendar':
            renderCalendar();
            break;
        case 'mybookings':
            renderMyBookings();
            break;
        case 'admin-rooms':
            renderAdminRooms();
            break;
        case 'admin-users':
            renderAdminUsers();
            break;
        case 'admin-buildings':
            renderAdminBuildings();
            break;
        case 'admin-logs':
            loadLogs();
            break;
    }

    // Fermer le sidebar sur mobile
    toggleSidebar(true);
}

function toggleSidebar(forceClose = false) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const isOpen = sidebar.classList.contains('translate-x-0');

    if (forceClose || isOpen) {
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
        sidebar.setAttribute('aria-hidden', 'true');
    } else {
        sidebar.classList.add('translate-x-0');
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
        sidebar.setAttribute('aria-hidden', 'false');
    }
}

// ========================================
// Initialisation de l'application
// ========================================

async function initApp() {
    // Mettre a jour les infos utilisateur
    const fullName = `${currentUser.prenom} ${currentUser.nom}`;
    document.getElementById('userName').textContent = fullName;
    document.getElementById('userRole').textContent = currentUser.role;
    document.getElementById('pageSubtitle').textContent = `Bienvenue, ${currentUser.prenom}`;

    // Avatar colore de l'utilisateur connecte
    const avatarContainer = document.getElementById('userAvatar');
    const gradient = getAvatarGradient(fullName);
    avatarContainer.className = `w-10 h-10 rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center text-white font-semibold`;
    avatarContainer.textContent = getInitials(fullName);

    // Afficher/masquer le menu admin selon le role
    if (currentUser.role === 'Admin') {
        document.getElementById('adminNav').classList.remove('hidden');
    } else {
        document.getElementById('adminNav').classList.add('hidden');
    }

    // Charger les donnees
    await Promise.all([
        loadRooms(),
        loadBookings(),
        loadBuildings()
    ]);

    if (currentUser.role === 'Admin') {
        loadUsers();
    }

    renderDashboard();
}

// ========================================
// Chargement des donnees
// ========================================

async function loadRooms() {
    try {
        rooms = await api('rooms.php');
    } catch (error) {
        console.error('Error loading rooms:', error);
        rooms = [];
    }
}

async function loadBookings() {
    try {
        bookings = await api('bookings.php?mine=true');
    } catch (error) {
        console.error('Error loading bookings:', error);
        bookings = [];
    }
}

async function loadAllBookings() {
    try {
        return await api('bookings.php?all=true');
    } catch (error) {
        console.error('Error loading all bookings:', error);
        return [];
    }
}

async function loadUsers() {
    try {
        users = await api('users.php');
    } catch (error) {
        console.error('Error loading users:', error);
        users = [];
    }
}

async function loadBuildings() {
    try {
        buildings = await api('buildings.php');
    } catch (error) {
        console.error('Error loading buildings:', error);
        buildings = [];
    }
}

// ========================================
// Rendu du Dashboard
// ========================================

function renderDashboard() {
    const availableRooms = rooms.filter(r => r.status === 'available').length;
    const today = new Date().toISOString().split('T')[0];
    const todayBookings = bookings.filter(b => b.date === today && b.status !== 'cancelled');
    const pendingCount = bookings.filter(b => b.status === 'pending').length;
    const confirmedCount = bookings.filter(b => b.status === 'confirmed').length;

    document.getElementById('statsCards').innerHTML = `
        <div class="glass-card rounded-2xl p-5 border border-dark-700/50 hover-lift" role="region" aria-label="Salles disponibles">
            <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center mb-3" aria-hidden="true">
                <svg class="w-5 h-5 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16"/></svg>
            </div>
            <p class="text-2xl font-bold text-white">${availableRooms}</p>
            <p class="text-sm text-dark-400">Salles disponibles</p>
        </div>
        <div class="glass-card rounded-2xl p-5 border border-dark-700/50 hover-lift" role="region" aria-label="Reservations aujourd'hui">
            <div class="w-10 h-10 rounded-xl bg-violet-500/10 flex items-center justify-center mb-3" aria-hidden="true">
                <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <p class="text-2xl font-bold text-white">${todayBookings.length}</p>
            <p class="text-sm text-dark-400">Aujourd'hui</p>
        </div>
        <div class="glass-card rounded-2xl p-5 border border-dark-700/50 hover-lift" role="region" aria-label="Reservations en attente">
            <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center mb-3" aria-hidden="true">
                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl font-bold text-white">${pendingCount}</p>
            <p class="text-sm text-dark-400">En attente</p>
        </div>
        <div class="glass-card rounded-2xl p-5 border border-dark-700/50 hover-lift" role="region" aria-label="Reservations confirmees">
            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center mb-3" aria-hidden="true">
                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl font-bold text-white">${confirmedCount}</p>
            <p class="text-sm text-dark-400">Confirmees</p>
        </div>
    `;

    // Salles disponibles
    const availableRoomsHtml = rooms
        .filter(r => r.status === 'available')
        .slice(0, 4)
        .map(r => `
            <div class="glass-card rounded-2xl border border-dark-700/50 p-5 hover-lift">
                <div class="flex justify-between mb-3">
                    <div>
                        <h3 class="font-semibold text-white">${escapeHtml(r.name)}</h3>
                        <p class="text-sm text-dark-400">${r.capacity} pers.</p>
                    </div>
                    <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-emerald-500/20 text-emerald-400">Disponible</span>
                </div>
                <button onclick="openBookingModal(${r.id})" class="w-full py-2 bg-primary-500/10 hover:bg-primary-500/20 text-primary-400 font-medium rounded-xl transition-colors" aria-label="Reserver la salle ${escapeHtml(r.name)}">
                    Reserver
                </button>
            </div>
        `).join('');

    document.getElementById('availableRoomsGrid').innerHTML = availableRoomsHtml || '<p class="text-dark-400 col-span-2">Aucune salle disponible</p>';

    // Planning du jour
    const scheduleHtml = todayBookings.length
        ? todayBookings
            .sort((a, b) => a.start.localeCompare(b.start))
            .map(b => `
                <div class="flex items-center gap-3 p-3 bg-dark-800/50 rounded-xl">
                    <div class="w-1 h-12 rounded-full ${b.status === 'confirmed' ? 'bg-emerald-500' : 'bg-amber-500'}" aria-hidden="true"></div>
                    <div>
                        <p class="text-sm font-medium text-white">${escapeHtml(b.subject)}</p>
                        <p class="text-xs text-dark-400">${escapeHtml(b.roomName)} - ${b.start}-${b.end}</p>
                    </div>
                </div>
            `).join('')
        : '<p class="text-dark-400 text-center py-8">Aucune reservation aujourd\'hui</p>';

    document.getElementById('todaySchedule').innerHTML = scheduleHtml;
}

// ========================================
// Rendu des salles
// ========================================

function renderRooms() {
    renderEquipmentFilter();

    const filteredRooms = filterRoomsData();

    document.getElementById('roomsGrid').innerHTML = filteredRooms.map(r => `
        <article class="glass-card rounded-2xl border border-dark-700/50 p-5 hover-lift" aria-labelledby="room-${r.id}">
            <div class="flex justify-between mb-3">
                <div>
                    <h3 id="room-${r.id}" class="font-semibold text-white">${escapeHtml(r.name)}</h3>
                    <p class="text-sm text-dark-400">${r.building ? escapeHtml(r.building) + ' - ' : ''}Etage ${r.floor || 1} - ${r.capacity} pers.</p>
                </div>
                <span class="px-2.5 py-1 text-xs font-medium rounded-full ${getStatusClass(r.status)}" role="status">
                    ${getStatusText(r.status)}
                </span>
            </div>
            <p class="text-sm text-dark-400 mb-4">${escapeHtml(r.description || '')}</p>
            <div class="flex flex-wrap gap-2 mb-4" role="list" aria-label="Equipements">
                ${(r.equipment || []).map(e => `<span class="px-2 py-1 text-xs bg-dark-700/50 text-dark-300 rounded-lg" role="listitem">${escapeHtml(e)}</span>`).join('')}
            </div>
            ${r.status === 'available'
                ? `<button onclick="openBookingModal(${r.id})" class="w-full py-2.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white font-medium rounded-xl transition-opacity hover:opacity-90" aria-label="Reserver ${escapeHtml(r.name)}">Reserver</button>`
                : `<button disabled class="w-full py-2.5 bg-dark-700 text-dark-400 rounded-xl cursor-not-allowed" aria-disabled="true">${r.status === 'occupied' ? 'Occupee' : 'En maintenance'}</button>`
            }
        </article>
    `).join('');
}

function renderEquipmentFilter() {
    const container = document.getElementById('filterEquipment');
    if (!container) return;

    const allEquipment = [...new Set(rooms.flatMap(r => r.equipment || []))].sort();

    const currentCheckboxes = container.querySelectorAll('input[name="filterEquip"]');
    const currentValues = [...currentCheckboxes].map(cb => cb.value);
    if (JSON.stringify(currentValues) === JSON.stringify(allEquipment)) return;

    container.innerHTML = allEquipment.map(eq => `
        <label class="flex items-center gap-2 px-3 py-1.5 bg-dark-800 border border-dark-700 rounded-lg cursor-pointer hover:border-primary-500/50 transition-colors">
            <input type="checkbox" name="filterEquip" value="${escapeHtml(eq)}" onchange="filterRooms()" class="w-3.5 h-3.5 rounded bg-dark-700 border-dark-600 text-primary-500 focus:ring-primary-500">
            <span class="text-xs text-dark-300">${escapeHtml(eq)}</span>
        </label>
    `).join('');
}

function filterRoomsData() {
    const searchTerm = document.getElementById('searchRooms')?.value?.toLowerCase() || '';
    const capacityFilter = document.getElementById('filterCapacity')?.value || '';
    const statusFilter = document.getElementById('filterStatus')?.value || '';

    const selectedEquipment = Array.from(document.querySelectorAll('input[name="filterEquip"]:checked'))
        .map(cb => cb.value);

    return rooms.filter(r => {
        const matchesSearch = r.name.toLowerCase().includes(searchTerm) ||
                             (r.description || '').toLowerCase().includes(searchTerm);
        const matchesCapacity = !capacityFilter ||
            (capacityFilter === 'small' && r.capacity <= 6) ||
            (capacityFilter === 'medium' && r.capacity > 6 && r.capacity <= 15) ||
            (capacityFilter === 'large' && r.capacity > 15);
        const matchesStatus = !statusFilter || r.status === statusFilter;

        const matchesEquipment = selectedEquipment.length === 0 ||
            selectedEquipment.every(eq => (r.equipment || []).includes(eq));

        return matchesSearch && matchesCapacity && matchesStatus && matchesEquipment;
    });
}

function filterRooms() {
    renderRooms();
}

// ========================================
// Rendu du calendrier
// ========================================

async function renderCalendar() {
    const allBookings = await loadAllBookings();
    const dayNames = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    const months = ['Janvier', 'Fevrier', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Aout', 'Septembre', 'Octobre', 'Novembre', 'Decembre'];

    const HOUR_HEIGHT = 50;
    const START_HOUR = 8;
    const END_HOUR = 19;
    const TOTAL_HOURS = END_HOUR - START_HOUR;

    const today = new Date();
    const monday = new Date(today);
    monday.setDate(today.getDate() - ((today.getDay() + 6) % 7) + currentWeekOffset * 7);

    document.getElementById('calendarTitle').textContent = `${months[monday.getMonth()]} ${monday.getFullYear()}`;

    // En-tetes : cellule vide + 7 jours
    let html = '<div class="bg-dark-800/50 p-3"></div>';
    const dayDates = [];

    for (let d = 0; d < 7; d++) {
        const date = new Date(monday);
        date.setDate(monday.getDate() + d);
        dayDates.push(date);
        const isToday = date.toDateString() === today.toDateString();

        html += `
            <div class="bg-dark-800/50 p-3 text-center">
                <div class="text-xs text-dark-400">${dayNames[d]}</div>
                <div class="text-lg font-semibold ${isToday ? 'text-primary-400' : 'text-white'}">${date.getDate()}</div>
            </div>
        `;
    }

    // Colonne des heures
    html += '<div>';
    for (let h = 0; h < TOTAL_HOURS; h++) {
        html += `<div style="height:${HOUR_HEIGHT}px" class="text-xs text-dark-400 text-right pr-3 pt-0.5 border-t border-dark-700/30">${String(START_HOUR + h).padStart(2, '0')}:00</div>`;
    }
    html += '</div>';

    // 7 colonnes de jours avec reservations en position absolue
    for (let d = 0; d < 7; d++) {
        const dateStr = dayDates[d].toISOString().split('T')[0];
        const dayBookings = allBookings.filter(b => b.date === dateStr && b.status !== 'cancelled');

        // Lignes de fond (grille horaire)
        let gridLines = '';
        for (let h = 0; h < TOTAL_HOURS; h++) {
            gridLines += `<div style="height:${HOUR_HEIGHT}px" class="border-t border-dark-700/30"></div>`;
        }

        // Reservations positionnees en absolu
        let bookingsHtml = '';
        dayBookings.forEach(b => {
            const [sh, sm] = b.start.split(':').map(Number);
            const [eh, em] = b.end.split(':').map(Number);

            // Borner dans la plage visible
            const startMinutes = Math.max((sh - START_HOUR) * 60 + sm, 0);
            const endMinutes = Math.min((eh - START_HOUR) * 60 + em, TOTAL_HOURS * 60);
            if (endMinutes <= startMinutes) return;

            const top = (startMinutes / 60) * HOUR_HEIGHT;
            const height = ((endMinutes - startMinutes) / 60) * HOUR_HEIGHT;

            const colorClass = b.status === 'confirmed'
                ? 'bg-primary-500/20 border-primary-500/40 hover:bg-primary-500/30'
                : 'bg-amber-500/20 border-amber-500/40 hover:bg-amber-500/30';

            bookingsHtml += `
                <div class="absolute left-0.5 right-0.5 ${colorClass} border rounded-lg px-2 py-0.5 overflow-hidden transition-colors cursor-default z-10"
                     style="top:${top}px;height:${Math.max(height - 2, 18)}px"
                     title="${escapeHtml(b.subject)} - ${escapeHtml(b.roomName)} (${b.start}-${b.end})">
                    <p class="text-xs font-medium text-white truncate">${escapeHtml(b.roomName)}</p>
                    ${height >= 38 ? `<p class="text-xs text-dark-300 truncate">${escapeHtml(b.subject)}</p>` : ''}
                    ${height >= 55 ? `<p class="text-xs text-dark-400">${b.start} - ${b.end}</p>` : ''}
                </div>`;
        });

        html += `<div class="relative bg-dark-900/30">${gridLines}${bookingsHtml}</div>`;
    }

    document.getElementById('calendarGrid').innerHTML = html;
}

function changeWeek(delta) {
    currentWeekOffset += delta;
    renderCalendar();
}

function goToToday() {
    currentWeekOffset = 0;
    renderCalendar();
}

// ========================================
// Rendu des reservations
// ========================================

function renderMyBookings() {
    const filtered = currentFilter === 'all'
        ? bookings
        : bookings.filter(b => b.status === currentFilter);

    const rows = filtered.length
        ? filtered.map(b => `
            <tr class="hover:bg-dark-800/30">
                <td class="px-6 py-4 text-sm font-medium text-white">${escapeHtml(b.ref)}</td>
                <td class="px-6 py-4 text-sm text-dark-200">${escapeHtml(b.roomName)}</td>
                <td class="px-6 py-4 text-sm text-dark-200">${formatDate(b.date)}</td>
                <td class="px-6 py-4 text-sm text-dark-200">${b.start}-${b.end}</td>
                <td class="px-6 py-4">
                    <span class="px-2.5 py-1 text-xs font-medium rounded-full ${getBookingStatusClass(b.status)}">
                        ${getBookingStatusText(b.status)}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-dark-200">${escapeHtml(b.lastAction || 'Creee')}</td>
                <td class="px-6 py-4">
                    ${b.status !== 'cancelled'
                        ? `<button onclick="cancelBooking(${b.id})" class="text-sm text-rose-400 hover:text-rose-300 focus:outline-none focus:ring-2 focus:ring-rose-500 rounded" aria-label="Annuler la reservation ${escapeHtml(b.ref)}">Annuler</button>`
                        : '-'
                    }
                </td>
            </tr>
        `).join('')
        : '<tr><td colspan="7" class="px-6 py-12 text-center text-dark-400">Aucune reservation</td></tr>';

    document.getElementById('myBookingsTable').innerHTML = rows;
}

function filterMyBookings(filter, evt) {
    currentFilter = filter;

    document.querySelectorAll('.booking-filter').forEach(btn => {
        btn.classList.remove('active');
        btn.setAttribute('aria-pressed', 'false');
    });

    if (evt && evt.target) {
        evt.target.classList.add('active');
        evt.target.setAttribute('aria-pressed', 'true');
    }

    renderMyBookings();
}

// ========================================
// Administration - Salles
// ========================================

function renderAdminRooms() {
    populateBuildingSelect();

    document.getElementById('adminRoomsTable').innerHTML = rooms.map(r => `
        <tr class="hover:bg-dark-800/30">
            <td class="px-6 py-4">
                <p class="font-medium text-white">${escapeHtml(r.name)}</p>
                <p class="text-sm text-dark-400">${r.building ? escapeHtml(r.building) + ' - ' : ''}Etage ${r.floor || 1}</p>
            </td>
            <td class="px-6 py-4 text-sm text-dark-200">${r.capacity} pers.</td>
            <td class="px-6 py-4">
                <span class="px-2.5 py-1 text-xs font-medium rounded-full ${getStatusClass(r.status)}">
                    ${getStatusText(r.status)}
                </span>
            </td>
            <td class="px-6 py-4">
                <div class="flex gap-2">
                    <button onclick="openEditRoom(${r.id})" class="p-2 hover:bg-primary-500/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" title="Modifier" aria-label="Modifier ${escapeHtml(r.name)}">
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button onclick="toggleRoomStatus(${r.id}, '${r.status}')" class="p-2 hover:bg-dark-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" title="Changer l'etat" aria-label="Changer l'etat de ${escapeHtml(r.name)}">
                        <svg class="w-4 h-4 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button onclick="deleteRoom(${r.id})" class="p-2 hover:bg-rose-500/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-rose-500" title="Supprimer" aria-label="Supprimer ${escapeHtml(r.name)}">
                        <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

// ========================================
// Administration - Utilisateurs
// ========================================

function renderAdminUsers() {
    document.getElementById('adminUsersTable').innerHTML = users.map(u => `
        <tr class="hover:bg-dark-800/30">
            <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                    ${avatarHtml(u.name)}
                    <span class="font-medium text-white">${escapeHtml(u.name)}</span>
                </div>
            </td>
            <td class="px-6 py-4 text-sm text-dark-200">${escapeHtml(u.email)}</td>
            <td class="px-6 py-4">
                <span class="px-2.5 py-1 text-xs font-medium rounded-full ${u.role === 'Admin' ? 'bg-violet-500/20 text-violet-400' : u.role === 'Delegue' ? 'bg-primary-500/20 text-primary-400' : 'bg-dark-600 text-dark-300'}">
                    ${escapeHtml(u.role)}
                </span>
            </td>
            <td class="px-6 py-4">
                <div class="flex gap-2">
                    <button onclick="openEditUser(${u.id})" class="p-2 hover:bg-primary-500/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" title="Modifier" aria-label="Modifier ${escapeHtml(u.name)}">
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button onclick="deleteUser(${u.id})" class="p-2 hover:bg-rose-500/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-rose-500" title="Supprimer" aria-label="Supprimer ${escapeHtml(u.name)}">
                        <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

// ========================================
// Administration - Batiments
// ========================================

function renderAdminBuildings() {
    document.getElementById('adminBuildingsTable').innerHTML = buildings.map(b => `
        <tr class="hover:bg-dark-800/30">
            <td class="px-6 py-4">
                <p class="font-medium text-white">${escapeHtml(b.name)}</p>
            </td>
            <td class="px-6 py-4 text-sm text-dark-200">${escapeHtml(b.address || '-')}</td>
            <td class="px-6 py-4">
                <span class="px-2.5 py-1 text-xs font-medium rounded-full bg-primary-500/20 text-primary-400">${b.roomCount} salle${b.roomCount > 1 ? 's' : ''}</span>
            </td>
            <td class="px-6 py-4">
                <div class="flex gap-2">
                    <button onclick="openEditBuilding(${b.id})" class="p-2 hover:bg-primary-500/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500" title="Modifier" aria-label="Modifier ${escapeHtml(b.name)}">
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button onclick="deleteBuilding(${b.id})" class="p-2 hover:bg-rose-500/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-rose-500" title="Supprimer" aria-label="Supprimer ${escapeHtml(b.name)}">
                        <svg class="w-4 h-4 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

// ========================================
// Confirmation modale
// ========================================

function confirmAction(title, message, actionLabel = 'Supprimer') {
    return new Promise((resolve) => {
        const modal = document.getElementById('modal-confirm');
        document.getElementById('confirm-title').textContent = title;
        document.getElementById('confirm-message').textContent = message;
        document.getElementById('confirm-action-btn').textContent = actionLabel;

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.setAttribute('aria-hidden', 'false');

        const actionBtn = document.getElementById('confirm-action-btn');
        const cancelBtn = document.getElementById('confirm-cancel-btn');
        const closeBtn = document.getElementById('confirm-close-btn');

        function cleanup() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modal.setAttribute('aria-hidden', 'true');
            actionBtn.removeEventListener('click', onConfirm);
            cancelBtn.removeEventListener('click', onCancel);
            closeBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('keydown', onKey);
        }

        function onConfirm() { cleanup(); resolve(true); }
        function onCancel() { cleanup(); resolve(false); }
        function onKey(e) {
            if (e.key === 'Escape') onCancel();
        }

        actionBtn.addEventListener('click', onConfirm);
        cancelBtn.addEventListener('click', onCancel);
        closeBtn.addEventListener('click', onCancel);
        modal.addEventListener('keydown', onKey);
        cancelBtn.focus();
    });
}

// ========================================
// Modales
// ========================================

function openModal(modalName) {
    const modal = document.getElementById('modal-' + modalName);
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.setAttribute('aria-hidden', 'false');

        const firstInput = modal.querySelector('input, select, textarea, button');
        if (firstInput) {
            firstInput.focus();
        }

        modal.addEventListener('keydown', handleModalKeydown);
    }

    if (modalName === 'booking') {
        populateBookingModal();
    }
}

function closeModal(modalName) {
    const modal = document.getElementById('modal-' + modalName);
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.setAttribute('aria-hidden', 'true');
        modal.removeEventListener('keydown', handleModalKeydown);
    }
}

function handleModalKeydown(event) {
    if (event.key === 'Escape') {
        const modal = event.currentTarget;
        const modalName = modal.id.replace('modal-', '');
        closeModal(modalName);
    }
}

function openBookingModal(roomId) {
    openModal('booking');
    setTimeout(() => {
        const select = document.getElementById('bookingRoom');
        if (select && roomId) {
            select.value = roomId;
        }
    }, 50);
}

function populateBookingModal() {
    const availableRooms = rooms.filter(r => r.status === 'available');
    document.getElementById('bookingRoom').innerHTML = availableRooms
        .map(r => `<option value="${r.id}">${escapeHtml(r.name)}${r.building ? ' - ' + escapeHtml(r.building) : ''} (${r.capacity}p)</option>`)
        .join('');

    document.getElementById('bookingDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('bookingDate').min = new Date().toISOString().split('T')[0];

    // Creneaux horaires de 07:00 a 20:00 par tranches de 30 min
    const startSelect = document.getElementById('bookingStart');
    const endSelect = document.getElementById('bookingEnd');
    const slots = [];
    for (let h = 7; h <= 20; h++) {
        for (let m = 0; m < 60; m += 30) {
            if (h === 20 && m > 0) break;
            const t = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
            slots.push(t);
        }
    }

    startSelect.innerHTML = slots.map(t => `<option value="${t}">${t}</option>`).join('');
    endSelect.innerHTML = slots.map(t => `<option value="${t}">${t}</option>`).join('');

    // Pre-selectionner 09:00 - 10:00
    startSelect.value = '09:00';
    endSelect.value = '10:00';

    // Quand on change l'heure de debut, avancer la fin de +1h
    startSelect.onchange = function() {
        const idx = slots.indexOf(this.value);
        const endIdx = Math.min(idx + 2, slots.length - 1);
        endSelect.value = slots[endIdx];
    };
}

// ========================================
// Edition - Salles
// ========================================

function openEditRoom(roomId) {
    const room = rooms.find(r => r.id === roomId);
    if (!room) return;

    // Populer le select batiment dans le modal d'edition
    const select = document.getElementById('editRoomBuilding');
    select.innerHTML = '<option value="">-- Aucun --</option>' +
        buildings.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');

    document.getElementById('editRoomId').value = room.id;
    document.getElementById('editRoomName').value = room.name;
    document.getElementById('editRoomCapacity').value = room.capacity;
    document.getElementById('editRoomFloor').value = room.floor || 0;
    document.getElementById('editRoomBuilding').value = room.buildingId || '';
    document.getElementById('editRoomDescription').value = room.description || '';

    openModal('editRoom');
}

async function handleEditRoomSubmit(event) {
    event.preventDefault();

    const roomId = parseInt(document.getElementById('editRoomId').value);
    const buildingVal = document.getElementById('editRoomBuilding').value;
    const floor = parseInt(document.getElementById('editRoomFloor').value);
    const description = document.getElementById('editRoomDescription').value;

    // Construire la description avec l'etage
    let fullDescription = description;
    if (floor > 0) {
        fullDescription = `Etage ${floor} - ${description}`;
    } else {
        fullDescription = `RDC - ${description}`;
    }

    try {
        await api('rooms.php', 'PUT', {
            id: roomId,
            name: document.getElementById('editRoomName').value,
            capacity: parseInt(document.getElementById('editRoomCapacity').value),
            description: fullDescription,
            buildingId: buildingVal ? parseInt(buildingVal) : null
        });

        showToast('Salle modifiee avec succes', 'success');
        closeModal('editRoom');
        await loadRooms();
        renderAdminRooms();
        renderDashboard();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

// ========================================
// Edition - Utilisateurs
// ========================================

function openEditUser(userId) {
    const user = users.find(u => u.id === userId);
    if (!user) return;

    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUserNom').value = user.nom;
    document.getElementById('editUserPrenom').value = user.prenom;
    document.getElementById('editUserEmail').value = user.email;
    document.getElementById('editUserRole').value = user.role;

    openModal('editUser');
}

async function handleEditUserSubmit(event) {
    event.preventDefault();

    try {
        await api('users.php', 'PUT', {
            id: parseInt(document.getElementById('editUserId').value),
            nom: document.getElementById('editUserNom').value,
            prenom: document.getElementById('editUserPrenom').value,
            email: document.getElementById('editUserEmail').value,
            role: document.getElementById('editUserRole').value
        });

        showToast('Utilisateur modifie avec succes', 'success');
        closeModal('editUser');
        await loadUsers();
        renderAdminUsers();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function handleAddUserSubmit(event) {
    event.preventDefault();

    try {
        await api('users.php', 'POST', {
            nom: document.getElementById('addUserNom').value,
            prenom: document.getElementById('addUserPrenom').value,
            email: document.getElementById('addUserEmail').value,
            password: document.getElementById('addUserPassword').value,
            role: document.getElementById('addUserRole').value
        });

        showToast('Utilisateur cree avec succes', 'success');
        closeModal('addUser');
        event.target.reset();
        await loadUsers();
        renderAdminUsers();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function deleteUser(userId) {
    const user = users.find(u => u.id === userId);
    const confirmed = await confirmAction(
        'Supprimer cet utilisateur ?',
        `${user ? user.nom + ' ' + user.prenom : 'Cet utilisateur'} sera definitivement supprime.`
    );
    if (!confirmed) return;

    try {
        await api('users.php', 'DELETE', { id: userId });
        showToast('Utilisateur supprime', 'success');
        await loadUsers();
        renderAdminUsers();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

// ========================================
// Edition - Batiments
// ========================================

function openEditBuilding(buildingId) {
    const building = buildings.find(b => b.id === buildingId);
    if (!building) return;

    document.getElementById('editBuildingId').value = building.id;
    document.getElementById('editBuildingName').value = building.name;
    document.getElementById('editBuildingAddress').value = building.address || '';

    openModal('editBuilding');
}

async function handleAddBuildingSubmit(event) {
    event.preventDefault();

    try {
        await api('buildings.php', 'POST', {
            name: document.getElementById('addBuildingName').value,
            address: document.getElementById('addBuildingAddress').value
        });

        showToast('Batiment cree avec succes', 'success');
        closeModal('addBuilding');
        event.target.reset();
        await loadBuildings();
        renderAdminBuildings();
        // Mettre a jour les selects batiment dans les modales salle
        populateBuildingSelect();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function handleEditBuildingSubmit(event) {
    event.preventDefault();

    try {
        await api('buildings.php', 'PUT', {
            id: parseInt(document.getElementById('editBuildingId').value),
            name: document.getElementById('editBuildingName').value,
            address: document.getElementById('editBuildingAddress').value
        });

        showToast('Batiment modifie avec succes', 'success');
        closeModal('editBuilding');
        await loadBuildings();
        await loadRooms();
        renderAdminBuildings();
        populateBuildingSelect();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function deleteBuilding(buildingId) {
    const building = buildings.find(b => b.id === buildingId);
    const confirmed = await confirmAction(
        'Supprimer ce batiment ?',
        `"${building ? building.name : 'Ce batiment'}" sera definitivement supprime.`
    );
    if (!confirmed) return;

    try {
        await api('buildings.php', 'DELETE', { id: buildingId });
        showToast('Batiment supprime', 'success');
        await loadBuildings();
        renderAdminBuildings();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

// ========================================
// Actions
// ========================================

async function handleBookingSubmit(event) {
    event.preventDefault();

    const btn = document.getElementById('bookingSubmitBtn');
    setButtonLoading(btn, true);

    const selectedExtras = Array.from(document.querySelectorAll('input[name="extras"]:checked'))
        .map(cb => cb.value);

    try {
        await api('bookings.php', 'POST', {
            roomId: parseInt(document.getElementById('bookingRoom').value),
            date: document.getElementById('bookingDate').value,
            start: document.getElementById('bookingStart').value,
            end: document.getElementById('bookingEnd').value,
            subject: document.getElementById('bookingSubject').value,
            options: selectedExtras
        });

        showToast('Reservation creee avec succes', 'success');
        closeModal('booking');
        event.target.reset();
        await loadBookings();
        renderDashboard();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        setButtonLoading(btn, false, 'Creer');
    }
}

async function handleAddRoomSubmit(event) {
    event.preventDefault();

    const buildingVal = document.getElementById('roomBuilding').value;

    try {
        await api('rooms.php', 'POST', {
            name: document.getElementById('roomName').value,
            capacity: parseInt(document.getElementById('roomCapacity').value),
            floor: parseInt(document.getElementById('roomFloor').value),
            description: document.getElementById('roomDescription').value,
            buildingId: buildingVal ? parseInt(buildingVal) : null
        });

        showToast('Salle ajoutee avec succes', 'success');
        closeModal('addRoom');
        event.target.reset();
        await loadRooms();
        renderAdminRooms();
        renderDashboard();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function cancelBooking(bookingId) {
    const confirmed = await confirmAction(
        'Annuler cette reservation ?',
        'La reservation sera annulee. Cette action est irreversible.',
        'Annuler la reservation'
    );
    if (!confirmed) return;

    try {
        await api('bookings.php', 'DELETE', { id: bookingId });
        showToast('Reservation annulee', 'success');
        await loadBookings();
        renderMyBookings();
        renderDashboard();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function toggleRoomStatus(roomId, currentStatus) {
    const statuses = ['available', 'occupied', 'maintenance'];
    const nextStatus = statuses[(statuses.indexOf(currentStatus) + 1) % statuses.length];

    try {
        await api('rooms.php', 'PUT', { id: roomId, status: nextStatus });
        showToast('Statut modifie', 'success');
        await loadRooms();
        renderAdminRooms();
        renderDashboard();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

async function deleteRoom(roomId) {
    const room = rooms.find(r => r.id === roomId);
    const confirmed = await confirmAction(
        'Supprimer cette salle ?',
        `"${room ? room.name : 'Cette salle'}" sera definitivement supprimee.`
    );
    if (!confirmed) return;

    try {
        await api('rooms.php', 'DELETE', { id: roomId });
        showToast('Salle supprimee', 'success');
        await loadRooms();
        renderAdminRooms();
        renderDashboard();
    } catch (error) {
        showToast(error.message, 'error');
    }
}

// ========================================
// Batiments - Select
// ========================================

function populateBuildingSelect() {
    const select = document.getElementById('roomBuilding');
    if (!select) return;

    select.innerHTML = '<option value="">-- Aucun --</option>' +
        buildings.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
}

// ========================================
// Avatars utilisateurs
// ========================================

const avatarGradients = [
    'from-primary-500 to-violet-500',
    'from-emerald-500 to-teal-500',
    'from-rose-500 to-pink-500',
    'from-amber-500 to-orange-500',
    'from-indigo-500 to-blue-500',
    'from-fuchsia-500 to-purple-500',
    'from-cyan-500 to-sky-500',
    'from-lime-500 to-green-500'
];

function getAvatarGradient(name) {
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return avatarGradients[Math.abs(hash) % avatarGradients.length];
}

function getInitials(name) {
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
}

function avatarHtml(name, size = 'w-10 h-10', textSize = 'text-sm') {
    const gradient = getAvatarGradient(name);
    const initials = getInitials(name);
    return `<div class="${size} rounded-full bg-gradient-to-br ${gradient} flex items-center justify-center text-white font-semibold ${textSize} flex-shrink-0" aria-hidden="true">${escapeHtml(initials)}</div>`;
}

// ========================================
// Utilitaires
// ========================================

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function setButtonLoading(button, isLoading, originalText = '') {
    if (isLoading) {
        button.disabled = true;
        button.innerHTML = '<div class="loader" aria-label="Chargement"></div>';
    } else {
        button.disabled = false;
        button.innerHTML = `<span>${originalText}</span>`;
    }
}

function getStatusClass(status) {
    const classes = {
        available: 'bg-emerald-500/20 text-emerald-400',
        occupied: 'bg-rose-500/20 text-rose-400',
        maintenance: 'bg-amber-500/20 text-amber-400'
    };
    return classes[status] || 'bg-dark-600 text-dark-300';
}

function getStatusText(status) {
    const texts = {
        available: 'Disponible',
        occupied: 'Occupee',
        maintenance: 'Maintenance'
    };
    return texts[status] || status;
}

function getBookingStatusClass(status) {
    const classes = {
        confirmed: 'bg-emerald-500/20 text-emerald-400',
        pending: 'bg-amber-500/20 text-amber-400',
        cancelled: 'bg-rose-500/20 text-rose-400'
    };
    return classes[status] || 'bg-dark-600 text-dark-300';
}

function getBookingStatusText(status) {
    const texts = {
        confirmed: 'Confirmee',
        pending: 'En attente',
        cancelled: 'Annulee'
    };
    return texts[status] || status;
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('fr-FR', {
        weekday: 'short',
        day: 'numeric',
        month: 'short'
    });
}

function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');

    const typeClasses = {
        success: 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400',
        error: 'bg-rose-500/20 border-rose-500/30 text-rose-400',
        info: 'bg-dark-800 border-dark-700 text-white'
    };

    toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg animate-slide-in border ${typeClasses[type] || typeClasses.info}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'polite');
    toast.innerHTML = `<span class="text-sm font-medium">${escapeHtml(message)}</span>`;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ========================================
// Initialisation
// ========================================

document.addEventListener('DOMContentLoaded', () => {
    // Auth
    document.getElementById('loginFormEl')?.addEventListener('submit', handleLogin);
    document.getElementById('registerFormEl')?.addEventListener('submit', handleRegister);

    // Formulaires existants
    document.getElementById('bookingFormEl')?.addEventListener('submit', handleBookingSubmit);
    document.getElementById('addRoomFormEl')?.addEventListener('submit', handleAddRoomSubmit);

    // Formulaires edition
    document.getElementById('editRoomFormEl')?.addEventListener('submit', handleEditRoomSubmit);
    document.getElementById('addUserFormEl')?.addEventListener('submit', handleAddUserSubmit);
    document.getElementById('editUserFormEl')?.addEventListener('submit', handleEditUserSubmit);
    document.getElementById('addBuildingFormEl')?.addEventListener('submit', handleAddBuildingSubmit);
    document.getElementById('editBuildingFormEl')?.addEventListener('submit', handleEditBuildingSubmit);

    // Verifier la session existante
    checkSession();
});

// ========================================
// Administration - Logs
// ========================================

async function loadLogs(reset = true) {
    if (reset) {
        logsPage = 1;
        logs = [];
    }

    const filter = document.getElementById('logsFilter')?.value || '';
    const params = `?page=${logsPage}&limit=50${filter ? '&type=' + encodeURIComponent(filter) : ''}`;

    try {
        const result = await api(`logs.php${params}`);
        if (reset) {
            logs = result.logs;
        } else {
            logs = logs.concat(result.logs);
        }
        logsTotal = result.total;
        renderAdminLogs();
    } catch (error) {
        showToast('Erreur lors du chargement des logs', 'error');
    }
}

function renderAdminLogs() {
    const actionLabels = {
        'BOOKING_CREATED': 'Reservation creee',
        'BOOKING_UPDATED': 'Reservation modifiee',
        'BOOKING_CANCELLED': 'Reservation annulee',
        'ROOM_CREATED': 'Salle creee',
        'ROOM_UPDATED': 'Salle modifiee',
        'ROOM_DELETED': 'Salle supprimee',
        'USER_CREATED': 'Utilisateur cree',
        'USER_UPDATED': 'Utilisateur modifie',
        'USER_DELETED': 'Utilisateur supprime',
        'BUILDING_CREATED': 'Batiment cree',
        'BUILDING_UPDATED': 'Batiment modifie',
        'BUILDING_DELETED': 'Batiment supprime'
    };

    const actionColors = {
        'CREATED': 'bg-emerald-500/20 text-emerald-400',
        'UPDATED': 'bg-primary-500/20 text-primary-400',
        'CANCELLED': 'bg-rose-500/20 text-rose-400',
        'DELETED': 'bg-rose-500/20 text-rose-400'
    };

    const rows = logs.length
        ? logs.map(log => {
            const actionSuffix = log.action.split('_').pop();
            const colorClass = actionColors[actionSuffix] || 'bg-dark-600 text-dark-300';
            const label = actionLabels[log.action] || log.action;
            const dateStr = new Date(log.date.replace(' ', 'T')).toLocaleString('fr-FR', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });

            return `
                <tr class="hover:bg-dark-800/30">
                    <td class="px-6 py-4 text-sm text-dark-300">${dateStr}</td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-white">${escapeHtml(log.actorName)}</span>
                            <span class="px-1.5 py-0.5 text-xs rounded ${log.actorRole === 'Admin' ? 'bg-violet-500/20 text-violet-400' : 'bg-dark-600 text-dark-300'}">${escapeHtml(log.actorRole)}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-2.5 py-1 text-xs font-medium rounded-full ${colorClass}">${escapeHtml(label)}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-dark-200">${log.targetLabel ? escapeHtml(log.targetLabel) : '-'}</td>
                </tr>
            `;
        }).join('')
        : '<tr><td colspan="4" class="px-6 py-12 text-center text-dark-400">Aucun log</td></tr>';

    document.getElementById('adminLogsTable').innerHTML = rows;

    const loadMoreBtn = document.getElementById('logsLoadMore');
    if (loadMoreBtn) {
        loadMoreBtn.classList.toggle('hidden', logs.length >= logsTotal);
    }
}

function filterLogs() {
    loadLogs(true);
}

function loadMoreLogs() {
    logsPage++;
    loadLogs(false);
}

// Export pour utilisation globale
window.showAuthForm = showAuthForm;
window.handleLogout = handleLogout;
window.showPage = showPage;
window.toggleSidebar = toggleSidebar;
window.openModal = openModal;
window.closeModal = closeModal;
window.openBookingModal = openBookingModal;
window.filterRooms = filterRooms;
window.filterMyBookings = filterMyBookings;
window.changeWeek = changeWeek;
window.goToToday = goToToday;
window.cancelBooking = cancelBooking;
window.toggleRoomStatus = toggleRoomStatus;
window.deleteRoom = deleteRoom;
window.openEditRoom = openEditRoom;
window.openEditUser = openEditUser;
window.deleteUser = deleteUser;
window.openEditBuilding = openEditBuilding;
window.deleteBuilding = deleteBuilding;
window.filterLogs = filterLogs;
window.loadMoreLogs = loadMoreLogs;
