"""Media processing endpoints (audio/video)."""

import base64
import json
import os
import tempfile
from enum import Enum
from typing import Optional

from fastapi import APIRouter, File, Form, HTTPException, UploadFile, WebSocket, WebSocketDisconnect
from loguru import logger
from pydantic import BaseModel

from ..services.media.audio_stream import AudioStreamManager
from ..services.media.transcription import (
    TranscriptionError,
    TranscriptionService,
)
from ..services.media.webrtc_signaling import (
    WebRTCSignalingError,
    WebRTCSignalingManager,
)

router = APIRouter()


class TranscriptionModel(str, Enum):
    """Available Whisper model sizes."""

    TINY = "tiny"
    BASE = "base"
    SMALL = "small"
    MEDIUM = "medium"
    LARGE = "large"


class TranscribeRequest(BaseModel):
    """Request model for audio transcription."""

    audio_url: str
    model: TranscriptionModel = TranscriptionModel.BASE
    language: Optional[str] = None  # Auto-detect if not specified


class TranscribeResponse(BaseModel):
    """Response model for transcription."""

    success: bool
    text: Optional[str] = None
    segments: Optional[list[dict]] = None
    language: Optional[str] = None
    duration: Optional[float] = None
    error: Optional[str] = None


class StreamConfig(BaseModel):
    """Configuration for video streaming."""

    rtmp_key: Optional[str] = None
    resolution: str = "720p"
    bitrate: int = 2500
    enable_hls: bool = True


class StreamResponse(BaseModel):
    """Response for stream operations."""

    success: bool
    stream_id: Optional[str] = None
    rtmp_url: Optional[str] = None
    hls_url: Optional[str] = None
    error: Optional[str] = None


@router.post("/transcribe", response_model=TranscribeResponse)
async def transcribe_audio(request: TranscribeRequest):
    """
    Transcribe audio using OpenAI Whisper.

    Supports various audio formats (mp3, wav, m4a, etc.).
    Returns text and timestamped segments.
    """
    # Check if Whisper is available
    if not TranscriptionService.is_whisper_available():
        raise HTTPException(
            status_code=503,
            detail="Whisper is not installed on this server. Install with: pip install openai-whisper",
        )

    try:
        # Create service with requested model
        service = TranscriptionService(model_name=request.model.value)

        # Transcribe the audio URL (synchronous call)
        result = service.transcribe_url(
            url=request.audio_url,
            language=request.language,
        )

        return TranscribeResponse(
            success=True,
            text=result.get("text"),
            segments=result.get("segments"),
            language=result.get("language"),
            duration=result.get("duration"),
        )

    except TranscriptionError as e:
        logger.error(f"Transcription error: {e}")
        return TranscribeResponse(
            success=False,
            error=str(e),
        )
    except Exception as e:
        logger.error(f"Unexpected transcription error: {e}")
        return TranscribeResponse(
            success=False,
            error=str(e),
        )


