"""WebRTC signaling service for video call management."""

import asyncio
import uuid
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from typing import Any, Optional

from loguru import logger

# Type alias for WebSocket-like objects
WebSocketConnection = Any


class WebRTCSignalingError(Exception):
    """Custom exception for WebRTC signaling errors."""

    pass


class PeerConnectionState(Enum):
    """State of a peer's WebRTC connection."""

    NEW = "new"
    CONNECTING = "connecting"
    CONNECTED = "connected"
    DISCONNECTED = "disconnected"
    FAILED = "failed"
    CLOSED = "closed"


class RoomStatus(Enum):
    """Status of a video room."""

    ACTIVE = "active"
    CLOSED = "closed"


@dataclass
class Peer:
    """Represents a peer in a video room."""

    peer_id: str
    user_id: str
    websocket: WebSocketConnection
    display_name: str = ""
    joined_at: datetime = field(default_factory=datetime.utcnow)
    connection_state: PeerConnectionState = PeerConnectionState.NEW
    is_audio_enabled: bool = True
    is_video_enabled: bool = True

    def __hash__(self) -> int:
        """Hash by peer_id for set operations."""
        return hash(self.peer_id)

    def __eq__(self, other: object) -> bool:
        """Equality by peer_id."""
        if not isinstance(other, Peer):
            return False
        return self.peer_id == other.peer_id


@dataclass
class SignalingMessage:
    """Represents a WebRTC signaling message."""

    message_id: str
    message_type: str  # offer, answer, ice_candidate
    from_peer_id: str
    to_peer_id: str
    payload: dict[str, Any]
    created_at: datetime = field(default_factory=datetime.utcnow)


