import React from 'react';
import { createRoot } from 'react-dom/client';
import { Player } from '@remotion/player';
import { PromoVideo } from '../../remotion/promo/PromoVideo';
import { defaultPromoProps, PromoVideoProps } from '../../remotion/promo/types';
import { TOTAL_FRAMES, FPS } from '../../remotion/promo/brand';

const PromoPlayerApp: React.FC = () => {
  const container = document.getElementById('promo-player-root');
  const dataAttr = container?.getAttribute('data-props');

  let props: PromoVideoProps = defaultPromoProps;
  if (dataAttr) {
    try {
      props = { ...defaultPromoProps, ...JSON.parse(dataAttr) };
    } catch {
      // Use defaults
    }
  }

  return (
    <div style={{ width: '100%', maxWidth: 1280, margin: '0 auto' }}>
      <Player
        component={PromoVideo}
        inputProps={props}
        durationInFrames={TOTAL_FRAMES}
        fps={FPS}
        compositionWidth={1920}
        compositionHeight={1080}
        style={{
          width: '100%',
          aspectRatio: '16 / 9',
        }}
        controls
        autoPlay
        loop
      />
    </div>
  );
};

// Mount
const container = document.getElementById('promo-player-root');
if (container) {
  const root = createRoot(container);
  root.render(<PromoPlayerApp />);
}