@router.post("/transcribe/upload", response_model=TranscribeResponse)
async def transcribe_uploaded_audio(
    file: UploadFile = File(...),
    model: TranscriptionModel = Form(default=TranscriptionModel.BASE),
    language: Optional[str] = Form(default=None),
):
    """
    Transcribe an uploaded audio file.

    Alternative to providing a URL - useful for direct uploads.
    """
    supported_formats = TranscriptionService.get_supported_formats()
    supported_content_types = [
        "audio/mpeg",
        "audio/mp3",
        "audio/wav",
        "audio/x-wav",
        "audio/mp4",
        "audio/m4a",
        "audio/ogg",
        "audio/webm",
        "audio/flac",
        "audio/x-flac",
        "audio/aac",
        "audio/x-ms-wma",
        # Chrome's MediaRecorder often reports video/webm for audio-only recordings
        "video/webm",
    ]

    # Validate content type
    if file.content_type and file.content_type not in supported_content_types:
        raise HTTPException(
            status_code=400,
            detail=f"Unsupported audio format: {file.content_type}. Supported: {', '.join(supported_content_types)}",
        )

    # Check if Whisper is available
    if not TranscriptionService.is_whisper_available():
        raise HTTPException(
            status_code=503,
            detail="Whisper is not installed on this server. Install with: pip install openai-whisper",
        )

    temp_file_path = None

    try:
        # Read the uploaded file
        audio_bytes = await file.read()

        if len(audio_bytes) == 0:
            raise HTTPException(
                status_code=400,
                detail="Uploaded file is empty",
            )

        logger.info(
            f"Received upload: {file.filename}, size: {len(audio_bytes)} bytes, "
            f"content-type: {file.content_type}"
        )

        # Determine file extension from filename
        filename = file.filename or "audio.mp3"
        ext = os.path.splitext(filename)[1].lower()
        if not ext or ext not in supported_formats:
            ext = ".mp3"  # Default

        # Write to temporary file (Whisper requires a file path)
        with tempfile.NamedTemporaryFile(
            suffix=ext, delete=False, prefix="whisper_upload_"
        ) as tmp_file:
            tmp_file.write(audio_bytes)
            temp_file_path = tmp_file.name

        # Create service with requested model
        service = TranscriptionService(model_name=model.value)

        # Transcribe the uploaded audio (synchronous call)
        result = service.transcribe(
            audio_path=temp_file_path,
            language=language,
        )

        return TranscribeResponse(
            success=True,
            text=result.get("text"),
            segments=result.get("segments"),
            language=result.get("language"),
            duration=result.get("duration"),
        )

    except HTTPException:
        raise
    except TranscriptionError as e:
        logger.error(f"Upload transcription error: {e}")
        return TranscribeResponse(
            success=False,
            error=str(e),
        )
    except Exception as e:
        logger.error(f"Unexpected upload transcription error: {e}")
        return TranscribeResponse(
            success=False,
            error=str(e),
        )
    finally:
        # Clean up temporary file
        if temp_file_path and os.path.exists(temp_file_path):
            try:
                os.unlink(temp_file_path)
                logger.debug(f"Cleaned up temporary file: {temp_file_path}")
            except Exception as e:
                logger.warning(f"Failed to clean up temporary file: {e}")


@router.post("/stream/start", response_model=StreamResponse)
async def start_stream(config: StreamConfig):
    """
    Start a video streaming session.

    Sets up RTMP ingestion and HLS output for live streaming.
    """
    import uuid

    stream_id = str(uuid.uuid4())[:8]

    # TODO: Implement RTMP/HLS streaming setup
    return StreamResponse(
        success=False,
        stream_id=stream_id,
        error="Video streaming not yet implemented. Phase 4 will add RTMP/HLS.",
    )


@router.post("/stream/{stream_id}/stop")
async def stop_stream(stream_id: str):
    """Stop an active streaming session."""
    # TODO: Implement stream stopping
    return {
        "success": False,
        "stream_id": stream_id,
        "error": "Stream management not yet implemented.",
    }


@router.get("/stream/{stream_id}/status")
async def get_stream_status(stream_id: str):
    """Get the status of a streaming session."""
    # TODO: Implement stream status
    return {
        "stream_id": stream_id,
        "status": "not_found",
        "error": "Stream status not yet implemented.",
    }


