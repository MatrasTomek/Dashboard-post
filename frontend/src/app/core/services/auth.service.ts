import { Injectable, computed, inject, signal } from '@angular/core';
import { catchError, of, tap } from 'rxjs';

import { ApiService } from './api.service';
import { User } from '../models/user.model';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiService);

  private readonly currentUserSignal = signal<User | null>(null);
  private readonly sessionCheckedSignal = signal(false);

  readonly currentUser = this.currentUserSignal.asReadonly();
  readonly sessionChecked = this.sessionCheckedSignal.asReadonly();
  readonly isAuthenticated = computed(() => this.currentUserSignal() !== null);

  /** Called once at app bootstrap (see app.initializer.ts) before the router activates any route. */
  checkSession() {
    return this.api.get<User>('/auth/me').pipe(
      tap((user) => this.currentUserSignal.set(user)),
      catchError(() => {
        this.currentUserSignal.set(null);
        return of(null);
      }),
      tap(() => this.sessionCheckedSignal.set(true))
    );
  }

  login(username: string, password: string) {
    return this.api
      .post<User>('/auth/login', { username, password })
      .pipe(tap((user) => this.currentUserSignal.set(user)));
  }

  logout() {
    return this.api.post('/auth/logout').pipe(tap(() => this.currentUserSignal.set(null)));
  }

  /** Called by the auth interceptor on a 401 from any endpoint other than login/me. */
  clearSession() {
    this.currentUserSignal.set(null);
  }
}
