import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Player } from '@remotion/player';
import { AbsoluteFill, Sequence, Img, interpolate, spring, useCurrentFrame, useVideoConfig } from 'remotion';

// Types
interface Photo {
  url: string;
  caption?: string;
  category: 'before' | 'after' | 'general';
}

interface VideoData {
  type: string;
  id: number;
  address: string;
  city: string;
  state: string;
  zip: string;
  photos: Photo[];
  estimate?: {
    total: number;
    squareFootage?: number;
    pricePerSqft?: number;
  };
  scopeOfWork?: string;
  completionWeeks?: number;
  companyName?: string;
  companyPhone?: string;
  companyTagline?: string;
  primaryColor?: string;
  accentColor?: string;
}

const FPS = 30;

// ============================================
// SCENE 1: INTRO
// ============================================
const IntroScene: React.FC<{ data: VideoData }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const titleScale = spring({ frame, fps, config: { stiffness: 100, damping: 20 } });
  const titleOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const subtitleOpacity = interpolate(frame, [20, 40], [0, 1], { extrapolateRight: 'clamp' });
  const brandSlide = interpolate(frame, [40, 60], [50, 0], { extrapolateRight: 'clamp' });
  const brandOpacity = interpolate(frame, [40, 60], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill style={{ backgroundColor: '#fafafa', opacity: fadeOut }}>
      {/* Decorative background elements */}
      <div style={{
        position: 'absolute',
        top: -100,
        right: -100,
        width: 400,
        height: 400,
        borderRadius: '50%',
        background: `linear-gradient(135deg, ${data.primaryColor || '#1e3a5f'}20, transparent)`,
      }} />
      <div style={{
        position: 'absolute',
        bottom: -150,
        left: -150,
        width: 500,
        height: 500,
        borderRadius: '50%',
        background: `linear-gradient(135deg, ${data.accentColor || '#10b981'}15, transparent)`,
      }} />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Main Title */}
        <div style={{
          transform: `scale(${titleScale})`,
          opacity: titleOpacity,
          textAlign: 'center',
        }}>
          <h1 style={{
            fontSize: 80,
            fontWeight: 800,
            color: '#111',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, sans-serif',
            letterSpacing: -2,
          }}>
            {data.address}
          </h1>
        </div>

        {/* City, State */}
        <div style={{ opacity: subtitleOpacity, marginTop: 20 }}>
          <p style={{
            fontSize: 36,
            color: '#666',
            margin: 0,
            fontFamily: 'system-ui, -apple-system, sans-serif',
            fontWeight: 300,
          }}>
            {data.city}, {data.state}
          </p>
        </div>

        {/* Company Brand */}
        <div style={{
          position: 'absolute',
          bottom: 80,
          opacity: brandOpacity,
          transform: `translateY(${brandSlide}px)`,
          display: 'flex',
          alignItems: 'center',
          gap: 16,
        }}>
          <div style={{
            width: 56,
            height: 56,
            borderRadius: 12,
            backgroundColor: data.primaryColor || '#1e3a5f',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: 'white',
            fontSize: 24,
            fontWeight: 'bold',
          }}>
            {data.companyName?.charAt(0) || 'A'}
          </div>
          <span style={{
            fontSize: 28,
            fontWeight: 600,
            color: data.primaryColor || '#1e3a5f',
          }}>
            {data.companyName || 'Painting Made Easy Denver'}
          </span>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

