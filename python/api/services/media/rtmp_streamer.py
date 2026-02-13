"""RTMP Streaming relay - receives WebSocket media chunks and pushes to RTMP destinations via FFmpeg."""

import asyncio
import shutil
from typing import Optional

from loguru import logger


class RTMPStreamerError(Exception):
    """Error during RTMP streaming."""

    pass


class RTMPStreamer:
    """Relays media chunks to one or more RTMP destinations via FFmpeg.

    Flow:
    1. Browser composites video feeds + HTML overlays onto a canvas
    2. canvas.captureStream(30) -> MediaRecorder (H.264 WebM or VP8)
    3. Chunks sent via WebSocket to this service
    4. FFmpeg re-muxes to FLV and pushes to RTMP endpoints
    """

    def __init__(self, stream_id: str, destinations: list[dict]):
        self.stream_id = stream_id
        self.destinations = destinations  # [{"rtmp_url": "...", "stream_key": "..."}]
        self.process: Optional[asyncio.subprocess.Process] = None
        self.is_streaming = False
        self.bytes_received = 0
        self.codec_mode = "copy"  # "copy" for H.264, "transcode" for VP8

    async def start(self, codec_hint: str = "h264") -> None:
        """Start FFmpeg process for RTMP streaming."""
        if not shutil.which("ffmpeg"):
            raise RTMPStreamerError("FFmpeg is not installed on this server")

        if not self.destinations:
            raise RTMPStreamerError("No RTMP destinations configured")

        # Build output arguments based on number of destinations
        output_args = self._build_output_args(codec_hint)

        cmd = [
            "ffmpeg",
            "-i",
            "pipe:0",  # Read from stdin
            *output_args,
        ]

        logger.info(
            f"Starting RTMP stream {self.stream_id} with "
            f"{len(self.destinations)} destination(s)"
        )
        logger.debug(f"FFmpeg command: {' '.join(cmd)}")

        self.process = await asyncio.create_subprocess_exec(
            *cmd,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )

        self.is_streaming = True
        logger.info(f"RTMP stream {self.stream_id} started")

    def _build_output_args(self, codec_hint: str) -> list[str]:
        """Build FFmpeg output arguments for RTMP destinations."""
        args = []

        # Codec settings based on browser codec
        if codec_hint == "h264":
            self.codec_mode = "copy"
            video_codec = ["-c:v", "copy"]
        else:
            # VP8/VP9 from Firefox needs transcoding
            self.codec_mode = "transcode"
            video_codec = [
                "-c:v",
                "libx264",
                "-preset",
                "ultrafast",
                "-tune",
                "zerolatency",
            ]

        audio_codec = ["-c:a", "aac", "-b:a", "128k"]

        if len(self.destinations) == 1:
            # Single destination - direct output
            dest = self.destinations[0]
            rtmp_url = f"{dest['rtmp_url']}/{dest['stream_key']}"
            args.extend([*video_codec, *audio_codec, "-f", "flv", rtmp_url])
        else:
            # Multiple destinations - use tee muxer
            outputs = []
            for dest in self.destinations:
                rtmp_url = f"{dest['rtmp_url']}/{dest['stream_key']}"
                outputs.append(f"[f=flv]{rtmp_url}")

            tee_output = "|".join(outputs)
            args.extend([
                *video_codec,
                *audio_codec,
                "-f",
                "tee",
                "-map",
                "0:v?",
                "-map",
                "0:a?",
                tee_output,
            ])

        return args

    async def write_chunk(self, data: bytes) -> int:
        """Write a media chunk to FFmpeg stdin."""
        if not self.process or not self.process.stdin or not self.is_streaming:
            raise RTMPStreamerError("Stream is not active")

        try:
            self.process.stdin.write(data)
            await self.process.stdin.drain()
            self.bytes_received += len(data)
            return len(data)
        except (BrokenPipeError, ConnectionResetError) as e:
            logger.error(
                f"FFmpeg pipe error for stream {self.stream_id}: {e}"
            )
            self.is_streaming = False
            raise RTMPStreamerError(
                f"FFmpeg process closed unexpectedly: {e}"
            )

    async def stop(self) -> dict:
        """Stop streaming and return stats."""
        if not self.process:
            raise RTMPStreamerError("No stream process to stop")

        self.is_streaming = False

        try:
            if self.process.stdin and not self.process.stdin.is_closing():
                self.process.stdin.close()

            try:
                await asyncio.wait_for(self.process.wait(), timeout=10)
            except asyncio.TimeoutError:
                logger.warning(
                    f"FFmpeg timeout for stream {self.stream_id}, "
                    f"killing process"
                )
                self.process.kill()
                await self.process.wait()

        except Exception as e:
            logger.error(
                f"Error stopping stream {self.stream_id}: {e}"
            )

        # Check stderr for FFmpeg errors
        stderr_output = ""
        if self.process.stderr:
            stderr = await self.process.stderr.read()
            if stderr:
                stderr_output = stderr.decode("utf-8", errors="replace")[
                    -500:
                ]
                if "error" in stderr_output.lower():
                    logger.warning(
                        f"FFmpeg stderr for stream {self.stream_id}: "
                        f"{stderr_output}"
                    )

        logger.info(
            f"Stream {self.stream_id} stopped: "
            f"{self.bytes_received} bytes processed, "
            f"codec_mode={self.codec_mode}"
        )

        return {
            "stream_id": self.stream_id,
            "bytes_received": self.bytes_received,
            "codec_mode": self.codec_mode,
            "destinations": len(self.destinations),
        }

    @property
    def stats(self) -> dict:
        """Get current streaming stats."""
        return {
            "stream_id": self.stream_id,
            "is_streaming": self.is_streaming,
            "bytes_received": self.bytes_received,
            "codec_mode": self.codec_mode,
            "destination_count": len(self.destinations),
        }


class StreamRegistry:
    """Thread-safe registry of active streaming sessions."""

    def __init__(self):
        self._streamers: dict[str, RTMPStreamer] = {}
        self._lock = asyncio.Lock()

    async def start(
        self,
        stream_id: str,
        destinations: list[dict],
        codec_hint: str = "h264",
    ) -> RTMPStreamer:
        """Create and start a new streaming session."""
        async with self._lock:
            if stream_id in self._streamers:
                raise RTMPStreamerError(
                    f"Stream {stream_id} already exists"
                )

            streamer = RTMPStreamer(stream_id, destinations)
            await streamer.start(codec_hint)
            self._streamers[stream_id] = streamer
            return streamer

    def get(self, stream_id: str) -> Optional[RTMPStreamer]:
        """Get an active streamer by ID."""
        return self._streamers.get(stream_id)

    async def stop(self, stream_id: str) -> Optional[dict]:
        """Stop a stream and return stats."""
        async with self._lock:
            streamer = self._streamers.pop(stream_id, None)
            if not streamer:
                return None
            return await streamer.stop()

    async def stop_all(self) -> list[dict]:
        """Stop all active streams."""
        async with self._lock:
            results = []
            for stream_id in list(self._streamers.keys()):
                streamer = self._streamers.pop(stream_id)
                try:
                    result = await streamer.stop()
                    results.append(result)
                except Exception as e:
                    logger.error(
                        f"Error stopping stream {stream_id}: {e}"
                    )
            return results

    @property
    def active_count(self) -> int:
        """Number of active streams."""
        return len(self._streamers)

    @property
    def active_streams(self) -> list[dict]:
        """Get stats for all active streams."""
        return [s.stats for s in self._streamers.values()]


# Global singleton registry
stream_registry = StreamRegistry()