@router.websocket("/ws/audio")
async def websocket_audio_stream(websocket: WebSocket):
    """
    WebSocket endpoint for real-time audio streaming (walkie-talkie).

    Query params:
        room_id: Room to join
        user_id: User's unique identifier
        user_name: User's display name

    Message types (incoming):
        - Binary: Audio chunks (broadcast to room)
        - JSON {"type": "join", "room_id": "xxx", "user_id": "xxx", "user_name": "xxx"}
        - JSON {"type": "leave"}
        - JSON {"type": "transcribe", "audio_base64": "xxx"} - transcribe provided audio
        - JSON {"type": "mute"} / {"type": "unmute"}
        - JSON {"type": "start_transmission"} - start push-to-talk
        - JSON {"type": "end_transmission"} - end push-to-talk (triggers transcription if enabled)

    Message types (outgoing):
        - {"type": "user_joined", "user_id": "xxx", "user_name": "xxx", "participants": [...]}
        - {"type": "user_left", "user_id": "xxx"}
        - {"type": "audio", "user_id": "xxx", "user_name": "xxx"} followed by binary audio
        - {"type": "transcription", "user_id": "xxx", "text": "xxx"}
        - {"type": "error", "message": "xxx"}
        - {"type": "joined", "room_id": "xxx", "participants": [...]}
    """
    from ..services.media.audio_stream import AudioStreamError

    await websocket.accept()

    manager = AudioStreamManager()
    user_id: Optional[str] = None
    room_id: Optional[str] = None
    user_name: Optional[str] = None
    is_transmitting: bool = False

    # Check for query params for initial join
    query_params = websocket.query_params
    initial_room_id = query_params.get("room_id")
    initial_user_id = query_params.get("user_id")
    initial_user_name = query_params.get("user_name")

    try:
        # Auto-join if query params provided
        if initial_room_id and initial_user_id and initial_user_name:
            user_id = initial_user_id
            user_name = initial_user_name
            room_id = initial_room_id

            # Get or create the room, then join
            room = await manager.get_or_create_room(room_id)
            participant = await manager.join_room(
                room_id=room_id,
                user_id=user_id,
                websocket=websocket,
                display_name=user_name,
            )

            # Send confirmation to the joining user
            await websocket.send_json({
                "type": "joined",
                "room_id": room_id,
                "participants": room.get_participants(),
            })

            logger.info(f"User {user_name} ({user_id}) auto-joined room {room_id}")

        # Main message loop
        while True:
            message = await websocket.receive()

            # Handle binary audio data
            if "bytes" in message:
                audio_data = message["bytes"]

                if not user_id or not room_id:
                    await websocket.send_json({
                        "type": "error",
                        "message": "Must join a room before sending audio",
                    })
                    continue

                try:
                    if is_transmitting:
                        # Add chunk to active transmission
                        await manager.send_audio_chunk(user_id, audio_data)
                    else:
                        # Direct broadcast without push-to-talk flow
                        await manager.broadcast_audio(user_id, audio_data)

                    logger.debug(
                        f"Broadcast {len(audio_data)} bytes from {user_id}"
                    )
                except AudioStreamError as e:
                    await websocket.send_json({
                        "type": "error",
                        "message": str(e),
                    })

            # Handle text/JSON messages
            elif "text" in message:
                try:
                    data = json.loads(message["text"])
                except json.JSONDecodeError:
                    await websocket.send_json({
                        "type": "error",
                        "message": "Invalid JSON message",
                    })
                    continue

                msg_type = data.get("type")

                if msg_type == "join":
                    # Join a room
                    new_room_id = data.get("room_id")
                    new_user_id = data.get("user_id")
                    new_user_name = data.get("user_name")

                    if not all([new_room_id, new_user_id, new_user_name]):
                        await websocket.send_json({
                            "type": "error",
                            "message": "Missing required fields: room_id, user_id, user_name",
                        })
                        continue

                    # Leave current room if in one
                    if user_id:
                        await manager.leave_room(user_id)

                    user_id = new_user_id
                    user_name = new_user_name
                    room_id = new_room_id

                    # Get or create the room, then join
                    room = await manager.get_or_create_room(room_id)
                    participant = await manager.join_room(
                        room_id=room_id,
                        user_id=user_id,
                        websocket=websocket,
                        display_name=user_name,
                    )

                    await websocket.send_json({
                        "type": "joined",
                        "room_id": room_id,
                        "participants": room.get_participants(),
                    })

                    logger.info(f"User {user_name} ({user_id}) joined room {room_id}")

                elif msg_type == "leave":
                    # Leave current room
                    if user_id:
                        left_room_id = manager.get_user_room(user_id)
                        await manager.leave_room(user_id)
                        await websocket.send_json({
                            "type": "left",
                            "room_id": left_room_id,
                        })
                        logger.info(f"User {user_id} left room {left_room_id}")
                        room_id = None
                        is_transmitting = False
                    else:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Not in a room",
                        })

                elif msg_type == "mute":
                    if user_id and room_id:
                        try:
                            room = await manager.get_room(room_id)
                            if room:
                                await room.set_muted(user_id, muted=True)
                                await websocket.send_json({
                                    "type": "muted",
                                    "user_id": user_id,
                                })
                                logger.debug(f"User {user_id} muted")
                        except AudioStreamError as e:
                            await websocket.send_json({
                                "type": "error",
                                "message": str(e),
                            })
                    else:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Not in a room",
                        })

                elif msg_type == "unmute":
                    if user_id and room_id:
                        try:
                            room = await manager.get_room(room_id)
                            if room:
                                await room.set_muted(user_id, muted=False)
                                await websocket.send_json({
                                    "type": "unmuted",
                                    "user_id": user_id,
                                })
                                logger.debug(f"User {user_id} unmuted")
                        except AudioStreamError as e:
                            await websocket.send_json({
                                "type": "error",
                                "message": str(e),
                            })
                    else:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Not in a room",
                        })

                elif msg_type == "start_transmission":
                    # Start push-to-talk transmission
                    if not user_id or not room_id:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Must join a room before transmitting",
                        })
                        continue

                    try:
                        message_id = await manager.start_transmission(user_id)
                        is_transmitting = True
                        await websocket.send_json({
                            "type": "transmission_started",
                            "message_id": message_id,
                        })
                        logger.debug(f"User {user_id} started transmission {message_id}")
                    except AudioStreamError as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": str(e),
                        })

                elif msg_type == "end_transmission":
                    # End push-to-talk transmission (triggers transcription if enabled)
                    if not user_id:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Not transmitting",
                        })
                        continue

                    try:
                        audio_message = await manager.end_transmission(user_id)
                        is_transmitting = False
                        await websocket.send_json({
                            "type": "transmission_ended",
                            "message_id": audio_message.message_id if audio_message else None,
                        })
                        logger.debug(f"User {user_id} ended transmission")
                    except AudioStreamError as e:
                        is_transmitting = False
                        await websocket.send_json({
                            "type": "error",
                            "message": str(e),
                        })

                elif msg_type == "transcribe":
                    # Transcribe provided audio (manual transcription request)
                    if not user_id:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Must join a room before requesting transcription",
                        })
                        continue

                    audio_base64 = data.get("audio_base64")

                    if not audio_base64:
                        await websocket.send_json({
                            "type": "error",
                            "message": "No audio_base64 provided for transcription",
                        })
                        continue

                    # Decode provided audio
                    try:
                        audio_data = base64.b64decode(audio_base64)
                    except Exception as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": f"Invalid base64 audio: {e}",
                        })
                        continue

                    # Determine audio format
                    audio_format = data.get("format", ".webm")

                    # Transcribe using the transcription service directly
                    try:
                        text = await _transcribe_audio_data(audio_data, audio_format)

                        if text:
                            await websocket.send_json({
                                "type": "transcription",
                                "user_id": user_id,
                                "text": text,
                            })
                            logger.info(f"Transcription for {user_id}: {text[:50]}...")
                        else:
                            await websocket.send_json({
                                "type": "error",
                                "message": "Transcription failed or no speech detected",
                            })
                    except Exception as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": f"Transcription error: {e}",
                        })

                else:
                    await websocket.send_json({
                        "type": "error",
                        "message": f"Unknown message type: {msg_type}",
                    })

    except WebSocketDisconnect:
        logger.info(f"WebSocket disconnected for user {user_id}")
    except Exception as e:
        logger.error(f"WebSocket error for user {user_id}: {e}")
    finally:
        # Clean up on disconnect
        if user_id:
            await manager.leave_room(user_id)
            logger.info(f"Cleaned up user {user_id} on disconnect")


