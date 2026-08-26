import { Injectable, inject } from '@angular/core';

import { ApiService } from './api.service';
import { SocialAccountStatus } from '../models/social-account.model';

@Injectable({ providedIn: 'root' })
export class SocialAccountService {
  private readonly api = inject(ApiService);

  status() {
    return this.api.get<SocialAccountStatus>('/social/status');
  }

  connectStart() {
    return this.api.get<{ authorizationUrl: string }>('/social/connect/start');
  }

  disconnect() {
    return this.api.post<void>('/social/disconnect');
  }

  checkHealth() {
    return this.api.post<{ valid: boolean }>('/social/refresh-token');
  }
}
