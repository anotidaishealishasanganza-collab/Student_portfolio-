/* AU shared MySQL workflow bridge. Records are authoritative on the server. */
(function () {
  const apiUrl = 'api/workflows.php';
  let refreshTimer = null;
  const toLocalRecord = r => ({
    id: Number(r.id), type: r.payload.type || (r.record_type === 'achievement' ? 'Achievement' : r.record_type),
    year: r.payload.year || '', title: r.payload.title || r.payload.organisation || r.record_type,
    detail: r.payload.detail || r.payload.message || '', evidence: r.payload.evidence || '',
    status: r.status, student: r.owner_name, studentId: r.owner_user_id,
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
    await request('create', { type, payload });
    await refreshSharedRecords(true);
  }
  async function decideRecord(id, status, comment) {
    await request('decide', { id, status, comment });
    await refreshSharedRecords(true);
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
  window.addAchievement = async function (e) {
    e.preventDefault(); const f = new FormData(e.target);
    try { await createRecord('achievement', { type:f.get('type'), year:f.get('year'), title:String(f.get('title')).trim(), detail:String(f.get('detail')).trim(), evidence:String(f.get('evidence')||'').trim() }); closeModal(); toast('Achievement submitted to the shared verifier queue.'); }
    catch(err){ toast(err.message); }
  };
  window.saveDecision = async function (e,id,status) {
    e.preventDefault(); const comment = new FormData(e.target).get('comment') || '';
    try { await decideRecord(id,status,comment); closeModal(); toast('Decision saved. The student will see the update.'); }
    catch(err){ toast(err.message); }
  };
  window.addLetter = async function (e) {
    e.preventDefault(); const f=new FormData(e.target);
    try { await createRecord('recommendation',{ to:f.get('to'), purpose:f.get('purpose'), context:f.get('context')||'' }); closeModal(); toast('Recommendation request sent to the shared lecturer queue.'); }
    catch(err){ toast(err.message); }
  };
  window.issueLetter = async function (e,id) {
    e.preventDefault(); const body=new FormData(e.target).get('body');
    try { await decideRecord(id,'Issued',body); closeModal(); toast('Recommendation issued and student notified.'); }
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
