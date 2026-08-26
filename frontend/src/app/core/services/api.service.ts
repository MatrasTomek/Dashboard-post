import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';

/**
 * Thin wrapper around HttpClient scoped to the backend's /api base path.
 * Requests are same-origin in production (SPA and API share a domain), and
 * proxied to the local PHP dev server via proxy.conf.json during `ng serve`.
 */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = '/api';

  get<T>(path: string, params?: Record<string, string | number>) {
    return this.http.get<T>(`${this.baseUrl}${path}`, { params });
  }

  post<T>(path: string, body: unknown = {}) {
    return this.http.post<T>(`${this.baseUrl}${path}`, body);
  }

  put<T>(path: string, body: unknown = {}) {
    return this.http.put<T>(`${this.baseUrl}${path}`, body);
  }

  delete<T>(path: string) {
    return this.http.delete<T>(`${this.baseUrl}${path}`);
  }
}
