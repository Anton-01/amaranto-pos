import api from './axios';

/**
 * Single client of the media module's API.
 *
 * Every path the module speaks lives here, so a route renamed on the backend
 * is a one-file change on the frontend instead of a hunt through components.
 */

export const mediaApi = {
  // --- Library ------------------------------------------------------------
  list: (params) => api.get('/media/files', { params }).then((r) => r.data),
  show: (id) => api.get(`/media/files/${id}`).then((r) => r.data.data),
  update: (id, payload) => api.put(`/media/files/${id}`, payload).then((r) => r.data),
  toggleStatus: (id) => api.patch(`/media/files/${id}/toggle-status`).then((r) => r.data),
  remove: (id, reason) => api.delete(`/media/files/${id}`, { data: { reason } }).then((r) => r.data),
  reapplyPermissions: (id) =>
    api.post(`/media/files/${id}/reapply-permissions`).then((r) => r.data),

  /**
   * Uploads one file.
   *
   * The Content-Type header is deliberately left unset: the browser has to
   * write it itself so it can append the multipart boundary. Forcing
   * 'multipart/form-data' here produces a body PHP cannot parse, and the
   * symptom is an empty $_FILES with no error anywhere.
   */
  upload: (formData, onProgress) =>
    api
      .post('/media/files', formData, {
        headers: { 'Content-Type': undefined },
        onUploadProgress: (event) => {
          if (onProgress && event.total) {
            onProgress(Math.round((event.loaded * 100) / event.total));
          }
        },
      })
      .then((r) => r.data),

  /**
   * Object URL for the file's bytes.
   *
   * The bytes are private, so they cannot be pointed at with a plain <img src>:
   * that request would carry no Authorization header and come back 401. They
   * are fetched as a blob through the authenticated client and handed to the
   * browser as an object URL, which the caller must revoke when it unmounts.
   */
  contentUrl: async (id, { download = false } = {}) => {
    const response = await api.get(`/media/files/${id}/content`, {
      params: download ? { download: 1 } : {},
      responseType: 'blob',
    });

    return URL.createObjectURL(response.data);
  },

  // --- Share links --------------------------------------------------------
  shareLinks: (fileId) => api.get(`/media/files/${fileId}/share-links`).then((r) => r.data),
  createShareLink: (fileId, payload) =>
    api.post(`/media/files/${fileId}/share-links`, payload).then((r) => r.data),
  revokeShareLink: (fileId, linkId) =>
    api.delete(`/media/files/${fileId}/share-links/${linkId}`).then((r) => r.data),

  // --- Allowed file types -------------------------------------------------
  fileTypes: (params) => api.get('/media/file-types', { params }).then((r) => r.data),
  fileTypeCatalogs: () => api.get('/media/file-types/catalogs').then((r) => r.data.data),
  createFileType: (payload) => api.post('/media/file-types', payload).then((r) => r.data),
  updateFileType: (id, payload) => api.put(`/media/file-types/${id}`, payload).then((r) => r.data),
  toggleFileType: (id) => api.patch(`/media/file-types/${id}/toggle-status`).then((r) => r.data),
  removeFileType: (id) => api.delete(`/media/file-types/${id}`).then((r) => r.data),

  // --- Google Drive credentials -------------------------------------------
  driveCredentials: () => api.get('/media/drive/credentials').then((r) => r.data),
  saveDriveCredentials: (payload) => api.post('/media/drive/credentials', payload).then((r) => r.data),
  testDriveConnection: (payload) => api.post('/media/drive/test-connection', payload).then((r) => r.data),
  disableDrive: () => api.delete('/media/drive/credentials').then((r) => r.data),

  // --- Audit trail --------------------------------------------------------
  auditLogs: (params) => api.get('/media/audit', { params }).then((r) => r.data),
  auditCatalogs: () => api.get('/media/audit/catalogs').then((r) => r.data.data),
};

export default mediaApi;
