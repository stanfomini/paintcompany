import { AbsoluteFill, Img, interpolate, Sequence, useCurrentFrame, useVideoConfig } from 'remotion';
import { Photo } from '../../types';

interface PhotoSlideshowProps {
  photos: Photo[];
  companyName?: string;
  primaryColor?: string;
  secondsPerPhoto?: number;
}

export const PhotoSlideshow: React.FC<PhotoSlideshowProps> = ({
  photos,
  companyName = 'Painting Made Easy Denver',
  primaryColor = '#1e3a5f',
  secondsPerPhoto = 3,
}) => {
  const { fps } = useVideoConfig();
  const framesPerPhoto = secondsPerPhoto * fps;

  // Separate photos by category
  const beforePhotos = photos.filter((p) => p.category === 'before');
  const afterPhotos = photos.filter((p) => p.category === 'after');
  const generalPhotos = photos.filter((p) => p.category === 'general');

  // If we have before/after pairs, show them side by side
  const hasBeforeAfter = beforePhotos.length > 0 && afterPhotos.length > 0;

  if (hasBeforeAfter) {
    // Show before/after pairs
    const pairs = Math.min(beforePhotos.length, afterPhotos.length);
    return (
      <AbsoluteFill style={{ backgroundColor: 'white' }}>
        {Array.from({ length: pairs }).map((_, index) => (
          <Sequence key={index} from={index * framesPerPhoto} durationInFrames={framesPerPhoto}>
            <BeforeAfterSlide
              before={beforePhotos[index]}
              after={afterPhotos[index]}
              companyName={companyName}
              primaryColor={primaryColor}
            />
          </Sequence>
        ))}
        {/* Show remaining general photos */}
        {generalPhotos.map((photo, index) => (
          <Sequence
            key={`general-${index}`}
            from={(pairs + index) * framesPerPhoto}
            durationInFrames={framesPerPhoto}
          >
            <SinglePhotoSlide photo={photo} companyName={companyName} />
          </Sequence>
        ))}
      </AbsoluteFill>
    );
  }

  // Show all photos as single slides
  const allPhotos = [...photos];
  return (
    <AbsoluteFill style={{ backgroundColor: 'white' }}>
      {allPhotos.map((photo, index) => (
        <Sequence key={index} from={index * framesPerPhoto} durationInFrames={framesPerPhoto}>
          <SinglePhotoSlide photo={photo} companyName={companyName} />
        </Sequence>
      ))}
    </AbsoluteFill>
  );
};

// Single photo slide component
const SinglePhotoSlide: React.FC<{ photo: Photo; companyName?: string }> = ({
  photo,
  companyName,
}) => {
  const frame = useCurrentFrame();
  const { durationInFrames } = useVideoConfig();

  const opacity = interpolate(
    frame,
    [0, 10, durationInFrames - 10, durationInFrames],
    [0, 1, 1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );

  const scale = interpolate(frame, [0, durationInFrames], [1, 1.05], {
    extrapolateRight: 'clamp',
  });

  return (
    <AbsoluteFill style={{ opacity }}>
      <div
        style={{
          width: '100%',
          height: '100%',
          overflow: 'hidden',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          backgroundColor: '#f5f5f5',
        }}
      >
        <Img
          src={photo.url}
          style={{
            width: '100%',
            height: '100%',
            objectFit: 'cover',
            transform: `scale(${scale})`,
          }}
        />
      </div>

      {/* Caption */}
      {photo.caption && (
        <div
          style={{
            position: 'absolute',
            bottom: 40,
            left: 0,
            right: 0,
            textAlign: 'center',
          }}
        >
          <span
            style={{
              backgroundColor: 'rgba(0,0,0,0.7)',
              color: 'white',
              padding: '12px 24px',
              borderRadius: 8,
              fontSize: 24,
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            {photo.caption}
          </span>
        </div>
      )}

      {/* Watermark */}
      <div
        style={{
          position: 'absolute',
          bottom: 20,
          right: 20,
          backgroundColor: 'rgba(255,255,255,0.9)',
          padding: '8px 16px',
          borderRadius: 20,
          fontSize: 14,
          fontWeight: 600,
          color: '#333',
          fontFamily: 'system-ui, -apple-system, sans-serif',
        }}
      >
        {companyName}
      </div>
    </AbsoluteFill>
  );
};

// Before/After comparison slide
const BeforeAfterSlide: React.FC<{
  before: Photo;
  after: Photo;
  companyName?: string;
  primaryColor?: string;
}> = ({ before, after, companyName, primaryColor = '#1e3a5f' }) => {
  const frame = useCurrentFrame();
  const { durationInFrames } = useVideoConfig();

  const opacity = interpolate(
    frame,
    [0, 10, durationInFrames - 10, durationInFrames],
    [0, 1, 1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  );

  const beforeSlide = interpolate(frame, [0, 20], [-50, 0], {
    extrapolateRight: 'clamp',
  });

  const afterSlide = interpolate(frame, [10, 30], [50, 0], {
    extrapolateRight: 'clamp',
  });

  return (
    <AbsoluteFill style={{ opacity, backgroundColor: 'white' }}>
      <div
        style={{
          display: 'flex',
          width: '100%',
          height: '100%',
          gap: 20,
          padding: 40,
        }}
      >
        {/* Before */}
        <div
          style={{
            flex: 1,
            display: 'flex',
            flexDirection: 'column',
            transform: `translateX(${beforeSlide}px)`,
          }}
        >
          <div
            style={{
              backgroundColor: '#f59e0b',
              color: 'white',
              padding: '8px 24px',
              fontSize: 24,
              fontWeight: 'bold',
              textAlign: 'center',
              borderRadius: '8px 8px 0 0',
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            BEFORE
          </div>
          <div
            style={{
              flex: 1,
              overflow: 'hidden',
              borderRadius: '0 0 8px 8px',
              border: '3px solid #f59e0b',
            }}
          >
            <Img
              src={before.url}
              style={{ width: '100%', height: '100%', objectFit: 'cover' }}
            />
          </div>
        </div>

        {/* After */}
        <div
          style={{
            flex: 1,
            display: 'flex',
            flexDirection: 'column',
            transform: `translateX(${afterSlide}px)`,
          }}
        >
          <div
            style={{
              backgroundColor: '#10b981',
              color: 'white',
              padding: '8px 24px',
              fontSize: 24,
              fontWeight: 'bold',
              textAlign: 'center',
              borderRadius: '8px 8px 0 0',
              fontFamily: 'system-ui, -apple-system, sans-serif',
            }}
          >
            AFTER
          </div>
          <div
            style={{
              flex: 1,
              overflow: 'hidden',
              borderRadius: '0 0 8px 8px',
              border: '3px solid #10b981',
            }}
          >
            <Img
              src={after.url}
              style={{ width: '100%', height: '100%', objectFit: 'cover' }}
            />
          </div>
        </div>
      </div>

      {/* Watermark */}
      <div
        style={{
          position: 'absolute',
          bottom: 20,
          right: 20,
          backgroundColor: 'rgba(255,255,255,0.9)',
          padding: '8px 16px',
          borderRadius: 20,
          fontSize: 14,
          fontWeight: 600,
          color: primaryColor,
          fontFamily: 'system-ui, -apple-system, sans-serif',
        }}
      >
        {companyName}
      </div>
    </AbsoluteFill>
  );
};
