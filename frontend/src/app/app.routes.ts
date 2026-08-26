import { Routes } from '@angular/router';

import { authGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () => import('./features/login/login.page').then((m) => m.LoginPage)
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./features/dashboard/post-history.page').then((m) => m.PostHistoryPage)
  },
  {
    path: 'settings',
    canActivate: [authGuard],
    loadComponent: () => import('./features/settings/settings.page').then((m) => m.SettingsPage)
  },
  {
    path: 'posts/new',
    canActivate: [authGuard],
    loadComponent: () => import('./features/post-wizard/post-wizard.page').then((m) => m.PostWizardPage)
  },
  {
    path: 'posts/:id',
    canActivate: [authGuard],
    loadComponent: () => import('./features/post-wizard/post-wizard.page').then((m) => m.PostWizardPage)
  },
  { path: '**', redirectTo: '' }
];
