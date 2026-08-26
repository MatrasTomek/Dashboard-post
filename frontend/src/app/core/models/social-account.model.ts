export interface SocialAccountStatus {
  connected: boolean;
  fbPageName?: string;
  igUsername?: string | null;
  status?: 'active' | 'expired' | 'error';
  lastError?: string | null;
  connectedAt?: string;
}
