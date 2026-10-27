/* Management-only API binding. Load after core/auth.js and before management/management.js.
   Authentication remains with the host SaleSync session; bearer tokens never enter page markup. */
(function (global) {
  'use strict';
  const root = String(global.SaleSyncConfig?.apiBase || '/api/admin').replace(/\/$/, '');
  const tokenProvider = () => global.SaleSync?.auth?.getAccessToken?.() || global.sessionStorage.getItem('salesync.accessToken') || '';
  async function request(path, options) {
    const token = tokenProvider();
    if (!token) throw new Error('Management API requires an authenticated admin session.');
    const response = await fetch(`${root}${path}`, { ...options, headers: { Accept: 'application/json', ...(options?.body ? { 'Content-Type': 'application/json' } : {}), Authorization: `Bearer ${token}`, ...options?.headers } });
    if (!response.ok) { const body = await response.json().catch(() => ({})); throw new Error(body?.error?.message || `Management API request failed (${response.status}).`); }
    if (response.headers.get('content-type')?.includes('text/csv')) return response.blob();
    return response.json();
  }
  const query = (values = {}) => { const p = new URLSearchParams(); for (const [key,value] of Object.entries(values)) if (value !== '' && value != null) p.set(key,String(value)); return p.size ? `?${p}` : ''; };
  const json = (method, body) => ({ method, body: JSON.stringify(body || {}) });
  const api = {
    dashboard: { metrics: () => request('/dashboard/metrics'), activity: q => request('/dashboard/activity'+query(q)) },
    users: { list: q => request('/users'+query(q)), get: id => request(`/users/${encodeURIComponent(id)}`), activate: id => request(`/users/${id}/activate`,json('POST')), suspend: (id,reason) => request(`/users/${id}/suspend`,json('POST',{reason})), restore: id => request(`/users/${id}/restore`,json('POST')) },
    applications: { list: q => request('/seller-applications'+query(q)), get: id => request(`/seller-applications/${id}`), approve: id => request(`/seller-applications/${id}/approve`,json('POST')), reject: (id,reason) => request(`/seller-applications/${id}/reject`,json('POST',{reason})), store: id => request(`/stores/${id}`), suspendStore: (id,reason) => request(`/stores/${id}/suspend`,json('POST',{reason})), restoreStore: id => request(`/stores/${id}/restore`,json('POST')) },
    products: { list: q => request('/products'+query(q)), get: id => request(`/products/${id}`), deactivate: (id,reason) => request(`/products/${id}/deactivate`,json('POST',{reason})), restore: id => request(`/products/${id}/restore`,json('POST')) },
    orders: { list: q => request('/orders'+query(q)), get: id => request(`/orders/${id}`) },
    payments: { list: q => request('/payments'+query(q)), get: id => request(`/payments/${id}`) },
    reports: { sales: q => request('/reports/sales'+query(q)), csv: q => request('/reports/sales.csv'+query(q)) },
    settings: { categories: () => request('/settings/categories'), createCategory: body => request('/settings/categories',json('POST',body)), updateCategory: (id,body) => request(`/settings/categories/${id}`,json('PATCH',body)), deactivateCategory: id => request(`/settings/categories/${id}/deactivate`,json('POST')), business: () => request('/settings/business'), setBusiness: (key,value) => request(`/settings/business/${encodeURIComponent(key)}`,json('PUT',{value})), createProduct: body => request('/settings/products',json('POST',body)), updateProduct: (id,body) => request(`/settings/products/${id}`,json('PATCH',body)), publishProduct: id => request(`/settings/products/${id}/publish`,json('POST')), archiveProduct: id => request(`/settings/products/${id}/archive`,json('POST')) },
    activity: q => request('/activity'+query(q))
  };
  global.SaleSyncManagementApi = Object.freeze(api);
})(window);