class VideoRoom:
    """
    Manages a single video call room with participants.

    Features:
    - Track connected WebSocket clients (peers)
    - Handle WebRTC signaling (offers, answers, ICE candidates)
    - Support for 1:1 and group video calls
    - Track peer connection states
    - Broadcast signaling messages to appropriate peers
    """

    def __init__(
        self,
        room_id: str,
        name: str = "",
        max_participants: int = 10,
        is_private: bool = False,
    ):
        """
        Initialize a video room.

        Args:
            room_id: Unique identifier for the room
            name: Human-readable room name
            max_participants: Maximum number of participants allowed
            is_private: Whether the room requires invitation to join
        """
        self.room_id = room_id
        self.name = name or f"VideoRoom-{room_id[:8]}"
        self.max_participants = max_participants
        self.is_private = is_private

        self._peers: dict[str, Peer] = {}
        self._status = RoomStatus.ACTIVE
        self._created_at = datetime.utcnow()
        self._lock = asyncio.Lock()

        # Track signaling messages for debugging/logging
        self._signaling_history: list[SignalingMessage] = []

        logger.info(
            f"VideoRoom created: {self.room_id} (name={self.name}, "
            f"max_participants={max_participants}, is_private={is_private})"
        )

    @property
    def status(self) -> RoomStatus:
        """Get the current room status."""
        return self._status

    @property
    def participant_count(self) -> int:
        """Get the number of participants in the room."""
        return len(self._peers)

    @property
    def is_full(self) -> bool:
        """Check if the room is at capacity."""
        return self.participant_count >= self.max_participants

    @property
    def is_empty(self) -> bool:
        """Check if the room has no participants."""
        return self.participant_count == 0

    async def add_peer(
        self,
        user_id: str,
        websocket: WebSocketConnection,
        display_name: str = "",
    ) -> Peer:
        """
        Add a peer to the room.

        Args:
            user_id: Unique identifier for the user
            websocket: WebSocket connection for the user
            display_name: Display name for the user

        Returns:
            The created Peer object

        Raises:
            WebRTCSignalingError: If room is full or closed
        """
        async with self._lock:
            if self._status == RoomStatus.CLOSED:
                raise WebRTCSignalingError(f"Room {self.room_id} is closed")

            if self.is_full:
                raise WebRTCSignalingError(
                    f"Room {self.room_id} is full ({self.max_participants} participants)"
                )

            # Generate a unique peer ID
            peer_id = str(uuid.uuid4())

            # Check if user already has a connection, remove old one
            existing_peer_id = None
            for pid, peer in self._peers.items():
                if peer.user_id == user_id:
                    existing_peer_id = pid
                    break

            if existing_peer_id:
                logger.warning(
                    f"User {user_id} already in room {self.room_id}, replacing connection"
                )
                await self._remove_peer_internal(existing_peer_id)

            peer = Peer(
                peer_id=peer_id,
                user_id=user_id,
                websocket=websocket,
                display_name=display_name or f"User-{user_id[:8]}",
            )
            self._peers[peer_id] = peer

            logger.info(
                f"Peer {peer.display_name} ({peer_id}) joined room {self.room_id}"
            )

            # Notify other peers about the new participant
            await self._broadcast_system_message(
                {
                    "type": "peer_joined",
                    "peer_id": peer_id,
                    "user_id": user_id,
                    "display_name": peer.display_name,
                    "participant_count": self.participant_count,
                },
                exclude_peer=peer_id,
            )

            # Send existing peers list to the new participant
            existing_peers = [
                {
                    "peer_id": p.peer_id,
                    "user_id": p.user_id,
                    "display_name": p.display_name,
                    "is_audio_enabled": p.is_audio_enabled,
                    "is_video_enabled": p.is_video_enabled,
                }
                for p in self._peers.values()
                if p.peer_id != peer_id
            ]

            await self._send_to_peer(
                peer_id,
                {
                    "type": "room_joined",
                    "room_id": self.room_id,
                    "room_name": self.name,
                    "peer_id": peer_id,
                    "existing_peers": existing_peers,
                },
            )

            return peer

    async def _remove_peer_internal(self, peer_id: str) -> None:
        """Internal method to remove a peer without lock."""
        if peer_id in self._peers:
            del self._peers[peer_id]

    async def remove_peer(self, peer_id: str) -> bool:
        """
        Remove a peer from the room.

        Args:
            peer_id: Peer ID to remove

        Returns:
            True if peer was removed, False if not found
        """
        async with self._lock:
            if peer_id not in self._peers:
                return False

            peer = self._peers[peer_id]
            del self._peers[peer_id]

            logger.info(
                f"Peer {peer.display_name} ({peer_id}) left room {self.room_id}"
            )

            # Notify other peers
            await self._broadcast_system_message(
                {
                    "type": "peer_left",
                    "peer_id": peer_id,
                    "user_id": peer.user_id,
                    "display_name": peer.display_name,
                    "participant_count": self.participant_count,
                }
            )

            return True

    def get_peer(self, peer_id: str) -> Optional[Peer]:
        """Get a peer by peer ID."""
        return self._peers.get(peer_id)

    def get_peer_by_user_id(self, user_id: str) -> Optional[Peer]:
        """Get a peer by user ID."""
        for peer in self._peers.values():
            if peer.user_id == user_id:
                return peer
        return None

    def get_peers(self) -> list[dict[str, Any]]:
        """
        Get list of all peers with their status.

        Returns:
            List of peer information dictionaries
        """
        return [
            {
                "peer_id": p.peer_id,
                "user_id": p.user_id,
                "display_name": p.display_name,
                "joined_at": p.joined_at.isoformat(),
                "connection_state": p.connection_state.value,
                "is_audio_enabled": p.is_audio_enabled,
                "is_video_enabled": p.is_video_enabled,
            }
            for p in self._peers.values()
        ]

    async def handle_offer(
        self,
        from_peer_id: str,
        to_peer_id: str,
        sdp: str,
    ) -> None:
        """
        Handle a WebRTC offer from one peer to another.

        Args:
            from_peer_id: Peer ID sending the offer
            to_peer_id: Peer ID receiving the offer
            sdp: Session Description Protocol string

        Raises:
            WebRTCSignalingError: If peers not found or invalid
        """
        if from_peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {from_peer_id} not found in room")
        if to_peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {to_peer_id} not found in room")

        from_peer = self._peers[from_peer_id]

        # Update connection state
        from_peer.connection_state = PeerConnectionState.CONNECTING

        message = SignalingMessage(
            message_id=str(uuid.uuid4()),
            message_type="offer",
            from_peer_id=from_peer_id,
            to_peer_id=to_peer_id,
            payload={"sdp": sdp},
        )
        self._signaling_history.append(message)

        logger.debug(
            f"Offer from {from_peer.display_name} to peer {to_peer_id} in room {self.room_id}"
        )

        # Send offer to target peer
        await self._send_to_peer(
            to_peer_id,
            {
                "type": "offer",
                "from_peer_id": from_peer_id,
                "from_display_name": from_peer.display_name,
                "sdp": sdp,
            },
        )

    async def handle_answer(
        self,
        from_peer_id: str,
        to_peer_id: str,
        sdp: str,
    ) -> None:
        """
        Handle a WebRTC answer from one peer to another.

        Args:
            from_peer_id: Peer ID sending the answer
            to_peer_id: Peer ID receiving the answer
            sdp: Session Description Protocol string

        Raises:
            WebRTCSignalingError: If peers not found or invalid
        """
        if from_peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {from_peer_id} not found in room")
        if to_peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {to_peer_id} not found in room")

        from_peer = self._peers[from_peer_id]

        message = SignalingMessage(
            message_id=str(uuid.uuid4()),
            message_type="answer",
            from_peer_id=from_peer_id,
            to_peer_id=to_peer_id,
            payload={"sdp": sdp},
        )
        self._signaling_history.append(message)

        logger.debug(
            f"Answer from {from_peer.display_name} to peer {to_peer_id} in room {self.room_id}"
        )

        # Send answer to target peer
        await self._send_to_peer(
            to_peer_id,
            {
                "type": "answer",
                "from_peer_id": from_peer_id,
                "from_display_name": from_peer.display_name,
                "sdp": sdp,
            },
        )

    async def handle_ice_candidate(
        self,
        from_peer_id: str,
        to_peer_id: str,
        candidate: dict[str, Any],
    ) -> None:
        """
        Handle an ICE candidate from one peer to another.

        Args:
            from_peer_id: Peer ID sending the candidate
            to_peer_id: Peer ID receiving the candidate
            candidate: ICE candidate dictionary containing candidate, sdpMid, sdpMLineIndex

        Raises:
            WebRTCSignalingError: If peers not found or invalid
        """
        if from_peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {from_peer_id} not found in room")
        if to_peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {to_peer_id} not found in room")

        from_peer = self._peers[from_peer_id]

        message = SignalingMessage(
            message_id=str(uuid.uuid4()),
            message_type="ice_candidate",
            from_peer_id=from_peer_id,
            to_peer_id=to_peer_id,
            payload={"candidate": candidate},
        )
        self._signaling_history.append(message)

        logger.debug(
            f"ICE candidate from {from_peer.display_name} to peer {to_peer_id} "
            f"in room {self.room_id}"
        )

        # Send ICE candidate to target peer
        await self._send_to_peer(
            to_peer_id,
            {
                "type": "ice_candidate",
                "from_peer_id": from_peer_id,
                "candidate": candidate,
            },
        )

    async def update_peer_state(
        self,
        peer_id: str,
        connection_state: Optional[PeerConnectionState] = None,
        is_audio_enabled: Optional[bool] = None,
        is_video_enabled: Optional[bool] = None,
    ) -> None:
        """
        Update a peer's state.

        Args:
            peer_id: Peer ID to update
            connection_state: New connection state
            is_audio_enabled: Whether audio is enabled
            is_video_enabled: Whether video is enabled

        Raises:
            WebRTCSignalingError: If peer not found
        """
        if peer_id not in self._peers:
            raise WebRTCSignalingError(f"Peer {peer_id} not found in room")

        peer = self._peers[peer_id]
        updates: dict[str, Any] = {}

        if connection_state is not None:
            peer.connection_state = connection_state
            updates["connection_state"] = connection_state.value

        if is_audio_enabled is not None:
            peer.is_audio_enabled = is_audio_enabled
            updates["is_audio_enabled"] = is_audio_enabled

        if is_video_enabled is not None:
            peer.is_video_enabled = is_video_enabled
            updates["is_video_enabled"] = is_video_enabled

        if updates:
            # Broadcast state change to all peers
            await self._broadcast_system_message(
                {
                    "type": "peer_state_changed",
                    "peer_id": peer_id,
                    "updates": updates,
                }
            )

            logger.debug(
                f"Peer {peer.display_name} state updated in room {self.room_id}: {updates}"
            )

    async def _send_to_peer(
        self,
        peer_id: str,
        message: dict[str, Any],
    ) -> bool:
        """
        Send a message to a specific peer.

        Args:
            peer_id: Peer ID to send to
            message: Message dictionary to send

        Returns:
            True if sent successfully, False otherwise
        """
        peer = self._peers.get(peer_id)
        if not peer:
            return False

        try:
            await peer.websocket.send_json(message)
            return True
        except Exception as e:
            logger.warning(f"Failed to send message to peer {peer_id}: {e}")
            return False

    async def _broadcast_system_message(
        self,
        message: dict[str, Any],
        exclude_peer: Optional[str] = None,
    ) -> None:
        """
        Broadcast a system message to all peers.

        Args:
            message: Message dictionary to send
            exclude_peer: Optional peer ID to exclude from broadcast
        """
        failed_peers = []
        for peer_id, peer in self._peers.items():
            if peer_id == exclude_peer:
                continue

            try:
                await peer.websocket.send_json(message)
            except Exception as e:
                logger.warning(
                    f"Failed to send system message to {peer.display_name}: {e}"
                )
                failed_peers.append(peer_id)

        # Remove failed peers
        for peer_id in failed_peers:
            await self.remove_peer(peer_id)

    async def broadcast_to_all(
        self,
        message: dict[str, Any],
        exclude_peer: Optional[str] = None,
    ) -> None:
        """
        Broadcast a custom message to all peers.

        Args:
            message: Message dictionary to send
            exclude_peer: Optional peer ID to exclude from broadcast
        """
        await self._broadcast_system_message(message, exclude_peer)

    async def close(self) -> None:
        """Close the room and disconnect all peers."""
        async with self._lock:
            self._status = RoomStatus.CLOSED

            # Notify all peers
            await self._broadcast_system_message(
                {
                    "type": "room_closed",
                    "room_id": self.room_id,
                }
            )

            # Clear all peers
            self._peers.clear()
            self._signaling_history.clear()

            logger.info(f"VideoRoom closed: {self.room_id}")

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
            "is_private": self.is_private,
            "created_at": self._created_at.isoformat(),
            "peers": self.get_peers(),
        }


