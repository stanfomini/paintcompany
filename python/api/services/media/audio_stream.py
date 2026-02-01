"""Real-time audio streaming service for walkie-talkie functionality."""

import asyncio
import tempfile
import uuid
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from pathlib import Path
from typing import Any, Optional

from loguru import logger

# Type alias for WebSocket-like objects
WebSocketConnection = Any


class AudioStreamError(Exception):
    """Custom exception for audio streaming errors."""

    pass


class RoomStatus(Enum):
    """Status of an audio room."""

    ACTIVE = "active"
    CLOSED = "closed"


@dataclass
class Participant:
    """Represents a participant in an audio room."""

    user_id: str
    websocket: WebSocketConnection
    display_name: str = ""
    joined_at: datetime = field(default_factory=datetime.utcnow)
    is_speaking: bool = False
    is_muted: bool = False

    def __hash__(self) -> int:
        """Hash by user_id for set operations."""
        return hash(self.user_id)

    def __eq__(self, other: object) -> bool:
        """Equality by user_id."""
        if not isinstance(other, Participant):
            return False
        return self.user_id == other.user_id


@dataclass
class AudioMessage:
    """Represents an audio message being transmitted."""

    message_id: str
    sender_id: str
    sender_name: str
    chunks: list[bytes] = field(default_factory=list)
    started_at: datetime = field(default_factory=datetime.utcnow)
    completed: bool = False
    transcription: Optional[str] = None


