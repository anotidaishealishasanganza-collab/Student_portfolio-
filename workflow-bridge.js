/* AU shared MySQL workflow bridge. Records are authoritative on the server. */
(function () {
  const apiUrl = 'api/workflows.php';
  let refreshTimer = null;
  let availableLecturers = [];
  let selectedAchievementFiles = [];
  const toLocalRecord = r => ({
    id: Number(r.id), type: r.payload.type || (r.record_type === 'achievement' ? 'Achievement' : r.record_type),
    year: r.payload.year || '', title: r.payload.title || r.payload.organisation || r.record_type,
    detail: r.payload.detail || r.payload.message || '', evidence: r.payload.evidence || '',
    evidence_submissions: Array.isArray(r.payload.evidence_submissions) ? r.payload.evidence_submissions : [],
    attachments: Array.isArray(r.attachments) ? r.attachments : [],
    status: r.status, student: r.owner_name, studentId: r.owner_user_id,
    ownerCollege: r.owner_college || '',
    date: String(r.created_at || '').slice(0,10), comment: r.payload.review_comment || '',
    reviewer: r.payload.reviewer || '', to: r.payload.to || '', purpose: r.payload.purpose || '',
    note: r.payload.note || r.payload.context || '', body: r.payload.body || '',
    approvedBy: r.payload.reviewer || '', organisation: r.payload.organisation || '',
    role: r.payload.role || '', period: r.payload.period || '', progress: r.payload.progress || 0
  });
  async function request(action, extra = {}, method = 'POST') {
    const options = { method, credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (method === 'POST') {
      if (!csrfToken) throw new Error('Security token missing. Please sign in again.');
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify({ action, ...extra });
    }
    const response = await fetch(apiUrl, options);
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Shared workflow request failed.');
    return data;
  }
  async function refreshSharedRecords(showErrors = false) {
    if (!authenticatedUser) return;
    try {
      const response = await fetch(apiUrl, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Could not load shared records.');
      if (data.current_user && authenticatedUser) Object.assign(authenticatedUser, data.current_user);
      availableLecturers = Array.isArray(data.lecturers) ? data.lecturers : [];
      const rows = data.records.map(toLocalRecord);
      db.items = rows.filter((x, i) => x.studentId && data.records[i].record_type === 'achievement');
      db.letters = rows.filter((x, i) => data.records[i].record_type === 'recommendation');
      db.placements = rows.filter((x, i) => data.records[i].record_type === 'attachment');
      db.support = rows.filter((x, i) => data.records[i].record_type === 'support');
      db.opportunities = rows.filter((x, i) => data.records[i].record_type === 'opportunity');
      db.notifications = (data.notifications || []).map(n => ({ id: Number(n.id), title: n.title || 'Portal update', message: n.message || '', read: Number(n.is_read) === 1, createdAt: n.created_at, created_at: n.created_at, type: n.record_type || 'Update', recipientRole: currentRole, reference: n.record_id, recordId: n.record_id }));
      localStorage.setItem(accountStorageKey(), JSON.stringify(db));
      if (document.getElementById('app') && !document.getElementById('app').classList.contains('hidden')) renderPage();
    } catch (e) { console.error('Shared workflow sync:', e); if (showErrors) toast(e.message); }
  }
  async function createRecord(type, payload) {
    const result = await request('create', { type, payload });
    await refreshSharedRecords(true);
    return result;
  }
  async function decideRecord(id, status, comment) {
    const result = await request('decide', { id, status, comment });
    await refreshSharedRecords(true);
    return result;
  }
  function emailDeliveryMessage(result) {
    if (Number(result.email_count) > Number(result.emails_sent)) {
      return ' The record was saved, but one or more email notifications could not be delivered; in-app notifications are available.';
    }
    return '';
  }
  const originalActivate = window.activateAuthenticatedUser;
  if (typeof originalActivate === 'function') {
    window.activateAuthenticatedUser = function (user) {
      originalActivate(user);
      refreshSharedRecords(true);
      if (refreshTimer) clearInterval(refreshTimer);
      refreshTimer = setInterval(() => refreshSharedRecords(false), 12000);
    };
  }
  const allowedEvidenceExtensions = new Set(['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);
  function displayEvidenceFiles() {
    const list = document.getElementById('achievementSelectedFiles');
    if (!list) return;
    list.innerHTML = selectedAchievementFiles.map((file, index) =>
      `<div class="list-row"><span>${esc(file.name)} · ${(file.size / 1048576).toFixed(2)} MB</span><button type="button" class="btn light small" onclick="achievementRemoveSelectedFile(${index})">Remove</button></div>`
    ).join('') || '<small class="muted">No files selected.</small>';
  }
  window.resetAchievementEvidenceSelection = function () {
    selectedAchievementFiles = [];
    displayEvidenceFiles();
  };
  function validateEvidenceFiles(files, existingCount = 0) {
    if (files.length + existingCount > 5) return 'An achievement can have no more than five evidence files.';
    for (const file of files) {
      const extension = file.name.split('.').pop().toLowerCase();
      if (!allowedEvidenceExtensions.has(extension)) return `${file.name}: select a PDF, JPG/JPEG, PNG, DOC or DOCX file.`;
      if (file.size < 1) return `${file.name}: empty files cannot be uploaded.`;
      if (file.size > 10 * 1024 * 1024) return `${file.name}: each file must be 10 MB or smaller.`;
    }
    return '';
  }
  window.achievementFilesChanged = function (input) {
    selectedAchievementFiles = Array.from(input.files || []);
    const error = validateEvidenceFiles(selectedAchievementFiles);
    if (error) {
      selectedAchievementFiles = [];
      input.value = '';
      toast(error);
    }
    displayEvidenceFiles();
  };
  window.achievementRemoveSelectedFile = function (index) {
    selectedAchievementFiles.splice(index, 1);
    const list = document.getElementById('achievementSelectedFiles');
    const picker = list && list.parentElement.querySelector('input[type="file"]');
    if (picker) picker.value = '';
    displayEvidenceFiles();
  };
  function sendAchievementEvidence(form, action, fields, existingCount = 0) {
    const error = validateEvidenceFiles(selectedAchievementFiles, existingCount);
    if (error) { toast(error); return; }
    const progress = form.querySelector('#achievementUploadProgress');
    const progressBar = form.querySelector('#achievementUploadProgressBar');
    const progressText = form.querySelector('#achievementUploadProgressText');
    const submit = form.querySelector('button[type="submit"]');
    const body = new FormData();
    body.append('action', action);
    if (action === 'create') {
      body.append('type', 'achievement');
      body.append('payload', JSON.stringify(fields));
    } else {
      Object.entries(fields).forEach(([key, value]) => body.append(key, value));
    }
    selectedAchievementFiles.forEach(file => body.append('evidence_files[]', file, file.name));
    if (progress) progress.style.display = 'block';
    if (submit) submit.disabled = true;
    const request = new XMLHttpRequest();
    request.open('POST', apiUrl);
    request.withCredentials = true;
    request.setRequestHeader('X-CSRF-Token', csrfToken);
    request.upload.onprogress = event => {
      if (!event.lengthComputable || !progressBar || !progressText) return;
      const percent = Math.round(event.loaded / event.total * 100);
      progressBar.value = percent;
      progressText.textContent = `Uploading evidence: ${percent}%`;
    };
    request.onload = async () => {
      let result;
      try { result = JSON.parse(request.responseText); }
      catch (_) { result = null; }
      if (request.status < 200 || request.status >= 300 || !result || !result.success) {
        if (submit) submit.disabled = false;
        if (progressText) progressText.textContent = result && result.message ? result.message : 'The evidence upload failed. Try again.';
        toast(result && result.message ? result.message : 'The evidence upload failed. Try again.');
        return;
      }
      if (progressBar) progressBar.value = 100;
      if (progressText) progressText.textContent = 'Upload complete.';
      selectedAchievementFiles = [];
      await refreshSharedRecords(true);
      closeModal();
      toast(action === 'create'
        ? 'Achievement and evidence submitted to the shared verifier queue.' + emailDeliveryMessage(result)
        : 'Additional evidence uploaded and the achievement resubmitted for verification.');
    };
    request.onerror = () => {
      if (submit) submit.disabled = false;
      if (progressText) progressText.textContent = 'The upload could not reach the server. Try again.';
      toast('The upload could not reach the server. Try again.');
    };
    request.send(body);
  }
  window.addAchievement = function (event) {
    event.preventDefault();
    if (!csrfToken) { toast('Security token missing. Please sign in again.'); return; }
    const form = event.target;
    const values = new FormData(form);
    sendAchievementEvidence(form, 'create', {
      type: values.get('type'),
      year: values.get('year'),
      title: String(values.get('title') || '').trim(),
      detail: String(values.get('detail') || '').trim(),
      evidence: String(values.get('evidence') || '').trim(),
      share_activity: values.get('share_activity') === 'on'
    });
  };
  window.addAchievementEvidence = function (event, id) {
    event.preventDefault();
    if (!csrfToken) { toast('Security token missing. Please sign in again.'); return; }
    const record = db.items.find(item => Number(item.id) === Number(id));
    if (!record || record.status !== 'Needs changes') {
      toast('Additional evidence can only be uploaded to an achievement returned for changes.');
      return;
    }
    const form = event.target;
    const values = new FormData(form);
    sendAchievementEvidence(form, 'add_evidence', {
      id: String(Number(id)),
      evidence_description: String(values.get('evidence_description') || '').trim()
    }, (record.attachments || []).length);
  };
  window.saveDecision = async function (e,id,status) {
    e.preventDefault(); const comment = new FormData(e.target).get('comment') || '';
    try { const result = await decideRecord(id,status,comment); closeModal(); toast('Decision saved. The student will see the update.' + emailDeliveryMessage(result)); }
    catch(err){ toast(err.message); }
  };
  window.addLetter = async function (e) {
    e.preventDefault(); const f=new FormData(e.target);
    try { const result = await createRecord('recommendation',{ lecturer_user_id:f.get('lecturer_user_id'), purpose:f.get('purpose'), context:f.get('context')||'' }); closeModal(); toast('Recommendation request saved.' + emailDeliveryMessage(result)); }
    catch(err){ toast(err.message); }
  };
  window.recommendationForm = async function () {
    if (!availableLecturers.length) await refreshSharedRecords(true);
    const options = availableLecturers.map(lecturer =>
      `<option value="${esc(lecturer.user_id)}">${esc(lecturer.full_name)}</option>`
    ).join('');
    openModal('Request a recommendation', `<form onsubmit="addLetter(event)"><div class="field"><label>LECTURER</label><select name="lecturer_user_id" required ${options ? '' : 'disabled'}>${options || '<option value="">No active lecturers are available.</option>'}</select></div><div class="field"><label>PURPOSE</label><select name="purpose"><option>Internship application</option><option>Employment application</option><option>Scholarship application</option><option>Further studies</option><option>Other professional opportunity</option></select></div><div class="field"><label>ADDITIONAL CONTEXT</label><textarea name="context" maxlength="4000" placeholder="Tell the lecturer about the opportunity and deadline."></textarea></div><button class="btn" ${options ? '' : 'disabled'}>Send request</button></form>`);
  };
  window.issueLetter = async function (e,id) {
    e.preventDefault(); const body=new FormData(e.target).get('body');
    try { const result = await request('submit_recommendation', {id:Number(id), body:String(body || '').trim()}); await refreshSharedRecords(true); closeModal(); toast('Recommendation issued.' + emailDeliveryMessage(result)); }
    catch(err){ toast(err.message); }
  };
  window.savePlacement = async function (e) {
    e.preventDefault(); const f=new FormData(e.target);
    try { await createRecord('attachment',{ organisation:f.get('org'), role:f.get('role'), period:f.get('period') }); closeModal(); toast('Attachment submitted to the shared supervisor queue.'); }
    catch(err){ toast(err.message); }
  };
  window.addSupport = async function (e,service) {
    e.preventDefault(); const message=new FormData(e.target).get('message');
    try { await createRecord('support',{ service, message }); closeModal(); toast('Support request sent to the shared support queue.'); }
    catch(err){ toast(err.message); }
  };
  // Shared server-side notification read actions.
window.markNotificationRead = async function(id) {
  try { await request('read_notification', {id:Number(id)}); await refreshSharedRecords(false); }
  catch(error) { console.error(error); toast(error.message || 'Could not update notification.'); }
};
window.markAllNotificationsRead = async function() {
  try {
    for (const n of (db.notifications || []).filter(x => !x.read)) await request('read_notification', {id:Number(n.id)});
    await refreshSharedRecords(false);
    toast('Notifications updated.');
  } catch(error) { console.error(error); toast(error.message || 'Could not update notifications.'); }
};
window.refreshSharedRecords = refreshSharedRecords;
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshSharedRecords(false); });

// Initial shared workflow synchronisation
if (typeof authenticatedUser !== 'undefined' && authenticatedUser) { refreshSharedRecords(true); if (refreshTimer) clearInterval(refreshTimer); refreshTimer = setInterval(() => refreshSharedRecords(false), 12000); }
})();
