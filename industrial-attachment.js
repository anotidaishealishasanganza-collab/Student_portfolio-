/* Industrial attachment screens use the authenticated university attachment API. */
(function () {
  const endpoint = 'api/industrial_attachment.php';
  const state = {
    userId: '',
    records: [],
    students: [],
    supervisors: [],
    analytics: {},
    organizationCounts: [],
    supervisorCounts: [],
    loading: false,
    loaded: false,
    error: '',
    query: '',
    statusFilter: 'All'
  };
  let searchTimer = null;

  function request(action, fields, multipart) {
    if (!csrfToken) return Promise.reject(new Error('Security token missing. Please sign in again.'));
    const options = {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-CSRF-Token': csrfToken }
    };
    if (multipart) {
      options.body = fields;
    } else {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify({ action, ...fields });
    }
    return fetch(endpoint, options).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Industrial attachment request failed.');
      return result;
    });
  }

  async function load(search = '') {
    if (!authenticatedUser) return;
    state.loading = true;
    state.error = '';
    const parameters = new URLSearchParams();
    if (['admin', 'attachment_coordinator'].includes(currentRole) && search) parameters.set('search', search);
    try {
      const response = await fetch(endpoint + (parameters.toString() ? '?' + parameters.toString() : ''), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Could not load industrial attachment records.');
      state.userId = authenticatedUser.user_id;
      state.records = Array.isArray(result.records) ? result.records : [];
      state.students = Array.isArray(result.students) ? result.students : [];
      state.supervisors = Array.isArray(result.supervisors) ? result.supervisors : [];
      state.analytics = result.analytics || {};
      state.organizationCounts = Array.isArray(result.organization_counts) ? result.organization_counts : [];
      state.supervisorCounts = Array.isArray(result.supervisor_counts) ? result.supervisor_counts : [];
      state.loaded = true;
      state.query = search;
      state.error = '';
    } catch (error) {
      state.error = error.message || 'Could not load industrial attachment records.';
    } finally {
      state.loading = false;
      if (isAttachmentPage()) renderCurrentAttachmentPage();
    }
  }

  function isAttachmentPage() {
    return ['attachment', 'attachment-management', 'attachment-supervision', 'placements', 'workplace-attachments'].includes(currentPage);
  }

  function renderCurrentAttachmentPage() {
    const pages = {
      attachment: renderIndustrialAttachmentPage,
      'attachment-management': renderIndustrialAttachmentManagementPage,
      'attachment-supervision': renderIndustrialAttachmentSupervisionPage,
      placements: renderIndustrialAttachmentSupervisionPage,
      'workplace-attachments': renderIndustrialWorkplacePage
    };
    const renderer = pages[currentPage];
    if (!renderer) return;
    const activeElement = document.activeElement;
    const preserveSearch = Boolean(activeElement && activeElement.classList.contains('search'));
    const selectionStart = preserveSearch ? activeElement.selectionStart : null;
    const content = document.getElementById('pageContent');
    content.innerHTML = renderer();
    if (preserveSearch) {
      const replacement = content.querySelector('.hub-toolbar .search');
      if (replacement) {
        replacement.focus();
        if (typeof selectionStart === 'number') replacement.setSelectionRange(selectionStart, selectionStart);
      }
    }
  }

  function ensureLoaded() {
    if (!authenticatedUser) return '<div class="card"><p class="muted">Sign in to view industrial attachment records.</p></div>';
    if (state.userId !== authenticatedUser.user_id) {
      state.loaded = false;
      state.records = [];
    }
    if (!state.loaded && !state.loading) load();
    if (state.loading && !state.loaded) return '<div class="card"><p class="muted">Loading current industrial attachment records...</p></div>';
    if (state.error) {
      return `<div class="notice">${esc(state.error)} <button class="btn light small" onclick="refreshIndustrialAttachments()">Retry</button></div>`;
    }
    return '';
  }

  function dateAtMidnight(value) {
    return new Date(String(value) + 'T00:00:00');
  }

  function todayDate() {
    const today = new Date();
    return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  }

  function dateDifference(from, to) {
    return Math.round((dateAtMidnight(to).getTime() - dateAtMidnight(from).getTime()) / 86400000);
  }

  function progress(record) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const start = dateAtMidnight(record.start_date);
    const end = dateAtMidnight(record.expected_end_date);
    const duration = end.getTime() - start.getTime();
    if (today.getTime() < start.getTime()) return 0;
    if (today.getTime() >= end.getTime()) return 100;
    if (duration <= 0) return 100;
    return Math.max(0, Math.min(100, Math.round((today.getTime() - start.getTime()) / duration * 100)));
  }

  function durationDays(record) {
    return Math.max(1, dateDifference(record.start_date, record.expected_end_date));
  }

  function completedDays(record) {
    return Math.max(0, Math.min(durationDays(record), dateDifference(record.start_date, todayDate())));
  }

  function remainingDays(record) {
    return Math.max(0, dateDifference(todayDate(), record.expected_end_date));
  }

  function formatDate(value) {
    if (!value) return 'Not recorded';
    const date = dateAtMidnight(value);
    return Number.isNaN(date.getTime()) ? esc(value) : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function currentStudentRecord() {
    return state.records.find(record => record.student_user_id === authenticatedUser.user_id);
  }

  function statusBadge(value) {
    const status = String(value || 'Unknown');
    let style = 'neutral';
    if (['Approved', 'Active', 'Completed'].includes(status)) style = '';
    else if (['Pending Approval', 'Pending Review', 'Changes Requested', 'Not Commenced'].includes(status)) style = 'pending';
    else if (status === 'Rejected') style = 'review';
    return `<span class="badge ${style}">${esc(status)}</span>`;
  }

  function documentLink(document) {
    return `<a class="btn light small" href="${endpoint}?download=${Number(document.id)}">${esc(document.original_filename)}</a>`;
  }

  function recordDetails(record) {
    return `<div class="attachment-kv">
      <div><small>Student</small><b>${esc(record.student_name)} · ${esc(record.student_user_id)}</b></div>
      <div><small>College / School</small><b>${esc(record.college || 'Not on file')}</b></div>
      <div><small>Organisation</small><b>${esc(record.organisation)}</b></div>
      <div><small>Location</small><b>${esc(record.organisation_location)}</b></div>
      <div><small>Physical address</small><b>${esc(record.physical_address)}</b></div>
      <div><small>Department</small><b>${esc(record.department)}</b></div>
      <div><small>Position</small><b>${esc(record.position_title)}</b></div>
      <div><small>Start date</small><b>${formatDate(record.start_date)}</b></div>
      <div><small>Expected completion</small><b>${formatDate(record.expected_end_date)}</b></div>
      <div><small>Academic supervisor</small><b>${esc(record.academic_supervisor_name || 'Not assigned')}</b></div>
      <div><small>Workplace supervisor</small><b>${esc(record.workplace_supervisor_name)}</b></div>
      <div><small>Workplace supervisor contact</small><b>${esc(record.workplace_supervisor_email)} · ${esc(record.workplace_supervisor_phone)}</b></div>
    </div>`;
  }

  function progressCard(record) {
    const value = progress(record);
    const status = record.approval_status === 'Approved'
      ? (record.commencement_status || 'Not Commenced')
      : record.approval_status;
    return `<div class="card attachment-record">
      <div class="attachment-status"><h3 class="panel-title" style="margin:0">${esc(record.organisation)}</h3><div>${statusBadge(record.approval_status)} ${statusBadge(status)}</div></div>
      ${record.approval_status !== 'Approved' && record.approval_reason
        ? `<div class="notice" style="margin-top:14px">${esc(record.approval_reason)}</div>` : ''}
      ${recordDetails(record)}
      <div class="attachment-status"><b>Attachment timeline</b><b>${value}% complete</b></div>
      <div class="attachment-progress" role="progressbar" aria-valuenow="${value}" aria-valuemin="0" aria-valuemax="100" aria-label="Attachment progress"><span style="width:${value}%"></span></div>
      <div class="attachment-kv">
        <div><small>Days completed</small><b>${completedDays(record)}</b></div>
        <div><small>Days remaining</small><b>${remainingDays(record)}</b></div>
        <div><small>Total duration</small><b>${durationDays(record)} days</b></div>
      </div>
      <div class="hub-meta"><span>Placement status</span>${statusBadge(record.approval_status)}<span>Commencement</span>${statusBadge(status)}</div>
      ${record.documents && record.documents.filter(file => file.document_type === 'Acceptance Letter').length
        ? `<p><b>Acceptance letter</b> <span class="hub-actions">${record.documents.filter(file => file.document_type === 'Acceptance Letter').map(documentLink).join('')}</span></p>` : ''}
      ${record.approval_status === 'Approved' ? `<button class="btn small" onclick="openIndustrialReportForm(${record.id})">＋ Submit weekly report</button>` : ''}
    </div>`;
  }

  function renderIndustrialAttachmentPage() {
    const loading = ensureLoaded();
    if (loading) return loading;
    const record = currentStudentRecord();
    if (!record) {
      return `<div class="notice">No industrial attachment placement is linked to your account yet. Your coordinator will add a placement using your existing student account.</div>
        <div class="card"><h3 class="panel-title">Industrial attachment tracking</h3><p class="muted">Placement approval, dates and supervisor details will appear here after the coordinator registers your placement.</p></div>`;
    }
    const reports = record.reports || [];
    const pending = reports.filter(report => report.status === 'Pending Review').length;
    const actions = (record.approval_status === 'Changes Requested' || record.approval_status === 'Rejected')
      ? `<div class="notice">Pending action: review the placement feedback and contact the Industrial Attachment Coordinator.</div>` : '';
    return `<div class="notice success">Your attachment information is linked to your authenticated student account. Progress is calculated from the scheduled start and end dates.</div>
      ${actions}${progressCard(record)}
      <div class="grid">${stat('Weekly reports', reports.length, '▤', 'Submission history')}${stat('Awaiting review', pending, '◷', 'Academic supervisor action')}${stat('Approved reports', reports.filter(report => report.status === 'Approved').length, '✓', 'Reviewed reports')}${stat('Pending actions', pending + (record.approval_status === 'Changes Requested' ? 1 : 0), '!', 'Items requiring follow-up')}</div>
      ${renderReportSection(record, false)}
      ${renderAnnouncements(record)}
      ${renderAssessmentSection(record)}`;
  }

  function renderReportSection(record, reviewer) {
    const reports = Array.isArray(record.reports) ? record.reports : [];
    return `<div class="card" style="margin-top:16px"><div class="section-head"><div><h2>Weekly attachment reports</h2><p>Submission dates, supporting documents, decisions and feedback.</p></div></div>
      ${reports.length ? reports.map(report => `<div class="list-row" style="align-items:flex-start"><div style="flex:1;min-width:0">
        <b>Week ${Number(report.week_number)} · ${formatDate(report.period_start)} – ${formatDate(report.period_end)}</b>
        <small>Submitted ${esc(report.submitted_at || '')} · ${statusBadge(report.status)}</small>
        <p><b>Activities</b><br>${esc(report.activities)}</p>
        <p><b>Skills acquired</b><br>${esc(report.skills_acquired)}</p>
        <p><b>Challenges</b><br>${esc(report.challenges)}</p>
        <p><b>Lessons learnt</b><br>${esc(report.lessons_learnt)}</p>
        ${report.latest_feedback ? `<p class="notice"><b>Supervisor feedback:</b> ${esc(report.latest_feedback)}</p>` : ''}
        ${report.documents && report.documents.length ? `<div class="hub-actions">${report.documents.map(documentLink).join('')}</div>` : ''}
        ${report.submissions && report.submissions.length > 1 ? `<details><summary>Submission history (${report.submissions.length} versions)</summary>${report.submissions.map(submission => {
          const data = submission.submission_data || {};
          return `<div class="hub-message"><b>Version ${Number(submission.version)} · ${esc(submission.submitted_at)}</b>
            <p><b>Activities:</b> ${esc(data.activities || '')}</p>
            <p><b>Skills acquired:</b> ${esc(data.skills_acquired || '')}</p>
            <p><b>Challenges:</b> ${esc(data.challenges || '')}</p>
            <p><b>Lessons learnt:</b> ${esc(data.lessons_learnt || '')}</p></div>`;
        }).join('')}</details>` : ''}
        ${report.history && report.history.length ? `<div class="attachment-history">${report.history.map(entry => `<small>${esc(entry.action)} · ${esc(entry.status)} · ${esc(entry.created_at)}${entry.feedback ? ` · ${esc(entry.feedback)}` : ''}</small>`).join('')}</div>` : ''}
        ${!reviewer && report.status === 'Changes Requested' ? `<button class="btn light small" onclick="openIndustrialReportForm(${record.id},${report.id})">Resubmit corrected report</button>` : ''}
      </div>${reviewer && report.status === 'Pending Review' ? `<div class="hub-actions"><button class="btn small" onclick="openIndustrialReportReview(${report.id},'Approved')">Approve</button><button class="btn light small" onclick="openIndustrialReportReview(${report.id},'Changes Requested')">Request corrections</button></div>` : ''}</div>`).join('') : '<div class="empty">No weekly reports have been submitted.</div>'}</div>`;
  }

  function renderAnnouncements(record) {
    const items = Array.isArray(record.announcements) ? record.announcements : [];
    return `<div class="card" style="margin-top:16px"><h3 class="panel-title">Attachment announcements</h3>
      ${items.length ? items.map(item => `<div class="hub-message"><b>${esc(item.title)}</b><p>${esc(item.message)}</p><small class="muted">${esc(item.created_at)}</small></div>`).join('') : '<p class="muted">No attachment announcements.</p>'}</div>`;
  }

  function renderAssessmentSection(record) {
    const assessments = record.assessments || {};
    return `<div class="card" style="margin-top:16px"><h3 class="panel-title">Final attachment assessment</h3>
      ${['Academic', 'Workplace'].map(type => {
        const assessment = assessments[type];
        return `<div class="hub-message"><b>${type} supervisor assessment</b>${assessment
          ? `<p>${esc(assessment.overall_feedback)}</p><p><b>Practical skills:</b> ${esc(assessment.practical_skills_feedback)}</p><small class="muted">${assessment.score === null ? 'No score' : `${Number(assessment.score)} / 100`} · ${esc(assessment.submitted_at)}</small>`
          : '<p class="muted">Not submitted yet.</p>'}</div>`;
      }).join('')}</div>`;
  }

  function managementStats() {
    const data = state.analytics;
    return `<div class="grid">${stat('Total attachment students', data.total || 0, '▣', 'Registered placements')}${stat('Pending approval', data.pending || 0, '◷', 'Awaiting supervisor decision')}${stat('Approved placements', data.approved || 0, '✓', 'Academic approval')}${stat('Active attachments', data.active || 0, '↗', 'Currently in progress')}${stat('Completed', data.completed || 0, '★', 'Scheduled end date reached')}${stat('Rejected', data.rejected || 0, '×', 'Placement decisions')}${stat('Overdue', data.overdue || 0, '!', 'End date passed; assessment outstanding')}</div>`;
  }

  function filteredRecords() {
    const query = state.query.toLowerCase();
    return state.records.filter(record => {
      const text = [
        record.student_name, record.student_user_id, record.college, record.organisation,
        record.department, record.position_title, record.academic_supervisor_name
      ].join(' ').toLowerCase();
      const matchesStatus = state.statusFilter === 'All' ||
        record.approval_status === state.statusFilter ||
        record.commencement_status === state.statusFilter;
      return (!query || text.includes(query)) && matchesStatus;
    });
  }

  function managementTable() {
    const records = filteredRecords();
    return `<div class="card"><div class="hub-toolbar">
      <input class="search" aria-label="Search placements" placeholder="Search name, student ID, college, organisation..." value="${esc(state.query)}" oninput="filterIndustrialPlacements(this.value)">
      <select aria-label="Filter by status" onchange="filterIndustrialStatus(this.value)">
        ${['All', 'Pending Approval', 'Approved', 'Rejected', 'Changes Requested', 'Active', 'Completed'].map(status => `<option ${state.statusFilter === status ? 'selected' : ''}>${status}</option>`).join('')}
      </select>
      <button class="btn small" onclick="openIndustrialPlacementForm()">＋ Register placement</button>
    </div><div class="table-wrap"><table><thead><tr><th>Student</th><th>College</th><th>Organisation</th><th>Department / Position</th><th>Dates</th><th>Academic Supervisor</th><th>Progress</th><th>Approval</th><th>Actions</th></tr></thead><tbody>
      ${records.map(record => `<tr><td><b>${esc(record.student_name)}</b><small>${esc(record.student_user_id)}</small></td>
        <td>${esc(record.college || 'Not on file')}</td><td>${esc(record.organisation)}<small>${esc(record.organisation_location)}</small></td>
        <td>${esc(record.department)}<small>${esc(record.position_title)}</small></td>
        <td>${formatDate(record.start_date)}<small>to ${formatDate(record.expected_end_date)}</small></td>
        <td>${esc(record.academic_supervisor_name || 'Not assigned')}</td>
        <td>${progress(record)}%<div class="hub-progress"><span style="width:${progress(record)}%"></span></div></td>
        <td>${statusBadge(record.approval_status)}</td>
        <td><div class="hub-actions"><button class="btn light small" onclick="viewIndustrialPlacement(${record.id})">View</button><button class="btn light small" onclick="openIndustrialPlacementForm(${record.id})">Edit</button></div></td></tr>`).join('') || '<tr><td colspan="9" class="empty">No placement records match this search.</td></tr>'}
      </tbody></table></div></div>`;
  }

  function supervisorCharts() {
    const rows = state.supervisorCounts;
    const max = Math.max(1, ...rows.map(row => Number(row.student_count)));
    const organisations = state.organizationCounts;
    const maxOrganisationCount = Math.max(1, ...organisations.map(row => Number(row.student_count)));
    return `<div class="hub-grid"><div class="card"><h3 class="panel-title">Students assigned to academic supervisors</h3>
      ${rows.map(row => `<div class="hub-chart-row"><span>${esc(row.full_name)}</span><div class="hub-chart-track"><span style="width:${Number(row.student_count) / max * 100}%"></span></div><b>${Number(row.student_count)}</b></div>`).join('') || '<p class="muted">No supervisor assignments yet.</p>'}</div>
      <div class="card"><h3 class="panel-title">Organisations hosting students</h3>
      ${organisations.map(item => `<div class="hub-chart-row"><span>${esc(item.organisation)}</span><div class="hub-chart-track"><span style="width:${Number(item.student_count) / maxOrganisationCount * 100}%"></span></div><b>${Number(item.student_count)}</b></div>`).join('') || '<p class="muted">No placements yet.</p>'}</div></div>`;
  }

  function renderIndustrialAttachmentManagementPage() {
    const loading = ensureLoaded();
    if (loading) return loading;
    return `<div class="notice success">Placement records are linked to existing active student accounts. Approval history, reports, notifications and files remain in the AUSSH database and private file storage.</div>
      ${managementStats()}${supervisorCharts()}${managementTable()}
      <div class="card" style="margin-top:16px"><h3 class="panel-title">Attachment announcements</h3><p class="muted">Publish an update to all currently registered attachment students or to one placement.</p><button class="btn small" onclick="openIndustrialAnnouncementForm()">＋ Publish announcement</button></div>`;
  }

  function supervisionStats() {
    const records = state.records;
    const reports = records.flatMap(record => (record.reports || []).map(report => ({ report, record })));
    const today = todayDate();
    return `<div class="grid">${stat('Assigned students', records.length, '▣', 'Your supervision caseload')}${stat('Currently on attachment', records.filter(record => record.commencement_status === 'Active').length, '✓', 'Approved and within dates')}${stat('Pending placement approvals', records.filter(record => ['Pending Approval', 'Changes Requested'].includes(record.approval_status)).length, '◷', 'Awaiting your decision')}${stat('Reports awaiting review', reports.filter(item => item.report.status === 'Pending Review').length, '▤', 'Weekly reports')}${stat('Completed attachments', records.filter(record => record.commencement_status === 'Completed').length, '★', 'Scheduled end date reached')}${stat('Overdue attachments', records.filter(record => record.expected_end_date < today && !(record.assessments || {}).Academic).length, '!', 'Final academic assessment outstanding')}</div>`;
  }

  function supervisorTable() {
    const rows = filteredRecords();
    return `<div class="card"><div class="hub-toolbar"><input class="search" aria-label="Search supervised students" placeholder="Search student, ID, college, organisation..." value="${esc(state.query)}" oninput="filterIndustrialPlacements(this.value)">
      <select aria-label="Filter by approval status" onchange="filterIndustrialStatus(this.value)">
        ${['All', 'Pending Approval', 'Approved', 'Rejected', 'Changes Requested', 'Active', 'Completed'].map(status => `<option ${state.statusFilter === status ? 'selected' : ''}>${status}</option>`).join('')}
      </select></div><div class="table-wrap"><table><thead><tr><th>Student</th><th>College</th><th>Organisation / Department</th><th>Dates</th><th>Progress</th><th>Approval</th><th>Attachment status</th><th>Details</th></tr></thead><tbody>
      ${rows.map(record => `<tr><td><b>${esc(record.student_name)}</b><small>${esc(record.student_user_id)}</small></td><td>${esc(record.college || 'Not on file')}</td>
        <td>${esc(record.organisation)}<small>${esc(record.department)}</small></td><td>${formatDate(record.start_date)}<small>to ${formatDate(record.expected_end_date)}</small></td>
        <td>${progress(record)}%</td><td>${statusBadge(record.approval_status)}</td><td>${statusBadge(record.commencement_status)}</td>
        <td><button class="btn light small" onclick="viewIndustrialPlacement(${record.id})">View details</button></td></tr>`).join('') || '<tr><td colspan="8" class="empty">No assigned students match this filter.</td></tr>'}
      </tbody></table></div></div>`;
  }

  function renderIndustrialAttachmentSupervisionPage() {
    const loading = ensureLoaded();
    if (loading) return loading;
    const records = state.records;
    const reports = records.flatMap(record => (record.reports || []).map(report => ({ report, record })));
    return `<div class="notice">Only placements assigned to your authenticated academic supervisor account are shown. Decisions and feedback are recorded in the shared AUSSH audit trail.</div>
      ${supervisionStats()}${supervisorTable()}
      <div class="hub-grid" style="margin-top:16px">${records.map(record => renderReportSection(record, true)).join('')}</div>
      <div class="card" style="margin-top:16px"><h3 class="panel-title">Reports awaiting review</h3>${reports.filter(item => item.report.status === 'Pending Review').map(item => `<div class="list-row"><div><b>${esc(item.record.student_name)} · Week ${Number(item.report.week_number)}</b><small>${esc(item.record.organisation)} · ${formatDate(item.report.period_start)} – ${formatDate(item.report.period_end)}</small></div><button class="btn small" onclick="viewIndustrialPlacement(${item.record.id})">Review</button></div>`).join('') || '<p class="muted">No weekly reports are awaiting review.</p>'}</div>`;
  }

  function renderIndustrialWorkplacePage() {
    const loading = ensureLoaded();
    if (loading) return loading;
    return `<div class="notice">You can see only placements that match your authenticated workplace supervisor account email.</div>
      <div class="hub-grid">${state.records.map(record => `<div class="card"><h3 class="panel-title">${esc(record.student_name)} · ${esc(record.organisation)}</h3>
        ${recordDetails(record)}<div class="hub-actions"><button class="btn small" onclick="openIndustrialAssessment(${record.id},'Workplace')">Submit workplace assessment</button></div>
        <h3 class="panel-title" style="margin-top:20px">Workplace attendance</h3>
        <div class="hub-actions"><button class="btn light small" onclick="openIndustrialAttendance(${record.id})">＋ Confirm attendance</button></div>
        ${(record.attendance || []).map(entry => `<div class="list-row"><div><b>${formatDate(entry.attendance_date)} · ${statusBadge(entry.attendance_status)}</b><small>${esc(entry.supervisor_note || 'No note')}</small></div></div>`).join('') || '<p class="muted">No attendance entries have been recorded.</p>'}
        ${renderAssessmentSection(record)}</div>`).join('') || '<div class="card empty">No students are assigned to your workplace supervisor account.</div>'}</div>`;
  }

  function filterIndustrialPlacements(value) {
    state.query = String(value || '').trim().toLowerCase();
    if (['admin', 'attachment_coordinator'].includes(currentRole)) {
      if (state.query.length >= 2 && state.query !== state.lastServerSearch) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
          state.lastServerSearch = state.query;
          load(state.query);
        }, 300);
      } else if (state.query.length < 2 && state.lastServerSearch) {
        state.lastServerSearch = '';
        load();
      } else {
        renderCurrentAttachmentPage();
      }
    } else {
      renderCurrentAttachmentPage();
    }
  }

  function filterIndustrialStatus(value) {
    state.statusFilter = value || 'All';
    renderCurrentAttachmentPage();
  }

  function studentOptions(selectedId, editingId) {
    const available = state.students.filter(student =>
      !student.attachment_id || Number(student.attachment_id) === Number(editingId)
    );
    return available.map(student => `<option value="${esc(student.user_id)}" ${selectedId === student.user_id ? 'selected' : ''}>${esc(student.full_name)} · ${esc(student.user_id)} · ${esc(student.college || 'College not on file')}</option>`).join('');
  }

  function openIndustrialPlacementForm(id) {
    const record = id ? state.records.find(item => Number(item.id) === Number(id)) : null;
    if (record && !state.students.some(item => item.user_id === record.student_user_id)) {
      state.students.unshift({
        user_id: record.student_user_id,
        full_name: record.student_name,
        college: record.college,
        attachment_id: record.id
      });
    }
    const studentId = record ? record.student_user_id : '';
    const supervisorOptions = state.supervisors.map(person =>
      `<option value="${esc(person.user_id)}" ${record && record.academic_supervisor_user_id === person.user_id ? 'selected' : ''}>${esc(person.full_name)} · ${esc(person.user_id)}</option>`
    ).join('');
    openModal(record ? 'Edit industrial attachment placement' : 'Register industrial attachment placement',
      `<form onsubmit="saveIndustrialPlacement(event,${record ? Number(record.id) : 'null'})">
        <div class="field"><label>SEARCH STUDENTS BY NAME, ID OR COLLEGE</label><input id="industrialStudentSearch" oninput="searchIndustrialStudents(this.value)" placeholder="Search the registered student directory"></div>
        <div class="field"><label>REGISTERED STUDENT</label><select name="student_user_id" id="industrialStudentSelect" required ${record ? 'disabled' : ''}><option value="">${record ? 'Student link cannot be changed' : 'Select an active student account'}</option>${studentOptions(studentId, id)}</select>${record ? `<input type="hidden" name="student_user_id" value="${esc(studentId)}">` : ''}</div>
        <div class="field"><label>ACADEMIC SUPERVISOR</label><select name="academic_supervisor_user_id" required><option value="">Select a lecturer / attachment supervisor</option>${supervisorOptions}</select></div>
        <div class="hub-form-grid">
          <div class="field"><label>HOST ORGANISATION</label><input name="organisation" maxlength="180" required value="${esc(record ? record.organisation : '')}"></div>
          <div class="field"><label>ORGANISATION LOCATION</label><input name="organisation_location" maxlength="180" required value="${esc(record ? record.organisation_location : '')}"></div>
          <div class="field wide"><label>PHYSICAL ADDRESS</label><textarea name="physical_address" maxlength="500" required>${esc(record ? record.physical_address : '')}</textarea></div>
          <div class="field"><label>DEPARTMENT OF PLACEMENT</label><input name="department" maxlength="180" required value="${esc(record ? record.department : '')}"></div>
          <div class="field"><label>INTERNSHIP / JOB POSITION</label><input name="position_title" maxlength="180" required value="${esc(record ? record.position_title : '')}"></div>
          <div class="field"><label>START DATE</label><input type="date" name="start_date" required value="${esc(record ? record.start_date : '')}"></div>
          <div class="field"><label>EXPECTED COMPLETION DATE</label><input type="date" name="expected_end_date" required value="${esc(record ? record.expected_end_date : '')}"></div>
          <div class="field"><label>WORKPLACE SUPERVISOR NAME</label><input name="workplace_supervisor_name" maxlength="120" required value="${esc(record ? record.workplace_supervisor_name : '')}"></div>
          <div class="field"><label>WORKPLACE SUPERVISOR EMAIL</label><input type="email" name="workplace_supervisor_email" maxlength="254" required value="${esc(record ? record.workplace_supervisor_email : '')}"></div>
          <div class="field"><label>WORKPLACE SUPERVISOR PHONE</label><input name="workplace_supervisor_phone" maxlength="40" required value="${esc(record ? record.workplace_supervisor_phone : '')}"></div>
          <div class="field"><label>ACCEPTANCE LETTER (PDF, JPG OR PNG · MAX 10 MB)</label><input type="file" name="acceptance_letter" accept=".pdf,.jpg,.jpeg,.png"></div>
        </div><button class="btn">${record ? 'Save placement changes' : 'Register placement'}</button>
      </form>`);
  }

  async function searchIndustrialStudents(value) {
    const select = document.getElementById('industrialStudentSelect');
    if (!select) return;
    const query = String(value || '').trim();
    if (query.length < 2) {
      select.innerHTML = '<option value="">Enter at least two characters to search</option>';
      return;
    }
    try {
      const response = await fetch(endpoint + '?search=' + encodeURIComponent(query), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Student search failed.');
      const current = select.value;
      const matches = result.students.filter(student => !student.attachment_id);
      select.innerHTML = '<option value="">Select an active student account</option>' +
        matches.map(student => `<option value="${esc(student.user_id)}">${esc(student.full_name)} · ${esc(student.user_id)} · ${esc(student.college || 'College not on file')}</option>`).join('');
      if (matches.some(student => student.user_id === current)) select.value = current;
      state.students = result.students;
    } catch (error) {
      toast(error.message || 'Student search failed.');
    }
  }

  async function saveIndustrialPlacement(event, id) {
    event.preventDefault();
    if (!csrfToken) return toast('Security token missing. Please sign in again.');
    const form = event.target;
    const data = new FormData(form);
    data.append('action', 'save_placement');
    if (id) data.append('id', String(Number(id)));
    try {
      const result = await request('save_placement', data, true);
      closeModal();
      await load();
      toast(id ? 'Placement updated.' : 'Placement registered and academic supervisor notified.');
      return result;
    } catch (error) {
      toast(error.message || 'Placement could not be saved.');
    }
  }

  function viewIndustrialPlacement(id) {
    const record = state.records.find(item => Number(item.id) === Number(id));
    if (!record) return;
    const actions = ['Pending Approval', 'Changes Requested'].includes(record.approval_status) &&
      ['admin', 'lecturer', 'supervisor'].includes(currentRole)
      ? `<div class="hub-actions"><button class="btn small" onclick="openIndustrialPlacementDecision(${record.id},'Approved')">Approve</button><button class="btn light small" onclick="openIndustrialPlacementDecision(${record.id},'Changes Requested')">Request changes</button><button class="btn light small" onclick="openIndustrialPlacementDecision(${record.id},'Rejected')">Reject</button></div>` : '';
    const academicAction = ['admin', 'lecturer', 'supervisor'].includes(currentRole)
      ? `<button class="btn light small" onclick="openIndustrialAssessment(${record.id},'Academic')">Final academic assessment</button>` : '';
    openModal('Industrial attachment record', `${recordDetails(record)}
      <div class="hub-meta">Placement approval ${statusBadge(record.approval_status)} Commencement ${statusBadge(record.commencement_status)} Progress ${progress(record)}%</div>
      ${record.approval_reason ? `<div class="notice">${esc(record.approval_reason)}</div>` : ''}
      <div class="hub-actions">${actions}${academicAction}</div>
      ${renderReportSection(record, ['admin', 'lecturer', 'supervisor'].includes(currentRole))}
      ${renderAssessmentSection(record)}
      ${renderAnnouncements(record)}
      <div class="card"><h3 class="panel-title">Placement history</h3>${(record.history || []).map(entry => `<div class="list-row"><div><b>${esc(entry.action)}</b><small>${esc(entry.actor_user_id)} · ${esc(entry.created_at)} · ${esc(entry.from_status || '')} → ${esc(entry.to_status || '')}</small>${entry.comment ? `<small>${esc(entry.comment)}</small>` : ''}</div></div>`).join('') || '<p class="muted">No placement decisions recorded.</p>'}</div>`);
  }

  function openIndustrialPlacementDecision(id, status) {
    const reasonRequired = status !== 'Approved';
    openModal(status === 'Approved' ? 'Approve placement' : status === 'Rejected' ? 'Reject placement' : 'Request placement changes',
      `<form onsubmit="saveIndustrialPlacementDecision(event,${Number(id)},'${status}')">
        <p class="muted">${reasonRequired ? 'A written explanation is required and will be visible to the student.' : 'Approval will notify the student and attachment coordinator.'}</p>
        <div class="field"><label>${reasonRequired ? 'EXPLANATION' : 'OPTIONAL COMMENT'}</label><textarea name="comment" maxlength="4000" ${reasonRequired ? 'required' : ''}></textarea></div>
        <button class="btn">${status === 'Approved' ? 'Approve placement' : status === 'Rejected' ? 'Confirm rejection' : 'Request changes'}</button></form>`);
  }

  async function saveIndustrialPlacementDecision(event, id, status) {
    event.preventDefault();
    try {
      await request('review_placement', {
        id: Number(id),
        status,
        comment: String(new FormData(event.target).get('comment') || '').trim()
      });
      closeModal();
      await load();
      toast('Placement decision saved and notifications sent.');
    } catch (error) {
      toast(error.message || 'Placement decision could not be saved.');
    }
  }

  function openIndustrialReportForm(attachmentId, reportId) {
    const record = state.records.find(item => Number(item.id) === Number(attachmentId));
    const report = reportId && record ? (record.reports || []).find(item => Number(item.id) === Number(reportId)) : null;
    const now = todayDate();
    const start = report ? report.period_start : now;
    const end = report ? report.period_end : now;
    const filesLabel = report ? 'Add supporting documents' : 'Supporting documents (optional)';
    openModal(report ? 'Resubmit weekly attachment report' : 'Submit weekly attachment report',
      `<form onsubmit="submitIndustrialReport(event,${Number(attachmentId)},${report ? Number(report.id) : 'null'})">
        <div class="hub-form-grid"><div class="field"><label>WEEK NUMBER</label><input type="number" min="1" max="104" name="week_number" required value="${report ? Number(report.week_number) : ''}" ${report ? 'readonly' : ''}></div>
        <div class="field"><label>REPORTING PERIOD START</label><input type="date" name="period_start" required value="${esc(start)}"></div>
        <div class="field"><label>REPORTING PERIOD END</label><input type="date" name="period_end" required value="${esc(end)}"></div>
        <div class="field wide"><label>ACTIVITIES PERFORMED</label><textarea name="activities" maxlength="12000" required>${esc(report ? report.activities : '')}</textarea></div>
        <div class="field wide"><label>SKILLS ACQUIRED</label><textarea name="skills_acquired" maxlength="6000" required>${esc(report ? report.skills_acquired : '')}</textarea></div>
        <div class="field wide"><label>CHALLENGES ENCOUNTERED</label><textarea name="challenges" maxlength="6000" required>${esc(report ? report.challenges : '')}</textarea></div>
        <div class="field wide"><label>LESSONS LEARNT</label><textarea name="lessons_learnt" maxlength="6000" required>${esc(report ? report.lessons_learnt : '')}</textarea></div>
        <div class="field wide"><label>${filesLabel} (PDF, JPG OR PNG · MAX 10 MB EACH)</label><input type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png" multiple></div></div>
        <button class="btn">${report ? 'Resubmit report' : 'Submit report'}</button></form>`);
  }

  async function submitIndustrialReport(event, attachmentId, reportId) {
    event.preventDefault();
    const data = new FormData(event.target);
    data.append('action', reportId ? 'resubmit_report' : 'submit_report');
    if (reportId) data.append('report_id', String(Number(reportId)));
    else data.append('attachment_id', String(Number(attachmentId)));
    try {
      await request(reportId ? 'resubmit_report' : 'submit_report', data, true);
      closeModal();
      await load();
      toast('Weekly report submitted to your academic supervisor.');
    } catch (error) {
      toast(error.message || 'Weekly report could not be submitted.');
    }
  }

  function openIndustrialReportReview(reportId, status) {
    const reasonRequired = status === 'Changes Requested';
    openModal(status === 'Approved' ? 'Approve weekly report' : 'Request report corrections',
      `<form onsubmit="saveIndustrialReportReview(event,${Number(reportId)},'${status}')">
        <div class="field"><label>${reasonRequired ? 'CORRECTION INSTRUCTIONS' : 'SUPERVISOR FEEDBACK'}</label><textarea name="feedback" maxlength="4000" ${reasonRequired ? 'required' : ''}></textarea></div>
        <button class="btn">${status === 'Approved' ? 'Approve report' : 'Request corrections'}</button></form>`);
  }

  async function saveIndustrialReportReview(event, reportId, status) {
    event.preventDefault();
    try {
      await request('review_report', {
        report_id: Number(reportId),
        status,
        feedback: String(new FormData(event.target).get('feedback') || '').trim()
      });
      closeModal();
      await load();
      toast('Report review saved and the student notified.');
    } catch (error) {
      toast(error.message || 'Report review could not be saved.');
    }
  }

  function openIndustrialAssessment(attachmentId, type) {
    const record = state.records.find(item => Number(item.id) === Number(attachmentId));
    if (!record) return;
    const assessment = (record.assessments || {})[type] || {};
    openModal(`${type} final attachment assessment`,
      `<form onsubmit="saveIndustrialAssessment(event,${Number(attachmentId)},'${type}')">
        <div class="field"><label>OVERALL PERFORMANCE FEEDBACK</label><textarea name="overall_feedback" maxlength="6000" required>${esc(assessment.overall_feedback || '')}</textarea></div>
        <div class="field"><label>PRACTICAL SKILLS / WORKPLACE PERFORMANCE</label><textarea name="practical_skills_feedback" maxlength="6000" required>${esc(assessment.practical_skills_feedback || '')}</textarea></div>
        <div class="field"><label>SCORE (OPTIONAL, 0–100)</label><input type="number" min="0" max="100" step="0.01" name="score" value="${assessment.score === null || assessment.score === undefined ? '' : Number(assessment.score)}"></div>
        <button class="btn">Save assessment</button></form>`);
  }

  async function saveIndustrialAssessment(event, attachmentId, type) {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      await request('save_assessment', {
        attachment_id: Number(attachmentId),
        assessment_type: type,
        overall_feedback: String(form.get('overall_feedback') || '').trim(),
        practical_skills_feedback: String(form.get('practical_skills_feedback') || '').trim(),
        score: String(form.get('score') || '')
      });
      closeModal();
      await load();
      toast('Final attachment assessment saved.');
    } catch (error) {
      toast(error.message || 'Assessment could not be saved.');
    }
  }

  function openIndustrialAttendance(attachmentId) {
    const record = state.records.find(item => Number(item.id) === Number(attachmentId));
    if (!record) return;
    openModal('Confirm workplace attendance',
      `<form onsubmit="saveIndustrialAttendance(event,${Number(attachmentId)})">
        <div class="field"><label>ATTENDANCE DATE</label><input type="date" name="attendance_date" min="${esc(record.start_date)}" max="${esc(record.expected_end_date)}" required value="${todayDate()}"></div>
        <div class="field"><label>ATTENDANCE STATUS</label><select name="attendance_status" required><option>Present</option><option>Absent</option><option>Leave</option></select></div>
        <div class="field"><label>SUPERVISOR NOTE</label><textarea name="supervisor_note" maxlength="2000"></textarea></div>
        <button class="btn">Save attendance</button></form>`);
  }

  async function saveIndustrialAttendance(event, attachmentId) {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      await request('record_attendance', {
        attachment_id: Number(attachmentId),
        attendance_date: String(form.get('attendance_date') || ''),
        attendance_status: String(form.get('attendance_status') || ''),
        supervisor_note: String(form.get('supervisor_note') || '').trim()
      });
      closeModal();
      await load();
      toast('Workplace attendance recorded.');
    } catch (error) {
      toast(error.message || 'Attendance could not be recorded.');
    }
  }

  function openIndustrialAnnouncementForm() {
    const options = state.records.map(record =>
      `<option value="${Number(record.id)}">${esc(record.student_name)} · ${esc(record.organisation)}</option>`
    ).join('');
    openModal('Publish attachment announcement',
      `<form onsubmit="publishIndustrialAnnouncement(event)">
        <div class="field"><label>TITLE</label><input name="title" maxlength="180" required></div>
        <div class="field"><label>MESSAGE</label><textarea name="message" maxlength="4000" required></textarea></div>
        <div class="field"><label>RECIPIENTS</label><select name="attachment_id"><option value="">All attachment students</option>${options}</select></div>
        <button class="btn">Publish announcement</button></form>`);
  }

  async function publishIndustrialAnnouncement(event) {
    event.preventDefault();
    const form = new FormData(event.target);
    try {
      await request('publish_announcement', {
        title: String(form.get('title') || '').trim(),
        message: String(form.get('message') || '').trim(),
        attachment_id: String(form.get('attachment_id') || '')
      });
      closeModal();
      await load();
      toast('Attachment announcement published.');
    } catch (error) {
      toast(error.message || 'Announcement could not be published.');
    }
  }

  function renderIndustrialAttachmentManagementPageSafe() {
    return renderIndustrialAttachmentManagementPage();
  }

  window.renderIndustrialAttachmentPage = renderIndustrialAttachmentPage;
  window.renderIndustrialAttachmentManagementPage = renderIndustrialAttachmentManagementPageSafe;
  window.renderIndustrialAttachmentSupervisionPage = renderIndustrialAttachmentSupervisionPage;
  window.renderIndustrialWorkplacePage = renderIndustrialWorkplacePage;
  window.industrialAttachmentProgress = progress;
  window.refreshIndustrialAttachments = () => load();
  window.filterIndustrialPlacements = filterIndustrialPlacements;
  window.filterIndustrialStatus = filterIndustrialStatus;
  window.openIndustrialPlacementForm = openIndustrialPlacementForm;
  window.searchIndustrialStudents = searchIndustrialStudents;
  window.saveIndustrialPlacement = saveIndustrialPlacement;
  window.viewIndustrialPlacement = viewIndustrialPlacement;
  window.openIndustrialPlacementDecision = openIndustrialPlacementDecision;
  window.saveIndustrialPlacementDecision = saveIndustrialPlacementDecision;
  window.openIndustrialReportForm = openIndustrialReportForm;
  window.submitIndustrialReport = submitIndustrialReport;
  window.openIndustrialReportReview = openIndustrialReportReview;
  window.saveIndustrialReportReview = saveIndustrialReportReview;
  window.openIndustrialAssessment = openIndustrialAssessment;
  window.saveIndustrialAssessment = saveIndustrialAssessment;
  window.openIndustrialAttendance = openIndustrialAttendance;
  window.saveIndustrialAttendance = saveIndustrialAttendance;
  window.openIndustrialAnnouncementForm = openIndustrialAnnouncementForm;
  window.publishIndustrialAnnouncement = publishIndustrialAnnouncement;

  const originalActivate = window.activateAuthenticatedUser;
  if (typeof originalActivate === 'function') {
    window.activateAuthenticatedUser = function (user) {
      originalActivate(user);
      state.userId = user.user_id;
      state.loaded = false;
      state.records = [];
      state.query = '';
      state.statusFilter = 'All';
      load();
    };
  }

  const originalRenderPage = window.renderPage;
  if (typeof originalRenderPage === 'function') {
    window.renderPage = function () {
      if (isAttachmentPage()) {
        renderCurrentAttachmentPage();
        if (authenticatedUser && (!state.loaded || state.userId !== authenticatedUser.user_id) && !state.loading) load();
        return;
      }
      return originalRenderPage.apply(this, arguments);
    };
  }

  setInterval(() => {
    if (isAttachmentPage() && state.loaded && !state.loading) load(state.query);
  }, 60000);
})();
