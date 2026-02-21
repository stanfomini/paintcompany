"""Video processing service using FFmpeg."""

import asyncio
import json
import os
import shutil

from loguru import logger


class VideoProcessorError(Exception):
    """Custom exception for video processing errors."""
    pass


class VideoProcessor:
    """Processes uploaded videos: transcode to MP4, extract thumbnail, probe metadata."""

    @staticmethod
    async def process(input_path: str, output_dir: str) -> dict:
        """
        Process a video file: transcode to MP4, extract thumbnail, probe metadata.

        Args:
            input_path: Path to the input video file
            output_dir: Directory to write output files

        Returns:
            Dict with video_path, thumbnail_path, duration, width, height, file_size
        """
        os.makedirs(output_dir, exist_ok=True)

        video_path = os.path.join(output_dir, "output.mp4")
        thumbnail_path = os.path.join(output_dir, "thumbnail.jpg")

        # 1. Probe input dimensions to build a safe scale filter
        logger.info(f"Probing input dimensions for {input_path}")
        probe_input_cmd = [
            "ffprobe", "-v", "quiet",
            "-select_streams", "v:0",
            "-show_entries", "stream=width,height",
            "-of", "json",
            input_path,
        ]
        probe_proc = await asyncio.create_subprocess_exec(
            *probe_input_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        probe_stdout, _ = await probe_proc.communicate()

        # Default: just ensure even dimensions
        vf_filter = "pad=ceil(iw/2)*2:ceil(ih/2)*2"

        if probe_proc.returncode == 0 and probe_stdout:
            try:
                probe_info = json.loads(probe_stdout.decode())
                streams = probe_info.get("streams", [])
                if streams:
                    iw = streams[0].get("width", 0)
                    ih = streams[0].get("height", 0)
                    # Calculate target dimensions: fit within 1920x1080, preserve aspect ratio
                    if iw > 0 and ih > 0:
                        scale_w = min(1920 / iw, 1.0)
                        scale_h = min(1080 / ih, 1.0)
                        scale_factor = min(scale_w, scale_h)
                        tw = int(iw * scale_factor)
                        th = int(ih * scale_factor)
                        # Ensure even dimensions (libx264 requirement)
                        tw = tw - (tw % 2)
                        th = th - (th % 2)
                        vf_filter = f"scale={tw}:{th}"
                        logger.info(f"Input {iw}x{ih} -> target {tw}x{th}")
            except (json.JSONDecodeError, ValueError, KeyError) as e:
                logger.warning(f"Failed to parse input probe, using safe filter: {e}")

        # 1. Transcode to MP4
        logger.info(f"Transcoding {input_path} to {video_path}")
        transcode_cmd = [
            "ffmpeg", "-i", input_path,
            "-c:v", "libx264", "-preset", "slow", "-crf", "28",
            "-c:a", "aac", "-b:a", "128k",
            "-movflags", "+faststart",
            "-vf", vf_filter,
            "-y", video_path,
        ]

        proc = await asyncio.create_subprocess_exec(
            *transcode_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        _, stderr = await proc.communicate()

        if proc.returncode != 0:
            error_msg = stderr.decode()[-500:] if stderr else "Unknown FFmpeg error"
            logger.error(f"FFmpeg transcode failed: {error_msg}")
            raise VideoProcessorError(f"Transcode failed: {error_msg}")

        if not os.path.exists(video_path):
            raise VideoProcessorError("Transcode produced no output file")

        logger.info(f"Transcode complete: {video_path}")

        # 2. Extract thumbnail
        logger.info(f"Extracting thumbnail to {thumbnail_path}")
        thumb_cmd = [
            "ffmpeg", "-i", video_path,
            "-ss", "00:00:01",
            "-vframes", "1",
            "-q:v", "5",
            "-y", thumbnail_path,
        ]

        proc = await asyncio.create_subprocess_exec(
            *thumb_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        await proc.communicate()

        # Thumbnail extraction is non-fatal; fallback to frame 0 if video < 1s
        if not os.path.exists(thumbnail_path):
            thumb_cmd_fallback = [
                "ffmpeg", "-i", video_path,
                "-vframes", "1",
                "-q:v", "5",
                "-y", thumbnail_path,
            ]
            proc = await asyncio.create_subprocess_exec(
                *thumb_cmd_fallback,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.PIPE,
            )
            await proc.communicate()

        # 3. Probe metadata
        logger.info("Probing video metadata")
        probe_cmd = [
            "ffprobe", "-v", "quiet",
            "-print_format", "json",
            "-show_format", "-show_streams",
            video_path,
        ]

        proc = await asyncio.create_subprocess_exec(
            *probe_cmd,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.PIPE,
        )
        stdout, _ = await proc.communicate()

        duration = 0
        width = 0
        height = 0

        if proc.returncode == 0 and stdout:
            try:
                probe_data = json.loads(stdout.decode())

                # Get duration from format
                fmt = probe_data.get("format", {})
                duration = int(float(fmt.get("duration", 0)))

                # Get dimensions from video stream
                for stream in probe_data.get("streams", []):
                    if stream.get("codec_type") == "video":
                        width = stream.get("width", 0)
                        height = stream.get("height", 0)
                        break
            except (json.JSONDecodeError, ValueError) as e:
                logger.warning(f"Failed to parse probe output: {e}")

        file_size = os.path.getsize(video_path) if os.path.exists(video_path) else 0

        result = {
            "video_path": video_path,
            "thumbnail_path": thumbnail_path if os.path.exists(thumbnail_path) else None,
            "duration": duration,
            "width": width,
            "height": height,
            "file_size": file_size,
        }

        logger.info(f"Video processing complete: {duration}s, {width}x{height}, {file_size} bytes")
        return result

    @staticmethod
    def cleanup(output_dir: str) -> None:
        """Remove the temporary processing directory."""
        if os.path.exists(output_dir):
            shutil.rmtree(output_dir, ignore_errors=True)
            logger.info(f"Cleaned up {output_dir}")