@router.websocket("/ws/video")
async def websocket_video_stream(websocket: WebSocket):
    """
    WebSocket endpoint for WebRTC signaling.

    Handles offer/answer exchange and ICE candidate relay for video calls.

    Query params:
        room_id: Room to join
        user_id: User's unique identifier
        user_name: User's display name

    Message types (incoming):
        - JSON {"type": "join", "room_id": "xxx", "user_id": "xxx", "user_name": "xxx"}
        - JSON {"type": "leave"}
        - JSON {"type": "offer", "target_user_id": "xxx", "sdp": {...}}
        - JSON {"type": "answer", "target_user_id": "xxx", "sdp": {...}}
        - JSON {"type": "ice-candidate", "target_user_id": "xxx", "candidate": {...}}

    Message types (outgoing):
        - {"type": "user_joined", "user_id": "xxx", "user_name": "xxx", "participants": [...]}
        - {"type": "user_left", "user_id": "xxx"}
        - {"type": "offer", "from_user_id": "xxx", "sdp": {...}}
        - {"type": "answer", "from_user_id": "xxx", "sdp": {...}}
        - {"type": "ice-candidate", "from_user_id": "xxx", "candidate": {...}}
        - {"type": "joined", "room_id": "xxx", "participants": [...]}
        - {"type": "error", "message": "xxx"}
    """
    await websocket.accept()

    manager = WebRTCSignalingManager()
    user_id: Optional[str] = None
    room_id: Optional[str] = None
    user_name: Optional[str] = None
    peer_id: Optional[str] = None

    # Check for query params for initial join
    query_params = websocket.query_params
    initial_room_id = query_params.get("room_id")
    initial_user_id = query_params.get("user_id")
    initial_user_name = query_params.get("user_name")

    try:
        # Auto-join if query params provided
        if initial_room_id and initial_user_id and initial_user_name:
            user_id = initial_user_id
            user_name = initial_user_name
            room_id = initial_room_id

            room = await manager.get_or_create_room(room_id)
            peer = await manager.join_room(
                room_id=room_id,
                user_id=user_id,
                websocket=websocket,
                display_name=user_name,
            )
            peer_id = peer.peer_id

            # Note: The room already sends a "room_joined" message to the peer
            # with existing_peers list, so we don't need to send "joined" here
            logger.info(f"User {user_name} ({user_id}) auto-joined video room {room_id} as peer {peer_id}")

        # Main message loop
        while True:
            message = await websocket.receive()

            # Handle text/JSON messages
            if "text" in message:
                try:
                    data = json.loads(message["text"])
                except json.JSONDecodeError:
                    await websocket.send_json({
                        "type": "error",
                        "message": "Invalid JSON message",
                    })
                    continue

                msg_type = data.get("type")

                if msg_type == "join":
                    # Join a room
                    new_room_id = data.get("room_id")
                    new_user_id = data.get("user_id")
                    new_user_name = data.get("user_name")

                    if not all([new_room_id, new_user_id, new_user_name]):
                        await websocket.send_json({
                            "type": "error",
                            "message": "Missing required fields: room_id, user_id, user_name",
                        })
                        continue

                    # Leave current room if in one
                    if peer_id:
                        await manager.leave_room(peer_id)

                    user_id = new_user_id
                    user_name = new_user_name
                    room_id = new_room_id

                    try:
                        room = await manager.get_or_create_room(room_id)
                        peer = await manager.join_room(
                            room_id=room_id,
                            user_id=user_id,
                            websocket=websocket,
                            display_name=user_name,
                        )
                        peer_id = peer.peer_id

                        # Note: The room already sends a "room_joined" message
                        logger.info(f"User {user_name} ({user_id}) joined video room {room_id} as peer {peer_id}")

                    except WebRTCSignalingError as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": str(e),
                        })

                elif msg_type == "leave":
                    # Leave current room
                    if peer_id:
                        left_room_id = manager.get_peer_room(peer_id)
                        await manager.leave_room(peer_id)
                        await websocket.send_json({
                            "type": "left",
                            "room_id": left_room_id,
                        })
                        logger.info(f"User {user_id} (peer {peer_id}) left video room {left_room_id}")
                        room_id = None
                        peer_id = None
                    else:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Not in a room",
                        })

                elif msg_type == "offer":
                    # Relay WebRTC offer to target peer
                    if not peer_id or not room_id:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Must join a room before sending offers",
                        })
                        continue

                    # Accept either target_user_id or target_peer_id
                    target_peer_id = data.get("target_peer_id")
                    target_user_id = data.get("target_user_id")
                    sdp = data.get("sdp")

                    # If target_user_id provided, look up the peer_id
                    if not target_peer_id and target_user_id:
                        target_peer_id = manager.get_user_peer_id(target_user_id)

                    if not target_peer_id or not sdp:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Missing required fields: target_user_id or target_peer_id, sdp",
                        })
                        continue

                    try:
                        await manager.handle_offer(peer_id, target_peer_id, sdp)
                        logger.debug(f"Relayed offer from peer {peer_id} to peer {target_peer_id}")
                    except WebRTCSignalingError as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": str(e),
                        })

                elif msg_type == "answer":
                    # Relay WebRTC answer to target peer
                    if not peer_id or not room_id:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Must join a room before sending answers",
                        })
                        continue

                    # Accept either target_user_id or target_peer_id
                    target_peer_id = data.get("target_peer_id")
                    target_user_id = data.get("target_user_id")
                    sdp = data.get("sdp")

                    # If target_user_id provided, look up the peer_id
                    if not target_peer_id and target_user_id:
                        target_peer_id = manager.get_user_peer_id(target_user_id)

                    if not target_peer_id or not sdp:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Missing required fields: target_user_id or target_peer_id, sdp",
                        })
                        continue

                    try:
                        await manager.handle_answer(peer_id, target_peer_id, sdp)
                        logger.debug(f"Relayed answer from peer {peer_id} to peer {target_peer_id}")
                    except WebRTCSignalingError as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": str(e),
                        })

                elif msg_type == "ice-candidate":
                    # Relay ICE candidate to target peer
                    if not peer_id or not room_id:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Must join a room before sending ICE candidates",
                        })
                        continue

                    # Accept either target_user_id or target_peer_id
                    target_peer_id = data.get("target_peer_id")
                    target_user_id = data.get("target_user_id")
                    candidate = data.get("candidate")

                    # If target_user_id provided, look up the peer_id
                    if not target_peer_id and target_user_id:
                        target_peer_id = manager.get_user_peer_id(target_user_id)

                    if not target_peer_id or candidate is None:
                        await websocket.send_json({
                            "type": "error",
                            "message": "Missing required fields: target_user_id or target_peer_id, candidate",
                        })
                        continue

                    try:
                        await manager.handle_ice_candidate(peer_id, target_peer_id, candidate)
                        logger.debug(f"Relayed ICE candidate from peer {peer_id} to peer {target_peer_id}")
                    except WebRTCSignalingError as e:
                        await websocket.send_json({
                            "type": "error",
                            "message": str(e),
                        })

                else:
                    await websocket.send_json({
                        "type": "error",
                        "message": f"Unknown message type: {msg_type}",
                    })

    except WebSocketDisconnect:
        logger.info(f"WebSocket disconnected for video user {user_id} (peer {peer_id})")
    except Exception as e:
        logger.error(f"WebSocket error for video user {user_id} (peer {peer_id}): {e}")
    finally:
        # Clean up on disconnect
        if peer_id:
            await manager.leave_room(peer_id)
            logger.info(f"Cleaned up video peer {peer_id} on disconnect")


