/**
 * CAMPO API client
 * All requests go to the PHP backend at /api/*
 */

const BASE = '/api'

async function request(method, path, body = null) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    credentials: 'same-origin'
  }
  if (body !== null) {
    if (body instanceof FormData) {
      delete opts.headers['Content-Type']
      opts.body = body
    } else {
      opts.body = JSON.stringify(body)
    }
  }
  const res = await fetch(BASE + path, opts)
  const ct = res.headers.get('content-type') || ''
  const data = ct.includes('application/json') ? await res.json() : await res.text()
  if (!res.ok) throw { status: res.status, data }
  return data
}

const get  = (path, params) => {
  const url = params ? `${path}?${new URLSearchParams(params)}` : path
  return request('GET', url)
}
const post = (path, body) => request('POST', path, body)

// Raw get exposed on the api object for ad-hoc calls
const apiGet = (path, params) => get(path, params)

// ── Auth ─────────────────────────────────────────────────────────────────────
export const auth = {
  check:          ()           => get('/check-auth'),
  login:          (u, p)       => post('/login', { username: u, password: p }),
  logout:         ()           => post('/logout'),
  tokenCheck:     (token)      => get('/user/token-check', { token }),
  activate:       (token, pw)  => post('/user/activate', { token, password: pw }),
  requestReset:   (username)   => post('/user/password-reset/request', { username }),
  completeReset:  (token, pw)  => post('/user/password-reset/complete', { token, password: pw }),
}

// ── Camps ────────────────────────────────────────────────────────────────────
export const camps = {
  list:      ()         => get('/camps'),
  active:    ()         => get('/camps/active'),
  create:    (d)        => post('/camps', d),
  update:    (id, d)    => post(`/camp/update?id=${id}`, d),
  delete:    (id)       => post(`/camp/delete?id=${id}`),
  setActive:    (id)    => post(`/camp/set-active?id=${id}`),
  setMapCenter: (id, d) => post(`/camp/map-center?id=${id}`, d),
  rates:     (id)       => get('/camp/rates', { id }),
  summary:   (id)       => get('/camp/summary', id ? { camp_id: id } : {}),
}

// ── Members ──────────────────────────────────────────────────────────────────
export const members = {
  list:        (p)      => get('/members', p || {}),
  create:      (d)      => post('/members', d),
  update:      (id, d)  => post(`/member/update?id=${id}`, d),
  delete:      (id)     => post(`/member/delete?id=${id}`),
  deleteAll:   ()       => post('/members/delete-all'),
  merge:       (id, d)  => post(`/member/merge?id=${id}`, d),
  history:     (id)     => get('/member/history', { id }),
  siteFee:     (id)     => get('/member/site-fee', { member_id: id }),
}

// ── Households ───────────────────────────────────────────────────────────────
export const households = {
  list:           ()       => get('/households'),
  create:         (d)      => post('/households', d),
  update:         (id, d)  => post(`/household/update?id=${id}`, d),
  delete:         (id)     => post(`/household/delete?id=${id}`),
  paymentHistory: (hhId)   => get('/household/payment-history', { household_id: hhId }),
}

// ── Member Households (CS-linked) ─────────────────────────────────────────────
export const memberHouseholds = {
  list:   (q)       => get('/member-households', q ? { q } : {}),
  create: (d)       => post('/member-households', d),
  update: (id, d)   => post(`/member-household/update?id=${id}`, d),
  assign: (memberId, householdId) => post(`/member/assign-household?member_id=${memberId}`, { household_id: householdId }),
}

// ── Sites ────────────────────────────────────────────────────────────────────
export const sites = {
  list:   (p)       => get('/sites', p || {}),
  create: (d)       => post('/sites', d),
  update: (id, d)   => post(`/site/update?id=${id}`, d),
  delete: (id)      => post(`/site/delete?id=${id}`),
  pin:    (id, d)   => post(`/site/pin?id=${id}`, d),
}

