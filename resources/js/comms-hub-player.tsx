import React, { useRef, useState, useCallback, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { Player, PlayerRef } from '@remotion/player';
import { CommsHubVideo } from '../../remotion/comms-hub/CommsHubVideo';
import { defaultCommsHubProps, CommsHubProps } from '../../remotion/comms-hub/types';
import { TOTAL_FRAMES, FPS } from '../../remotion/comms-hub/brand';

const CommsHubPlayerApp: React.FC = () => {
  const container = document.getElementById('comms-hub-player-root');
  const dataAttr = container?.getAttribute('data-props');
  const playerRef = useRef<PlayerRef>(null);
  const [isPlaying, setIsPlaying] = useState(false);

  let props: CommsHubProps = defaultCommsHubProps;
  if (dataAttr) {
    try {
      props = { ...defaultCommsHubProps, ...JSON.parse(dataAttr) };
    } catch {
      // Use defaults
    }
  }

  const handlePlay = useCallback(() => {
    const player = playerRef.current;
    if (!player) return;
    player.play();
    setIsPlaying(true);
  }, []);

  return (
    <div style={{ width: '100%', position: 'relative' }}>
      <Player
        ref={playerRef}
        component={CommsHubVideo}
        inputProps={props}
        durationInFrames={TOTAL_FRAMES}
        fps={FPS}
        compositionWidth={1920}
        compositionHeight={1080}
        style={{
          width: '100%',
          aspectRatio: '16 / 9',
          borderRadius: 16,
        }}
        controls
        autoPlay
        loop
      />
    </div>
  );
};

// Mount
const container = document.getElementById('comms-hub-player-root');
if (container) {
  const root = createRoot(container);
  root.render(<CommsHubPlayerApp />);
}
