// Brand constants for the Painting Made Easy Denver promo video

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
  warmRed: '#DC2626',
  softRed: '#FCA5A5',
  orange: '#F97316',
};

export const FPS = 30;

// Scene durations in seconds - redesigned narrative flow
export const SCENE_DURATIONS = {
  painPoints: 7,          // The old frustrating way
  solutionReveal: 4,      // "We built something better"
  processOverview: 7,     // Email invite → schedule → estimate → communicate
  estimateExperience: 8,  // Browser mockup with packages + signing
  jobProgress: 8,         // Dashboard with 5 stages
  crewDispatch: 5,        // Crew assignment + notification
  stayInControl: 7,       // Chat, photos, video - fast-paced
  cta: 6,                 // CTA - Painting Made Easy Denver
} as const;

// Scene durations in frames
export const SCENE_FRAMES = {
  painPoints: SCENE_DURATIONS.painPoints * FPS,                     // 210
  solutionReveal: SCENE_DURATIONS.solutionReveal * FPS,             // 120
  processOverview: SCENE_DURATIONS.processOverview * FPS,           // 210
  estimateExperience: SCENE_DURATIONS.estimateExperience * FPS,     // 240
  jobProgress: SCENE_DURATIONS.jobProgress * FPS,                   // 240
  crewDispatch: SCENE_DURATIONS.crewDispatch * FPS,                 // 150
  stayInControl: SCENE_DURATIONS.stayInControl * FPS,               // 210
  cta: SCENE_DURATIONS.cta * FPS,                                   // 180
} as const;

export const TOTAL_FRAMES = Object.values(SCENE_FRAMES).reduce((a, b) => a + b, 0); // 1560

// Animation configs
export const SPRING_CONFIGS = {
  snappy: { stiffness: 120, damping: 14 },
  gentle: { stiffness: 80, damping: 20 },
  bouncy: { stiffness: 100, damping: 10 },
  slow: { stiffness: 60, damping: 18 },
  punch: { stiffness: 200, damping: 12 },
} as const;

// Common animation durations
export const FADE_IN_FRAMES = 20;
export const FADE_OUT_FRAMES = 15;
export const STAGGER_OFFSET = 12;

// Typography - LARGE for 60+ audience
export const FONT = {
  system: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
  serif: 'Georgia, "Times New Roman", serif',
};

// Large text sizes for accessibility
export const TEXT = {
  hero: 80,
  heading: 60,
  subheading: 36,
  body: 28,
  label: 22,
  small: 18,
};
