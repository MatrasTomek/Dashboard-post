import { Injectable, inject } from '@angular/core';

import { ApiService } from './api.service';
import { AppSettings } from '../models/settings.model';

@Injectable({ providedIn: 'root' })
export class SettingsService {
  private readonly api = inject(ApiService);

  get() {
    return this.api.get<AppSettings>('/settings');
  }

  saveAnthropicKey(apiKey: string) {
    return this.api.put<{ saved: true }>('/settings/anthropic-key', { apiKey });
  }

  testAnthropicKey() {
    return this.api.post<{ valid: true }>('/settings/anthropic-key/test');
  }

  deleteAnthropicKey() {
    return this.api.delete<void>('/settings/anthropic-key');
  }

  savePreferences(payload: Partial<Pick<AppSettings, 'defaultModel' | 'historyTokenBudget' | 'brandVoiceNotes'>>) {
    return this.api.put<{ saved: true }>('/settings/preferences', payload);
  }
}
