"""Configuration settings for the Python service."""

from functools import lru_cache
from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Application settings loaded from environment variables."""

    model_config = SettingsConfigDict(
        env_file=str(Path(__file__).parent.parent.parent / ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # Service
    service_name: str = "apex-python-service"
    debug: bool = False
    host: str = "127.0.0.1"
    port: int = 8000

    # Laravel Integration
    laravel_url: str = "http://127.0.0.1"
    laravel_api_key: str = ""

    # AWS S3
    aws_access_key_id: str = ""
    aws_secret_access_key: str = ""
    aws_default_region: str = "us-east-1"
    aws_bucket: str = ""

    # ML Configuration
    ml_models_path: str = ""
    ml_gpu_enabled: bool = False

    # Scraping Configuration
    scraping_proxy_url: str = ""
    scraping_rate_limit: int = 10

    # Media Configuration
    rtmp_server_url: str = "rtmp://127.0.0.1:1935"
    hls_output_path: str = "/var/www/hls"


@lru_cache
def get_settings() -> Settings:
    """Get cached settings instance."""
    return Settings()
