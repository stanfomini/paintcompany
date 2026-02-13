import React from "react";
import { Composition } from "remotion";
import { HelloWorld } from "./components/HelloWorld";
import { PropertyShowcase, calculateDuration } from "./components/PropertyShowcase";
import { PropertyShowcaseProps, defaultProps } from "./types";
import { PromoVideo } from "./promo/PromoVideo";
import { defaultPromoProps } from "./promo/types";
import { TOTAL_FRAMES, FPS } from "./promo/brand";

// Default props for preview in Remotion Studio
const previewProps: PropertyShowcaseProps = {
  type: 'property',
  id: 1,
  address: '123 Main Street',
  city: 'Denver',
  state: 'CO',
  zip: '80202',
  photos: [
    { url: 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?w=800', caption: 'Living Room', category: 'before' },
    { url: 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=800', caption: 'Living Room', category: 'after' },
    { url: 'https://images.unsplash.com/photo-1556909114-f6e7ad7d3136?w=800', caption: 'Kitchen', category: 'general' },
  ],
  estimate: {
    items: [],
    subtotal: 8500,
    total: 8500,
    squareFootage: 2200,
    pricePerSqft: 3.86,
  },
  scopeOfWork: 'Full interior painting including walls, ceilings, trim, doors, and cabinet refinishing.',
  completionWeeks: 3,
  ...defaultProps,
};

export const RemotionRoot: React.FC = () => {
  return (
    <>
      {/* Promo Video - Main marketing video */}
      <Composition
        id="PromoVideo"
        component={PromoVideo}
        durationInFrames={TOTAL_FRAMES}
        fps={FPS}
        width={1920}
        height={1080}
        defaultProps={defaultPromoProps}
      />

      {/* Property Showcase Video */}
      <Composition
        id="PropertyShowcase"
        component={PropertyShowcase}
        durationInFrames={calculateDuration(previewProps.photos.length)}
        fps={30}
        width={1920}
        height={1080}
        defaultProps={previewProps}
      />

      {/* Original Hello World (for testing) */}
      <Composition
        id="HelloWorld"
        component={HelloWorld}
        durationInFrames={150}
        fps={30}
        width={1920}
        height={1080}
      />
    </>
  );
};