// ============================================
// SCENE 2: PHOTO SLIDESHOW
// ============================================
const PhotoSlide: React.FC<{ photo: Photo; companyName?: string }> = ({ photo, companyName }) => {
  const frame = useCurrentFrame();
  const { durationInFrames } = useVideoConfig();

  const opacity = interpolate(frame, [0, 15, durationInFrames - 15, durationInFrames], [0, 1, 1, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const scale = interpolate(frame, [0, durationInFrames], [1.1, 1], { extrapolateRight: 'clamp' });
  const panX = interpolate(frame, [0, durationInFrames], [-20, 20], { extrapolateRight: 'clamp' });

  return (
    <AbsoluteFill style={{ backgroundColor: '#000', opacity }}>
      <div style={{
        width: '100%',
        height: '100%',
        overflow: 'hidden',
      }}>
        <Img
          src={photo.url}
          style={{
            width: '100%',
            height: '100%',
            objectFit: 'cover',
            transform: `scale(${scale}) translateX(${panX}px)`,
          }}
        />
      </div>

      {/* Gradient overlay */}
      <div style={{
        position: 'absolute',
        bottom: 0,
        left: 0,
        right: 0,
        height: 200,
        background: 'linear-gradient(transparent, rgba(0,0,0,0.7))',
      }} />

      {/* Caption */}
      {photo.caption && (
        <div style={{
          position: 'absolute',
          bottom: 60,
          left: 60,
          color: 'white',
          fontSize: 32,
          fontWeight: 600,
          textShadow: '0 2px 10px rgba(0,0,0,0.5)',
        }}>
          {photo.caption}
        </div>
      )}

      {/* Watermark */}
      <div style={{
        position: 'absolute',
        bottom: 20,
        right: 20,
        backgroundColor: 'rgba(255,255,255,0.95)',
        padding: '8px 16px',
        borderRadius: 20,
        fontSize: 14,
        fontWeight: 600,
        color: '#333',
      }}>
        {companyName || 'Painting Made Easy Denver'}
      </div>
    </AbsoluteFill>
  );
};

const BeforeAfterSlide: React.FC<{ before: Photo; after: Photo; companyName?: string }> = ({ before, after, companyName }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const opacity = interpolate(frame, [0, 10, durationInFrames - 10, durationInFrames], [0, 1, 1, 0], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' });
  const beforeSlide = spring({ frame, fps, config: { stiffness: 80, damping: 20 } });
  const afterSlide = spring({ frame: frame - 10, fps, config: { stiffness: 80, damping: 20 } });

  return (
    <AbsoluteFill style={{ backgroundColor: '#f5f5f5', opacity, padding: 40 }}>
      <div style={{ display: 'flex', width: '100%', height: '100%', gap: 30 }}>
        {/* Before */}
        <div style={{
          flex: 1,
          display: 'flex',
          flexDirection: 'column',
          transform: `translateX(${(1 - beforeSlide) * -100}px)`,
          opacity: beforeSlide,
        }}>
          <div style={{
            backgroundColor: '#f59e0b',
            color: 'white',
            padding: '12px 0',
            fontSize: 28,
            fontWeight: 'bold',
            textAlign: 'center',
            borderRadius: '16px 16px 0 0',
          }}>
            BEFORE
          </div>
          <div style={{
            flex: 1,
            overflow: 'hidden',
            borderRadius: '0 0 16px 16px',
            boxShadow: '0 10px 40px rgba(0,0,0,0.15)',
          }}>
            <Img src={before.url} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
          </div>
        </div>

        {/* Arrow */}
        <div style={{
          display: 'flex',
          alignItems: 'center',
          opacity: afterSlide,
        }}>
          <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#10b981" strokeWidth="3">
            <path d="M5 12h14M12 5l7 7-7 7" />
          </svg>
        </div>

        {/* After */}
        <div style={{
          flex: 1,
          display: 'flex',
          flexDirection: 'column',
          transform: `translateX(${(1 - afterSlide) * 100}px)`,
          opacity: afterSlide,
        }}>
          <div style={{
            backgroundColor: '#10b981',
            color: 'white',
            padding: '12px 0',
            fontSize: 28,
            fontWeight: 'bold',
            textAlign: 'center',
            borderRadius: '16px 16px 0 0',
          }}>
            AFTER
          </div>
          <div style={{
            flex: 1,
            overflow: 'hidden',
            borderRadius: '0 0 16px 16px',
            boxShadow: '0 10px 40px rgba(0,0,0,0.15)',
          }}>
            <Img src={after.url} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
          </div>
        </div>
      </div>

      {/* Watermark */}
      <div style={{
        position: 'absolute',
        bottom: 20,
        right: 30,
        fontSize: 16,
        fontWeight: 600,
        color: '#666',
      }}>
        {companyName || 'Painting Made Easy Denver'}
      </div>
    </AbsoluteFill>
  );
};

// ============================================
// SCENE 3: PRICING
// ============================================
const PricingScene: React.FC<{ data: VideoData }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const scopeOpacity = interpolate(frame, [0, 25], [0, 1], { extrapolateRight: 'clamp' });
  const scopeSlide = interpolate(frame, [0, 25], [40, 0], { extrapolateRight: 'clamp' });
  const calcOpacity = interpolate(frame, [30, 50], [0, 1], { extrapolateRight: 'clamp' });
  const priceScale = spring({ frame: frame - 55, fps, config: { stiffness: 60, damping: 12 } });
  const priceOpacity = interpolate(frame, [55, 70], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  const hasEstimate = data.estimate && data.estimate.total > 0;

  return (
    <AbsoluteFill style={{ backgroundColor: '#111', opacity: fadeOut }}>
      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', padding: 80 }}>
        {/* Scope of Work */}
        <div style={{
          opacity: scopeOpacity,
          transform: `translateY(${scopeSlide}px)`,
          textAlign: 'center',
          maxWidth: 1000,
          marginBottom: 50,
        }}>
          <p style={{
            fontSize: 38,
            fontWeight: 400,
            color: '#fff',
            lineHeight: 1.5,
            fontStyle: 'italic',
          }}>
            {data.scopeOfWork || 'Full interior painting transformation including walls, ceilings, trim, and doors.'}
          </p>
        </div>

        {/* Calculation */}
        {data.estimate?.squareFootage && data.estimate?.pricePerSqft && (
          <div style={{ opacity: calcOpacity, textAlign: 'center', marginBottom: 30 }}>
            <p style={{ fontSize: 32, color: '#888' }}>
              {data.estimate.squareFootage.toLocaleString()} sqft × ${data.estimate.pricePerSqft.toFixed(2)}
            </p>
          </div>
        )}

        {/* Price */}
        {hasEstimate ? (
          <div style={{
            opacity: priceOpacity,
            transform: `scale(${Math.max(0, priceScale)})`,
          }}>
            <p style={{
              fontSize: 140,
              fontWeight: 800,
              color: data.accentColor || '#10b981',
              margin: 0,
              letterSpacing: -4,
            }}>
              ${data.estimate!.total.toLocaleString()}
            </p>
          </div>
        ) : (
          <div style={{
            opacity: priceOpacity,
            transform: `scale(${Math.max(0, priceScale)})`,
            border: `3px dashed ${data.accentColor || '#10b981'}`,
            borderRadius: 20,
            padding: '40px 60px',
          }}>
            <p style={{ fontSize: 48, fontWeight: 700, color: data.accentColor || '#10b981', margin: 0 }}>
              Custom Quote
            </p>
            <p style={{ fontSize: 24, color: '#888', marginTop: 10 }}>Contact us for pricing</p>
          </div>
        )}
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

// ============================================
// SCENE 4: TRANSITION (Home Sweet Home)
// ============================================
const TransitionScene: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const textScale = spring({ frame: frame - 10, fps, config: { stiffness: 80, damping: 15 } });
  const textOpacity = interpolate(frame, [10, 30], [0, 1], { extrapolateRight: 'clamp' });
  const fadeOut = interpolate(frame, [durationInFrames - 10, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  // Hearts
  const hearts = [
    { x: -220, y: -160, size: 35, delay: 0 },
    { x: 230, y: -130, size: 28, delay: 5 },
    { x: -190, y: 60, size: 24, delay: 10 },
    { x: 250, y: 90, size: 32, delay: 8 },
    { x: 0, y: -220, size: 45, delay: 3 },
    { x: -120, y: 140, size: 26, delay: 12 },
    { x: 180, y: -90, size: 30, delay: 6 },
  ];

  return (
    <AbsoluteFill style={{ backgroundColor: '#fff', opacity: fadeOut }}>
      {/* Floating Hearts */}
      {hearts.map((heart, i) => {
        const heartOpacity = interpolate(frame, [heart.delay, heart.delay + 20], [0, 1], { extrapolateRight: 'clamp' });
        const heartFloat = interpolate(frame, [0, durationInFrames], [0, -30]);
        const heartScale = spring({ frame: frame - heart.delay, fps, config: { stiffness: 100, damping: 10 } });

        return (
          <div key={i} style={{
            position: 'absolute',
            left: '50%',
            top: '50%',
            transform: `translate(${heart.x}px, ${heart.y + heartFloat}px) scale(${Math.max(0, heartScale)})`,
            opacity: heartOpacity,
          }}>
            <svg width={heart.size} height={heart.size} viewBox="0 0 24 24" fill="#fca5a5">
              <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
            </svg>
          </div>
        );
      })}

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        <div style={{
          opacity: textOpacity,
          transform: `scale(${Math.max(0, textScale)})`,
          textAlign: 'center',
        }}>
          <h2 style={{ fontSize: 90, fontWeight: 800, color: '#111', margin: 0, fontFamily: 'Georgia, serif' }}>
            HOME
          </h2>
          <h2 style={{ fontSize: 80, fontWeight: 400, color: '#111', margin: '10px 0', fontFamily: 'Georgia, serif', fontStyle: 'italic' }}>
            sweet
          </h2>
          <h2 style={{ fontSize: 90, fontWeight: 800, color: '#111', margin: 0, fontFamily: 'Georgia, serif' }}>
            HOME
          </h2>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

// ============================================
// SCENE 5: TIMELINE & GUARANTEE
// ============================================
const TimelineScene: React.FC<{ data: VideoData }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();

  const textOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const textSlide = interpolate(frame, [0, 20], [-40, 0], { extrapolateRight: 'clamp' });
  const clockScale = spring({ frame: frame - 25, fps, config: { stiffness: 100, damping: 15 } });
  const badgeScale = spring({ frame: frame - 50, fps, config: { stiffness: 80, damping: 12 } });
  const fadeOut = interpolate(frame, [durationInFrames - 15, durationInFrames], [1, 0], { extrapolateLeft: 'clamp' });

  return (
    <AbsoluteFill style={{ backgroundColor: '#fff', opacity: fadeOut }}>
      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center' }}>
        {/* Text */}
        <div style={{
          opacity: textOpacity,
          transform: `translateY(${textSlide}px)`,
          textAlign: 'center',
          marginBottom: 50,
        }}>
          <h2 style={{ fontSize: 56, fontWeight: 700, color: '#111', margin: 0, lineHeight: 1.3 }}>
            Project to start at close;
            <br />
            to be completed in {data.completionWeeks || 3} weeks!
          </h2>
        </div>

        {/* Clock */}
        <div style={{ transform: `scale(${Math.max(0, clockScale)})`, marginBottom: 50 }}>
          <svg width="120" height="120" viewBox="0 0 24 24" fill="none" stroke="#333" strokeWidth="1.5">
            <circle cx="12" cy="12" r="10" />
            <polyline points="12 6 12 12 16 14" />
          </svg>
        </div>

        {/* Guarantee Badge */}
        <div style={{ display: 'flex', alignItems: 'center', transform: `scale(${Math.max(0, badgeScale)})` }}>
          <div style={{
            width: 80,
            height: 90,
            backgroundColor: '#1f2937',
            borderRadius: '50% 50% 50% 50% / 30% 30% 70% 70%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            marginRight: -10,
            zIndex: 1,
          }}>
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d4a853" strokeWidth="3">
              <polyline points="20 6 9 17 4 12" />
            </svg>
          </div>
          <div style={{
            backgroundColor: '#d4a853',
            padding: '18px 45px 18px 35px',
            borderRadius: '0 30px 30px 0',
          }}>
            <span style={{ fontSize: 32, fontWeight: 'bold', color: '#1f2937', letterSpacing: 2 }}>
              GUARANTEE
            </span>
          </div>
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

// ============================================
// SCENE 6: CONTACT / CTA
// ============================================
const ContactScene: React.FC<{ data: VideoData }> = ({ data }) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();

  const headerOpacity = interpolate(frame, [0, 20], [0, 1], { extrapolateRight: 'clamp' });
  const headerSlide = interpolate(frame, [0, 20], [-30, 0], { extrapolateRight: 'clamp' });
  const contentOpacity = interpolate(frame, [20, 40], [0, 1], { extrapolateRight: 'clamp' });
  const ctaScale = spring({ frame: frame - 50, fps, config: { stiffness: 80, damping: 15 } });

  return (
    <AbsoluteFill style={{ backgroundColor: '#fafafa' }}>
      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', padding: 60 }}>
        {/* Company Name */}
        <div style={{
          opacity: headerOpacity,
          transform: `translateY(${headerSlide}px)`,
          textAlign: 'center',
          marginBottom: 20,
        }}>
          <h1 style={{ fontSize: 64, fontWeight: 800, color: '#111', margin: 0 }}>
            {data.companyName || 'Painting Made Easy Denver'}
          </h1>
        </div>

        {/* Address */}
        <div style={{ opacity: contentOpacity, textAlign: 'center', marginBottom: 50 }}>
          <p style={{ fontSize: 32, color: '#666', margin: 0 }}>
            {data.address}, {data.city}
          </p>
        </div>

        {/* QR Placeholder & Emoji */}
        <div style={{
          opacity: contentOpacity,
          display: 'flex',
          alignItems: 'center',
          gap: 40,
          marginBottom: 50,
        }}>
          <span style={{ fontSize: 64 }}>(・‿・)ノ</span>
          <div style={{
            width: 160,
            height: 160,
            backgroundColor: '#fff',
            border: '2px solid #ddd',
            borderRadius: 12,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            flexDirection: 'column',
            boxShadow: '0 4px 20px rgba(0,0,0,0.1)',
          }}>
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#999" strokeWidth="1">
              <rect x="3" y="3" width="7" height="7" />
              <rect x="14" y="3" width="7" height="7" />
              <rect x="3" y="14" width="7" height="7" />
              <rect x="14" y="14" width="4" height="4" />
            </svg>
            <span style={{ fontSize: 12, color: '#999', marginTop: 5 }}>Scan Me</span>
          </div>
        </div>

        {/* CTA */}
        <div style={{ transform: `scale(${Math.max(0, ctaScale)})`, textAlign: 'center' }}>
          <p style={{ fontSize: 28, fontWeight: 600, color: '#111', margin: 0 }}>
            {data.companyTagline || 'Professional Painting Services'}
          </p>
          <p style={{
            fontSize: 36,
            fontWeight: 700,
            color: data.primaryColor || '#1e3a5f',
            marginTop: 15,
          }}>
            {data.companyPhone || '(555) 123-4567'}
          </p>
        </div>
      </AbsoluteFill>

      {/* Footer */}
      <div style={{
        position: 'absolute',
        bottom: 30,
        left: 0,
        right: 0,
        display: 'flex',
        justifyContent: 'center',
        opacity: contentOpacity,
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{
            width: 36,
            height: 36,
            borderRadius: 8,
            backgroundColor: data.primaryColor || '#1e3a5f',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: 'white',
            fontSize: 16,
            fontWeight: 'bold',
          }}>
            {data.companyName?.charAt(0) || 'A'}
          </div>
          <span style={{ fontSize: 18, fontWeight: 500, color: '#888' }}>
            {data.companyName || 'Painting Made Easy Denver'}
          </span>
        </div>
      </div>
    </AbsoluteFill>
  );
};

// ============================================
// MAIN COMPOSITION
// ============================================
const PropertyShowcase: React.FC<{ data: VideoData }> = ({ data }) => {
  const photos = data.photos || [];
  const beforePhotos = photos.filter(p => p.category === 'before');
  const afterPhotos = photos.filter(p => p.category === 'after');
  const generalPhotos = photos.filter(p => p.category === 'general');

  // Scene durations in frames
  const INTRO_FRAMES = 4 * FPS;
  const PHOTO_FRAMES = 3 * FPS;
  const PRICING_FRAMES = 5 * FPS;
  const TRANSITION_FRAMES = 3 * FPS;
  const TIMELINE_FRAMES = 4 * FPS;
  const CONTACT_FRAMES = 5 * FPS;

  // Calculate photo section
  const pairsCount = Math.min(beforePhotos.length, afterPhotos.length);
  const totalPhotoSlides = pairsCount + generalPhotos.length;
  const photoSectionFrames = Math.max(1, totalPhotoSlides) * PHOTO_FRAMES;

  // Build sequences
  let currentFrame = 0;
  const sequences: JSX.Element[] = [];

  // 1. Intro
  sequences.push(
    <Sequence key="intro" from={currentFrame} durationInFrames={INTRO_FRAMES}>
      <IntroScene data={data} />
    </Sequence>
  );
  currentFrame += INTRO_FRAMES;

  // 2. Photos
  if (photos.length > 0) {
    // Before/After pairs
    for (let i = 0; i < pairsCount; i++) {
      sequences.push(
        <Sequence key={`pair-${i}`} from={currentFrame} durationInFrames={PHOTO_FRAMES}>
          <BeforeAfterSlide before={beforePhotos[i]} after={afterPhotos[i]} companyName={data.companyName} />
        </Sequence>
      );
      currentFrame += PHOTO_FRAMES;
    }

    // General photos
    generalPhotos.forEach((photo, i) => {
      sequences.push(
        <Sequence key={`general-${i}`} from={currentFrame} durationInFrames={PHOTO_FRAMES}>
          <PhotoSlide photo={photo} companyName={data.companyName} />
        </Sequence>
      );
      currentFrame += PHOTO_FRAMES;
    });
  }

  // 3. Pricing
  sequences.push(
    <Sequence key="pricing" from={currentFrame} durationInFrames={PRICING_FRAMES}>
      <PricingScene data={data} />
    </Sequence>
  );
  currentFrame += PRICING_FRAMES;

  // 4. Transition
  sequences.push(
    <Sequence key="transition" from={currentFrame} durationInFrames={TRANSITION_FRAMES}>
      <TransitionScene />
    </Sequence>
  );
  currentFrame += TRANSITION_FRAMES;

  // 5. Timeline
  sequences.push(
    <Sequence key="timeline" from={currentFrame} durationInFrames={TIMELINE_FRAMES}>
      <TimelineScene data={data} />
    </Sequence>
  );
  currentFrame += TIMELINE_FRAMES;

  // 6. Contact
  sequences.push(
    <Sequence key="contact" from={currentFrame} durationInFrames={CONTACT_FRAMES}>
      <ContactScene data={data} />
    </Sequence>
  );

  return <AbsoluteFill style={{ backgroundColor: '#fff' }}>{sequences}</AbsoluteFill>;
};

// ============================================
// VIDEO PLAYER APP
// ============================================
const VideoPlayerApp: React.FC = () => {
  const [videoData, setVideoData] = useState<VideoData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const type = params.get('type') || 'property';
    const id = params.get('id') || '1';

    fetch(`/api/video-data/${type}/${id}`)
      .then(res => {
        if (!res.ok) throw new Error('Failed to load video data');
        return res.json();
      })
      .then(data => {
        setVideoData(data);
        setLoading(false);
      })
      .catch(err => {
        setError(err.message);
        setLoading(false);
      });
  }, []);

  if (loading) {
    return (
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100%',
        color: '#888',
        fontSize: 18,
      }}>
        Loading video...
      </div>
    );
  }

  if (error || !videoData) {
    return (
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        height: '100%',
        color: '#ef4444',
        fontSize: 18,
      }}>
        {error || 'Failed to load video'}
      </div>
    );
  }

  // Calculate duration
  const photos = videoData.photos || [];
  const pairsCount = Math.min(
    photos.filter(p => p.category === 'before').length,
    photos.filter(p => p.category === 'after').length
  );
  const generalCount = photos.filter(p => p.category === 'general').length;
  const photoSlides = pairsCount + generalCount;

  const durationInFrames = (
    4 + // intro
    Math.max(1, photoSlides) * 3 + // photos
    5 + // pricing
    3 + // transition
    4 + // timeline
    5   // contact
  ) * FPS;

  return (
    <Player
      component={PropertyShowcase}
      inputProps={{ data: videoData }}
      durationInFrames={durationInFrames}
      fps={FPS}
      compositionWidth={1920}
      compositionHeight={1080}
      style={{ width: '100%', height: '100%' }}
      controls
      autoPlay={false}
    />
  );
};

// Mount the app
const container = document.getElementById('video-player-root');
if (container) {
  const root = createRoot(container);
  root.render(<VideoPlayerApp />);
}
