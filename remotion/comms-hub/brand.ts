// Brand constants for the Communications Hub showcase video

export const BRAND = {
  navy: '#1B2D4F',
  blue: '#2563EB',
  sky: '#38BDF8',
  green: '#10B981',
  amber: '#F59E0B',
  white: '#FFFFFF',
  gray: '#6B7280',
  lightGray: '#F3F4F6',
  dark: '#111827',
  red: '#EF4444',
  purple: '#8B5CF6',
  indigo: '#6366F1',
  teal: '#14B8A6',
  rose: '#F43F5E',
  orange: '#F97316',
  slate: '#475569',
};

export const FPS = 30;

// Scene durations in seconds
export const SCENE_DURATIONS = {
  hero: 5,               // "Communications Hub" title reveal
  theProblem: 7,         // Scattered channels - chaos visualization
  theAnswer: 4,          // "One Unified Inbox" reveal
  unifiedInbox: 8,       // Two-panel Gmail-style layout mockup
  liveChat: 7,           // Real-time messaging demo with typing indicators
  emailIntegration: 7,   // Email threads, inline replies, CRM linking
  messenger: 6,          // Facebook Messenger sync + bot handoff
  smartFeatures: 7,      // Translation, unread badges, notification routing
  allTogether: 8,        // Animated hub mockup with all channels flowing in
  cta: 6,                // CTA - "Every conversation. One place."
} as const;

// Scene durations in frames
export const SCENE_FRAMES = Object.fromEntries(
  Object.entries(SCENE_DURATIONS).map(([key, val]) => [key, val * FPS])
) as Record<keyof typeof SCENE_DURATIONS, number>;

export const TOTAL_FRAMES = Object.values(SCENE_DURATIONS).reduce((a, b) => a + b, 0) * FPS;

// Animation configs
export const SPRING_CONFIGS = {
  snappy: { stiffness: 120, damping: 14 },
  gentle: { stiffness: 80, damping: 20 },
  bouncy: { stiffness: 100, damping: 10 },
  slow: { stiffness: 60, damping: 18 },
  punch: { stiffness: 200, damping: 12 },
} as const;

export const FADE_IN_FRAMES = 20;
export const FADE_OUT_FRAMES = 15;
export const STAGGER_OFFSET = 12;

export const FONT = {
  system: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
  serif: 'Georgia, "Times New Roman", serif',
};

export const TEXT = {
  hero: 80,
  heading: 60,
  subheading: 36,
  body: 28,
  label: 22,
  small: 18,
  tiny: 14,
};
