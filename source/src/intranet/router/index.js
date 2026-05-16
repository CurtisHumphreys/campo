import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  { path: '/intranet/',          component: () => import('../views/Program.vue'),      name: 'program' },
  { path: '/intranet/map',       component: () => import('../views/Map.vue'),          name: 'map' },
  { path: '/intranet/noticeboard', component: () => import('../views/Noticeboard.vue'), name: 'noticeboard' },
  { path: '/intranet/polls',     component: () => import('../views/Polls.vue'),        name: 'polls' },
  { path: '/intranet/lost-found',component: () => import('../views/LostFound.vue'),    name: 'lost-found' },
  { path: '/intranet/ask-admin', component: () => import('../views/AskAdmin.vue'),     name: 'ask-admin' },
  { path: '/intranet/events',    component: () => import('../views/Events.vue'),       name: 'events' },
  { path: '/intranet/check-in',  component: () => import('../views/CheckIn.vue'),      name: 'check-in' },
  { path: '/intranet/waitlist',  component: () => import('../views/Waitlist.vue'),     name: 'waitlist' },
  { path: '/intranet/:pathMatch(.*)*', redirect: '/intranet/' }
]

export default createRouter({ history: createWebHistory(), routes })
