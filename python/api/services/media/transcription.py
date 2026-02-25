"""Whisper transcription service for audio-to-text conversion."""

import os
import tempfile
from pathlib import Path
from typing import Any, Optional

import httpx
from loguru import logger

# Lazy import whisper to avoid loading it until needed
_whisper = None


def _get_whisper():
    """Lazy load the whisper module."""
    global _whisper
    if _whisper is None:
        import whisper

        _whisper = whisper
    return _whisper


# Available Whisper models with their characteristics
WHISPER_MODELS = {
    "tiny": {
        "name": "tiny",
        "parameters": "39M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "~32x",
        "required_vram": "~1 GB",
    },
    "tiny.en": {
        "name": "tiny.en",
        "parameters": "39M",
        "english_only": True,
        "multilingual": False,
        "relative_speed": "~32x",
        "required_vram": "~1 GB",
    },
    "base": {
        "name": "base",
        "parameters": "74M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "~16x",
        "required_vram": "~1 GB",
    },
    "base.en": {
        "name": "base.en",
        "parameters": "74M",
        "english_only": True,
        "multilingual": False,
        "relative_speed": "~16x",
        "required_vram": "~1 GB",
    },
    "small": {
        "name": "small",
        "parameters": "244M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "~6x",
        "required_vram": "~2 GB",
    },
    "small.en": {
        "name": "small.en",
        "parameters": "244M",
        "english_only": True,
        "multilingual": False,
        "relative_speed": "~6x",
        "required_vram": "~2 GB",
    },
    "medium": {
        "name": "medium",
        "parameters": "769M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "~2x",
        "required_vram": "~5 GB",
    },
    "medium.en": {
        "name": "medium.en",
        "parameters": "769M",
        "english_only": True,
        "multilingual": False,
        "relative_speed": "~2x",
        "required_vram": "~5 GB",
    },
    "large": {
        "name": "large",
        "parameters": "1550M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "1x",
        "required_vram": "~10 GB",
    },
    "large-v2": {
        "name": "large-v2",
        "parameters": "1550M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "1x",
        "required_vram": "~10 GB",
    },
    "large-v3": {
        "name": "large-v3",
        "parameters": "1550M",
        "english_only": False,
        "multilingual": True,
        "relative_speed": "1x",
        "required_vram": "~10 GB",
    },
}

# Supported audio formats
SUPPORTED_FORMATS = {".mp3", ".wav", ".m4a", ".webm", ".ogg", ".flac", ".aac", ".wma"}


class TranscriptionError(Exception):
    """Custom exception for transcription errors."""

    pass