class AudioRoom:
    """
    Manages a single audio room with participants.

    Features:
    - Track connected WebSocket clients
    - Buffer audio chunks during push-to-talk
    - Broadcast audio to all participants
    - Optional transcription of completed messages
    """

    def __init__(
        self,
        room_id: str,
        name: str = "",
        max_participants: int = 50,
        enable_transcription: bool = False,
        transcription_model: str = "base",
    ):
        """
        Initialize an audio room.

        Args:
            room_id: Unique identifier for the room
            name: Human-readable room name
            max_participants: Maximum number of participants allowed
            enable_transcription: Whether to transcribe completed audio messages
            transcription_model: Whisper model to use for transcription
        """
        self.room_id = room_id
        self.name = name or f"Room-{room_id[:8]}"
        self.max_participants = max_participants
        self.enable_transcription = enable_transcription
        self.transcription_model = transcription_model

        self._participants: dict[str, Participant] = {}
        self._active_messages: dict[str, AudioMessage] = {}
        self._message_history: list[AudioMessage] = []
        self._status = RoomStatus.ACTIVE
        self._created_at = datetime.utcnow()
        self._lock = asyncio.Lock()

        # Transcription service (lazy loaded)
        self._transcription_service = None

        logger.info(
            f"AudioRoom created: {self.room_id} (name={self.name}, "
            f"max_participants={max_participants}, transcription={enable_transcription})"
        )

    @property
    def status(self) -> RoomStatus:
        """Get the current room status."""
        return self._status

    @property
    def participant_count(self) -> int:
        """Get the number of participants in the room."""
        return len(self._participants)

    @property
    def is_full(self) -> bool:
        """Check if the room is at capacity."""
        return self.participant_count >= self.max_participants

    def _get_transcription_service(self):
        """Lazy load the transcription service."""
        if self._transcription_service is None and self.enable_transcription:
            try:
                from api.services.media.transcription import TranscriptionService

                self._transcription_service = TranscriptionService(
                    model_name=self.transcription_model
                )
                logger.info(
                    f"Transcription service loaded for room {self.room_id} "
                    f"with model {self.transcription_model}"
                )
            except Exception as e:
                logger.warning(f"Failed to load transcription service: {e}")
                self.enable_transcription = False
        return self._transcription_service

    async def add_participant(
        self,
        user_id: str,
        websocket: WebSocketConnection,
        display_name: str = "",
    ) -> Participant:
        """
        Add a participant to the room.

        Args:
            user_id: Unique identifier for the user
            websocket: WebSocket connection for the user
            display_name: Display name for the user

        Returns:
            The created Participant object

        Raises:
            AudioStreamError: If room is full or closed
        """
        async with self._lock:
            if self._status == RoomStatus.CLOSED:
                raise AudioStreamError(f"Room {self.room_id} is closed")

            if self.is_full:
                raise AudioStreamError(
                    f"Room {self.room_id} is full ({self.max_participants} participants)"
                )

            # Remove existing connection for same user if present
            if user_id in self._participants:
                logger.warning(
                    f"User {user_id} already in room {self.room_id}, replacing connection"
                )
                del self._participants[user_id]

            participant = Participant(
                user_id=user_id,
                websocket=websocket,
                display_name=display_name or f"User-{user_id[:8]}",
            )
            self._participants[user_id] = participant

            logger.info(
                f"Participant {participant.display_name} ({user_id}) "
                f"joined room {self.room_id}"
            )

            # Notify other participants
            await self._broadcast_system_message(
                {
                    "type": "participant_joined",
                    "user_id": user_id,
                    "display_name": participant.display_name,
                    "participant_count": self.participant_count,
                },
                exclude_user=user_id,
            )

            return participant

    async def remove_participant(self, user_id: str) -> bool:
        """
        Remove a participant from the room.

        Args:
            user_id: User ID to remove

        Returns:
            True if participant was removed, False if not found
        """
        async with self._lock:
            if user_id not in self._participants:
                return False

            participant = self._participants[user_id]
            del self._participants[user_id]

            # Cancel any active message from this user
            if user_id in self._active_messages:
                del self._active_messages[user_id]

            logger.info(
                f"Participant {participant.display_name} ({user_id}) "
                f"left room {self.room_id}"
            )

            # Notify other participants
            await self._broadcast_system_message(
                {
                    "type": "participant_left",
                    "user_id": user_id,
                    "display_name": participant.display_name,
                    "participant_count": self.participant_count,
                }
            )

            return True

    def get_participant(self, user_id: str) -> Optional[Participant]:
        """Get a participant by user ID."""
        return self._participants.get(user_id)

    def get_participants(self) -> list[dict[str, Any]]:
        """
        Get list of all participants with their status.

        Returns:
            List of participant information dictionaries
        """
        return [
            {
                "user_id": p.user_id,
                "display_name": p.display_name,
                "joined_at": p.joined_at.isoformat(),
                "is_speaking": p.is_speaking,
                "is_muted": p.is_muted,
            }
            for p in self._participants.values()
        ]

    async def start_audio_message(self, sender_id: str) -> str:
        """
        Start a new push-to-talk audio message.

        Args:
            sender_id: User ID of the sender

        Returns:
            Message ID for the new audio message

        Raises:
            AudioStreamError: If sender not in room or already transmitting
        """
        if sender_id not in self._participants:
            raise AudioStreamError(f"User {sender_id} is not in room {self.room_id}")

        if sender_id in self._active_messages:
            raise AudioStreamError(f"User {sender_id} is already transmitting")

        participant = self._participants[sender_id]
        if participant.is_muted:
            raise AudioStreamError(f"User {sender_id} is muted")

        message_id = str(uuid.uuid4())
        message = AudioMessage(
            message_id=message_id,
            sender_id=sender_id,
            sender_name=participant.display_name,
        )
        self._active_messages[sender_id] = message
        participant.is_speaking = True

        logger.debug(
            f"Audio message started: {message_id} from {participant.display_name}"
        )

        # Notify participants that user started speaking
        await self._broadcast_system_message(
            {
                "type": "speaking_started",
                "user_id": sender_id,
                "display_name": participant.display_name,
                "message_id": message_id,
            }
        )

        return message_id

    async def add_audio_chunk(
        self,
        sender_id: str,
        chunk: bytes,
        broadcast: bool = True,
    ) -> None:
        """
        Add an audio chunk to an active message and optionally broadcast.

        Args:
            sender_id: User ID of the sender
            chunk: Audio data chunk (raw bytes)
            broadcast: Whether to immediately broadcast to other participants

        Raises:
            AudioStreamError: If no active message from sender
        """
        if sender_id not in self._active_messages:
            raise AudioStreamError(f"No active audio message from user {sender_id}")

        message = self._active_messages[sender_id]
        message.chunks.append(chunk)

        if broadcast:
            await self._broadcast_audio_chunk(sender_id, chunk, message.message_id)

    async def end_audio_message(
        self,
        sender_id: str,
        transcribe: bool = True,
    ) -> Optional[AudioMessage]:
        """
        End a push-to-talk audio message.

        Args:
            sender_id: User ID of the sender
            transcribe: Whether to transcribe the completed message

        Returns:
            The completed AudioMessage, or None if no active message

        Raises:
            AudioStreamError: If no active message from sender
        """
        if sender_id not in self._active_messages:
            logger.warning(f"No active audio message from user {sender_id} to end")
            return None

        message = self._active_messages.pop(sender_id)
        message.completed = True

        if sender_id in self._participants:
            self._participants[sender_id].is_speaking = False

        logger.debug(
            f"Audio message ended: {message.message_id} "
            f"({len(message.chunks)} chunks)"
        )

        # Notify participants that user stopped speaking
        await self._broadcast_system_message(
            {
                "type": "speaking_ended",
                "user_id": sender_id,
                "display_name": message.sender_name,
                "message_id": message.message_id,
            }
        )

        # Optionally transcribe the message
        if transcribe and self.enable_transcription and message.chunks:
            asyncio.create_task(self._transcribe_message(message))

        # Add to history (limit to last 100 messages)
        self._message_history.append(message)
        if len(self._message_history) > 100:
            self._message_history.pop(0)

        return message

    async def broadcast_audio(
        self,
        sender_id: str,
        audio_data: bytes,
    ) -> None:
        """
        Broadcast a complete audio message to all participants.

        This is for simple broadcasting without push-to-talk flow.

        Args:
            sender_id: User ID of the sender
            audio_data: Complete audio data to broadcast
        """
        if sender_id not in self._participants:
            raise AudioStreamError(f"User {sender_id} is not in room {self.room_id}")

        participant = self._participants[sender_id]
        message_id = str(uuid.uuid4())

        await self._broadcast_audio_chunk(
            sender_id, audio_data, message_id, is_complete=True
        )

        logger.debug(
            f"Broadcast complete audio from {participant.display_name}: "
            f"{len(audio_data)} bytes"
        )

    async def _broadcast_audio_chunk(
        self,
        sender_id: str,
        chunk: bytes,
        message_id: str,
        is_complete: bool = False,
    ) -> None:
        """
        Broadcast an audio chunk to all participants except the sender.

        Args:
            sender_id: User ID of the sender (will be excluded)
            chunk: Audio data chunk
            message_id: ID of the audio message
            is_complete: Whether this is a complete message
        """
        sender = self._participants.get(sender_id)
        sender_name = sender.display_name if sender else "Unknown"

        # Prepare message metadata
        metadata = {
            "type": "audio_chunk",
            "sender_id": sender_id,
            "sender_name": sender_name,
            "message_id": message_id,
            "is_complete": is_complete,
            "chunk_size": len(chunk),
        }

        # Send to all participants except sender
        failed_participants = []
        for user_id, participant in self._participants.items():
            if user_id == sender_id:
                continue

            try:
                # Send metadata followed by binary audio data
                # The WebSocket handler should handle this appropriately
                await participant.websocket.send_json(metadata)
                await participant.websocket.send_bytes(chunk)
            except Exception as e:
                logger.warning(
                    f"Failed to send audio to {participant.display_name}: {e}"
                )
                failed_participants.append(user_id)

        # Remove failed participants
        for user_id in failed_participants:
            await self.remove_participant(user_id)

    async def _broadcast_system_message(
        self,
        message: dict[str, Any],
        exclude_user: Optional[str] = None,
    ) -> None:
        """
        Broadcast a system message to all participants.

        Args:
            message: Message dictionary to send
            exclude_user: Optional user ID to exclude from broadcast
        """
        failed_participants = []
        for user_id, participant in self._participants.items():
            if user_id == exclude_user:
                continue

            try:
                await participant.websocket.send_json(message)
            except Exception as e:
                logger.warning(
                    f"Failed to send system message to {participant.display_name}: {e}"
                )
                failed_participants.append(user_id)

        # Remove failed participants
        for user_id in failed_participants:
            await self.remove_participant(user_id)

    async def _transcribe_message(self, message: AudioMessage) -> None:
        """
        Transcribe a completed audio message and broadcast the result.

        Args:
            message: The completed AudioMessage to transcribe
        """
        service = self._get_transcription_service()
        if not service:
            return

        temp_file_path = None
        try:
            # Combine all chunks into a single audio file
            audio_data = b"".join(message.chunks)

            # Write to temporary file for transcription
            temp_file = tempfile.NamedTemporaryFile(
                suffix=".wav", delete=False, prefix="walkie_"
            )
            temp_file.write(audio_data)
            temp_file.close()
            temp_file_path = temp_file.name

            # Transcribe
            result = service.transcribe(temp_file_path)
            message.transcription = result.get("text", "")

            text_preview = (
                f"'{message.transcription[:50]}...'"
                if len(message.transcription) > 50
                else f"'{message.transcription}'"
            )
            logger.info(f"Transcribed message {message.message_id}: {text_preview}")

            # Broadcast transcription to all participants
            await self._broadcast_system_message(
                {
                    "type": "transcription",
                    "message_id": message.message_id,
                    "sender_id": message.sender_id,
                    "sender_name": message.sender_name,
                    "text": message.transcription,
                }
            )

        except Exception as e:
            logger.error(f"Failed to transcribe message {message.message_id}: {e}")
        finally:
            # Clean up temp file
            if temp_file_path:
                try:
                    Path(temp_file_path).unlink(missing_ok=True)
                except Exception as e:
                    logger.warning(f"Failed to clean up temp file: {e}")

    async def set_muted(self, user_id: str, muted: bool) -> None:
        """
        Set the muted status of a participant.

        Args:
            user_id: User ID to mute/unmute
            muted: Whether the user should be muted
        """
        if user_id not in self._participants:
            raise AudioStreamError(f"User {user_id} is not in room {self.room_id}")

        self._participants[user_id].is_muted = muted

        await self._broadcast_system_message(
            {
                "type": "mute_changed",
                "user_id": user_id,
                "is_muted": muted,
            }
        )

    async def close(self) -> None:
        """Close the room and disconnect all participants."""
        async with self._lock:
            self._status = RoomStatus.CLOSED

            # Notify all participants
            await self._broadcast_system_message(
                {
                    "type": "room_closed",
                    "room_id": self.room_id,
                }
            )

            # Clear all participants
            self._participants.clear()
            self._active_messages.clear()

            logger.info(f"AudioRoom closed: {self.room_id}")

    def get_room_info(self) -> dict[str, Any]:
        """
        Get information about the room.

        Returns:
            Dictionary with room information
        """
        return {
            "room_id": self.room_id,
            "name": self.name,
            "status": self._status.value,
            "participant_count": self.participant_count,
            "max_participants": self.max_participants,
            "enable_transcription": self.enable_transcription,
            "created_at": self._created_at.isoformat(),
            "participants": self.get_participants(),
        }


