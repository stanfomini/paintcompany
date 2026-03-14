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

        # 1. Transcode to MP4 with Safari/iOS-compatible settings
        # Use FFmpeg's built-in scaling with SAR normalization to avoid
        # non-standard properties that Safari's hardware decoder rejects.
        vf_filter = (
            "scale='if(gte(iw,ih),min(1920,iw),-2)':'if(gte(iw,ih),-2,min(1920,ih))'"
            ":force_original_aspect_ratio=decrease"
            ":force_divisible_by=2"
            ",setsar=1"
        )

        logger.info(f"Transcoding {input_path} to {video_path}")
        transcode_cmd = [
            "ffmpeg", "-i", input_path,
            "-c:v", "libx264", "-preset", "slow", "-crf", "28",
            "-c:a", "aac", "-b:a", "128k",
            "-movflags", "+faststart",
            "-vf", vf_filter,
            "-pix_fmt", "yuv420p",
            "-colorspace", "bt709", "-color_primaries", "bt709", "-color_trc", "bt709",
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