// ── Waitlist ─────────────────────────────────────────────────────────────────
export const waitlist = {
  list:   ()       => get('/site/waitlist'),
  update: (id, d)  => post(`/site/waitlist-update?id=${id}`, d),
  delete: (id)     => post(`/site/waitlist-delete?id=${id}`),
}

// ── Site Allocations ─────────────────────────────────────────────────────────
export const siteAllocations = {
  list:   (campId)  => get('/site-allocations', campId ? { camp_id: campId } : {}),
  create: (d)       => post('/site-allocations', d),
  update: (id, d)   => post(`/site-allocation/update?id=${id}`, d),
  delete: (id)      => post(`/site-allocation/delete?id=${id}`),
  setFeeExpiry: (id, d) => post(`/site-allocation/fee-expiry?id=${id}`, d),
}

// ── Payments ─────────────────────────────────────────────────────────────────
export const payments = {
  list:           (p)     => get('/payments', p || {}),
  create:         (d)     => post('/payments', d),
  update:         (id,d)  => post(`/payment/update?id=${id}`, d),
  delete:         (id)    => post(`/payment/delete?id=${id}`),
  balance:        (memberId) => get('/member/balance', { member_id: memberId }),
  summary:        (campId)=> get('/payments/summary', campId ? { camp_id: campId } : {}),
  dashboardStats: ()      => get('/payments/dashboard-stats'),
  overview:       ()      => get('/dashboard/overview'),
  checkIns:       ()      => get('/payment-records/check-ins'),
  siteFeeAudit:   ()      => get('/payment-records/site-fee-audit'),
  auditRecalc:    (mid)   => post(`/payment-records/site-fee-audit/recalculate?member_id=${mid}`),
  auditApply:     (mid)   => post(`/payment-records/site-fee-audit/apply-expected?member_id=${mid}`),
  auditCustom:    (mid,d) => post(`/payment-records/site-fee-audit/custom?member_id=${mid}`, d),
  auditReview:    (mid,d) => post(`/payment-records/site-fee-audit/review?member_id=${mid}`, d),
}

// ── Rates ────────────────────────────────────────────────────────────────────
export const rates = {
  list:   (campId, sheet) => get('/rates', { ...(campId ? { camp_id: campId } : {}), ...(sheet ? { sheet } : {}) }),
  create: (d)           => post('/rates', d),
  update: (id, d)       => post(`/rate/update?id=${id}`, d),
  delete: (id)          => post(`/rate/delete?id=${id}`),
}

// ── Prepayments ──────────────────────────────────────────────────────────────
export const prepayments = {
  list:   (p)       => get('/prepayments', p || {}),
  review: (campId)  => get('/prepayments/review', { camp_id: campId }),
  create: (d)       => post('/prepayments', d),
  update: (id, d)   => post(`/prepayment/update?id=${id}`, d),
  match:  (id, hid) => post(`/prepayment/match?id=${id}`, { household_id: hid }),
  delete: (id)      => post(`/prepayment/delete?id=${id}`),
}

// ── Import ───────────────────────────────────────────────────────────────────
export const imports = {
  members: (fd) => post('/import/members', fd),
  sites:   (fd) => post('/import/sites', fd),
}

