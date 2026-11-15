const assert = require('node:assert/strict');

global.window = global;
global.currentRole = 'attachment_coordinator';
global.currentPage = 'home';
global.authenticatedUser = { user_id: 'COORDINATOR-1' };
global.csrfToken = 'test-token';
global.document = { activeElement: null };
global.setInterval = () => 0;

const timers = [];
global.setTimeout = callback => {
  timers.push(callback);
  return timers.length;
};
global.clearTimeout = () => {};

const requests = [];
global.fetch = url => {
  requests.push(String(url));
  return Promise.resolve({
    ok: true,
    json: () => Promise.resolve({
      success: true,
      records: [],
      students: [],
      supervisors: [],
      analytics: {},
      organization_counts: [],
      supervisor_counts: []
    })
  });
};

require('../industrial-attachment.js');

const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  global.filterIndustrialPlacements('smith');
  assert.equal(timers.length, 1, 'coordinator searches are debounced');
  timers.shift()();
  await tick();

  global.filterIndustrialPlacements('');
  await tick();

  assert.deepEqual(requests, [
    'api/industrial_attachment.php?search=smith',
    'api/industrial_attachment.php'
  ], 'clearing a server-side search reloads the full placement list');
  console.log('Industrial attachment search tests passed.');
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