class WebRTCSignalingManager:
    """
    Singleton manager for all video rooms.

    Provides methods to create, join, leave, and manage video rooms
    for WebRTC-based video calls.
    """

    _instance: Optional["WebRTCSignalingManager"] = None
    _lock: asyncio.Lock = asyncio.Lock()

    def __new__(cls) -> "WebRTCSignalingManager":
        """Singleton pattern to ensure single manager instance."""
        if cls._instance is None:
            cls._instance = super().__new__(cls)
            cls._instance._initialized = False
        return cls._instance

    def __init__(self):
        """Initialize the WebRTC signaling manager."""
        if self._initialized:
            return

        self._rooms: dict[str, VideoRoom] = {}
        self._peer_room_map: dict[str, str] = {}  # peer_id -> room_id
        self._user_peer_map: dict[str, str] = {}  # user_id -> peer_id
        self._room_cleanup_tasks: dict[str, asyncio.Task] = {}
        self._initialized = True

        logger.info("WebRTCSignalingManager initialized")

    @classmethod
    async def get_instance(cls) -> "WebRTCSignalingManager":
        """Get the singleton instance (async-safe)."""
        async with cls._lock:
            return cls()

    async def create_room(
        self,
        room_id: Optional[str] = None,
        name: str = "",
        max_participants: int = 10,
        is_private: bool = False,
        auto_close_empty: bool = True,
        auto_close_delay: int = 300,
    ) -> VideoRoom:
        """
        Create a new video room.

        Args:
            room_id: Optional room ID (generated if not provided)
            name: Human-readable room name
            max_participants: Maximum number of participants
            is_private: Whether room requires invitation
            auto_close_empty: Whether to auto-close when empty
            auto_close_delay: Seconds to wait before auto-closing

        Returns:
            The created VideoRoom

        Raises:
            WebRTCSignalingError: If room ID already exists
        """
        if room_id is None:
            room_id = str(uuid.uuid4())

        if room_id in self._rooms:
            raise WebRTCSignalingError(f"Room {room_id} already exists")

        room = VideoRoom(
            room_id=room_id,
            name=name,
            max_participants=max_participants,
            is_private=is_private,
        )
        self._rooms[room_id] = room

        if auto_close_empty:
            self._schedule_room_cleanup(room_id, auto_close_delay)

        logger.info(f"Room created: {room_id} (name={name})")
        return room

    async def get_room(self, room_id: str) -> Optional[VideoRoom]:
        """
        Get a room by ID.

        Args:
            room_id: Room ID to look up

        Returns:
            The VideoRoom or None if not found
        """
        return self._rooms.get(room_id)

    async def get_or_create_room(
        self,
        room_id: str,
        **kwargs,
    ) -> VideoRoom:
        """
        Get an existing room or create it if it doesn't exist.

        Args:
            room_id: Room ID
            **kwargs: Arguments to pass to create_room if creating

        Returns:
            The VideoRoom
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
    ) -> Peer:
        """
        Join a user to a room.

        Args:
            room_id: Room to join
            user_id: User ID
            websocket: WebSocket connection
            display_name: Display name

        Returns:
            The created Peer

        Raises:
            WebRTCSignalingError: If room not found
        """
        room = self._rooms.get(room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {room_id} not found")

        # Leave any existing room first
        if user_id in self._user_peer_map:
            old_peer_id = self._user_peer_map[user_id]
            await self.leave_room(old_peer_id)

        peer = await room.add_peer(user_id, websocket, display_name)
        self._peer_room_map[peer.peer_id] = room_id
        self._user_peer_map[user_id] = peer.peer_id

        # Cancel any pending cleanup for this room
        if room_id in self._room_cleanup_tasks:
            self._room_cleanup_tasks[room_id].cancel()
            del self._room_cleanup_tasks[room_id]

        return peer

    async def leave_room(self, peer_id: str) -> bool:
        """
        Remove a peer from their current room.

        Args:
            peer_id: Peer ID to remove

        Returns:
            True if peer was removed, False if not in any room
        """
        room_id = self._peer_room_map.get(peer_id)
        if not room_id:
            return False

        room = self._rooms.get(room_id)
        if room:
            peer = room.get_peer(peer_id)
            if peer:
                # Clean up user mapping
                if peer.user_id in self._user_peer_map:
                    del self._user_peer_map[peer.user_id]

            await room.remove_peer(peer_id)

            # Schedule cleanup if room is empty
            if room.is_empty:
                self._schedule_room_cleanup(room_id, 300)

        del self._peer_room_map[peer_id]
        return True

    async def handle_offer(
        self,
        from_peer_id: str,
        to_peer_id: str,
        sdp: str,
    ) -> None:
        """
        Handle a WebRTC offer.

        Args:
            from_peer_id: Peer ID sending the offer
            to_peer_id: Peer ID receiving the offer
            sdp: Session Description Protocol string

        Raises:
            WebRTCSignalingError: If peers not in same room
        """
        from_room_id = self._peer_room_map.get(from_peer_id)
        to_room_id = self._peer_room_map.get(to_peer_id)

        if not from_room_id:
            raise WebRTCSignalingError(f"Peer {from_peer_id} not in any room")
        if from_room_id != to_room_id:
            raise WebRTCSignalingError("Peers are not in the same room")

        room = self._rooms.get(from_room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {from_room_id} not found")

        await room.handle_offer(from_peer_id, to_peer_id, sdp)

    async def handle_answer(
        self,
        from_peer_id: str,
        to_peer_id: str,
        sdp: str,
    ) -> None:
        """
        Handle a WebRTC answer.

        Args:
            from_peer_id: Peer ID sending the answer
            to_peer_id: Peer ID receiving the answer
            sdp: Session Description Protocol string

        Raises:
            WebRTCSignalingError: If peers not in same room
        """
        from_room_id = self._peer_room_map.get(from_peer_id)
        to_room_id = self._peer_room_map.get(to_peer_id)

        if not from_room_id:
            raise WebRTCSignalingError(f"Peer {from_peer_id} not in any room")
        if from_room_id != to_room_id:
            raise WebRTCSignalingError("Peers are not in the same room")

        room = self._rooms.get(from_room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {from_room_id} not found")

        await room.handle_answer(from_peer_id, to_peer_id, sdp)

    async def handle_ice_candidate(
        self,
        from_peer_id: str,
        to_peer_id: str,
        candidate: dict[str, Any],
    ) -> None:
        """
        Handle an ICE candidate.

        Args:
            from_peer_id: Peer ID sending the candidate
            to_peer_id: Peer ID receiving the candidate
            candidate: ICE candidate dictionary

        Raises:
            WebRTCSignalingError: If peers not in same room
        """
        from_room_id = self._peer_room_map.get(from_peer_id)
        to_room_id = self._peer_room_map.get(to_peer_id)

        if not from_room_id:
            raise WebRTCSignalingError(f"Peer {from_peer_id} not in any room")
        if from_room_id != to_room_id:
            raise WebRTCSignalingError("Peers are not in the same room")

        room = self._rooms.get(from_room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {from_room_id} not found")

        await room.handle_ice_candidate(from_peer_id, to_peer_id, candidate)

    async def update_peer_connection_state(
        self,
        peer_id: str,
        connection_state: PeerConnectionState,
    ) -> None:
        """
        Update a peer's connection state.

        Args:
            peer_id: Peer ID to update
            connection_state: New connection state

        Raises:
            WebRTCSignalingError: If peer not found
        """
        room_id = self._peer_room_map.get(peer_id)
        if not room_id:
            raise WebRTCSignalingError(f"Peer {peer_id} not in any room")

        room = self._rooms.get(room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {room_id} not found")

        await room.update_peer_state(peer_id, connection_state=connection_state)

    async def update_peer_media_state(
        self,
        peer_id: str,
        is_audio_enabled: Optional[bool] = None,
        is_video_enabled: Optional[bool] = None,
    ) -> None:
        """
        Update a peer's media state (audio/video enabled).

        Args:
            peer_id: Peer ID to update
            is_audio_enabled: Whether audio is enabled
            is_video_enabled: Whether video is enabled

        Raises:
            WebRTCSignalingError: If peer not found
        """
        room_id = self._peer_room_map.get(peer_id)
        if not room_id:
            raise WebRTCSignalingError(f"Peer {peer_id} not in any room")

        room = self._rooms.get(room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {room_id} not found")

        await room.update_peer_state(
            peer_id,
            is_audio_enabled=is_audio_enabled,
            is_video_enabled=is_video_enabled,
        )

    def get_peer_room(self, peer_id: str) -> Optional[str]:
        """
        Get the room ID a peer is currently in.

        Args:
            peer_id: Peer ID

        Returns:
            Room ID or None if not in any room
        """
        return self._peer_room_map.get(peer_id)

    def get_user_peer_id(self, user_id: str) -> Optional[str]:
        """
        Get the peer ID for a user.

        Args:
            user_id: User ID

        Returns:
            Peer ID or None if user not connected
        """
        return self._user_peer_map.get(user_id)

    def get_room_participants(self, room_id: str) -> list[dict[str, Any]]:
        """
        Get participants in a room.

        Args:
            room_id: Room ID

        Returns:
            List of peer information dictionaries

        Raises:
            WebRTCSignalingError: If room not found
        """
        room = self._rooms.get(room_id)
        if not room:
            raise WebRTCSignalingError(f"Room {room_id} not found")

        return room.get_peers()

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

        # Remove peer and user mappings for all participants in the room
        peers_to_remove = [
            peer_id
            for peer_id, r_id in self._peer_room_map.items()
            if r_id == room_id
        ]
        for peer_id in peers_to_remove:
            peer = room.get_peer(peer_id)
            if peer and peer.user_id in self._user_peer_map:
                del self._user_peer_map[peer.user_id]
            del self._peer_room_map[peer_id]

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
            if room and room.is_empty:
                await self.close_room(room_id)
                logger.info(f"Room {room_id} auto-closed due to inactivity")

        self._room_cleanup_tasks[room_id] = asyncio.create_task(cleanup())

    async def shutdown(self) -> None:
        """Shutdown the manager and close all rooms."""
        logger.info("Shutting down WebRTCSignalingManager...")

        # Cancel all cleanup tasks
        for task in self._room_cleanup_tasks.values():
            task.cancel()
        self._room_cleanup_tasks.clear()

        # Close all rooms
        for room_id in list(self._rooms.keys()):
            await self.close_room(room_id)

        self._peer_room_map.clear()
        self._user_peer_map.clear()
        logger.info("WebRTCSignalingManager shutdown complete")


# Convenience function to get the manager instance
async def get_webrtc_signaling_manager() -> WebRTCSignalingManager:
    """Get the WebRTCSignalingManager singleton instance."""
    return await WebRTCSignalingManager.get_instance()
