"""Streaming endpoints - WebSocket relay to RTMP destinations via FFmpeg."""

import json

from fastapi import APIRouter, HTTPException, WebSocket, WebSocketDisconnect
from loguru import logger
from pydantic import BaseModel

from ..config import get_settings
from ..services.media.rtmp_streamer import (
    RTMPStreamerError,
    stream_registry,
)

router = APIRouter()


class StartStreamRequest(BaseModel):
    """Request to start an RTMP stream."""

    stream_id: str
    destinations: list[dict]
    codec_hint: str = "h264"


class StopStreamRequest(BaseModel):
    """Request to stop an RTMP stream."""

    stream_id: str


@router.post("/start")
async def start_stream(request: StartStreamRequest):
    """Start an RTMP streaming session.

    Prepares the FFmpeg pipeline. After starting, connect via WebSocket
    to send media chunks.
    """
    try:
        streamer = await stream_registry.start(
            request.stream_id,
            request.destinations,
            request.codec_hint,
        )
        return {
            "status": "ready",
            "stream_id": request.stream_id,
            "codec_mode": streamer.codec_mode,
            "destinations": len(request.destinations),
        }
    except RTMPStreamerError as e:
        logger.error(f"Failed to start stream {request.stream_id}: {e}")
        raise HTTPException(status_code=400, detail=str(e))


@router.post("/stop")
async def stop_stream(request: StopStreamRequest):
    """Stop an RTMP streaming session."""
    try:
        result = await stream_registry.stop(request.stream_id)
        if not result:
            raise HTTPException(
                status_code=404,
                detail=f"Stream {request.stream_id} not found",
            )
        return {"status": "stopped", **result}
    except RTMPStreamerError as e:
        logger.error(f"Failed to stop stream {request.stream_id}: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.get("/{stream_id}/status")
async def get_stream_status(stream_id: str):
    """Get the status of a streaming session."""
    streamer = stream_registry.get(stream_id)
    if not streamer:
        raise HTTPException(
            status_code=404,
            detail=f"Stream {stream_id} not found or not active",
        )
    return streamer.stats


@router.get("/active")
async def list_active_streams():
    """List all active streaming sessions."""
    return {
        "active_count": stream_registry.active_count,
        "streams": stream_registry.active_streams,
    }


def _validate_ws_token(websocket: WebSocket) -> bool:
    """Validate WebSocket API key from query parameter."""
    settings = get_settings()
    if not settings.python_api_key:
        return True  # No key configured (development)
    token = websocket.query_params.get("token")
    return token == settings.python_api_key


@router.websocket("/ws/{stream_id}")
async def websocket_stream_relay(websocket: WebSocket, stream_id: str):
    """WebSocket endpoint for streaming media chunks to RTMP.

    The browser sends binary WebM chunks from MediaRecorder.
    These are piped directly to the FFmpeg process for RTMP relay.

    Query params:
        token: API key for authentication

    Message types (incoming):
        - Binary: Media chunks (piped to FFmpeg)
        - JSON {"type": "ping"}: Keep-alive

    Message types (outgoing):
        - {"type": "connected", "stream_id": "xxx"}
        - {"type": "stats", "bytes_received": N}
        - {"type": "error", "message": "xxx"}
        - {"type": "pong"}
    """
    if not _validate_ws_token(websocket):
        await websocket.close(code=4001, reason="Unauthorized")
        return

    await websocket.accept()

    streamer = stream_registry.get(stream_id)
    if not streamer:
        await websocket.send_json({
            "type": "error",
            "message": f"Stream {stream_id} not found. Call /streaming/start first.",
        })
        await websocket.close(code=4004, reason="Stream not found")
        return

    await websocket.send_json({
        "type": "connected",
        "stream_id": stream_id,
        "codec_mode": streamer.codec_mode,
    })

    logger.info(f"WebSocket connected for stream {stream_id}")
    chunk_count = 0

    try:
        while True:
            message = await websocket.receive()

            # Handle binary media chunks
            if "bytes" in message:
                data = message["bytes"]
                try:
                    await streamer.write_chunk(data)
                    chunk_count += 1

                    # Send stats every 10 chunks (~10 seconds at 1-sec intervals)
                    if chunk_count % 10 == 0:
                        await websocket.send_json({
                            "type": "stats",
                            "bytes_received": streamer.bytes_received,
                            "chunk_count": chunk_count,
                        })

                except RTMPStreamerError as e:
                    await websocket.send_json({
                        "type": "error",
                        "message": str(e),
                    })
                    break

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

                if msg_type == "ping":
                    await websocket.send_json({"type": "pong"})

                else:
                    await websocket.send_json({
                        "type": "error",
                        "message": f"Unknown message type: {msg_type}",
                    })

    except WebSocketDisconnect:
        logger.info(f"WebSocket disconnected for stream {stream_id}")
    except Exception as e:
        logger.error(f"WebSocket error for stream {stream_id}: {e}")
    finally:
        logger.info(
            f"Stream {stream_id} WebSocket closed after {chunk_count} chunks, "
            f"{streamer.bytes_received} bytes total"
        )
