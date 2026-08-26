export interface AppSettings {
  anthropicKeyConfigured: boolean;
  defaultModel: string;
  historyTokenBudget: number;
  brandVoiceNotes: string;
}

export const AVAILABLE_MODELS = [
  { id: 'claude-sonnet-5', label: 'Claude Sonnet 5 (zbalansowany koszt/jakość)' },
  { id: 'claude-opus-5', label: 'Claude Opus 5 (najwyższa jakość, wyższy koszt)' },
  { id: 'claude-haiku-4-5', label: 'Claude Haiku 4.5 (najniższy koszt)' }
];
