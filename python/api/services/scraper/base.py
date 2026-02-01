"""Base scraper class with common functionality."""

import asyncio
import random
from abc import ABC, abstractmethod
from typing import Any, Dict, List, Optional

from loguru import logger
from playwright.async_api import Browser, Page, async_playwright


class BaseScraper(ABC):
    """
    Base class for web scrapers.

    Provides common functionality for browser automation with anti-detection.
    """

    # Modern user agents for rotation (2024-2025)
    USER_AGENTS = [
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:133.0) Gecko/20100101 Firefox/133.0",
        "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36",
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0",
    ]

    def __init__(self, headless: bool = True, proxy: Optional[str] = None):
        """
        Initialize the scraper.

        Args:
            headless: Run browser in headless mode
            proxy: Optional proxy URL (e.g., "http://user:pass@host:port")
        """
        self.headless = headless
        self.proxy = proxy
        self.browser: Optional[Browser] = None
        self._playwright = None

    async def __aenter__(self):
        """Async context manager entry."""
        await self.start()
        return self

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        """Async context manager exit."""
        await self.close()

    async def start(self):
        """Start the browser with anti-detection settings."""
        self._playwright = await async_playwright().start()

        # Chrome args to avoid detection
        chrome_args = [
            "--disable-blink-features=AutomationControlled",
            "--disable-features=IsolateOrigins,site-per-process",
            "--disable-site-isolation-trials",
            "--disable-web-security",
            "--no-sandbox",
            "--disable-setuid-sandbox",
            "--disable-dev-shm-usage",
            "--disable-accelerated-2d-canvas",
            "--disable-gpu",
            "--window-size=1920,1080",
            "--start-maximized",
            "--disable-infobars",
            "--disable-extensions",
            "--disable-plugins-discovery",
            "--disable-default-apps",
        ]

        launch_options = {
            "headless": self.headless,
            "args": chrome_args,
        }

        if self.proxy:
            launch_options["proxy"] = {"server": self.proxy}

        self.browser = await self._playwright.chromium.launch(**launch_options)
        logger.info(f"Browser started (headless={self.headless})")

    async def close(self):
        """Close the browser."""
        if self.browser:
            await self.browser.close()
        if self._playwright:
            await self._playwright.stop()
        logger.info("Browser closed")

    async def create_page(self) -> Page:
        """Create a new page with anti-detection measures."""
        if not self.browser:
            raise RuntimeError("Browser not started. Call start() first.")

        # Random user agent
        user_agent = random.choice(self.USER_AGENTS)

        context = await self.browser.new_context(
            user_agent=user_agent,
            viewport={"width": 1920, "height": 1080},
            locale="en-US",
            timezone_id="America/New_York",
            geolocation={"latitude": 39.7392, "longitude": -104.9903},  # Denver, CO
            permissions=["geolocation"],
            color_scheme="light",
            java_script_enabled=True,
            has_touch=False,
            is_mobile=False,
            device_scale_factor=1,
            extra_http_headers={
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
                "Accept-Language": "en-US,en;q=0.9",
                "Accept-Encoding": "gzip, deflate, br",
                "Cache-Control": "max-age=0",
                "Sec-Ch-Ua": '"Chromium";v="131", "Not_A Brand";v="24"',
                "Sec-Ch-Ua-Mobile": "?0",
                "Sec-Ch-Ua-Platform": '"Windows"',
                "Sec-Fetch-Dest": "document",
                "Sec-Fetch-Mode": "navigate",
                "Sec-Fetch-Site": "none",
                "Sec-Fetch-User": "?1",
                "Upgrade-Insecure-Requests": "1",
            },
        )

        page = await context.new_page()

        # Add stealth scripts
        await self._apply_stealth(page)

        return page

    async def _apply_stealth(self, page: Page):
        """Apply comprehensive stealth measures to avoid detection."""
        # Comprehensive stealth script
        await page.add_init_script("""
            // Override webdriver
            Object.defineProperty(navigator, 'webdriver', {
                get: () => undefined
            });

            // Override plugins to look realistic
            Object.defineProperty(navigator, 'plugins', {
                get: () => {
                    const plugins = [
                        { name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer', description: 'Portable Document Format' },
                        { name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai', description: '' },
                        { name: 'Native Client', filename: 'internal-nacl-plugin', description: '' }
                    ];
                    plugins.item = (i) => plugins[i];
                    plugins.namedItem = (name) => plugins.find(p => p.name === name);
                    plugins.refresh = () => {};
                    return plugins;
                }
            });

            // Override languages
            Object.defineProperty(navigator, 'languages', {
                get: () => ['en-US', 'en']
            });

            // Override permissions
            const originalQuery = window.navigator.permissions.query;
            window.navigator.permissions.query = (parameters) => (
                parameters.name === 'notifications' ?
                    Promise.resolve({ state: Notification.permission }) :
                    originalQuery(parameters)
            );

            // Override Chrome runtime
            window.chrome = {
                runtime: {},
                loadTimes: function() {},
                csi: function() {},
                app: {}
            };

            // Override connection info
            Object.defineProperty(navigator, 'connection', {
                get: () => ({
                    effectiveType: '4g',
                    rtt: 100,
                    downlink: 10,
                    saveData: false
                })
            });

            // Override hardware concurrency
            Object.defineProperty(navigator, 'hardwareConcurrency', {
                get: () => 8
            });

            // Override device memory
            Object.defineProperty(navigator, 'deviceMemory', {
                get: () => 8
            });

            // Override platform
            Object.defineProperty(navigator, 'platform', {
                get: () => 'Win32'
            });

            // Remove automation indicators
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Array;
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Promise;
            delete window.cdc_adoQpoasnfa76pfcZLmcfl_Symbol;

            // Override toString for functions
            const originalFunction = window.Function.prototype.toString;
            window.Function.prototype.toString = function() {
                if (this === window.navigator.permissions.query) {
                    return 'function query() { [native code] }';
                }
                return originalFunction.call(this);
            };
        """)

    async def random_delay(self, min_ms: int = 500, max_ms: int = 2000):
        """Add a random delay to mimic human behavior."""
        delay = random.randint(min_ms, max_ms) / 1000
        await asyncio.sleep(delay)

    async def scroll_page(self, page: Page, scroll_count: int = 3):
        """Scroll the page to load dynamic content."""
        for _ in range(scroll_count):
            await page.evaluate("window.scrollBy(0, window.innerHeight)")
            await self.random_delay(300, 800)

    async def wait_for_content(
        self,
        page: Page,
        selector: str,
        timeout: int = 30000
    ) -> bool:
        """Wait for a selector to appear on the page."""
        try:
            await page.wait_for_selector(selector, timeout=timeout)
            return True
        except Exception:
            return False

    @abstractmethod
    async def scrape_listing(self, url: str) -> Dict[str, Any]:
        """
        Scrape a single listing.

        Args:
            url: URL of the listing to scrape

        Returns:
            Dictionary containing listing data
        """
        pass

    @abstractmethod
    async def scrape_search(
        self,
        location: str,
        filters: Optional[Dict] = None,
        max_results: int = 50
    ) -> List[Dict[str, Any]]:
        """
        Scrape search results.

        Args:
            location: Location to search
            filters: Optional search filters
            max_results: Maximum number of results to return

        Returns:
            List of listing dictionaries
        """
        pass