class TranscriptionService:
    """
    Service for transcribing audio using OpenAI Whisper.

    Features:
    - Lazy model loading (loads on first use)
    - Model caching (keeps model in memory)
    - Support for local files and URLs
    - Multiple audio format support
    - Returns text, segments with timestamps, and language detection
    """

    _instance: Optional["TranscriptionService"] = None
    _model = None
    _model_name: Optional[str] = None

    def __new__(cls, model_name: str = "base"):
        """Singleton pattern to reuse loaded models."""
        if cls._instance is None:
            cls._instance = super().__new__(cls)
        return cls._instance

    def __init__(self, model_name: str = "base"):
        """
        Initialize the transcription service.

        Args:
            model_name: Whisper model to use. Options: tiny, base, small, medium, large
                       Add '.en' suffix for English-only models (e.g., 'base.en')
        """
        self._requested_model = model_name
        logger.info(f"TranscriptionService initialized with model: {model_name}")

    def _load_model(self) -> None:
        """Load the Whisper model if not already loaded."""
        if self._model is not None and self._model_name == self._requested_model:
            logger.debug(f"Using cached model: {self._model_name}")
            return

        if self._requested_model not in WHISPER_MODELS:
            raise TranscriptionError(
                f"Unknown model: {self._requested_model}. "
                f"Available models: {list(WHISPER_MODELS.keys())}"
            )

        logger.info(f"Loading Whisper model: {self._requested_model}")
        try:
            whisper = _get_whisper()
            self._model = whisper.load_model(self._requested_model)
            self._model_name = self._requested_model
            logger.info(f"Successfully loaded Whisper model: {self._requested_model}")
        except Exception as e:
            logger.error(f"Failed to load Whisper model: {e}")
            raise TranscriptionError(f"Failed to load model: {e}") from e

    def _validate_audio_format(self, file_path: str) -> None:
        """Validate that the audio file format is supported."""
        ext = Path(file_path).suffix.lower()
        if ext not in SUPPORTED_FORMATS:
            raise TranscriptionError(
                f"Unsupported audio format: {ext}. "
                f"Supported formats: {', '.join(SUPPORTED_FORMATS)}"
            )

    def _download_audio(self, url: str) -> str:
        """
        Download audio from URL to a temporary file.

        Args:
            url: URL of the audio file

        Returns:
            Path to the temporary file
        """
        logger.info(f"Downloading audio from URL: {url}")

        try:
            # Determine file extension from URL
            url_path = url.split("?")[0]  # Remove query params
            ext = Path(url_path).suffix.lower()
            if ext not in SUPPORTED_FORMATS:
                # Default to .mp3 if extension is unclear
                ext = ".mp3"

            # Download to temporary file
            with httpx.Client(timeout=120.0, follow_redirects=True) as client:
                response = client.get(url)
                response.raise_for_status()

                # Create temporary file with correct extension
                temp_file = tempfile.NamedTemporaryFile(
                    suffix=ext, delete=False, prefix="whisper_"
                )
                temp_file.write(response.content)
                temp_file.close()

                logger.info(f"Downloaded audio to: {temp_file.name}")
                return temp_file.name

        except httpx.HTTPError as e:
            logger.error(f"Failed to download audio: {e}")
            raise TranscriptionError(f"Failed to download audio from URL: {e}") from e
        except Exception as e:
            logger.error(f"Error downloading audio: {e}")
            raise TranscriptionError(f"Error downloading audio: {e}") from e

    def transcribe(
        self,
        audio_path: str,
        language: Optional[str] = None,
        task: str = "transcribe",
        verbose: bool = False,
    ) -> dict[str, Any]:
        """
        Transcribe audio from a local file.

        Args:
            audio_path: Path to the audio file
            language: Language code (e.g., 'en', 'es', 'fr'). Auto-detected if None.
            task: 'transcribe' or 'translate' (translate to English)
            verbose: Whether to print progress

        Returns:
            Dictionary containing:
            - text: Full transcription text
            - segments: List of segments with start, end, and text
            - language: Detected language code
            - duration: Audio duration in seconds
        """
        # Validate file exists
        if not os.path.exists(audio_path):
            raise TranscriptionError(f"Audio file not found: {audio_path}")

        # Validate format
        self._validate_audio_format(audio_path)

        # Load model if needed
        self._load_model()

        logger.info(f"Transcribing audio file: {audio_path}")

        try:
            # Prepare transcription options
            options = {
                "verbose": verbose,
                "task": task,
                "word_timestamps": True,
            }
            if language:
                options["language"] = language

            # Perform transcription
            result = self._model.transcribe(audio_path, **options)

            # Extract word-level timestamps and group into caption phrases
            segments = []
            for segment in result.get("segments", []):
                words = segment.get("words", [])
                if words:
                    # Group words into phrases of ~4 words for readable captions
                    phrase_words = []
                    phrase_start = None
                    for word_info in words:
                        w_start = round(word_info.get("start", 0), 2)
                        w_end = round(word_info.get("end", 0), 2)
                        w_text = word_info.get("word", "").strip()
                        if not w_text:
                            continue

                        if phrase_start is None:
                            phrase_start = w_start

                        phrase_words.append(w_text)

                        # Emit a caption every ~4 words or at end of segment
                        if len(phrase_words) >= 4 or word_info is words[-1]:
                            segments.append(
                                {
                                    "id": len(segments),
                                    "start": phrase_start,
                                    "end": w_end,
                                    "text": " ".join(phrase_words),
                                }
                            )
                            phrase_words = []
                            phrase_start = None
                else:
                    # Fallback: no word timestamps available
                    segments.append(
                        {
                            "id": segment.get("id"),
                            "start": round(segment.get("start", 0), 2),
                            "end": round(segment.get("end", 0), 2),
                            "text": segment.get("text", "").strip(),
                        }
                    )

            # Calculate duration from last segment
            duration = 0.0
            if segments:
                duration = segments[-1]["end"]

            response = {
                "text": result.get("text", "").strip(),
                "segments": segments,
                "language": result.get("language", "unknown"),
                "duration": round(duration, 2),
            }

            logger.info(
                f"Transcription complete. Language: {response['language']}, "
                f"Duration: {response['duration']}s, Segments: {len(segments)}"
            )

            return response

        except Exception as e:
            logger.error(f"Transcription failed: {e}")
            raise TranscriptionError(f"Transcription failed: {e}") from e

    def transcribe_url(
        self,
        url: str,
        language: Optional[str] = None,
        task: str = "transcribe",
        verbose: bool = False,
    ) -> dict[str, Any]:
        """
        Transcribe audio from a URL.

        Args:
            url: URL of the audio file
            language: Language code (e.g., 'en', 'es', 'fr'). Auto-detected if None.
            task: 'transcribe' or 'translate' (translate to English)
            verbose: Whether to print progress

        Returns:
            Dictionary containing:
            - text: Full transcription text
            - segments: List of segments with start, end, and text
            - language: Detected language code
            - duration: Audio duration in seconds
        """
        temp_file_path = None

        try:
            # Download the audio file
            temp_file_path = self._download_audio(url)

            # Transcribe the downloaded file
            result = self.transcribe(
                audio_path=temp_file_path,
                language=language,
                task=task,
                verbose=verbose,
            )

            return result

        finally:
            # Clean up temporary file
            if temp_file_path and os.path.exists(temp_file_path):
                try:
                    os.unlink(temp_file_path)
                    logger.debug(f"Cleaned up temporary file: {temp_file_path}")
                except Exception as e:
                    logger.warning(f"Failed to clean up temporary file: {e}")

    @staticmethod
    def get_available_models() -> list[dict[str, Any]]:
        """
        Get information about available Whisper models.

        Returns:
            List of dictionaries with model information
        """
        return list(WHISPER_MODELS.values())

    @staticmethod
    def get_supported_formats() -> list[str]:
        """
        Get list of supported audio formats.

        Returns:
            List of supported file extensions
        """
        return sorted(list(SUPPORTED_FORMATS))

    def change_model(self, model_name: str) -> None:
        """
        Change the Whisper model.

        Args:
            model_name: New model to load
        """
        if model_name not in WHISPER_MODELS:
            raise TranscriptionError(
                f"Unknown model: {model_name}. "
                f"Available models: {list(WHISPER_MODELS.keys())}"
            )

        if model_name != self._model_name:
            self._requested_model = model_name
            self._model = None  # Force reload on next transcription
            self._model_name = None
            logger.info(f"Model changed to: {model_name} (will load on next use)")

    def unload_model(self) -> None:
        """Unload the current model from memory."""
        if self._model is not None:
            self._model = None
            self._model_name = None
            logger.info("Model unloaded from memory")

    @property
    def current_model(self) -> Optional[str]:
        """Get the name of the currently loaded model."""
        return self._model_name

    @property
    def is_model_loaded(self) -> bool:
        """Check if a model is currently loaded."""
        return self._model is not None

    @staticmethod
    def is_whisper_available() -> bool:
        """Check if whisper is available for import."""
        try:
            import whisper  # noqa: F401

            return True
        except ImportError:
            return False
