// Brand constants for the Apex Negotiations promo video

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
};

export const FPS = 30;

// Scene durations in seconds
export const SCENE_DURATIONS = {
  brandIntro: 4,
  proposalMockup: 10,
  roomPicker: 8,
  communication: 8,
  photoCollaboration: 10,
  jobProgress: 10,
  professionalTeam: 8,
  cta: 17,
} as const;

// Scene durations in frames
export const SCENE_FRAMES = {
  brandIntro: SCENE_DURATIONS.brandIntro * FPS,           // 120
  proposalMockup: SCENE_DURATIONS.proposalMockup * FPS,   // 300
  roomPicker: SCENE_DURATIONS.roomPicker * FPS,            // 240
  communication: SCENE_DURATIONS.communication * FPS,       // 240
  photoCollaboration: SCENE_DURATIONS.photoCollaboration * FPS, // 300
  jobProgress: SCENE_DURATIONS.jobProgress * FPS,           // 300
  professionalTeam: SCENE_DURATIONS.professionalTeam * FPS, // 240
  cta: SCENE_DURATIONS.cta * FPS,                           // 510
} as const;

export const TOTAL_FRAMES = Object.values(SCENE_FRAMES).reduce((a, b) => a + b, 0); // 2250

// Animation configs
export const SPRING_CONFIGS = {
  snappy: { stiffness: 120, damping: 14 },
  gentle: { stiffness: 80, damping: 20 },
  bouncy: { stiffness: 100, damping: 10 },
  slow: { stiffness: 60, damping: 18 },
} as const;

// Common animation durations
export const FADE_IN_FRAMES = 20;
export const FADE_OUT_FRAMES = 15;
export const STAGGER_OFFSET = 18;

// Typography
export const FONT = {
  system: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
  serif: 'Georgia, "Times New Roman", serif',
};
