import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';

import { SettingsService } from '../../core/services/settings.service';
import { SocialAccountService } from '../../core/services/social-account.service';
import { AppSettings, AVAILABLE_MODELS } from '../../core/models/settings.model';
import { SocialAccountStatus } from '../../core/models/social-account.model';

@Component({
  selector: 'app-settings-page',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './settings.page.html',
  styleUrl: './settings.page.scss'
})
export class SettingsPage implements OnInit {
  private readonly settingsService = inject(SettingsService);
  private readonly socialAccountService = inject(SocialAccountService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly availableModels = AVAILABLE_MODELS;
  readonly settings = signal<AppSettings | null>(null);
  readonly loading = signal(true);

  readonly apiKeyInput = signal('');
  readonly savingKey = signal(false);
  readonly testingKey = signal(false);
  readonly keyMessage = signal<{ type: 'success' | 'error'; text: string } | null>(null);

  readonly savingPreferences = signal(false);
  readonly preferencesMessage = signal<{ type: 'success' | 'error'; text: string } | null>(null);

  readonly socialStatus = signal<SocialAccountStatus | null>(null);
  readonly socialLoading = signal(true);
  readonly connecting = signal(false);
  readonly checkingHealth = signal(false);
  readonly socialMessage = signal<{ type: 'success' | 'error'; text: string } | null>(null);

  defaultModel = '';
  historyTokenBudget = 6000;
  brandVoiceNotes = '';

  ngOnInit(): void {
    this.settingsService.get().subscribe((settings) => {
      this.settings.set(settings);
      this.defaultModel = settings.defaultModel;
      this.historyTokenBudget = settings.historyTokenBudget;
      this.brandVoiceNotes = settings.brandVoiceNotes;
      this.loading.set(false);
    });

    this.loadSocialStatus();
    this.handleOauthRedirectParams();
  }

  private loadSocialStatus(): void {
    this.socialLoading.set(true);
    this.socialAccountService.status().subscribe((status) => {
      this.socialStatus.set(status);
      this.socialLoading.set(false);
    });
  }

  private handleOauthRedirectParams(): void {
    const params = this.route.snapshot.queryParamMap;

    if (params.has('connected')) {
      this.socialMessage.set({ type: 'success', text: 'Konto Facebook połączone.' });
    } else if (params.has('error')) {
      this.socialMessage.set({
        type: 'error',
        text: `Nie udało się połączyć konta Facebook (${params.get('error')}).`
      });
    }

    if (params.has('connected') || params.has('error')) {
      void this.router.navigate([], { relativeTo: this.route, queryParams: {} });
    }
  }

  connectFacebook(): void {
    if (this.connecting()) {
      return;
    }

    this.connecting.set(true);
    this.socialAccountService.connectStart().subscribe({
      next: ({ authorizationUrl }) => {
        window.location.href = authorizationUrl;
      },
      error: () => {
        this.connecting.set(false);
        this.socialMessage.set({ type: 'error', text: 'Nie udało się zainicjować połączenia z Facebook.' });
      }
    });
  }

  checkSocialHealth(): void {
    if (this.checkingHealth()) {
      return;
    }

    this.checkingHealth.set(true);
    this.socialMessage.set(null);

    this.socialAccountService.checkHealth().subscribe({
      next: ({ valid }) => {
        this.checkingHealth.set(false);
        this.socialMessage.set(
          valid
            ? { type: 'success', text: 'Połączenie działa poprawnie.' }
            : { type: 'error', text: 'Połączenie wygasło — połącz konto ponownie.' }
        );
        this.loadSocialStatus();
      },
      error: (error: unknown) => {
        this.checkingHealth.set(false);
        this.socialMessage.set({ type: 'error', text: this.messageFor(error) });
      }
    });
  }

  disconnectFacebook(): void {
    this.socialAccountService.disconnect().subscribe(() => {
      this.socialStatus.set({ connected: false });
      this.socialMessage.set(null);
    });
  }

  saveApiKey(): void {
    if (this.apiKeyInput().trim() === '' || this.savingKey()) {
      return;
    }

    this.savingKey.set(true);
    this.keyMessage.set(null);

    this.settingsService.saveAnthropicKey(this.apiKeyInput().trim()).subscribe({
      next: () => {
        this.savingKey.set(false);
        this.apiKeyInput.set('');
        this.keyMessage.set({ type: 'success', text: 'Klucz API zapisany.' });
        this.settings.update((s) => (s ? { ...s, anthropicKeyConfigured: true } : s));
      },
      error: (error: unknown) => {
        this.savingKey.set(false);
        this.keyMessage.set({ type: 'error', text: this.messageFor(error) });
      }
    });
  }

  testApiKey(): void {
    if (this.testingKey()) {
      return;
    }

    this.testingKey.set(true);
    this.keyMessage.set(null);

    this.settingsService.testAnthropicKey().subscribe({
      next: () => {
        this.testingKey.set(false);
        this.keyMessage.set({ type: 'success', text: 'Klucz API działa poprawnie.' });
      },
      error: (error: unknown) => {
        this.testingKey.set(false);
        this.keyMessage.set({ type: 'error', text: this.messageFor(error) });
      }
    });
  }

  deleteApiKey(): void {
    this.settingsService.deleteAnthropicKey().subscribe(() => {
      this.settings.update((s) => (s ? { ...s, anthropicKeyConfigured: false } : s));
      this.keyMessage.set(null);
    });
  }

  savePreferences(): void {
    if (this.savingPreferences()) {
      return;
    }

    this.savingPreferences.set(true);
    this.preferencesMessage.set(null);

    this.settingsService
      .savePreferences({
        defaultModel: this.defaultModel,
        historyTokenBudget: this.historyTokenBudget,
        brandVoiceNotes: this.brandVoiceNotes
      })
      .subscribe({
        next: () => {
          this.savingPreferences.set(false);
          this.preferencesMessage.set({ type: 'success', text: 'Preferencje zapisane.' });
        },
        error: (error: unknown) => {
          this.savingPreferences.set(false);
          this.preferencesMessage.set({ type: 'error', text: this.messageFor(error) });
        }
      });
  }

  private messageFor(error: unknown): string {
    if (error instanceof HttpErrorResponse && error.error?.error?.message) {
      return error.error.error.message as string;
    }

    return 'Wystąpił błąd. Spróbuj ponownie.';
  }
}
