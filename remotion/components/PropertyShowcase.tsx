import { AbsoluteFill, Sequence } from 'remotion';
import { PropertyShowcaseProps, defaultProps } from '../types';
import { IntroScene } from './scenes/IntroScene';
import { PhotoSlideshow } from './scenes/PhotoSlideshow';
import { PricingScene } from './scenes/PricingScene';
import { TransitionScene } from './scenes/TransitionScene';
import { TimelineScene } from './scenes/TimelineScene';
import { ContactScene } from './scenes/ContactScene';

// Scene durations in seconds
const SCENE_DURATIONS = {
  intro: 4,
  photosPerSlide: 3,
  pricing: 5,
  transition: 3,
  timeline: 4,
  contact: 5,
};

const FPS = 30;

export const PropertyShowcase: React.FC<PropertyShowcaseProps> = (props) => {
  const {
    address,
    city,
    state,
    zip,
    photos,
    estimate,
    scopeOfWork,
    completionWeeks,
    companyName,
    companyPhone,
    companyTagline,
    qrCodeUrl,
    primaryColor,
    accentColor,
  } = { ...defaultProps, ...props };

  // Calculate photo slideshow duration based on number of photos
  const photoCount = photos.length || 1;
  const beforePhotos = photos.filter((p) => p.category === 'before').length;
  const afterPhotos = photos.filter((p) => p.category === 'after').length;
  const generalPhotos = photos.filter((p) => p.category === 'general').length;
  
  // If we have before/after pairs, count those plus remaining general
  const pairsCount = Math.min(beforePhotos, afterPhotos);
  const slidesCount = pairsCount + generalPhotos + Math.max(0, beforePhotos - pairsCount) + Math.max(0, afterPhotos - pairsCount);
  const photosDuration = Math.max(1, slidesCount) * SCENE_DURATIONS.photosPerSlide;

  // Calculate frame positions
  const introStart = 0;
  const introFrames = SCENE_DURATIONS.intro * FPS;
  
  const photosStart = introFrames;
  const photosFrames = photosDuration * FPS;
  
  const pricingStart = photosStart + photosFrames;
  const pricingFrames = SCENE_DURATIONS.pricing * FPS;
  
  const transitionStart = pricingStart + pricingFrames;
  const transitionFrames = SCENE_DURATIONS.transition * FPS;
  
  const timelineStart = transitionStart + transitionFrames;
  const timelineFrames = SCENE_DURATIONS.timeline * FPS;
  
  const contactStart = timelineStart + timelineFrames;
  const contactFrames = SCENE_DURATIONS.contact * FPS;

  return (
    <AbsoluteFill style={{ backgroundColor: 'white' }}>
      {/* Scene 1: Intro */}
      <Sequence from={introStart} durationInFrames={introFrames}>
        <IntroScene
          address={address}
          city={city}
          state={state}
          companyName={companyName}
          primaryColor={primaryColor}
        />
      </Sequence>

      {/* Scene 2: Photo Slideshow */}
      {photos.length > 0 && (
        <Sequence from={photosStart} durationInFrames={photosFrames}>
          <PhotoSlideshow
            photos={photos}
            companyName={companyName}
            primaryColor={primaryColor}
            secondsPerPhoto={SCENE_DURATIONS.photosPerSlide}
          />
        </Sequence>
      )}

      {/* Scene 3: Pricing */}
      <Sequence from={pricingStart} durationInFrames={pricingFrames}>
        <PricingScene
          estimate={estimate}
          scopeOfWork={scopeOfWork}
          accentColor={accentColor}
        />
      </Sequence>

      {/* Scene 4: Transition (Home Sweet Home) */}
      <Sequence from={transitionStart} durationInFrames={transitionFrames}>
        <TransitionScene primaryColor={primaryColor} />
      </Sequence>

      {/* Scene 5: Timeline & Guarantee */}
      <Sequence from={timelineStart} durationInFrames={timelineFrames}>
        <TimelineScene
          completionWeeks={completionWeeks}
          primaryColor={primaryColor}
        />
      </Sequence>

      {/* Scene 6: Contact/CTA */}
      <Sequence from={contactStart} durationInFrames={contactFrames}>
        <ContactScene
          address={address}
          city={city}
          companyName={companyName}
          companyPhone={companyPhone}
          companyTagline={companyTagline}
          qrCodeUrl={qrCodeUrl}
          primaryColor={primaryColor}
        />
      </Sequence>
    </AbsoluteFill>
  );
};

// Helper to calculate total duration
export const calculateDuration = (photoCount: number): number => {
  const photosDuration = Math.max(1, photoCount) * SCENE_DURATIONS.photosPerSlide;
  return (
    SCENE_DURATIONS.intro +
    photosDuration +
    SCENE_DURATIONS.pricing +
    SCENE_DURATIONS.transition +
    SCENE_DURATIONS.timeline +
    SCENE_DURATIONS.contact
  ) * FPS;
};