// ── Intranet admin ───────────────────────────────────────────────────────────
export const intranet = {
  get:          ()      => get('/intranet'),
  save:         (d)     => post('/intranet', d),
  features:     ()      => get('/intranet/features'),
  notifications:()      => get('/admin/notifications'),
  markRead:     (id)    => post('/admin/notifications/mark-read', { id }),
  markAllRead:  ()      => post('/admin/notifications/mark-all-read'),
  recipients:   ()      => get('/admin/notification-recipients'),
  saveRecipient:(d)     => post('/admin/notification-recipients/save', d),
  deleteRecipient:(d)   => post('/admin/notification-recipients/delete', d),
  testRecipient:(d)     => post('/admin/notification-recipients/test', d),
  updateMessage:(id,d)  => post(`/intranet/message/update?id=${id}`, d),
  deleteMessage:(id)    => post(`/intranet/message/delete?id=${id}`),
  saveLostFound:(id,d)  => post(id ? `/intranet/lost-found/save?id=${id}` : '/intranet/lost-found/save', d),
  deleteLostFound:(id)  => post(`/intranet/lost-found/delete?id=${id}`),
  saveNoticeboard:(id,d)=> post(id ? `/intranet/noticeboard/save?id=${id}` : '/intranet/noticeboard/save', d),
  deleteNoticeboard:(id)=> post(`/intranet/noticeboard/delete?id=${id}`),
  savePoll:     (id,d)  => post(id ? `/intranet/poll/save?id=${id}` : '/intranet/poll/save', d),
  deletePoll:   (id)    => post(`/intranet/poll/delete?id=${id}`),
  updateSiteUpdate:(id,d)=>post(`/intranet/site-update/update?id=${id}`, d),
  deleteSiteUpdate:(id) => post(`/intranet/site-update/delete?id=${id}`),
  updateCheckIn:(id,d)  => post(`/intranet/check-in/update?id=${id}`, d),
  deleteCheckIn:(id)    => post(`/intranet/check-in/delete?id=${id}`),
}

// ── Users ────────────────────────────────────────────────────────────────────
export const users = {
  list:             ()        => get('/users'),
  create:           (d)       => post('/users', d),
  update:           (id, d)   => post(`/user/update?id=${id}`, d),
  delete:           (id)      => post(`/user/delete?id=${id}`),
  changePassword:   (d)       => post('/user/change-password', d),
  resendActivation: (id)      => post(`/user/resend-activation?id=${id}`),
}

// ── Settings ─────────────────────────────────────────────────────────────────
export const settings = {
  get:      ()   => get('/settings'),
  save:     (d)  => post('/settings', d),
  getMail:  ()   => get('/settings/mail'),
  saveMail: (d)  => post('/settings/mail', d),
  testMail: (to) => post('/settings/mail/test', { to }),
}

