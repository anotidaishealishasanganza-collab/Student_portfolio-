const assert = require('node:assert/strict');

global.window = global;
global.setInterval = () => 0;
require('../industrial-attachment.js');

const calculate = global.industrialAttachmentProgress;
const shiftedDate = days => {
  const date = new Date();
  date.setHours(0, 0, 0, 0);
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};
const placement = (startOffset, endOffset) => ({
  start_date: shiftedDate(startOffset),
  expected_end_date: shiftedDate(endOffset)
});

assert.equal(calculate(placement(1, 11)), 0, 'progress is zero before the start date');
assert.equal(calculate(placement(0, 10)), 0, 'progress is zero on the start date');
assert.equal(calculate(placement(-3, 7)), 30, 'progress follows elapsed / total duration');
assert.equal(calculate(placement(-9, 1)), 90, 'progress is not prematurely capped');
assert.equal(calculate(placement(-10, 0)), 100, 'progress is complete on the end date');
assert.equal(calculate(placement(-11, -1)), 100, 'progress stays complete after the end date');

console.log('Industrial attachment progress tests passed.');
