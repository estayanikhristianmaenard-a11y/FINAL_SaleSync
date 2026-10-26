import { Router } from 'express';
import { requireAdmin } from '../../shared/auth/admin-auth.js';

export const adminRouter = Router();
adminRouter.use(requireAdmin);

// Modules are added incrementally; this authenticated endpoint confirms the
// actor context and gives clients a stable API version/capability response.
adminRouter.get('/capabilities', (_req, res) => {
  res.json({
    apiVersion: 'v1',
    modules: ['dashboard', 'users', 'seller-applications', 'products', 'orders', 'sales-reports', 'system-settings', 'payments', 'activity-log']
  });
});
