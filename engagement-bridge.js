/* Database-backed Student Engagement Hub views and actions. */
(function () {
  const endpoint = 'api/engagement.php';
  const state = {
    items: [],
    opportunities: [],
    resources: [],
    messages: [],
    contacts: [],
    feed: [],
    notifications: [],
    metrics: {},
    permissions: {},
    filters: { opportunities: 'All', opportunityClosing: 'Any closing date', deadlines: 'All', resources: 'All', feed: 'All' },
    search: { opportunities: '', resources: '', feed: '' }
  };
  let refreshTimer = null;
  const staffPageRoles = ['lecturer', 'verifier', 'hod', 'registry', 'supervisor', 'chaplaincy', 'dean', 'admin', 'career_advisor'];
  const opportunityTypes = ['Internship', 'Scholarship', 'Employment', 'Graduate programme', 'Volunteering', 'Competition'];
  const resourceTypes = ['CV template', 'Cover letter template', 'Career guide', 'Academic resource', 'Application guide', 'University document'];

  function addNav(roleKey, page, icon, title) {
    const role = roles[roleKey];
    if (!role) return;
    const existing = role.nav.find(item => item[0] === page);
    if (existing) { existing[1] = icon; existing[2] = title; }
    else role.nav.push([page, icon, title]);
  }
  ['student', 'alumni'].forEach(role => {
    addNav(role, 'notifications', '♧', 'Notifications');
    addNav(role, 'deadlines', '◷', 'Deadlines & events');
    addNav(role, 'resources', '▣', 'Resource library');
    addNav(role, 'activity', '✦', 'Student activity');
    addNav(role, 'messages', '✉', 'Messages');
    addNav(role, 'opportunities', '↗', 'Opportunities');
  });
  staffPageRoles.forEach(role => {
    addNav(role, 'notifications', '♧', 'Notifications');
    addNav(role, 'deadlines', '◷', 'Deadlines & events');
    addNav(role, 'opportunities', '↗', 'Opportunities');
    addNav(role, 'resources', '▣', 'Resource library');
    addNav(role, 'activity', '✦', 'Student activity');
    addNav(role, 'messages', '✉', 'Messages');
    addNav(role, 'analytics', '▥', 'Staff analytics');
  });

  function itemRequest(action, data = {}) {
    if (!csrfToken) return Promise.reject(new Error('Security token missing. Please sign in again.'));
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ action, ...data })
    }).then(async response => {
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'The request could not be completed.');
      return result;
    });
  }

  async function refreshHub(showErrors = false) {
    if (!authenticatedUser) return;
    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Could not load Student Engagement Hub data.');
      state.items = data.items || [];
      state.opportunities = data.opportunities || [];
      state.resources = data.resources || [];
      state.messages = data.messages || [];
      state.contacts = data.contacts || [];
      state.feed = data.feed || [];
      state.notifications = data.notifications || [];
      state.permissions = data.permissions || {};
      db.notifications = state.notifications.map(n => ({
        id: Number(n.id), title: n.title, message: n.message, read: Number(n.is_read) === 1,
        createdAt: n.created_at, type: n.record_type || 'Update', reference: n.record_id,
        recordId: n.record_id, recipientRole: currentRole
      }));
      localStorage.setItem(accountStorageKey(), JSON.stringify(db));
      updateUnreadCounts();
      if (['deadlines', 'opportunities', 'resources', 'activity', 'messages', 'notifications'].includes(currentPage)) renderPage();
    } catch (error) {
      console.error('Student Engagement Hub sync:', error);
      if (showErrors) toast(error.message);
    }
  }

  function updateUnreadCounts() {
    const unread = state.notifications.filter(item => Number(item.is_read) !== 1).length;
    const messageUnread = state.messages.filter(item => item.recipient_user_id === currentId && !item.read_at).length;
    document.querySelectorAll('.nav-item[data-page="notifications"] .nav-count').forEach(node => node.remove());
    document.querySelectorAll('.nav-item[data-page="messages"] .nav-count').forEach(node => node.remove());
    [['notifications', unread], ['messages', messageUnread]].forEach(([page, count]) => {
      const nav = document.querySelector(`.nav-item[data-page="${page}"]`);
      if (nav && count) {
        const badge = document.createElement('span');
        badge.className = 'nav-count';
        badge.textContent = count > 99 ? '99+' : String(count);
        nav.appendChild(badge);
      }
    });
    const bell = document.querySelector('.top-actions .icon-btn');
    if (bell) {
      bell.title = unread ? `${unread} unread notification${unread === 1 ? '' : 's'}` : 'Notifications';
      bell.setAttribute('aria-label', bell.title);
      bell.innerHTML = `♧${unread ? `<span class="nav-count">${unread > 99 ? '99+' : unread}</span>` : ''}`;
    }
  }

  function dateLabel(raw) {
    if (!raw) return 'Date to be confirmed';
    const date = new Date(String(raw).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? esc(raw) : date.toLocaleString([], { dateStyle: 'medium', timeStyle: raw.includes(':') ? 'short' : undefined });
  }
  function daysLeft(raw) {
    if (!raw) return null;
    const value = String(raw);
    const deadline = new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T23:59:59` : value.replace(' ', 'T'));
    if (Number.isNaN(deadline.getTime())) return null;
    if (deadline.toDateString() === new Date().toDateString() && deadline.getTime() >= Date.now()) return 0;
    const difference = deadline.getTime() - Date.now();
    return difference < 0 ? -1 : Math.ceil(difference / 86400000);
  }
  function statusPill(text, kind = '') {
    return `<span class="hub-pill ${kind}">${esc(text)}</span>`;
  }
  function cardActions(...buttons) {
    return `<div class="hub-card-actions">${buttons.filter(Boolean).join('')}</div>`;
  }
  function staffManagement() { return Boolean(state.permissions.can_publish); }
  function studentRole() { return ['student', 'alumni'].includes(currentRole); }
  function emptyCard(message) { return `<div class="card empty">${esc(message)}</div>`; }
  function safeLink(url, label = 'Open application') {
    if (!url || !/^https?:\/\//i.test(url)) return '';
    return `<a class="btn light small" href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(label)} ↗</a>`;
  }

  function deadlinePage() {
    const filter = state.filters.deadlines;
    const deadlines = state.items.filter(item => item.item_type === 'deadline').filter(item => {
      const days = daysLeft(item.due_at);
      const status = item.is_completed ? 'Completed' : (days !== null && days < 0 ? 'Overdue' : 'Upcoming');
      return filter === 'All' || status === filter;
    });
    const canManage = staffManagement();
    const cards = deadlines.map(item => {
      const days = daysLeft(item.due_at);
      const stateLabel = item.is_completed ? 'Completed' : days !== null && days < 0 ? 'Overdue' : 'Upcoming';
      const urgency = stateLabel === 'Overdue' ? 'urgent' : stateLabel === 'Completed' ? 'done' : '';
      return `<article class="card hub-card"><div class="hub-card-top"><span class="hub-icon">◷</span><div><h3>${esc(item.title)}</h3><div class="hub-meta">${statusPill(item.category)}${statusPill(stateLabel, urgency)}</div></div></div><p>${esc(item.description)}</p><div class="hub-meta"><span>Due <strong>${dateLabel(item.due_at)}</strong></span>${days !== null && !item.is_completed ? `<span>${days < 0 ? `${Math.abs(days)} day(s) overdue` : days === 0 ? 'Due today' : `${days} day(s) left`}</span>` : ''}${item.location ? `<span>${esc(item.location)}</span>` : ''}</div>${cardActions(
        studentRole() ? `<button class="btn light small" onclick="hubTrack(${item.id},'deadline','${item.is_tracked ? 'untrack' : 'tracked'}')">${item.is_tracked ? 'Following' : 'Remind me'}</button>` : '',
        studentRole() && !item.is_completed ? `<button class="btn small" onclick="hubTrack(${item.id},'deadline','completed')">Mark complete</button>` : '',
        canManage ? `<button class="btn light small" onclick="hubEditItem(${item.id})">Edit</button><button class="btn light small" onclick="hubDeleteItem(${item.id})">Delete</button>` : '',
        safeLink(item.link_url, 'Details')
      )}</article>`;
    }).join('');
    return welcome('Stay ahead of what matters.', 'Academic dates, applications, workshops and events in one place.',
      canManage ? '<button class="btn gold" onclick="hubItemForm()">＋ Add deadline or event</button>' : '')
      + `<div class="hub-toolbar">${['All', 'Upcoming', 'Overdue', 'Completed'].map(value => `<button class="btn ${filter === value ? '' : 'light'} small" onclick="hubSetFilter('deadlines','${value}')">${value}</button>`).join('')}</div>`
      + `<div class="hub-grid">${cards || emptyCard('No deadlines or events match this view.')}</div>`;
  }

  function opportunityPage() {
    const search = state.search.opportunities.toLowerCase();
    const category = state.filters.opportunities;
    const closing = state.filters.opportunityClosing;
    const rows = state.opportunities.filter(record => {
      const p = record.payload || {};
      const days = daysLeft(p.deadline);
      const closingMatches = closing === 'Any closing date'
        || (closing === 'Closing within 7 days' && days !== null && days >= 0 && days <= 7)
        || (closing === 'Closing within 30 days' && days !== null && days >= 0 && days <= 30)
        || (closing === 'Past closing date' && days !== null && days < 0);
      return closingMatches && (category === 'All' || p.category === category)
        && [p.title, p.organisation, p.description, p.location, p.eligibility].join(' ').toLowerCase().includes(search);
    });
    const cards = rows.map(record => {
      const p = record.payload || {};
      const days = daysLeft(p.deadline);
      const urgency = days !== null && days >= 0 && days <= 7;
      const canManage = staffManagement() && ['admin', 'hod', 'registry', 'career_advisor'].includes(currentRole);
      return `<article class="card hub-card"><div class="hub-card-top"><span class="hub-icon">↗</span><div><h3>${esc(p.title || 'Opportunity')}</h3><div class="hub-meta">${statusPill(p.category || 'Opportunity')}${days !== null && days >= 0 && days <= 7 ? statusPill('Closing soon', 'urgent') : ''}${new Date(record.created_at) > new Date(Date.now() - 7 * 86400000) ? statusPill('New') : ''}</div></div></div><p><strong>${esc(p.organisation || '')}</strong></p><p>${esc(p.description || '')}</p><div class="hub-meta"><span>Closes <strong>${dateLabel(p.deadline)}</strong></span>${p.location ? `<span>${esc(p.location)}</span>` : ''}<span>${Number(record.application_count || 0)} student application(s) marked</span></div><p class="muted"><strong>Eligibility:</strong> ${esc(p.eligibility || 'See application details.')}</p>${cardActions(
        studentRole() ? `<button class="btn light small" onclick="hubTrack(${record.id},'opportunity','${record.is_saved ? 'unsave' : 'saved'}')">${record.is_saved ? '★ Saved' : '☆ Save'}</button>` : '',
        studentRole() ? `<button class="btn small" onclick="hubTrack(${record.id},'opportunity','applied')" ${record.is_applied ? 'disabled' : ''}>${record.is_applied ? '✓ Applied' : 'Mark applied'}</button>` : '',
        safeLink(p.application_url, 'Apply / details'),
        canManage ? `<button class="btn light small" onclick="hubEditOpportunity(${record.id})">Edit</button><button class="btn light small" onclick="hubDeleteOpportunity(${record.id})">Remove</button>` : ''
      )}</article>`;
    }).join('');
    const canManage = staffManagement() && ['admin', 'hod', 'registry', 'career_advisor'].includes(currentRole);
    return welcome('Find your next opportunity.', 'Search internships, scholarships, employment, graduate programmes and more.',
      canManage ? '<button class="btn gold" onclick="hubOpportunityForm()">＋ Publish opportunity</button>' : '')
      + `<div class="hub-toolbar"><input class="field" placeholder="Search opportunities..." value="${esc(state.search.opportunities)}" oninput="hubSearch('opportunities',this.value)"><select aria-label="Opportunity type" onchange="hubSetFilter('opportunities',this.value)"><option>All</option>${opportunityTypes.map(type => `<option ${category === type ? 'selected' : ''}>${esc(type)}</option>`).join('')}</select><select aria-label="Closing date" onchange="hubSetFilter('opportunityClosing',this.value)">${['Any closing date', 'Closing within 7 days', 'Closing within 30 days', 'Past closing date'].map(value => `<option ${closing === value ? 'selected' : ''}>${esc(value)}</option>`).join('')}</select><span class="muted">${rows.length} result(s)</span></div>`
      + `<div class="hub-grid">${cards || emptyCard('No published opportunities match your search.')}</div>`;
  }

  function resourcePage() {
    const search = state.search.resources.toLowerCase();
    const category = state.filters.resources;
    const rows = state.resources.filter(resource => (category === 'All' || resource.category === category)
      && [resource.title, resource.description, resource.category, resource.original_name].join(' ').toLowerCase().includes(search));
    const cards = rows.map(resource => `<article class="card hub-card"><div class="hub-card-top"><span class="hub-icon">▣</span><div><h3>${esc(resource.title)}</h3><div class="hub-meta">${statusPill(resource.category)}${statusPill(new Date(resource.created_at) > new Date(Date.now() - 7 * 86400000) ? 'New resource' : 'Resource')}</div></div></div><p>${esc(resource.description)}</p><div class="hub-meta"><span>${esc(resource.original_name)}</span><span>Added ${dateLabel(resource.created_at)}</span><span>${Number(resource.download_count)} downloads</span></div>${cardActions(
        `<a class="btn small" href="api/resource_download.php?id=${Number(resource.id)}">Download</a>`,
        staffManagement() ? `<button class="btn light small" onclick="hubEditResource(${resource.id})">Edit details</button><button class="btn light small" onclick="hubDeleteItem(${resource.id})">Remove</button>` : ''
      )}</article>`).join('');
    return welcome('Tools for your next step.', 'Career, academic and application resources shared by authorised university staff.',
      staffManagement() ? '<button class="btn gold" onclick="hubResourceForm()">＋ Upload resource</button>' : '')
      + `<div class="hub-toolbar"><input class="field" placeholder="Search resources..." value="${esc(state.search.resources)}" oninput="hubSearch('resources',this.value)"><select onchange="hubSetFilter('resources',this.value)"><option>All</option>${resourceTypes.map(type => `<option ${category === type ? 'selected' : ''}>${esc(type)}</option>`).join('')}</select></div>`
      + `<div class="hub-grid">${cards || emptyCard('No resources match your search.')}</div>`;
  }

  function activityPage() {
    const search = state.search.feed.toLowerCase();
    const filter = state.filters.feed;
    const rows = state.feed.filter(row => (filter === 'All' || row.category === filter)
      && [row.payload.title, row.payload.description, row.owner_name, row.category].join(' ').toLowerCase().includes(search));
    const feed = rows.map(row => `<article class="hub-feed-item"><span class="hub-icon">${row.feed_type === 'achievement' ? '✦' : row.feed_type === 'event' ? '◷' : '✧'}</span><div><div class="hub-meta">${statusPill(row.category)}<span>${dateLabel(row.created_at)}</span></div><h3 style="margin:6px 0;color:var(--wine)">${esc(row.payload.title || row.category)}</h3><p>${esc(row.payload.description || '')}</p>${row.feed_type === 'achievement' ? `<small class="muted">Shared by ${esc(row.owner_name)} · Approved achievement</small>` : `<small class="muted">Africa University Student Success Hub · ${esc(row.owner_name)}</small>`}</div></article>`).join('');
    const announcements = state.items.filter(item => item.item_type === 'announcement');
    const management = staffManagement()
      ? `<div class="card" style="margin-top:16px"><div class="section-head"><div><h2 class="panel-title">Manage announcements</h2><p>Publish, edit or remove updates shared with the AU community.</p></div><button class="btn small" onclick="hubAnnouncementForm()">＋ Publish announcement</button></div>${announcements.map(item => `<div class="list-row"><div><b>${esc(item.title)}</b><small>${esc(item.category)} · ${dateLabel(item.created_at)}</small></div><div class="hub-actions"><button class="btn light small" onclick="hubEditAnnouncement(${Number(item.id)})">Edit</button><button class="btn light small" onclick="hubDeleteItem(${Number(item.id)})">Delete</button></div></div>`).join('') || '<p class="muted">No announcements published yet.</p>'}</div>`
      : '';
    return welcome('Good things are happening at AU.', 'A privacy-aware stream of shared achievements, announcements and events.')
      + `<div class="hub-toolbar"><input class="field" placeholder="Search recent activity..." value="${esc(state.search.feed)}" oninput="hubSearch('feed',this.value)"><select onchange="hubSetFilter('feed',this.value)"><option>All</option>${[...new Set(state.feed.map(row => row.category))].map(item => `<option ${filter === item ? 'selected' : ''}>${esc(item)}</option>`).join('')}</select><span class="muted">Only achievements students chose to share appear here.</span></div>`
      + `<div class="card">${feed || '<div class="empty">No recent shared activity yet.</div>'}</div>${management}`;
  }

  function messagesPage() {
    const messages = state.messages.slice().reverse();
    const unread = state.messages.filter(item => item.recipient_user_id === currentId && !item.read_at).length;
    const contacts = state.contacts.map(contact => `<option value="${esc(contact.user_id)}">${esc(contact.full_name)} · ${esc(contact.role)}</option>`).join('');
    return welcome('A direct line to your AU support network.', 'Messages are private to the sender and recipient.')
      + `<div class="card"><div class="section-head"><div><h2>Inbox & messages</h2><p>${unread} unread message(s)</p></div></div><form onsubmit="hubSendMessage(event)"><div class="hub-form-grid"><div class="field"><label>MESSAGE TO</label><select name="recipient_user_id" required>${contacts || '<option value="">No authorised contacts available.</option>'}</select></div><div class="field wide"><label>MESSAGE</label><textarea name="body" maxlength="5000" required placeholder="Write a private message..."></textarea></div></div><button class="btn" ${contacts ? '' : 'disabled'}>Send message</button></form></div>`
      + `<div class="card" style="margin-top:16px"><h2 class="panel-title">Recent conversations</h2>${messages.length ? messages.map(message => {
        const received = message.recipient_user_id === currentId;
        const other = received ? message.sender_name : message.recipient_name;
        const unreadClass = received && !message.read_at ? 'unread' : '';
        return `<article class="hub-message ${unreadClass}"><div class="hub-message-meta"><b>${received ? 'From' : 'To'} ${esc(other)}</b><span>${dateLabel(message.created_at)}${received && !message.read_at ? ' · Unread' : ''}</span></div><p>${esc(message.body)}</p>${received && !message.read_at ? `<button class="btn light small" onclick="hubReadMessage(${Number(message.id)})">Mark read</button>` : ''}</article>`;
      }).join('') : '<div class="empty">No messages yet. Choose an authorised contact to start a conversation.</div>'}</div>`;
  }

  function notificationsPage() {
    const notifications = state.notifications;
    return welcome('Your updates, in one place.', 'Deadlines, opportunities, achievements and recommendations.')
      + `<div class="hub-toolbar"><button class="btn light small" onclick="markAllNotificationsRead()">Mark all as read</button><span class="muted">${notifications.filter(item => Number(item.is_read) !== 1).length} unread</span></div>`
      + `<div class="card">${notifications.length ? notifications.map(item => `<article class="notification-item ${Number(item.is_read) === 1 ? 'is-read' : ''}"><div class="notification-symbol">${item.record_type === 'deadline' ? '◷' : item.record_type === 'achievement' ? '✦' : item.record_type === 'message' ? '✉' : '↗'}</div><div class="notification-copy"><b>${esc(item.title)}</b><p>${esc(item.message)}</p><small>${esc(item.record_type || 'Update')} · ${dateLabel(item.created_at)}</small></div><button class="btn light small" onclick="markNotificationRead('${Number(item.id)}')">${Number(item.is_read) === 1 ? 'Read' : 'Mark read'}</button></article>`).join('') : '<div class="empty">You are all caught up.</div>'}</div>`;
  }

  async function loadAnalytics() {
    const result = await itemRequest('analytics');
    state.metrics = result.metrics || {};
    state.analyticsScope = result.scope;
  }
  window.hubDashboardStats = function () {
    const labels = {
      registered_students: 'Registered students', active_students: 'Active student accounts',
      achievement_submissions: 'Achievement submissions', pending_achievements: 'Pending achievements',
      approved_achievements: 'Approved achievements', rejected_achievements: 'Rejected achievements',
      recommendation_requests: 'Recommendation requests', completed_recommendations: 'Completed recommendations',
      published_opportunities: 'Published opportunities', opportunity_applications: 'Student applications marked',
      verification_workload: 'Verification workload', support_requests: 'Pending support requests',
      upcoming_deadlines: 'Upcoming deadlines', engagement_actions: 'Student engagement actions'
    };
    const cards = Object.entries(state.metrics).map(([key, value]) =>
      stat(labels[key] || key.replace(/_/g, ' '), Number(value).toLocaleString(), '▥', 'Live authorised database count')
    ).join('');
    return `<div class="grid">${cards || emptyCard('No live metrics are available for this role.')}</div>`;
  };
  function analyticsPage() {
    const metrics = state.metrics;
    const labels = {
      registered_students: 'Registered students', active_students: 'Active student accounts',
      achievement_submissions: 'Achievement submissions', pending_achievements: 'Pending achievements',
      approved_achievements: 'Approved achievements', rejected_achievements: 'Rejected achievements',
      recommendation_requests: 'Recommendation requests', completed_recommendations: 'Completed recommendations',
      upcoming_deadlines: 'Upcoming deadlines', published_opportunities: 'Published opportunities',
      opportunity_applications: 'Student applications marked', engagement_actions: 'Student engagement actions',
      verification_workload: 'Verification workload'
    };
    const entries = Object.entries(metrics);
    const max = Math.max(1, ...entries.map(([, value]) => Number(value)));
    const cards = entries.map(([key, value]) => stat(labels[key] || key.replace(/_/g, ' '), Number(value).toLocaleString(), '▥', state.analyticsScope === 'institutional' ? 'Current database count' : 'Role-visible queue count')).join('');
    const chart = entries.map(([key, value]) => `<div class="hub-chart-row"><span>${esc(labels[key] || key)}</span><div class="hub-chart-track"><span style="width:${Math.max(1, Number(value) / max * 100)}%"></span></div><b>${Number(value).toLocaleString()}</b></div>`).join('');
    return welcome('Insight for student success.', 'Live counts from authorised MySQL records; values are not estimates.')
      + `<div class="grid">${cards || emptyCard('No analytics are available.')}</div><div class="card" style="margin-top:16px"><h2 class="panel-title">Engagement overview</h2>${chart || '<p class="muted">No activity data is available yet.</p>'}<small class="muted">Institutional counts are shown only to authorised management roles. Other staff see workflow workload totals.</small></div>`;
  }

  function deadlineForm(item = null) {
    const value = item || {};
    const date = value.due_at ? String(value.due_at).replace(' ', 'T').slice(0, 16) : '';
    const categories = ['Academic deadline', 'Application deadline', 'Workshop', 'Seminar', 'Event', 'Submission reminder'];
    openModal(value.id ? 'Edit deadline or event' : 'Add a deadline or event', `<form onsubmit="hubSaveItem(event,'deadline',${Number(value.id || 0)})"><div class="hub-form-grid"><div class="field"><label>TITLE</label><input name="title" maxlength="180" required value="${esc(value.title || '')}"></div><div class="field"><label>TYPE</label><select name="category">${categories.map(category => `<option ${value.category === category ? 'selected' : ''}>${category}</option>`).join('')}</select></div><div class="field"><label>DATE & TIME</label><input type="datetime-local" name="due_at" required value="${esc(date)}"></div><div class="field"><label>LOCATION</label><input name="location" maxlength="180" value="${esc(value.location || '')}"></div><div class="field"><label>AUDIENCE</label><select name="target_role"><option value="student">Students</option><option value="alumni" ${value.target_role === 'alumni' ? 'selected' : ''}>Alumni</option><option value="all" ${value.target_role === 'all' ? 'selected' : ''}>Students and alumni</option></select></div><div class="field"><label>DETAILS LINK (OPTIONAL)</label><input type="url" name="link_url" maxlength="2048" value="${esc(value.link_url || '')}"></div><div class="field wide"><label>DESCRIPTION</label><textarea name="description" maxlength="12000" required>${esc(value.description || '')}</textarea></div></div><button class="btn">Save and notify audience</button></form>`);
  }
  function opportunityForm(record = null) {
    const p = record ? record.payload || {} : {};
    openModal(record ? 'Edit opportunity' : 'Publish opportunity', `<form onsubmit="hubSaveOpportunity(event,${Number(record?.id || 0)})"><div class="hub-form-grid"><div class="field"><label>TITLE</label><input name="title" maxlength="180" required value="${esc(p.title || '')}"></div><div class="field"><label>ORGANISATION</label><input name="organisation" maxlength="180" required value="${esc(p.organisation || '')}"></div><div class="field"><label>OPPORTUNITY TYPE</label><select name="category">${opportunityTypes.map(type => `<option ${p.category === type ? 'selected' : ''}>${esc(type)}</option>`).join('')}</select></div><div class="field"><label>LOCATION</label><input name="location" maxlength="180" value="${esc(p.location || '')}"></div><div class="field"><label>CLOSING DATE</label><input type="date" name="closing_date" required value="${esc(p.deadline || '')}"></div><div class="field"><label>APPLICATION LINK</label><input type="url" name="link_url" maxlength="2048" required value="${esc(p.application_url || '')}"></div><div class="field wide"><label>DESCRIPTION</label><textarea name="description" required maxlength="12000">${esc(p.description || '')}</textarea></div><div class="field wide"><label>ELIGIBILITY</label><textarea name="eligibility" required maxlength="4000">${esc(p.eligibility || '')}</textarea></div></div><button class="btn">Publish opportunity</button></form>`);
  }
  function resourceForm() {
    openModal('Upload resource', `<form onsubmit="hubUploadResource(event)"><div class="hub-form-grid"><div class="field"><label>TITLE</label><input name="title" maxlength="180" required></div><div class="field"><label>CATEGORY</label><select name="category" required>${resourceTypes.map(type => `<option>${esc(type)}</option>`).join('')}</select></div><div class="field wide"><label>DESCRIPTION</label><textarea name="description" maxlength="4000" required></textarea></div><div class="field wide"><label>FILE (PDF, DOCX, XLSX, PPTX OR TXT; MAXIMUM 10 MB)</label><input name="file" type="file" accept=".pdf,.docx,.xlsx,.pptx,.txt" required></div></div><p class="muted">Files are stored outside the website's public directory and downloaded only by signed-in users.</p><button class="btn">Upload resource</button></form>`);
  }
  function announcementForm(item = null) {
    const categories = ['Announcement', 'Event', 'Success story'];
    const date = item?.due_at ? String(item.due_at).replace(' ', 'T').slice(0, 16) : '';
    openModal(item ? 'Edit announcement' : 'Publish an announcement', `<form onsubmit="hubSaveItem(event,'announcement',${Number(item?.id || 0)})"><div class="hub-form-grid"><div class="field"><label>TITLE</label><input name="title" maxlength="180" required value="${esc(item?.title || '')}"></div><div class="field"><label>CATEGORY</label><select name="category">${categories.map(category => `<option ${item?.category === category ? 'selected' : ''}>${category}</option>`).join('')}</select></div><div class="field"><label>DATE (OPTIONAL)</label><input type="datetime-local" name="due_at" value="${esc(date)}"></div><div class="field"><label>AUDIENCE</label><select name="target_role"><option value="student">Students</option><option value="alumni" ${item?.target_role === 'alumni' ? 'selected' : ''}>Alumni</option><option value="all" ${item?.target_role === 'all' ? 'selected' : ''}>Students and alumni</option></select></div><div class="field wide"><label>DETAILS</label><textarea name="description" maxlength="12000" required>${esc(item?.description || '')}</textarea></div></div><button class="btn">Save announcement</button></form>`);
  }

  async function reloadAndRender(message) {
    await refreshHub();
    closeModal();
    if (message) toast(message);
    renderPage();
  }
  window.hubRefresh = refreshHub;
  window.hubSetFilter = function (key, value) { state.filters[key] = value; renderPage(); };
  window.hubSearch = function (key, value) {
    state.search[key] = value;
    renderPage();
    const placeholder = { opportunities: 'Search opportunities', resources: 'Search resources', feed: 'Search recent activity' }[key];
    const input = placeholder && document.querySelector(`.hub-toolbar input[placeholder^="${placeholder}"]`);
    if (input) { input.focus(); input.setSelectionRange(value.length, value.length); }
  };
  window.hubItemForm = () => deadlineForm();
  window.hubEditItem = id => deadlineForm(state.items.find(item => Number(item.id) === Number(id)));
  window.hubOpportunityForm = () => opportunityForm();
  window.hubResourceForm = () => resourceForm();
  window.hubAnnouncementForm = () => announcementForm();
  window.hubEditAnnouncement = id => announcementForm(state.items.find(item => Number(item.id) === Number(id) && item.item_type === 'announcement'));

  window.hubSaveItem = async function (event, type, id) {
    event.preventDefault();
    const form = Object.fromEntries(new FormData(event.target));
    try {
      await itemRequest('save_item', { item_type: type, id, ...form });
      await reloadAndRender(id ? 'Updated successfully.' : 'Published. Audience notifications were created.');
    } catch (error) { toast(error.message); }
  };
  window.hubDeleteItem = async function (id) {
    if (!confirm('Remove this item? This cannot be undone.')) return;
    try { await itemRequest('delete_item', { id }); await reloadAndRender('Item removed.'); }
    catch (error) { toast(error.message); }
  };
  window.hubTrack = async function (id, type, action) {
    try {
      const result = await itemRequest('track', { id, item_type: type, track_action: action });
      await refreshHub();
      toast(result.already_recorded ? 'This action was already saved.' : action === 'applied' ? 'Application marked.' : action === 'saved' ? 'Opportunity saved.' : action === 'unsave' ? 'Opportunity removed from saved items.' : action === 'completed' ? 'Deadline marked complete.' : action === 'untrack' ? 'Deadline reminder removed.' : 'Deadline reminder set.');
      renderPage();
    } catch (error) { toast(error.message); }
  };
  window.hubSaveOpportunity = async function (event, id) {
    event.preventDefault();
    const form = Object.fromEntries(new FormData(event.target));
    try { await itemRequest('save_opportunity', { id, ...form }); await reloadAndRender(id ? 'Opportunity updated.' : 'Opportunity published and students notified.'); }
    catch (error) { toast(error.message); }
  };
  window.hubEditOpportunity = id => opportunityForm(state.opportunities.find(item => Number(item.id) === Number(id)));
  window.hubDeleteOpportunity = async function (id) {
    if (!confirm('Remove this opportunity from the board?')) return;
    try { await itemRequest('delete_opportunity', { id }); await reloadAndRender('Opportunity removed.'); }
    catch (error) { toast(error.message); }
  };
  window.hubUploadResource = async function (event) {
    event.preventDefault();
    const form = new FormData(event.target);
    form.append('action', 'upload_resource');
    try {
      const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfToken }, body: form });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Resource upload failed.');
      await reloadAndRender('Resource uploaded securely.');
    } catch (error) { toast(error.message); }
  };
  window.hubEditResource = function (id) {
    const resource = state.resources.find(item => Number(item.id) === Number(id));
    if (!resource) return;
    openModal('Edit resource details', `<form onsubmit="hubUpdateResource(event,${Number(id)})"><div class="field"><label>TITLE</label><input name="title" maxlength="180" required value="${esc(resource.title)}"></div><div class="field"><label>CATEGORY</label><select name="category">${resourceTypes.map(type => `<option ${resource.category === type ? 'selected' : ''}>${esc(type)}</option>`).join('')}</select></div><div class="field"><label>DESCRIPTION</label><textarea name="description" maxlength="4000" required>${esc(resource.description)}</textarea></div><button class="btn">Save details</button></form>`);
  };
  window.hubUpdateResource = async function (event, id) {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.target));
    try { await itemRequest('update_resource', { id, ...values }); await reloadAndRender('Resource updated.'); }
    catch (error) { toast(error.message); }
  };
  window.hubSendMessage = async function (event) {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.target));
    try { await itemRequest('send_message', values); await reloadAndRender('Message sent. The recipient has an in-app notification.'); }
    catch (error) { toast(error.message); }
  };
  window.hubReadMessage = async function (id) {
    try { await itemRequest('read_message', { id }); await refreshHub(); renderPage(); }
    catch (error) { toast(error.message); }
  };
  window.markNotificationRead = async function (id) {
    try {
      if (!csrfToken) throw new Error('Security token missing. Please sign in again.');
      const response = await fetch('api/workflows.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, body: JSON.stringify({ action: 'read_notification', id: Number(id) }) });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Could not update notification.');
      await refreshHub(); renderPage();
    } catch (error) { toast(error.message); }
  };
  window.markAllNotificationsRead = async function () {
    try {
      await itemRequest('read_all_notifications');
      await refreshHub(); renderPage();
      toast('Notifications marked read.');
    } catch (error) { toast(error.message); }
  };
  window.hubRefreshAnalytics = async function () {
    try { await loadAnalytics(); renderPage(); }
    catch (error) { toast(error.message); }
  };
  window.hubCareerAdvisorForm = function () {
    if (currentRole !== 'admin') return toast('Only an administrator can provision Career Advising accounts.');
    openModal('Create Career Advising account', `<form onsubmit="hubCreateCareerAdvisor(event)"><div class="field"><label>UNIVERSITY ID</label><input name="user_id" maxlength="64" pattern="[A-Za-z0-9._-]{3,64}" required></div><div class="field"><label>FULL NAME</label><input name="full_name" maxlength="120" required></div><div class="field"><label>EMAIL ADDRESS</label><input name="email" type="email" maxlength="254" required></div><div class="field"><label>TEMPORARY PASSWORD</label><input name="password" type="password" minlength="10" maxlength="4096" required></div><p class="muted">Share the temporary password securely and ask the account holder to change it.</p><button class="btn">Create account</button></form>`);
  };
  window.hubCreateCareerAdvisor = async function (event) {
    event.preventDefault();
    const form = Object.fromEntries(new FormData(event.target));
    try {
      const response = await fetch('api/register.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ ...form, role: 'career_advisor' })
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Account creation failed.');
      await reloadAndRender('Career Advising account created.');
    } catch (error) { toast(error.message); }
  };

  const originalRender = window.renderPage;
  window.renderPage = function () {
    const pages = {
      deadlines: deadlinePage,
      opportunities: opportunityPage,
      resources: resourcePage,
      activity: activityPage,
      messages: messagesPage,
      analytics: analyticsPage,
      notifications: notificationsPage
    };
    if (['adminhome', 'staffhome'].includes(currentPage)) {
      const dashboard = currentPage;
      originalRender();
      loadAnalytics().then(() => { if (currentPage === dashboard) originalRender(); }).catch(error => {
        if (currentPage === dashboard) {
          document.getElementById('pageContent').insertAdjacentHTML('afterbegin', `<div class="notice">${esc(error.message)}</div>`);
        }
      });
      return;
    }
    if (!Object.prototype.hasOwnProperty.call(pages, currentPage)) return originalRender();
    if (currentPage === 'analytics') {
      document.getElementById('pageContent').innerHTML = `${welcome('Insight for student success.', 'Loading authorised live data...')}<div class="card">Loading analytics...</div>`;
      loadAnalytics().then(() => { if (currentPage === 'analytics') document.getElementById('pageContent').innerHTML = analyticsPage(); }).catch(error => {
        document.getElementById('pageContent').innerHTML = `<div class="notice">${esc(error.message)}</div>`;
      });
      return;
    }
    document.getElementById('pageContent').innerHTML = pages[currentPage]();
  };

  const originalBuildNav = window.buildNav;
  window.buildNav = function () {
    originalBuildNav();
    document.querySelectorAll('.nav-item[data-page]').forEach(button => {
      const icon = button.querySelector('span:first-child');
      if (icon) icon.classList.add('nav-feature-icon');
    });
    updateUnreadCounts();
  };
  if (typeof window.usersPage === 'function') {
    const originalUsersPage = window.usersPage;
    window.usersPage = function () {
      return originalUsersPage()
        + `<div class="card" style="margin-top:16px"><div class="section-head"><div><h2 class="panel-title">Career Advising access</h2><p>Create a real Career Advising account to publish opportunities and view career engagement analytics.</p></div><button class="btn small" onclick="hubCareerAdvisorForm()">＋ Create Career Advisor account</button></div></div>`;
    };
  }
  const originalActivate = window.activateAuthenticatedUser;
  window.activateAuthenticatedUser = function (user) {
    originalActivate(user);
    refreshHub(true);
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = setInterval(() => refreshHub(false), 30000);
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshHub(false); });
  const notificationButton = document.querySelector('.top-actions .icon-btn');
  if (notificationButton) notificationButton.onclick = () => openPage('notifications');
})();
