import React from 'react';
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring, interpolate } from 'remotion';
import { BRAND, FONT, SPRING_CONFIGS, STAGGER_OFFSET } from '../brand';
import { PromoVideoProps } from '../types';
import { MockupFrame } from '../components/MockupFrame';
import { AnimatedCursor } from '../components/AnimatedCursor';

interface GalleryPhoto {
  label: string;
  color: string;
}

const galleryPhotos: GalleryPhoto[] = [
  { label: 'Living Room', color: '#8B5CF6' },
  { label: 'Kitchen', color: '#EC4899' },
  { label: 'Master Bed', color: '#F59E0B' },
  { label: 'Bathroom', color: '#10B981' },
  { label: 'Dining', color: '#3B82F6' },
  { label: 'Hallway', color: '#6366F1' },
];

export const PhotoCollaborationScene: React.FC<{ data: PromoVideoProps }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  // Title
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const titleSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });

  // Browser mockup
  const browserScale = spring({ frame: frame - 25, fps, config: SPRING_CONFIGS.gentle });
  const browserOpacity = interpolate(frame, [25, 45], [0, 1], { extrapolateRight: 'clamp' });

  // Gallery photos stagger
  const galleryStart = 50;

  // Lightbox opens
  const lightboxFrame = 140;
  const lightboxScale = spring({ frame: frame - lightboxFrame, fps, config: SPRING_CONFIGS.snappy });
  const lightboxOpacity = interpolate(frame, [lightboxFrame, lightboxFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Annotation circles appear
  const annotFrame1 = 180;
  const annotFrame2 = 200;
  const annot1Scale = spring({ frame: frame - annotFrame1, fps, config: SPRING_CONFIGS.bouncy });
  const annot2Scale = spring({ frame: frame - annotFrame2, fps, config: SPRING_CONFIGS.bouncy });

  // Comment appears
  const commentFrame = 230;
  const commentScale = spring({ frame: frame - commentFrame, fps, config: SPRING_CONFIGS.snappy });
  const commentOpacity = interpolate(frame, [commentFrame, commentFrame + 10], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  // Fade
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], {
    extrapolateLeft: 'clamp',
  });

  // Cursor
  const cursorWaypoints = [
    { x: 500, y: 400, frame: 100 },
    { x: 430, y: 380, frame: lightboxFrame, click: true },
    { x: 680, y: 420, frame: annotFrame1, click: true },
    { x: 750, y: 350, frame: annotFrame2, click: true },
  ];

  return (
    <AbsoluteFill style={{ backgroundColor: BRAND.white, opacity: fadeOut }}>
      {/* Title */}
      <div
        style={{
          position: 'absolute',
          top: 50,
          left: 0,
          right: 0,
          textAlign: 'center',
          opacity: titleOpacity,
          transform: `translateY(${titleSlide}px)`,
        }}
      >
        <h2 style={{ fontSize: 48, fontWeight: 700, color: BRAND.navy, margin: 0, fontFamily: FONT.system }}>
          Photo Markup & Collaboration
        </h2>
        <p style={{ fontSize: 24, color: BRAND.gray, marginTop: 8, fontFamily: FONT.system }}>
          Annotate, comment, and share project photos
        </p>
      </div>

      {/* Browser mockup */}
      <div
        style={{
          position: 'absolute',
          top: 160,
          left: 180,
          right: 180,
          bottom: 50,
          opacity: browserOpacity,
          transform: `scale(${Math.max(0.8, browserScale)})`,
        }}
      >
        <MockupFrame type="browser" url={`${data.website}/properties/1/photos`}>
          <div style={{ padding: 30, height: '100%', position: 'relative' }}>
            {/* Gallery header */}
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 }}>
              <h3 style={{ fontSize: 22, fontWeight: 700, color: BRAND.dark, margin: 0, fontFamily: FONT.system }}>
                Property Photos
              </h3>
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 6,
                  backgroundColor: BRAND.blue,
                  borderRadius: 8,
                  padding: '8px 16px',
                }}
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2">
                  <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                  <circle cx="9" cy="9" r="2" />
                  <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                </svg>
                <span style={{ fontSize: 14, fontWeight: 600, color: BRAND.white, fontFamily: FONT.system }}>
                  Upload
                </span>
              </div>
            </div>

            {/* Photo grid */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(3, 1fr)',
                gap: 12,
              }}
            >
              {galleryPhotos.map((photo, i) => {
                const photoScale = spring({
                  frame: frame - (galleryStart + i * 10),
                  fps,
                  config: SPRING_CONFIGS.snappy,
                });

                return (
                  <div
                    key={photo.label}
                    style={{
                      transform: `scale(${Math.max(0, photoScale)})`,
                      borderRadius: 12,
                      overflow: 'hidden',
                      aspectRatio: '4/3',
                      position: 'relative',
                    }}
                  >
                    {/* Photo placeholder with gradient */}
                    <div
                      style={{
                        width: '100%',
                        height: '100%',
                        background: `linear-gradient(135deg, ${photo.color}40, ${photo.color}20)`,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke={photo.color} strokeWidth="1.5" opacity="0.6">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                        <circle cx="9" cy="9" r="2" />
                        <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                      </svg>
                    </div>
                    {/* Label */}
                    <div
                      style={{
                        position: 'absolute',
                        bottom: 0,
                        left: 0,
                        right: 0,
                        padding: '6px 10px',
                        background: 'rgba(0,0,0,0.5)',
                      }}
                    >
                      <span style={{ fontSize: 12, fontWeight: 600, color: BRAND.white, fontFamily: FONT.system }}>
                        {photo.label}
                      </span>
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Lightbox overlay */}
            {frame >= lightboxFrame && (
              <div
                style={{
                  position: 'absolute',
                  top: 0,
                  left: 0,
                  right: 0,
                  bottom: 0,
                  backgroundColor: 'rgba(0,0,0,0.85)',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  opacity: lightboxOpacity,
                }}
              >
                {/* Lightbox image */}
                <div
                  style={{
                    width: '75%',
                    height: '80%',
                    borderRadius: 16,
                    overflow: 'hidden',
                    transform: `scale(${Math.max(0, lightboxScale)})`,
                    position: 'relative',
                    background: `linear-gradient(135deg, #8B5CF640, #8B5CF620)`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  {/* Photo content placeholder */}
                  <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#8B5CF6" strokeWidth="1" opacity="0.4">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                    <circle cx="9" cy="9" r="2" />
                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                  </svg>

                  {/* Annotation circle 1 */}
                  {frame >= annotFrame1 && (
                    <div
                      style={{
                        position: 'absolute',
                        top: '35%',
                        left: '30%',
                        transform: `scale(${Math.max(0, annot1Scale)})`,
                      }}
                    >
                      <div
                        style={{
                          width: 60,
                          height: 60,
                          borderRadius: '50%',
                          border: `3px solid ${BRAND.red}`,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}
                      >
                        <span style={{ fontSize: 18, fontWeight: 700, color: BRAND.red, fontFamily: FONT.system }}>1</span>
                      </div>
                    </div>
                  )}

                  {/* Annotation circle 2 */}
                  {frame >= annotFrame2 && (
                    <div
                      style={{
                        position: 'absolute',
                        top: '25%',
                        right: '25%',
                        transform: `scale(${Math.max(0, annot2Scale)})`,
                      }}
                    >
                      <div
                        style={{
                          width: 60,
                          height: 60,
                          borderRadius: '50%',
                          border: `3px solid ${BRAND.amber}`,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}
                      >
                        <span style={{ fontSize: 18, fontWeight: 700, color: BRAND.amber, fontFamily: FONT.system }}>2</span>
                      </div>
                    </div>
                  )}

                  {/* Comment bubble */}
                  {frame >= commentFrame && (
                    <div
                      style={{
                        position: 'absolute',
                        bottom: '15%',
                        right: '10%',
                        opacity: commentOpacity,
                        transform: `scale(${Math.max(0, commentScale)})`,
                        transformOrigin: 'bottom right',
                      }}
                    >
                      <div
                        style={{
                          backgroundColor: BRAND.white,
                          borderRadius: 12,
                          padding: '10px 16px',
                          boxShadow: '0 4px 20px rgba(0,0,0,0.3)',
                          maxWidth: 220,
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 4 }}>
                          <div
                            style={{
                              width: 20,
                              height: 20,
                              borderRadius: '50%',
                              backgroundColor: BRAND.blue,
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              color: BRAND.white,
                              fontSize: 10,
                              fontWeight: 700,
                            }}
                          >
                            J
                          </div>
                          <span style={{ fontSize: 11, fontWeight: 600, color: BRAND.dark, fontFamily: FONT.system }}>
                            John
                          </span>
                        </div>
                        <p style={{ fontSize: 13, color: BRAND.dark, margin: 0, fontFamily: FONT.system, lineHeight: 1.3 }}>
                          Needs extra prep work on this wall - peeling paint.
                        </p>
                      </div>
                    </div>
                  )}

                  {/* Close button */}
                  <div
                    style={{
                      position: 'absolute',
                      top: 12,
                      right: 12,
                      width: 32,
                      height: 32,
                      borderRadius: '50%',
                      backgroundColor: 'rgba(255,255,255,0.2)',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={BRAND.white} strokeWidth="2">
                      <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                  </div>
                </div>
              </div>
            )}
          </div>
        </MockupFrame>
      </div>

      <AnimatedCursor waypoints={cursorWaypoints} />
    </AbsoluteFill>
  );
};