// ── Square ────────────────────────────────────────────────────────────────────
export const square = {
  config:       ()    => get('/square/config'),
  saveConfig:   (tok) => post('/square/config', { token: tok }),
  clearConfig:  ()    => post('/square/config/clear'),
  customers:    ()    => get('/square/customers'),
  link:         (sid, hid) => post('/square/link', { square_customer_id: sid, household_id: hid }),
  unlink:       (hid) => post(`/square/unlink?id=${hid}`),
  charges:      (cid) => get('/square/charges', { customer_id: cid }),
  importCharge: (d)   => post('/square/import-charge', d),
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
export const dashboard = () => get('/dashboard')
export const dashboardV2 = {
  summary:        (campId) => get('/dashboard/summary',        campId ? { camp_id: campId } : {}),
  reconciliation: (campId, dateFrom, dateTo) => get('/dashboard/reconciliation', {
    camp_id: campId, ...(dateFrom ? { date_from: dateFrom, date_to: dateTo || dateFrom } : {})
  }),
  chartData:      (campId) => get('/dashboard/chart-data',     campId ? { camp_id: campId } : {}),
}

// ── Intranet Admin (structured namespace for IntranetAdmin.vue) ──────────────
export const intranetAdmin = {
  features:      ()        => get('/intranet/features'),
  program:       (campId)  => get('/intranet/program',          campId ? { camp_id: campId } : {}),
  notices:       (campId)  => get('/intranet/noticeboard/list', campId ? { camp_id: campId } : {}),
  polls:         (campId)  => get('/intranet/polls/list',       campId ? { camp_id: campId } : {}),
  lostFound:     (campId)  => get('/intranet/lost-found/list',  campId ? { camp_id: campId } : {}),
  createSession: (d)       => post('/intranet/session/create', d),
  updateSession: (id, d)   => post(`/intranet/session/update?id=${id}`, d),
  deleteSession: (id)      => post(`/intranet/session/delete?id=${id}`),
  createNotice:  (d)       => post('/intranet/noticeboard/save', d),
  updateNotice:  (id, d)   => post(`/intranet/noticeboard/save?id=${id}`, d),
  deleteNotice:  (id)      => post(`/intranet/noticeboard/delete?id=${id}`),
  createPoll:    (d)       => post('/intranet/poll/save', d),
  updatePoll:    (id, d)   => post(`/intranet/poll/save?id=${id}`, d),
  deletePoll:    (id)      => post(`/intranet/poll/delete?id=${id}`),
  resolveItem:   (id)      => post(`/intranet/lost-found/resolve?id=${id}`),
  updateSiteUpdate:(id,d)  => post(`/intranet/site-update/update?id=${id}`, d),
  deleteSiteUpdate:(id)    => post(`/intranet/site-update/delete?id=${id}`),
  updateCheckIn: (id,d)    => post(`/intranet/check-in/update?id=${id}`, d),
  deleteCheckIn: (id)      => post(`/intranet/check-in/delete?id=${id}`),
  importSessions:      (fd)      => post('/intranet/sessions/import', fd),
  campoUsers:          ()        => get('/intranet/campo-users'),
  campoUserLink:       (id, hid) => post(`/intranet/campo-user/link-household?id=${id}`, { household_id: hid }),
  campoUserDelete:     (id)      => post(`/intranet/campo-user/delete?id=${id}`),
  campoHouseholdSearch:(q)       => get('/intranet/campo-user/household-search', { q }),
}

// ── ChurchSuite ──────────────────────────────────────────────────────────────
export const churchsuite = {
  status:       ()      => get('/churchsuite/status'),
  events:       ()      => get('/churchsuite/events'),
  diagnostics:  ()      => get('/churchsuite/diagnostics'),
  syncDirectory:    (token) => post('/churchsuite/directory-sync', token ? { sync_token: token } : {}),
  fillMissingSpouses: ()  => post('/churchsuite/fill-missing-spouses'),
  searchContacts:   (q)   => get('/churchsuite/search-contacts', { q }),
  importContact:    (id)  => post('/churchsuite/import-contact', { cs_id: id }),
  syncCamp:     (id, body) => post(`/camp/churchsuite-sync?id=${id}`, body || {}),
  startOAuth:   ()      => get('/churchsuite/oauth/start'),
  disconnect:   ()      => post('/churchsuite/oauth/disconnect'),
}

export const featureRequests = {
  list:     ()         => get('/feature-requests'),
  create:   (d)        => post('/feature-requests', d),
  complete: (id)       => post(`/feature-request/complete?id=${id}`),
  delete:   (id)       => post(`/feature-request/delete?id=${id}`),
  update:   (id, d)    => post(`/feature-request/update?id=${id}`, d),
  reorder:  (ids)      => post('/feature-request/reorder', { ids }),
}

// ── Consolidated api object (used by admin views) ────────────────────────────
export const api = {
  dashboard,
  dashboardV2,
  get: apiGet,
  camps, members, households, memberHouseholds, sites, siteAllocations, waitlist,
  payments, rates, prepayments, imports,
  intranetAdmin, users, settings, churchsuite, featureRequests,
}

// ── Public intranet (no auth) ─────────────────────────────────────────────────
export const publicApi = {
  intranet:     ()      => get('/public/intranet'),
  features:     ()      => get('/public/intranet/features'),
  trackVisit:   (d)     => post('/public/intranet/visit', d),
  submitMessage:(d)     => post('/public/intranet/message', d),
  submitSiteUpdate:(d)  => post('/public/intranet/site-update', d),
  checkIn:      (d)     => post('/public/intranet/check-in', d),
  searchCheckIn:(q)     => get('/public/intranet/check-in-search', { q }),
  submitLostFound:(d)   => post('/public/intranet/lost-found', d),
  submitNoticeboard:(d) => post('/public/intranet/noticeboard', d),
  submitPollResponse:(d)=> post('/public/intranet/poll-response', d),
  sitesMap:     ()      => get('/public/sites-map'),
  mapConfig:    ()      => get('/public/map-config'),
  submitWaitlist:(d)   => post('/waitlist', d),
}