@router.get("/models")
async def list_whisper_models():
    """List available Whisper models with their characteristics."""
    models = TranscriptionService.get_available_models()
    return {
        "whisper_available": TranscriptionService.is_whisper_available(),
        "models": models,
    }


@router.get("/status")
async def get_transcription_status():
    """Get the status of the transcription service."""
    return {
        "whisper_available": TranscriptionService.is_whisper_available(),
        "supported_formats": TranscriptionService.get_supported_formats(),
        "endpoints": {
            "transcribe_url": "/media/transcribe",
            "transcribe_upload": "/media/transcribe/upload",
            "models": "/media/models",
        },
    }


async def _transcribe_audio_data(
    audio_data: bytes,
    audio_format: str = ".webm",
) -> Optional[str]:
    """
    Helper function to transcribe audio data.

    Args:
        audio_data: Audio bytes to transcribe
        audio_format: Audio format extension (e.g., '.webm', '.wav')

    Returns:
        Transcription text, or None if transcription failed
    """
    if not TranscriptionService.is_whisper_available():
        logger.warning("Whisper not available for transcription")
        return None

    temp_file_path = None
    try:
        # Write audio to temporary file
        with tempfile.NamedTemporaryFile(
            suffix=audio_format,
            delete=False,
            prefix="walkie_transcribe_",
        ) as tmp_file:
            tmp_file.write(audio_data)
            temp_file_path = tmp_file.name

        # Transcribe
        service = TranscriptionService(model_name="base")
        result = service.transcribe(audio_path=temp_file_path)
        return result.get("text", "").strip()

    except TranscriptionError as e:
        logger.error(f"Transcription error: {e}")
        return None
    except Exception as e:
        logger.error(f"Unexpected transcription error: {e}")
        return None
    finally:
        # Clean up temporary file
        if temp_file_path and os.path.exists(temp_file_path):
            try:
                os.unlink(temp_file_path)
            except Exception as e:
                logger.warning(f"Failed to clean up temp file: {e}")
