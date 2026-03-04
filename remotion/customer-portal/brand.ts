// Brand constants for the Customer Portal Showcase video

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
  pink: '#EC4899',
  indigo: '#6366F1',
  teal: '#14B8A6',
};

export const FPS = 30;

export const SCENE_DURATIONS = {
  hero: 5,
  dashboard: 9,
  estimateViewer: 10,
  estimateBuilder: 9,
  jobProgress: 10,
  cta: 6,
} as const;

export const SCENE_FRAMES = {
  hero: SCENE_DURATIONS.hero * FPS,
  dashboard: SCENE_DURATIONS.dashboard * FPS,
  estimateViewer: SCENE_DURATIONS.estimateViewer * FPS,
  estimateBuilder: SCENE_DURATIONS.estimateBuilder * FPS,
  jobProgress: SCENE_DURATIONS.jobProgress * FPS,
  cta: SCENE_DURATIONS.cta * FPS,
} as const;

export const TOTAL_FRAMES = Object.values(SCENE_FRAMES).reduce((a, b) => a + b, 0);

export const SPRING_CONFIGS = {
  snappy: { stiffness: 120, damping: 14 },
  gentle: { stiffness: 80, damping: 20 },
  bouncy: { stiffness: 100, damping: 10 },
  slow: { stiffness: 60, damping: 18 },
  punch: { stiffness: 200, damping: 12 },
} as const;

export const FONT = {
  system: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
};

export const TEXT = {
  hero: 80,
  heading: 60,
  subheading: 36,
  body: 28,
  label: 22,
  small: 18,
  xs: 14,
};

export const STAGGER = 10;