class AudioStreamManager:
    """
    Singleton manager for all audio rooms.

    Provides methods to create, join, leave, and manage audio rooms
    for walkie-talkie functionality.
    """

    _instance: Optional["AudioStreamManager"] = None
    _lock: asyncio.Lock = asyncio.Lock()

    def __new__(cls) -> "AudioStreamManager":
        """Singleton pattern to ensure single manager instance."""
        if cls._instance is None:
            cls._instance = super().__new__(cls)
            cls._instance._initialized = False
        return cls._instance

    def __init__(self):
        """Initialize the audio stream manager."""
        if self._initialized:
            return

        self._rooms: dict[str, AudioRoom] = {}
        self._user_room_map: dict[str, str] = {}  # user_id -> room_id
        self._room_cleanup_tasks: dict[str, asyncio.Task] = {}
        self._initialized = True

        logger.info("AudioStreamManager initialized")

    @classmethod
    async def get_instance(cls) -> "AudioStreamManager":
        """Get the singleton instance (async-safe)."""
        async with cls._lock:
            return cls()

    async def create_room(
        self,
        room_id: Optional[str] = None,
        name: str = "",
        max_participants: int = 50,
        enable_transcription: bool = False,
        transcription_model: str = "base",
        auto_close_empty: bool = True,
        auto_close_delay: int = 300,
    ) -> AudioRoom:
        """
        Create a new audio room.

        Args:
            room_id: Optional room ID (generated if not provided)
            name: Human-readable room name
            max_participants: Maximum number of participants
            enable_transcription: Whether to enable transcription
            transcription_model: Whisper model for transcription
            auto_close_empty: Whether to auto-close when empty
            auto_close_delay: Seconds to wait before auto-closing

        Returns:
            The created AudioRoom

        Raises:
            AudioStreamError: If room ID already exists
        """
        if room_id is None:
            room_id = str(uuid.uuid4())

        if room_id in self._rooms:
            raise AudioStreamError(f"Room {room_id} already exists")

        room = AudioRoom(
            room_id=room_id,
            name=name,
            max_participants=max_participants,
            enable_transcription=enable_transcription,
            transcription_model=transcription_model,
        )
        self._rooms[room_id] = room

        if auto_close_empty:
            self._schedule_room_cleanup(room_id, auto_close_delay)

        logger.info(f"Room created: {room_id} (name={name})")
        return room

    async def get_room(self, room_id: str) -> Optional[AudioRoom]:
        """
        Get a room by ID.

        Args:
            room_id: Room ID to look up

        Returns:
            The AudioRoom or None if not found
        """
        return self._rooms.get(room_id)

    async def get_or_create_room(
        self,
        room_id: str,
        **kwargs,
    ) -> AudioRoom:
        """
        Get an existing room or create it if it doesn't exist.

        Args:
            room_id: Room ID
            **kwargs: Arguments to pass to create_room if creating

        Returns:
            The AudioRoom
        """
        if room_id in self._rooms:
            return self._rooms[room_id]
        return await self.create_room(room_id=room_id, **kwargs)

    async def join_room(
        self,
        room_id: str,
        user_id: str,
        websocket: WebSocketConnection,
        display_name: str = "",
    ) -> Participant:
        """
        Join a user to a room.

        Args:
            room_id: Room to join
            user_id: User ID
            websocket: WebSocket connection
            display_name: Display name

        Returns:
            The created Participant

        Raises:
            AudioStreamError: If room not found
        """
        room = self._rooms.get(room_id)
        if not room:
            raise AudioStreamError(f"Room {room_id} not found")

        # Leave any existing room first
        if user_id in self._user_room_map:
            await self.leave_room(user_id)

        participant = await room.add_participant(user_id, websocket, display_name)
        self._user_room_map[user_id] = room_id

        # Cancel any pending cleanup for this room
        if room_id in self._room_cleanup_tasks:
            self._room_cleanup_tasks[room_id].cancel()
            del self._room_cleanup_tasks[room_id]

        return participant

    async def leave_room(self, user_id: str) -> bool:
        """
        Remove a user from their current room.

        Args:
            user_id: User ID to remove

        Returns:
            True if user was removed, False if not in any room
        """
        room_id = self._user_room_map.get(user_id)
        if not room_id:
            return False

        room = self._rooms.get(room_id)
        if room:
            await room.remove_participant(user_id)

            # Schedule cleanup if room is empty
            if room.participant_count == 0:
                self._schedule_room_cleanup(room_id, 300)

        del self._user_room_map[user_id]
        return True

    async def broadcast_audio(
        self,
        user_id: str,
        audio_data: bytes,
    ) -> None:
        """
        Broadcast audio from a user to their current room.

        Args:
            user_id: User ID of the sender
            audio_data: Audio data to broadcast

        Raises:
            AudioStreamError: If user not in any room
        """
        room_id = self._user_room_map.get(user_id)
        if not room_id:
            raise AudioStreamError(f"User {user_id} is not in any room")

        room = self._rooms.get(room_id)
        if not room:
            raise AudioStreamError(f"Room {room_id} not found")

        await room.broadcast_audio(user_id, audio_data)

    async def start_transmission(self, user_id: str) -> str:
        """
        Start a push-to-talk transmission for a user.

        Args:
            user_id: User ID starting transmission

        Returns:
            Message ID for the transmission

        Raises:
            AudioStreamError: If user not in any room
        """
        room_id = self._user_room_map.get(user_id)
        if not room_id:
            raise AudioStreamError(f"User {user_id} is not in any room")

        room = self._rooms.get(room_id)
        if not room:
            raise AudioStreamError(f"Room {room_id} not found")

        return await room.start_audio_message(user_id)

    async def send_audio_chunk(
        self,
        user_id: str,
        chunk: bytes,
    ) -> None:
        """
        Send an audio chunk during a push-to-talk transmission.

        Args:
            user_id: User ID sending the chunk
            chunk: Audio data chunk

        Raises:
            AudioStreamError: If user not in any room or no active transmission
        """
        room_id = self._user_room_map.get(user_id)
        if not room_id:
            raise AudioStreamError(f"User {user_id} is not in any room")

        room = self._rooms.get(room_id)
        if not room:
            raise AudioStreamError(f"Room {room_id} not found")

        await room.add_audio_chunk(user_id, chunk)

    async def end_transmission(self, user_id: str) -> Optional[AudioMessage]:
        """
        End a push-to-talk transmission for a user.

        Args:
            user_id: User ID ending transmission

        Returns:
            The completed AudioMessage

        Raises:
            AudioStreamError: If user not in any room
        """
        room_id = self._user_room_map.get(user_id)
        if not room_id:
            raise AudioStreamError(f"User {user_id} is not in any room")

        room = self._rooms.get(room_id)
        if not room:
            raise AudioStreamError(f"Room {room_id} not found")

        return await room.end_audio_message(user_id)

    def get_room_participants(self, room_id: str) -> list[dict[str, Any]]:
        """
        Get participants in a room.

        Args:
            room_id: Room ID

        Returns:
            List of participant information dictionaries

        Raises:
            AudioStreamError: If room not found
        """
        room = self._rooms.get(room_id)
        if not room:
            raise AudioStreamError(f"Room {room_id} not found")

        return room.get_participants()

    def get_user_room(self, user_id: str) -> Optional[str]:
        """
        Get the room ID a user is currently in.

        Args:
            user_id: User ID

        Returns:
            Room ID or None if not in any room
        """
        return self._user_room_map.get(user_id)

    def list_rooms(self) -> list[dict[str, Any]]:
        """
        List all active rooms.

        Returns:
            List of room information dictionaries
        """
        return [room.get_room_info() for room in self._rooms.values()]

    async def close_room(self, room_id: str) -> bool:
        """
        Close a room and remove all participants.

        Args:
            room_id: Room ID to close

        Returns:
            True if room was closed, False if not found
        """
        room = self._rooms.get(room_id)
        if not room:
            return False

        # Remove user mappings for all participants in the room
        users_to_remove = [
            user_id
            for user_id, r_id in self._user_room_map.items()
            if r_id == room_id
        ]
        for user_id in users_to_remove:
            del self._user_room_map[user_id]

        # Close the room
        await room.close()

        # Remove from rooms dict
        del self._rooms[room_id]

        # Cancel any cleanup task
        if room_id in self._room_cleanup_tasks:
            self._room_cleanup_tasks[room_id].cancel()
            del self._room_cleanup_tasks[room_id]

        logger.info(f"Room {room_id} closed and removed")
        return True

    def _schedule_room_cleanup(self, room_id: str, delay: int) -> None:
        """Schedule a room for cleanup after a delay if it remains empty."""
        if room_id in self._room_cleanup_tasks:
            self._room_cleanup_tasks[room_id].cancel()

        async def cleanup():
            await asyncio.sleep(delay)
            room = self._rooms.get(room_id)
            if room and room.participant_count == 0:
                await self.close_room(room_id)
                logger.info(f"Room {room_id} auto-closed due to inactivity")

        self._room_cleanup_tasks[room_id] = asyncio.create_task(cleanup())

    async def shutdown(self) -> None:
        """Shutdown the manager and close all rooms."""
        logger.info("Shutting down AudioStreamManager...")

        # Cancel all cleanup tasks
        for task in self._room_cleanup_tasks.values():
            task.cancel()
        self._room_cleanup_tasks.clear()

        # Close all rooms
        for room_id in list(self._rooms.keys()):
            await self.close_room(room_id)

        self._user_room_map.clear()
        logger.info("AudioStreamManager shutdown complete")


# Convenience function to get the manager instance
async def get_audio_stream_manager() -> AudioStreamManager:
    """Get the AudioStreamManager singleton instance."""
    return await AudioStreamManager.get_instance()
