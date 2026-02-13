"""HLS Recording service using FFmpeg for video call recording."""

import asyncio
import os
import shutil
from typing import Optional

from loguru import logger


class HLSRecorderError(Exception):
    """Error during HLS recording."""

    pass


class HLSRecorder:
    """Records media chunks into HLS format using FFmpeg.

    Flow:
    1. Browser captures MediaRecorder chunks (webm/vp8+opus)
    2. Chunks are sent to this service via POST
    3. FFmpeg converts them to HLS segments (.ts) + manifest (.m3u8)
    4. Final files are picked up by Laravel for storage
    """

    def __init__(self, recording_id: str, output_dir: str):
        self.recording_id = recording_id
        self.output_dir = output_dir
        self.process: Optional[asyncio.subprocess.Process] = None
        self.is_recording = False
        self.bytes_received = 0

    async def start(self) -> None:
        """Start FFmpeg process for HLS segmenting."""
        os.makedirs(self.output_dir, exist_ok=True)

        # Check FFmpeg is available
        if not shutil.which("ffmpeg"):
            raise HLSRecorderError("FFmpeg is not installed on this server")

        segment_pattern = os.path.join(self.output_dir, "segment_%04d.ts")
        manifest_path = os.path.join(self.output_dir, "playlist.m3u8")

        self.process = await asyncio.create_subprocess_exec(
            "ffmpeg",
            "-i",
            "pipe:0",  # Read from stdin
            "-c:v",
            "libx264",
            "-preset",
            "ultrafast",  # Fast encoding for real-time
            "-tune",
            "zerolatency",
            "-c:a",
            "aac",
            "-b:a",
            "128k",
            "-f",
            "hls",
            "-hls_time",
            "6",  # 6-second segments
            "-hls_list_size",
            "0",  # Keep all segments in manifest
            "-hls_segment_filename",
            segment_pattern,
            manifest_path,
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )

        self.is_recording = True
        logger.info(
            f"Started HLS recording {self.recording_id} → {self.output_dir}"
        )

    async def write_chunk(self, data: bytes) -> int:
        """Write a media chunk to FFmpeg stdin.

        Returns the number of bytes written.
        """
        if not self.process or not self.process.stdin or not self.is_recording:
            raise HLSRecorderError("Recording is not active")

        try:
            self.process.stdin.write(data)
            await self.process.stdin.drain()
            self.bytes_received += len(data)
            return len(data)
        except (BrokenPipeError, ConnectionResetError) as e:
            logger.error(f"FFmpeg pipe error for {self.recording_id}: {e}")
            self.is_recording = False
            raise HLSRecorderError(f"FFmpeg process closed unexpectedly: {e}")

    async def stop(self) -> str:
        """Stop recording and return the manifest path.

        Closes FFmpeg stdin and waits for it to finish writing segments.
        """
        if not self.process:
            raise HLSRecorderError("No recording process to stop")

        self.is_recording = False

        try:
            if self.process.stdin and not self.process.stdin.is_closing():
                self.process.stdin.close()

            # Wait for FFmpeg to finish (with timeout)
            try:
                await asyncio.wait_for(self.process.wait(), timeout=30)
            except asyncio.TimeoutError:
                logger.warning(
                    f"FFmpeg timeout for {self.recording_id}, killing process"
                )
                self.process.kill()
                await self.process.wait()

        except Exception as e:
            logger.error(
                f"Error stopping recording {self.recording_id}: {e}"
            )

        manifest_path = os.path.join(self.output_dir, "playlist.m3u8")

        if os.path.exists(manifest_path):
            logger.info(
                f"Recording {self.recording_id} complete: "
                f"{self.bytes_received} bytes received → {manifest_path}"
            )
            return manifest_path

        # Check stderr for FFmpeg errors
        if self.process.stderr:
            stderr = await self.process.stderr.read()
            if stderr:
                logger.error(
                    f"FFmpeg stderr for {self.recording_id}: "
                    f"{stderr.decode('utf-8', errors='replace')[-500:]}"
                )

        raise HLSRecorderError(
            f"Recording failed: manifest not created at {manifest_path}"
        )

    def get_output_files(self) -> list[str]:
        """Get list of all output files (manifest + segments)."""
        if not os.path.exists(self.output_dir):
            return []

        return [
            os.path.join(self.output_dir, f)
            for f in os.listdir(self.output_dir)
            if f.endswith((".m3u8", ".ts"))
        ]

    async def cleanup(self) -> None:
        """Remove temporary recording files."""
        if os.path.exists(self.output_dir):
            shutil.rmtree(self.output_dir, ignore_errors=True)
            logger.info(f"Cleaned up recording dir: {self.output_dir}")


class RecordingRegistry:
    """Thread-safe registry of active recording sessions."""

    def __init__(self):
        self._recorders: dict[str, HLSRecorder] = {}
        self._lock = asyncio.Lock()

    async def start(self, recording_id: str) -> HLSRecorder:
        """Create and start a new recording session."""
        async with self._lock:
            if recording_id in self._recorders:
                raise HLSRecorderError(
                    f"Recording {recording_id} already exists"
                )

            output_dir = f"/tmp/hls-recordings/{recording_id}"
            recorder = HLSRecorder(recording_id, output_dir)
            await recorder.start()
            self._recorders[recording_id] = recorder
            return recorder

    def get(self, recording_id: str) -> Optional[HLSRecorder]:
        """Get an active recorder by ID."""
        return self._recorders.get(recording_id)

    async def stop(self, recording_id: str) -> Optional[str]:
        """Stop a recording and return the manifest path."""
        async with self._lock:
            recorder = self._recorders.pop(recording_id, None)
            if not recorder:
                return None

            return await recorder.stop()

    async def cleanup(self, recording_id: str) -> None:
        """Clean up recording files."""
        async with self._lock:
            recorder = self._recorders.pop(recording_id, None)
            if recorder:
                await recorder.cleanup()

    @property
    def active_count(self) -> int:
        """Number of active recordings."""
        return len(self._recorders)


# Global singleton registry
recording_registry = RecordingRegistry()
