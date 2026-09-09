import api from './axios';

/**
 * Single client of the social publishing module's API.
 *
 * Same discipline as `api/media.js`: every path the module speaks lives here,
 * so a route renamed on the backend is a one-file change on the frontend
 * instead of a hunt through components.
 */

export const socialApi = {
  // --- Composer -----------------------------------------------------------

  /** Channels, their connection state and the caption ceilings to count against. */
  catalogs: () => api.get('/social/catalogs').then((r) => r.data.data),

  /**
   * Queues a publication.
   *
   * Answers 202, never 200: nothing has been published when this resolves. The
   * backend has written the log rows and handed the work to the queue, and the
   * copy in the composer says exactly that.
   */
  publish: (fileId, payload) =>
    api.post(`/social/publish/${fileId}`, payload).then((r) => r.data),

  // --- Log ----------------------------------------------------------------
  posts: (params) => api.get('/social/posts', { params }).then((r) => r.data),
  history: (fileId) => api.get(`/social/posts/${fileId}/history`).then((r) => r.data.data),

  // --- Credentials (admin) ------------------------------------------------
  accounts: () => api.get('/social/accounts').then((r) => r.data),
  saveAccount: (payload) => api.post('/social/accounts', payload).then((r) => r.data),
  testAccount: (id) => api.post(`/social/accounts/${id}/test`).then((r) => r.data),
  disableAccount: (id) => api.delete(`/social/accounts/${id}`).then((r) => r.data),
};

export default socialApi;
