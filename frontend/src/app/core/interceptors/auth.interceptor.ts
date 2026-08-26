import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { AuthService } from '../services/auth.service';

/**
 * Attaches the CSRF-guard header the backend requires on every mutating request
 * (see RequireAjaxHeaderMiddleware), and globally reacts to a 401 by clearing the
 * client-side session state and bouncing to /login — except for the login/me calls
 * themselves, which report auth failure as normal data, not a global redirect trigger.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const withHeader = req.clone({ setHeaders: { 'X-Requested-With': 'XMLHttpRequest' } });

  return next(withHeader).pipe(
    catchError((error: unknown) => {
      const isAuthEndpoint = req.url.includes('/auth/login') || req.url.includes('/auth/me');

      if (error instanceof HttpErrorResponse && error.status === 401 && !isAuthEndpoint) {
        auth.clearSession();
        void router.navigate(['/login']);
      }

      return throwError(() => error);
    })
  );
};
