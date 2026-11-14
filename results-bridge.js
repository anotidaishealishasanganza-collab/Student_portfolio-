/* Server-backed academic results workflow. Marks never use browser storage. */
(function () {
  const endpoint = 'api/results.php';
  const state = { results: [], assignments: [], settings: {}, counts: {}, batches: [], audit: [], students: [], lecturers: [], hods: [], imports: [], importPreview: null, entryMode: 'individual', loading: false, saving: false, error: '', bulkError: '' };
  let refreshTimer = null;

  function addNav(role, title) {
    if (!roles[role]) return;
    if (!roles[role].nav.some(item => item[0] === 'results')) {
      roles[role].nav.push(['results', '▤', title]);
    }
  }
  addNav('student', 'Academic Results');
  addNav('lecturer', 'Academic Results');
  addNav('hod', 'Results Approval');
  addNav('registry', 'Results Publication');
  addNav('admin', 'Results Oversight');

  function escHtml(value) {
    return typeof esc === 'function' ? esc(value == null ? '' : String(value)) : String(value || '');
  }
  function dateLabel(value) {
    if (!value) return '—';
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? escHtml(value) : parsed.toLocaleString();
  }
  function statusBadge(value) {
    const kind = value === 'Published' || value === 'Approved' ? '' :
      (value === 'Submitted' || value === 'Draft' ? 'pending' :
        (value === 'Returned' ? 'review' : 'neutral'));
    return `<span class="badge ${kind}">${escHtml(value)}</span>`;
  }
  function resultRequest(action, data = {}, method = 'POST') {
    const options = { method, credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (method === 'POST') {
      if (!csrfToken) return Promise.reject(new Error('Security token missing. Please sign in again.'));
      options.headers['Content-Type'] = 'application/json';
      options.headers['X-CSRF-Token'] = csrfToken;
      options.body = JSON.stringify({ action, ...data });
    }
    return fetch(endpoint, options).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'The results request could not be completed.');
      return result;
    });
  }
  async function refreshResults(showErrors = false) {
    if (!authenticatedUser) return;
    state.loading = true;
    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Could not load academic results.');
      if (data.current_user && authenticatedUser) Object.assign(authenticatedUser, data.current_user);
      state.error = '';
      state.results = data.results || [];
      state.assignments = data.assignments || [];
      state.imports = data.imports || [];
      state.settings = data.settings || {};
      state.counts = data.counts || {};
      state.batches = data.batches || [];
      state.audit = data.audit || [];
      state.students = data.students || [];
      state.lecturers = data.lecturers || [];
      state.hods = data.hods || [];
      if (currentPage === 'results') renderPage();
    } catch (error) {
      state.results = [];
      state.assignments = [];
      state.error = error.message || 'Could not load academic results.';
      console.error('Academic results sync:', error);
      if (showErrors) toast(error.message);
      if (currentPage === 'results') renderPage();
    } finally {
      state.loading = false;
    }
  }
  function actionMessage(message) {
    return `<div class="notice">${escHtml(message)}</div>`;
  }
  function resultTable(rows, showStudent) {
    return `<div class="table-wrap results-table"><table><thead><tr>${showStudent ? '<th>Student</th>' : ''}
      <th>Course</th><th>Academic year</th><th>Semester</th><th>Coursework</th><th>Examination</th>
      <th>Total</th><th>Grade</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
      ${rows.map(row => `<tr>${showStudent ? `<td><b>${escHtml(row.student_name)}</b><small style="display:block">${escHtml(row.student_university_id)}</small><small style="display:block">${escHtml(row.student_college || 'College / School not on file')}</small></td>` : ''}
        <td><b>${escHtml(row.course_code)}</b><small style="display:block">${escHtml(row.course_name)}</small></td>
        <td>${escHtml(row.academic_year)}</td><td>${escHtml(row.semester)}</td>
        <td>${Number(row.coursework_mark).toFixed(2)}</td><td>${Number(row.examination_mark).toFixed(2)}</td>
        <td><b>${Number(row.total_mark).toFixed(2)}</b></td><td>${escHtml(row.grade || '—')}</td>
        <td>${statusBadge(row.workflow_status)}</td><td>${dateLabel(row.updated_at)}</td>
        <td>${resultAction(row)}</td></tr>`).join('') || `<tr><td colspan="${showStudent ? 11 : 10}" class="empty">No results are available in this view.</td></tr>`}
      </tbody></table></div>`;
  }
  function resultAction(row) {
    if (currentRole === 'lecturer' && ['Draft', 'Returned'].includes(row.workflow_status)) {
      const importedDraft = Number(row.import_id) > 0 && row.workflow_status === 'Draft';
      return `<button class="btn small" onclick="resultEditDraft(${Number(row.id)})">Edit draft</button>
        ${importedDraft ? '<small>Submit this class using its import batch.</small>' :
          `<button class="btn light small" onclick="resultSubmit(${Number(row.id)})">Submit to HOD</button>`}`;
    }
    if (currentRole === 'hod' && row.workflow_status === 'Submitted') {
      return `<button class="btn small" onclick="resultReview(${Number(row.id)},'approve')">Approve</button>
        <button class="btn light small" onclick="resultReview(${Number(row.id)},'return')">Return</button>`;
    }
    if (currentRole === 'registry' && row.workflow_status === 'Published') {
      return `<button class="btn light small" onclick="resultCorrection(${Number(row.id)})">Controlled correction</button>`;
    }
    return '—';
  }
  function lecturerEntryOptions() {
    return `<div class="toolbar" role="group" aria-label="Choose result entry method">
      <button class="btn small ${state.entryMode === 'individual' ? '' : 'light'}" onclick="resultSetEntryMode('individual')">Enter individual results</button>
      <button class="btn small ${state.entryMode === 'bulk' ? '' : 'light'}" onclick="resultSetEntryMode('bulk')">Upload class CSV</button>
    </div>`;
  }
  function individualEntryPanel() {
    const settings = state.settings;
    const canEnter = settings.coursework_max !== null && settings.examination_max !== null;
    const options = state.assignments.map(item => `<option value="${Number(item.id)}">
      ${escHtml(item.student_name)} · ${escHtml(item.student_university_id)} · ${escHtml(item.student_college || 'College / School not on file')} · ${escHtml(item.course_code)} ${escHtml(item.course_name)}
      · ${escHtml(item.academic_year)} ${escHtml(item.semester)}</option>`).join('');
    return `<div class="card"><h2 class="panel-title">Enter individual student result</h2>
      ${!canEnter ? actionMessage('Result entry is unavailable until Registry configures the coursework and examination maxima.') : ''}
      ${state.assignments.length ? `<form onsubmit="resultSaveDraft(event)">
        <div class="form-grid"><div class="field wide"><label>ASSIGNED STUDENT, COURSE, YEAR & SEMESTER</label>
          <select name="assignment_id" id="resultAssignment" required onchange="resultAssignmentChanged()">${options}</select></div>
          <div class="field"><label>COURSEWORK MARK (MAX ${escHtml(settings.coursework_max == null ? '—' : settings.coursework_max)})</label>
            <input name="coursework_mark" id="resultCoursework" type="number" min="0" max="${escHtml(settings.coursework_max || 0)}" step="0.01" required oninput="resultPreviewTotal()"></div>
          <div class="field"><label>EXAMINATION MARK (MAX ${escHtml(settings.examination_max == null ? '—' : settings.examination_max)})</label>
            <input name="examination_mark" id="resultExamination" type="number" min="0" max="${escHtml(settings.examination_max || 0)}" step="0.01" required oninput="resultPreviewTotal()"></div></div>
        <div class="notice" id="resultTotalPreview">Total: enter both marks to preview.</div>
        <button class="btn" ${canEnter ? '' : 'disabled'}>Review & save as draft</button></form>` :
        '<div class="empty">No active course allocations are assigned to your account. Contact Registry.</div>'}</div>`;
  }
  function importPreviewPanel() {
    const preview = state.importPreview;
    if (!preview) return '';
    const errors = Array.isArray(preview.rows) ? preview.rows : [];
    return `<div class="card" style="margin-top:16px"><div class="section-head"><div>
      <h2 class="panel-title">Import preview · ${escHtml(preview.import.source_filename || '')}</h2>
      <p>${escHtml(preview.import.course_code)} · ${escHtml(preview.import.academic_year)} · ${escHtml(preview.import.semester)}</p>
      </div>${statusBadge(preview.import.import_status)}</div>
      <p>${Number(preview.import.valid_rows)} valid row(s) · ${Number(preview.import.invalid_rows)} invalid row(s)</p>
      ${preview.import.import_status === 'Previewed' && Number(preview.import.invalid_rows) > 0
        ? actionMessage('Nothing has been imported. Correct every invalid row, download a fresh roster if needed, and upload the corrected CSV again.')
        : ''}
      <div class="table-wrap results-table"><table><thead><tr><th>CSV row</th><th>Student ID</th><th>Course</th>
        <th>Coursework</th><th>Examination</th><th>Total</th><th>Validation</th></tr></thead><tbody>
        ${errors.map(row => {
          const rowErrors = Array.isArray(row.validation_errors) ? row.validation_errors : [];
          return `<tr><td>${row.row_type === 'missing' ? 'Missing roster row' : Number(row.row_number)}</td>
            <td>${escHtml(row.student_university_id || '—')}</td><td>${escHtml(row.course_code || '—')}</td>
            <td>${row.coursework_mark == null ? '—' : Number(row.coursework_mark).toFixed(2)}</td>
            <td>${row.examination_mark == null ? '—' : Number(row.examination_mark).toFixed(2)}</td>
            <td>${row.total_mark == null ? '—' : Number(row.total_mark).toFixed(2)}</td>
            <td>${rowErrors.length ? `<span class="badge review">${escHtml(rowErrors.join(' '))}</span>` : statusBadge(row.row_status)}</td></tr>`;
        }).join('') || '<tr><td colspan="7" class="empty">This import has no preview rows.</td></tr>'}
        </tbody></table></div>
      ${preview.import.import_status === 'Previewed' && Number(preview.import.invalid_rows) === 0
        ? `<p><button class="btn" onclick="resultImportDrafts()">Save ${Number(preview.import.valid_rows)} valid row(s) as drafts</button></p>`
        : ''}
      ${preview.import.import_status === 'Drafts Saved'
        ? `<p class="notice">All ${Number(preview.import.valid_rows)} class results are saved as drafts and remain hidden from students.</p>
           <button class="btn" onclick="resultConfirmImportSubmit()">Confirm submission of all drafts to HOD</button>`
        : ''}
      ${preview.import.import_status === 'Submitted'
        ? '<p class="notice">These results have been submitted to the HOD approval queue. Publication still requires HOD approval and Registry action.</p>'
        : ''}</div>`;
  }
  function importHistoryPanel() {
    if (!state.imports.length) return '';
    return `<div class="card" style="margin-top:16px"><h2 class="panel-title">Recent class imports</h2>
      ${state.imports.map(job => `<div class="list-row"><div><b>${escHtml(job.source_filename)}</b>
        <small>${escHtml(job.course_code)} · ${escHtml(job.academic_year)} ${escHtml(job.semester)} · ${Number(job.valid_rows)} valid / ${Number(job.invalid_rows)} invalid · ${dateLabel(job.created_at)}</small>
        </div><div>${statusBadge(job.import_status)}
          <button class="btn light small" onclick="resultLoadImport('${escHtml(job.import_token)}')">Review import</button>
          ${job.import_status === 'Drafts Saved' ? `<button class="btn small" onclick="resultStartImportSubmit('${escHtml(job.import_token)}')">Submit drafts</button>` : ''}
        </div></div>`).join('')}</div>`;
  }
  function bulkEntryPanel() {
    const courseSelections = [...new Map(state.assignments.map(item => [
      [item.department_code, item.course_code, item.academic_year, item.semester].join('|'),
      item
    ])).values()];
    const options = courseSelections.map(item => `<option value="${Number(item.id)}">
      ${escHtml(item.course_code)} · ${escHtml(item.course_name)} · ${escHtml(item.academic_year)} ${escHtml(item.semester)}</option>`).join('');
    return `<div class="card"><h2 class="panel-title">Upload results for an entire class</h2>
      <p class="muted">CSV UTF-8 only. XLSX parsing requires PhpSpreadsheet, which is not installed in this project. Save Excel workbooks as CSV UTF-8 before upload.</p>
      ${state.settings.coursework_max == null || state.settings.examination_max == null
        ? actionMessage('Registry must configure the official component maxima before class results can be imported.')
        : ''}
      ${state.assignments.length ? `<div class="field"><label>ASSIGNED COURSE, ACADEMIC YEAR & SEMESTER</label>
          <select id="resultBulkAssignment" required>${options}</select></div>
        <div class="toolbar"><button class="btn light small" onclick="resultDownloadTemplate()">Download class roster template</button>
          <span class="muted">Template includes every active student allocated to this class.</span></div>
        ${state.bulkError ? `<div class="notice" role="alert">${escHtml(state.bulkError)}</div>` : ''}
        <form onsubmit="resultBulkPreview(event)">
          <input type="hidden" name="assignment_id">
          <div class="field"><label>CLASS RESULTS FILE (.CSV)</label>
            <input type="file" name="file" accept=".csv,text/csv" required></div>
          <button class="btn">Upload & preview all rows</button>
        </form>` :
        '<div class="empty">No active course allocations are available for bulk upload.</div>'}
      ${importPreviewPanel()}${importHistoryPanel()}</div>`;
  }
  function lecturerPage() {
    return `<div class="notice">Only Registry-provisioned student/course allocations are available. Marks are checked against the configured assessment maxima; official grades are shown only when the institution supplies a grading scale.</div>
      ${lecturerEntryOptions()}${state.entryMode === 'individual' ? individualEntryPanel() : bulkEntryPanel()}
      <div class="card" style="margin-top:16px"><h2 class="panel-title">My submitted results & drafts</h2>
        ${resultTable(state.results, true)}</div>`;
  }
  function hodPage() {
    const pending = state.results.filter(row => row.workflow_status === 'Submitted');
    const history = state.results.filter(row => row.workflow_status !== 'Submitted');
    return `<div class="notice">This queue contains only results allocated to your HOD account. Approvals are forwarded to Registry; returning a result requires a correction reason.</div>
      <div class="card"><h2 class="panel-title">Awaiting departmental approval (${pending.length})</h2>${resultTable(pending, true)}</div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">Reviewed results</h2>${resultTable(history, true)}</div>`;
  }
  function settingsForm() {
    const settings = state.settings;
    const scale = settings.grade_scale ? JSON.stringify(settings.grade_scale, null, 2) : '';
    return `<form onsubmit="resultSaveSettings(event)"><div class="form-grid">
      <div class="field"><label>COURSEWORK MAXIMUM</label><input name="coursework_max" type="number" min="0.01" max="100" step="0.01" value="${escHtml(settings.coursework_max == null ? '' : settings.coursework_max)}" required></div>
      <div class="field"><label>EXAMINATION MAXIMUM</label><input name="examination_max" type="number" min="0.01" max="100" step="0.01" value="${escHtml(settings.examination_max == null ? '' : settings.examination_max)}" required></div>
      <div class="field wide"><label>OFFICIAL GRADE SCALE (OPTIONAL JSON)</label><textarea name="grade_scale" placeholder='[{"min":80,"max":100,"grade":"A"}]'>${escHtml(scale)}</textarea>
        <small class="muted">No Africa University grading boundaries are assumed. Leave empty unless an institution-approved scale is provided. Ranges may not overlap.</small></div>
      </div><button class="btn">Save marking rules</button></form>`;
  }
  function assignmentForm() {
    const choices = (rows, label) => rows.map(item => `<option value="${escHtml(item.user_id)}">${escHtml(item.full_name)} · ${escHtml(item.user_id)}</option>`).join('') || `<option value="">No active ${label} accounts</option>`;
    return `<form onsubmit="resultCreateAssignment(event)"><div class="form-grid">
      <div class="field"><label>STUDENT</label><select name="student_user_id" required>${choices(state.students, 'student')}</select></div>
      <div class="field"><label>LECTURER</label><select name="lecturer_user_id" required>${choices(state.lecturers, 'lecturer')}</select></div>
      <div class="field"><label>DEPARTMENT / UNIT CODE</label><input name="department_code" maxlength="64" required></div>
      <div class="field"><label>HOD REVIEWER</label><select name="hod_user_id" required>${choices(state.hods, 'HOD')}</select></div>
      <div class="field"><label>COURSE CODE</label><input name="course_code" maxlength="40" required></div>
      <div class="field"><label>COURSE NAME</label><input name="course_name" maxlength="180" required></div>
      <div class="field"><label>ACADEMIC YEAR (e.g. 2026/2027)</label><input name="academic_year" pattern="\\d{4}/\\d{4}" placeholder="2026/2027" required></div>
      <div class="field"><label>SEMESTER</label><input name="semester" maxlength="32" placeholder="Semester 1" required></div>
      </div><button class="btn">Create course allocation</button></form>`;
  }
  function registryPage() {
    const terms = [...new Set(state.results.filter(row => row.workflow_status === 'Approved')
      .map(row => `${row.academic_year}|${row.semester}`))];
    return `<div class="notice">Only HOD-approved results can be published. Publication is batched by academic year and semester. Corrections to published results require a reason and are permanently audited.</div>
      <div class="card"><h2 class="panel-title">Institution-approved marking rules</h2>
        ${settingsForm()}<p class="muted">Maximum total is 100. Configure the actual component maxima and approved grade boundaries supplied by Africa University.</p></div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">Create authorised course allocations</h2>
        <p class="muted">Allocations bind a student, lecturer, course, academic term, department and approving HOD. This does not bypass HOD approval.</p>${assignmentForm()}</div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">HOD-approved results awaiting publication</h2>
        ${terms.map(term => { const [year, semester] = term.split('|');
          const count = state.results.filter(row => row.workflow_status === 'Approved' && row.academic_year === year && row.semester === semester).length;
          return `<div class="list-row"><div><b>${escHtml(year)} · ${escHtml(semester)}</b><small>${count} approved result(s)</small></div>
            <button class="btn small" data-year="${escHtml(year)}" data-semester="${escHtml(semester)}" onclick="resultPublishFromButton(this)">Review & publish batch</button></div>`;
        }).join('') || '<div class="empty">No HOD-approved results are awaiting publication.</div>'}</div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">All academic results</h2>${resultTable(state.results, true)}</div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">Publication history</h2>${batchTable()}</div>`;
  }
  function batchTable() {
    return `<div class="table-wrap results-table"><table><thead><tr><th>Academic year</th><th>Semester</th><th>Results</th><th>Published by</th><th>Publication date</th><th>Batch</th></tr></thead><tbody>
      ${state.batches.map(batch => `<tr><td>${escHtml(batch.academic_year)}</td><td>${escHtml(batch.semester)}</td>
        <td>${Number(batch.result_count)}</td><td>${escHtml(batch.publisher_name || batch.publisher_user_id)}</td>
        <td>${dateLabel(batch.published_at)}</td><td>${Number(batch.id)}</td></tr>`).join('') ||
        '<tr><td colspan="6" class="empty">No publication batches yet.</td></tr>'}</tbody></table></div>`;
  }
  function studentPage() {
    if (!state.results.length) {
      return `<div class="card"><h2 class="panel-title">Academic Results</h2>
        <p class="muted">College / School: ${escHtml(authenticatedUser.college || 'Not on file')}</p>
        <div class="empty">No results have been officially published to your account yet. Draft, submitted and HOD-approved results are not visible here.</div></div>`;
    }
    const years = [...new Set(state.results.map(row => row.academic_year))];
    return `<div class="notice">These are official results published by Academic Registry. Previous published semesters remain available below.</div>
      <p class="muted">College / School: ${escHtml(authenticatedUser.college || 'Not on file')}</p>
      ${years.map(year => `<section class="card" style="margin-bottom:16px"><h2 class="panel-title">${escHtml(year)}</h2>
        ${[...new Set(state.results.filter(row => row.academic_year === year).map(row => row.semester))].map(semester => {
          const rows = state.results.filter(row => row.academic_year === year && row.semester === semester);
          return `<h3>${escHtml(semester)}</h3><div class="table-wrap results-table"><table><thead><tr><th>Course code</th><th>Course name</th>
            <th>Coursework</th><th>Examination</th><th>Total</th><th>Grade</th><th>Published</th></tr></thead><tbody>
            ${rows.map(row => `<tr><td><b>${escHtml(row.course_code)}</b></td><td>${escHtml(row.course_name)}</td>
              <td>${Number(row.coursework_mark).toFixed(2)}</td><td>${Number(row.examination_mark).toFixed(2)}</td>
              <td><b>${Number(row.total_mark).toFixed(2)}</b></td><td>${escHtml(row.grade || '—')}</td>
              <td>${dateLabel(row.publication_date)}</td></tr>`).join('')}</tbody></table></div>`;
        }).join('')}</section>`).join('')}`;
  }
  function adminPage() {
    return `<div class="notice">Read-only oversight: administrators cannot approve or publish results. Any Registry correction is recorded with its reason and before/after values.</div>
      <div class="grid">${stat('Pending HOD approval', state.counts.pending || 0, '◷', 'Submitted')}${stat('HOD approved', state.counts.approved || 0, '✓', 'Awaiting Registry')}${stat('Published', state.counts.published || 0, '▤', 'Official results')}${stat('Returned', state.counts.returned || 0, '↩', 'For correction')}</div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">Publication history</h2>${batchTable()}</div>
      <div class="card" style="margin-top:16px"><h2 class="panel-title">Result audit trail</h2>
      <div class="table-wrap results-table"><table><thead><tr><th>Date</th><th>Actor ID</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody>
        ${state.audit.map(row => `<tr><td>${dateLabel(row.created_at)}</td><td>${escHtml(row.actor_user_id)}</td>
          <td>${escHtml(row.action)}</td><td>${escHtml(row.record_type)} #${Number(row.record_id)}</td>
          <td><code>${escHtml(row.details || '')}</code></td></tr>`).join('') ||
          '<tr><td colspan="5" class="empty">No results audit events have been recorded.</td></tr>'}</tbody></table></div></div>
      `;
  }
  function renderResults() {
    if (state.error) return `<div class="notice" role="alert">Academic results could not be loaded: ${escHtml(state.error)}</div>`;
    if (state.loading && !state.results.length) return '<div class="card">Loading academic results…</div>';
    if (currentRole === 'lecturer') return lecturerPage();
    if (currentRole === 'hod') return hodPage();
    if (currentRole === 'registry') return registryPage();
    if (currentRole === 'admin') return adminPage();
    if (currentRole === 'student') return studentPage();
    return '<div class="card">Your role is not authorised to access academic results.</div>';
  }

  const originalRenderPage = window.renderPage;
  if (typeof originalRenderPage === 'function') {
    window.renderPage = function () {
      if (currentPage === 'results') {
        document.getElementById('pageContent').innerHTML = renderResults();
        return;
      }
      return originalRenderPage();
    };
  }
  const originalActivate = window.activateAuthenticatedUser;
  if (typeof originalActivate === 'function') {
    window.activateAuthenticatedUser = function (user) {
      originalActivate(user);
      refreshResults(true);
      if (refreshTimer) clearInterval(refreshTimer);
      refreshTimer = setInterval(() => refreshResults(false), 20000);
    };
  }
  window.resultSetEntryMode = function (mode) {
    if (!['individual', 'bulk'].includes(mode)) return;
    state.entryMode = mode;
    renderPage();
  };
  window.resultDownloadTemplate = function () {
    const assignmentId = document.getElementById('resultBulkAssignment').value;
    if (!assignmentId) return toast('Select an assigned class before downloading its template.');
    window.location.href = `${endpoint}?action=download_template&assignment_id=${encodeURIComponent(assignmentId)}`;
  };
  window.resultBulkPreview = async function (event) {
    event.preventDefault();
    const form = event.target;
    const assignment = document.getElementById('resultBulkAssignment').value;
    const file = form.elements.file.files[0];
    if (!assignment || !file) return toast('Select a class and CSV file.');
    const formData = new FormData(form);
    formData.set('action', 'preview_import');
    formData.set('assignment_id', assignment);
    state.bulkError = '';
    const button = form.querySelector('button[type="submit"], button:not([type])');
    if (button) {
      button.disabled = true;
      button.textContent = 'Validating every row…';
    }
    try {
      if (!csrfToken) throw new Error('Security token missing. Please sign in again.');
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'The CSV could not be previewed.');
      state.importPreview = { import: data.import, rows: data.rows || [] };
      state.imports = [data.import, ...state.imports.filter(job => job.import_token !== data.import.import_token)];
      state.entryMode = 'bulk';
      renderPage();
    } catch (error) {
      state.bulkError = error.message || 'The CSV could not be previewed.';
      toast(state.bulkError);
      renderPage();
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = 'Upload & preview all rows';
      }
    }
  };
  window.resultLoadImport = async function (token) {
    try {
      const data = await resultRequest('get_import', { import_token: token });
      state.importPreview = { import: data.import, rows: data.rows || [] };
      state.entryMode = 'bulk';
      state.bulkError = '';
      renderPage();
    } catch (error) {
      toast(error.message);
    }
  };
  window.resultImportDrafts = async function () {
    const job = state.importPreview && state.importPreview.import;
    if (!job || job.import_status !== 'Previewed' || Number(job.invalid_rows) !== 0) {
      return toast('Only a fully valid preview can be imported.');
    }
    await perform('import_drafts', { import_token: job.import_token }, 'Class results saved as drafts.');
    await resultLoadImport(job.import_token);
  };
  window.resultStartImportSubmit = function (token) {
    window.resultLoadImport(token).then(() => window.resultConfirmImportSubmit());
  };
  window.resultConfirmImportSubmit = function () {
    const job = state.importPreview && state.importPreview.import;
    if (!job || job.import_status !== 'Drafts Saved') return toast('No imported draft batch is ready for submission.');
    openModal('Confirm class submission to HOD', `<p>Submit all <b>${Number(job.valid_rows)}</b> draft result(s) for
      <b>${escHtml(job.course_code)} · ${escHtml(job.academic_year)} ${escHtml(job.semester)}</b>?</p>
      <p>This action sends the whole class batch to its assigned HOD(s). It does not publish any results.</p>
      <button class="btn" onclick="resultSubmitImport('${escHtml(job.import_token)}')">Confirm submission to HOD</button>`);
  };
  window.resultSubmitImport = async function (token) {
    await perform('submit_import', { import_token: token, confirmed: true }, 'Class results submitted to the HOD approval queue.');
    await resultLoadImport(token);
  };
  async function perform(action, payload, success) {
    if (state.saving) return;
    state.saving = true;
    const buttons = Array.from(document.querySelectorAll('#pageContent button, #modal button'))
      .map(button => ({ button, wasDisabled: button.disabled }));
    buttons.forEach(item => { item.button.disabled = true; });
    document.getElementById('pageContent').setAttribute('aria-busy', 'true');
    try {
      await resultRequest(action, payload);
      closeModal();
      toast(success);
      await refreshResults(true);
    } catch (error) {
      toast(error.message);
    } finally {
      state.saving = false;
      document.getElementById('pageContent').removeAttribute('aria-busy');
      buttons.forEach(item => { item.button.disabled = item.wasDisabled; });
    }
  }
  window.resultAssignmentChanged = function () {
    const assignment = state.assignments.find(item => Number(item.id) === Number(document.getElementById('resultAssignment').value));
    window.resultEditingId = null;
    document.getElementById('resultCoursework').value = '';
    document.getElementById('resultExamination').value = '';
    resultPreviewTotal();
    return assignment;
  };
  window.resultPreviewTotal = function () {
    const coursework = Number(document.getElementById('resultCoursework').value);
    const examination = Number(document.getElementById('resultExamination').value);
    const preview = document.getElementById('resultTotalPreview');
    if (!Number.isFinite(coursework) || !Number.isFinite(examination) ||
      document.getElementById('resultCoursework').value === '' ||
      document.getElementById('resultExamination').value === '') {
      preview.textContent = 'Total: enter both marks to preview.';
      return;
    }
    preview.textContent = `Total mark: ${(coursework + examination).toFixed(2)} / ${state.settings.coursework_max + state.settings.examination_max}`;
  };
  window.resultEditDraft = function (id) {
    const row = state.results.find(item => Number(item.id) === id);
    if (!row) return;
    const assignment = state.assignments.find(item => Number(item.id) === Number(row.assignment_id));
    if (!assignment) return toast('This course allocation is no longer active. Contact Registry.');
    document.getElementById('resultAssignment').value = assignment.id;
    document.getElementById('resultCoursework').value = row.coursework_mark;
    document.getElementById('resultExamination').value = row.examination_mark;
    window.resultEditingId = id;
    resultPreviewTotal();
    document.getElementById('resultCoursework').focus();
  };
  window.resultSaveDraft = function (event) {
    event.preventDefault();
    const form = new FormData(event.target);
    const assignment = state.assignments.find(item => Number(item.id) === Number(form.get('assignment_id')));
    const coursework = Number(form.get('coursework_mark'));
    const examination = Number(form.get('examination_mark'));
    const total = coursework + examination;
    if (!assignment || coursework < 0 || examination < 0 || total > 100) {
      toast('Check the assigned course and mark ranges.');
      return;
    }
    openModal('Review result before saving', `<p><b>${escHtml(assignment.student_name)}</b> · ${escHtml(assignment.student_university_id)}</p>
      <p>${escHtml(assignment.course_code)} · ${escHtml(assignment.course_name)} · ${escHtml(assignment.academic_year)} ${escHtml(assignment.semester)}</p>
      <div class="list-row"><span>Coursework</span><b>${coursework.toFixed(2)} / ${state.settings.coursework_max}</b></div>
      <div class="list-row"><span>Examination</span><b>${examination.toFixed(2)} / ${state.settings.examination_max}</b></div>
      <div class="list-row"><span>Total</span><b>${total.toFixed(2)} / ${state.settings.coursework_max + state.settings.examination_max}</b></div>
      <p class="muted">A saved draft is not official and is not visible to the student. Submit it separately to the HOD after review.</p>
      <button class="btn" onclick="resultConfirmSave(${Number(assignment.id)},${coursework},${examination})">Save as draft</button>`);
  };
  window.resultConfirmSave = function (assignmentId, coursework, examination) {
    perform('save_draft', {
      assignment_id: assignmentId, coursework_mark: coursework,
      examination_mark: examination, result_id: window.resultEditingId || null
    }, 'Draft saved in the results system.');
    window.resultEditingId = null;
  };
  window.resultSubmit = function (id) {
    const row = state.results.find(item => Number(item.id) === id);
    if (!row) return;
    openModal('Submit result to HOD', `<p>Submit <b>${escHtml(row.course_code)}</b> for ${escHtml(row.student_university_id)}?</p>
      <p>Coursework ${Number(row.coursework_mark).toFixed(2)} + examination ${Number(row.examination_mark).toFixed(2)} = <b>${Number(row.total_mark).toFixed(2)}</b>.</p>
      <button class="btn" onclick="resultConfirmSubmit(${id})">Confirm submission</button>`);
  };
  window.resultConfirmSubmit = function (id) { perform('submit', { result_id: id }, 'Result submitted to the HOD.'); };
  window.resultReview = function (id, decision) {
    const row = state.results.find(item => Number(item.id) === id);
    if (!row) return;
    if (decision === 'approve') {
      openModal('Approve result', `<p>Approve ${escHtml(row.course_code)} for ${escHtml(row.student_name)} and forward it to Registry?</p>
        <p>Total: <b>${Number(row.total_mark).toFixed(2)}</b></p><button class="btn" onclick="resultConfirmReview(${id},'approve','')">Approve & forward</button>`);
      return;
    }
    openModal('Return result for correction', `<p>${escHtml(row.course_code)} · ${escHtml(row.student_name)}</p>
      <form onsubmit="resultConfirmReturn(event,${id})"><div class="field"><label>REASON FOR RETURN</label>
        <textarea name="reason" maxlength="2000" required></textarea></div><button class="btn">Return to lecturer</button></form>`);
  };
  window.resultConfirmReview = function (id, decision, reason) {
    perform('review', { result_id: id, decision, reason }, decision === 'approve' ? 'Result approved and forwarded to Registry.' : 'Result returned to lecturer.');
  };
  window.resultConfirmReturn = function (event, id) {
    event.preventDefault();
    const reason = new FormData(event.target).get('reason');
    window.resultConfirmReview(id, 'return', String(reason || '').trim());
  };
  window.resultSaveSettings = function (event) {
    event.preventDefault();
    const form = new FormData(event.target);
    perform('save_settings', {
      coursework_max: form.get('coursework_max'),
      examination_max: form.get('examination_max'),
      grade_scale: form.get('grade_scale')
    }, 'Institutional marking rules saved.');
  };
  window.resultCreateAssignment = function (event) {
    event.preventDefault();
    const form = new FormData(event.target);
    const payload = {};
    for (const [key, value] of form.entries()) payload[key] = value;
    perform('create_assignment', payload, 'Course allocation created.');
  };
  window.resultPublish = function (year, semester) {
    const rows = state.results.filter(row => row.workflow_status === 'Approved' &&
      row.academic_year === year && row.semester === semester);
    openModal('Review publication batch', `<p>Publish <b>${rows.length}</b> HOD-approved result(s) for <b>${escHtml(year)} · ${escHtml(semester)}</b>?</p>
      <p>Students will be notified and results will become visible as official records. This action is recorded in the audit history.</p>
      <form onsubmit="resultConfirmPublish(event)">
        <input type="hidden" name="academic_year" value="${escHtml(year)}">
        <input type="hidden" name="semester" value="${escHtml(semester)}">
        <label><input type="checkbox" required> I reviewed this batch and confirm official publication.</label>
        <p><button class="btn">Publish official results</button></p></form>`);
  };
  window.resultPublishFromButton = function (button) {
    window.resultPublish(button.dataset.year, button.dataset.semester);
  };
  window.resultConfirmPublish = function (event) {
    event.preventDefault();
    const form = new FormData(event.target);
    perform('publish', {
      academic_year: form.get('academic_year'),
      semester: form.get('semester'),
      confirmed: true
    }, 'Official results published.');
  };
  window.resultCorrection = function (id) {
    const row = state.results.find(item => Number(item.id) === id);
    if (!row) return;
    openModal('Registry correction to published result', `<p><b>${escHtml(row.student_name)}</b> · ${escHtml(row.course_code)} · ${escHtml(row.academic_year)} ${escHtml(row.semester)}</p>
      <p>Published marks: ${Number(row.coursework_mark).toFixed(2)} coursework + ${Number(row.examination_mark).toFixed(2)} exam = ${Number(row.total_mark).toFixed(2)}.</p>
      <form onsubmit="resultConfirmCorrection(event,${id})"><div class="form-grid">
        <div class="field"><label>CORRECTED COURSEWORK</label><input name="coursework_mark" type="number" min="0" max="${escHtml(state.settings.coursework_max)}" step="0.01" value="${Number(row.coursework_mark).toFixed(2)}" required></div>
        <div class="field"><label>CORRECTED EXAMINATION</label><input name="examination_mark" type="number" min="0" max="${escHtml(state.settings.examination_max)}" step="0.01" value="${Number(row.examination_mark).toFixed(2)}" required></div>
        <div class="field wide"><label>CORRECTION REASON (REQUIRED)</label><textarea name="reason" maxlength="2000" required></textarea></div></div>
        <button class="btn">Save audited correction</button></form>`);
  };
  window.resultConfirmCorrection = function (event, id) {
    event.preventDefault();
    const form = new FormData(event.target);
    perform('correct_published', {
      result_id: id, coursework_mark: form.get('coursework_mark'),
      examination_mark: form.get('examination_mark'), reason: form.get('reason')
    }, 'Published result corrected and audit history recorded.');
  };

  if (typeof authenticatedUser !== 'undefined' && authenticatedUser) {
    refreshResults(true);
    refreshTimer = setInterval(() => refreshResults(false), 20000);
  }
})();
