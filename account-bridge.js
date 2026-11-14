/* Account administration screens share the existing authenticated AUSSH session. */
(function () {
  const endpoint = 'api/accounts.php';
  const state = { accounts: [], audit: [], students: [], isSuperAdministrator: false };
  const ordinaryRoles = [
    ['lecturer', 'Lecturer'],
    ['verifier', 'Achievements Verifier'],
    ['hod', 'Head of Department'],
    ['registry', 'Registry'],
    ['supervisor', 'Attachment Supervisor'],
    ['chaplaincy', 'Chaplaincy'],
    ['dean', 'Dean of Students'],
    ['career_advisor', 'Career Advisor'],
    ['alumni', 'Alumni']
  ];
  const colleges = [
    'College of Business and Management Sciences',
    'College of Engineering and Applied Sciences',
    'College of Health, Agriculture and Natural Sciences',
    'College of Social Sciences, Theology, Humanities and Education',
    'School of Law'
  ];
  const collegeOptions = (selected = '') =>
    `<option value="">Select College / School</option>${colleges.map(college =>
      `<option value="${esc(college)}" ${selected === college ? 'selected' : ''}>${esc(college)}</option>`
    ).join('')}`;

  if (roles.registry && !roles.registry.nav.some(item => item[0] === 'student-verification')) {
    roles.registry.nav.push(['student-verification', '✓', 'Student Verification']);
  }

  async function accountRequest(action, data = {}) {
    if (!csrfToken) throw new Error('Security token missing. Please sign in again.');
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ action, ...data })
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Account request failed.');
    return result;
  }

  async function loadAccounts(showError = true) {
    const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Could not load accounts.');
    state.accounts = result.accounts || [];
    state.audit = result.audit || [];
    state.isSuperAdministrator = Boolean(result.is_super_administrator);
    if (showError && currentPage === 'users') renderAccountsPage();
    return result;
  }

  async function loadPendingStudents() {
    const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Could not load pending student registrations.');
    state.students = result.students || [];
    renderStudentVerificationPage();
  }

  function roleOptions(selected = '') {
    const entries = ordinaryRoles.concat(state.isSuperAdministrator ? [['admin', 'Administrator']] : []);
    return entries.map(([value, label]) =>
      `<option value="${value}" ${selected === value ? 'selected' : ''}>${label}</option>`
    ).join('');
  }
  function encodedArgument(value) {
    return encodeURIComponent(String(value));
  }

  function accountsMarkup() {
    const rows = state.accounts.map(account => {
      const superAdmin = account.is_super_administrator;
      const roleEdit = state.isSuperAdministrator
        ? `<button class="btn light small" onclick="accountChangeRole(decodeURIComponent('${encodedArgument(account.user_id)}'),'${esc(account.role)}')">Change role</button>`
        : '';
      const superEdit = state.isSuperAdministrator && account.role === 'admin'
        ? `<button class="btn light small" onclick="accountSetSuper(decodeURIComponent('${encodedArgument(account.user_id)}'),${!superAdmin})">${superAdmin ? 'Remove Super Admin' : 'Make Super Admin'}</button>`
        : '';
      const actions = [
        `<button class="btn light small" onclick="accountEditCollege(decodeURIComponent('${encodedArgument(account.user_id)}'),decodeURIComponent('${encodedArgument(account.college || '')}'))">College / School</button>`,
        roleEdit,
        superEdit,
        account.status === 'Active' && account.user_id !== currentId
          ? `<button class="btn light small" onclick="accountDeactivate(decodeURIComponent('${encodedArgument(account.user_id)}'))">Deactivate</button>`
          : '',
        account.status === 'Disabled' && account.role !== 'student'
          ? `<button class="btn small" onclick="accountResendInvitation(decodeURIComponent('${encodedArgument(account.user_id)}'))">Send activation</button>`
          : ''
      ].filter(Boolean).join('');
      const verification = account.role === 'student'
        ? `<small>${esc(account.student_verification_status || 'Not on file')}</small>`
        : '';
      return `<tr><td><b>${esc(account.full_name)}</b><small style="display:block">${esc(account.email || '')}</small>${verification}</td><td>${esc(account.user_id)}</td><td>${esc(account.role)}${superAdmin ? ' · Super Admin' : ''}</td><td>${badge(account.status)}</td><td>${esc(account.college || 'Not on file')}</td><td>${new Date(String(account.created_at).replace(' ', 'T')).toLocaleDateString()}</td><td><div class="toolbar">${actions}</div></td></tr>`;
    }).join('');
    const auditRows = state.audit.map(entry =>
      `<div class="list-row"><div><b>${esc(entry.action.replaceAll('_', ' '))}</b><small>${esc(entry.actor_name || entry.actor_user_id)} → ${esc(entry.target_name || entry.user_id || 'account')} · ${esc(entry.user_id || '')}</small><small>${esc(JSON.stringify(entry.details || {}))}</small></div><small>${esc(entry.created_at)}</small></div>`
    ).join('');
    return `<div class="notice">Staff accounts are inactive until their invitee activates the secure, single-use email link and sets their own password. Student identity verification is managed separately by Registry. No password hashes or activation tokens are shown here.</div>
      <div class="card"><div class="section-head"><div><h2 class="panel-title">Account directory</h2><p>${state.accounts.length} account(s) · ${state.isSuperAdministrator ? 'Designated Super Administrator' : 'Administrator'}</p></div><button class="btn small" onclick="accountCreateForm()">＋ Invite account</button></div>
      <div class="table-wrap"><table><thead><tr><th>Name / email</th><th>University ID</th><th>Role</th><th>Status</th><th>College / School</th><th>Created</th><th>Actions</th></tr></thead><tbody>${rows || '<tr><td colspan="7" class="empty">No accounts are available.</td></tr>'}</tbody></table></div></div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">Account history</h2><p class="muted">Creation, invitation, activation, deactivation, role and College / School changes.</p>${auditRows || '<p class="muted">No account changes have been recorded.</p>'}</div>`;
  }

  async function renderAccountsPage() {
    const content = document.getElementById('pageContent');
    if (!content) return;
    content.innerHTML = `${welcome('Account management.', 'Loading authorised account directory...')}<div class="card">Loading accounts and audit history...</div>`;
    try {
      await loadAccounts(false);
      if (currentPage === 'users') content.innerHTML = accountsMarkup();
    } catch (error) {
      if (currentPage === 'users') content.innerHTML = `<div class="notice">${esc(error.message)}</div>`;
    }
  }

  function studentVerificationMarkup() {
    const rows = state.students.map(student =>
      `<article class="list-row"><div style="flex:1"><b>${esc(student.full_name)} · ${esc(student.user_id)}</b><small>${esc(student.email || '')} · Submitted ${esc(student.created_at)}</small><small>${esc(student.college || 'College / School not on file')} · ${esc(student.programme)} · ${esc(student.study_year)}</small></div><div class="toolbar"><button class="btn small" onclick="accountVerifyStudent(decodeURIComponent('${encodedArgument(student.user_id)}'))">Verify identity</button><button class="btn light small" onclick="accountRejectStudent(decodeURIComponent('${encodedArgument(student.user_id)}'))">Reject</button></div></article>`
    ).join('');
    return `${welcome('Student identity verification.', 'Review the university ID and academic details against Registry records before making a decision. Registration alone does not establish verified academic status.')}
      <div class="notice">Verified and rejected decisions are recorded in the account audit history. Rejection requires a reason and disables that account. Students receive an in-app notification.</div>
      <div class="card"><h2 class="panel-title">Pending registrations (${state.students.length})</h2>${rows || '<div class="empty">There are no student registrations awaiting verification.</div>'}</div>`;
  }

  async function renderStudentVerificationPage() {
    const content = document.getElementById('pageContent');
    if (!content) return;
    content.innerHTML = `${welcome('Student identity verification.', 'Loading pending Registry reviews...')}<div class="card">Loading pending registrations...</div>`;
    try {
      await loadPendingStudents();
    } catch (error) {
      if (currentPage === 'student-verification') content.innerHTML = `<div class="notice">${esc(error.message)}</div>`;
    }
  }

  window.usersPage = function () {
    return `${welcome('Account management.', 'Open the authorised directory to invite staff, manage account access and review changes.') }<div class="card"><button class="btn" onclick="accountOpenDirectory()">Open Account Management</button></div>`;
  };
  window.accountOpenDirectory = function () {
    openPage('users');
  };
  window.accountCreateForm = function (initialRole = '') {
    if (currentRole !== 'admin') return toast('Only an administrator can invite staff accounts.');
    openModal('Invite a university account', `<form onsubmit="accountCreate(event)">
      <div class="form-grid">
        <div class="field"><label>UNIVERSITY ID</label><input name="user_id" maxlength="64" pattern="[A-Za-z0-9._-]{3,64}" required></div>
        <div class="field"><label>FULL NAME</label><input name="full_name" maxlength="120" required></div>
        <div class="field"><label>UNIVERSITY EMAIL</label><input name="email" type="email" maxlength="254" required></div>
        <div class="field"><label>ROLE</label><select name="role" required>${roleOptions(initialRole)}</select></div>
        <div class="field wide"><label>COLLEGE / SCHOOL</label><select name="college" required>${collegeOptions()}</select></div>
        ${state.isSuperAdministrator ? '<label class="muted wide"><input type="checkbox" name="make_super_administrator"> Designate this administrator as a Super Administrator</label>' : ''}
      </div>
      <p class="muted">No password is created or emailed. The account stays inactive until its recipient sets a password through the emailed 24-hour activation link.</p>
      <button class="btn">Create and send invitation</button></form>`);
  };
  window.accountCreate = async function (event) {
    event.preventDefault();
    const values = new FormData(event.target);
    try {
      const result = await accountRequest('create_account', {
        user_id: String(values.get('user_id') || '').trim(),
        full_name: String(values.get('full_name') || '').trim(),
        email: String(values.get('email') || '').trim(),
        role: values.get('role'),
        college: String(values.get('college') || ''),
        make_super_administrator: values.get('make_super_administrator') === 'on'
      });
      closeModal();
      toast(result.message);
      await renderAccountsPage();
    } catch (error) { toast(error.message); }
  };
  window.accountDeactivate = async function (userId) {
    if (!confirm(`Deactivate account ${userId}? Existing sessions will lose access on their next authenticated request.`)) return;
    try {
      await accountRequest('deactivate_account', { user_id: userId });
      toast('Account deactivated.');
      await renderAccountsPage();
    } catch (error) { toast(error.message); }
  };
  window.accountResendInvitation = async function (userId) {
    try {
      const result = await accountRequest('resend_invitation', { user_id: userId });
      toast(result.message);
      await renderAccountsPage();
    } catch (error) { toast(error.message); }
  };
  window.accountEditCollege = function (userId, college) {
    openModal('Assign College / School', `<form onsubmit="accountSaveCollege(event,'${esc(userId)}')"><div class="field"><label>COLLEGE / SCHOOL</label><select name="college" required>${collegeOptions(college)}</select></div><button class="btn">Save College / School</button></form>`);
  };
  window.accountSaveCollege = async function (event, userId) {
    event.preventDefault();
    const college = String(new FormData(event.target).get('college') || '');
    try {
      await accountRequest('set_college', { user_id: userId, college });
      closeModal();
      toast('College / School assignment saved.');
      await renderAccountsPage();
    } catch (error) { toast(error.message); }
  };
  window.accountChangeRole = function (userId, role) {
    if (!state.isSuperAdministrator) return toast('Only a designated Super Administrator can assign roles.');
    openModal('Change account role', `<form onsubmit="accountSaveRole(event,'${esc(userId)}')"><div class="field"><label>ROLE</label><select name="role" required>${roleOptions(role)}</select></div><p class="muted">Administrator privileges are restricted to designated Super Administrators.</p><button class="btn">Save role</button></form>`);
  };
  window.accountSaveRole = async function (event, userId) {
    event.preventDefault();
    try {
      await accountRequest('change_role', { user_id: userId, role: new FormData(event.target).get('role') });
      closeModal();
      toast('Account role updated and audited.');
      await renderAccountsPage();
    } catch (error) { toast(error.message); }
  };
  window.accountSetSuper = async function (userId, enabled) {
    const verb = enabled ? 'designate' : 'remove the Super Administrator designation from';
    if (!confirm(`Are you sure you want to ${verb} ${userId}?`)) return;
    try {
      await accountRequest('set_super_administrator', { user_id: userId, enabled });
      toast('Super Administrator designation updated.');
      await renderAccountsPage();
    } catch (error) { toast(error.message); }
  };
  window.accountVerifyStudent = async function (userId) {
    if (!confirm(`Confirm Registry identity verification for ${userId}?`)) return;
    try {
      await accountRequest('verify_student', { user_id: userId });
      toast('Student identity verified.');
      await renderStudentVerificationPage();
    } catch (error) { toast(error.message); }
  };
  window.accountRejectStudent = function (userId) {
    openModal('Reject student registration', `<form onsubmit="accountSaveStudentRejection(event,'${esc(userId)}')"><div class="field"><label>REJECTION REASON</label><textarea name="reason" maxlength="2000" required></textarea></div><button class="btn">Reject registration</button></form>`);
  };
  window.accountSaveStudentRejection = async function (event, userId) {
    event.preventDefault();
    try {
      await accountRequest('reject_student', {
        user_id: userId,
        reason: String(new FormData(event.target).get('reason') || '').trim()
      });
      closeModal();
      toast('Registration rejected and the student account disabled.');
      await renderStudentVerificationPage();
    } catch (error) { toast(error.message); }
  };

  const originalRender = window.renderPage;
  window.renderPage = function () {
    if (currentPage === 'users' && currentRole === 'admin') {
      renderAccountsPage();
      return;
    }
    if (currentPage === 'student-verification' && currentRole === 'registry') {
      renderStudentVerificationPage();
      return;
    }
    originalRender();
  };
  const originalBuildNav = window.buildNav;
  window.buildNav = function () {
    originalBuildNav();
  };
  const originalActivate = window.activateAuthenticatedUser;
  if (typeof originalActivate === 'function') {
    window.activateAuthenticatedUser = function (user) {
      originalActivate(user);
      if (user && ['admin', 'registry'].includes(user.role)) {
        if (currentPage === 'users' && user.role === 'admin') renderAccountsPage();
        if (currentPage === 'student-verification' && user.role === 'registry') renderStudentVerificationPage();
      }
    };
  }

  async function handleActivationFragment() {
    const match = location.hash.match(/(?:^#|&)account-activation=([a-f0-9]{64})(?:&|$)/i);
    if (!match) return;
    const token = match[1];
    history.replaceState(null, '', location.pathname + location.search);
    openModal('Activate your AUSSH account', `<form onsubmit="accountActivate(event)"><p class="muted">Choose a password for your account. The activation link is single-use and expires after 24 hours.</p><div class="field"><label>NEW PASSWORD</label><input type="password" name="password" minlength="10" maxlength="4096" autocomplete="new-password" required></div><div class="field"><label>CONFIRM PASSWORD</label><input type="password" name="password_confirmation" minlength="10" maxlength="4096" autocomplete="new-password" required></div><button class="btn">Activate account</button></form>`);
    window.accountActivationToken = token;
  }
  window.accountActivate = async function (event) {
    event.preventDefault();
    const form = new FormData(event.target);
    const password = String(form.get('password') || '');
    if (password !== String(form.get('password_confirmation') || '')) {
      toast('Passwords do not match.');
      return;
    }
    try {
      const csrfResponse = await fetch('api/csrf.php', { credentials: 'same-origin', cache: 'no-store' });
      const csrfData = await csrfResponse.json();
      if (!csrfResponse.ok || !csrfData.csrf_token) throw new Error('Could not establish a secure activation session.');
      const response = await fetch('api/activate_account.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfData.csrf_token },
        body: JSON.stringify({ token: window.accountActivationToken, password })
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Account activation failed.');
      delete window.accountActivationToken;
      closeModal();
      toast(result.message);
      showLogin();
    } catch (error) { toast(error.message); }
  };
  handleActivationFragment().catch(error => toast(error.message));
})();
