"""Realtor.com scraper implementation."""

import re
from typing import Any, Dict, List, Optional
from urllib.parse import urlencode

from bs4 import BeautifulSoup
from loguru import logger

from .base import BaseScraper


class RealtorScraper(BaseScraper):
    """
    Scraper for Realtor.com real estate listings.
    """

    BASE_URL = "https://www.realtor.com"

    async def scrape_listing(self, url: str) -> Dict[str, Any]:
        """
        Scrape a single Realtor.com listing.

        Args:
            url: Realtor.com listing URL

        Returns:
            Dictionary containing listing data
        """
        page = await self.create_page()

        try:
            logger.info(f"Scraping Realtor.com listing: {url}")

            # Navigate to the page - use domcontentloaded for faster loading
            response = await page.goto(url, wait_until="domcontentloaded", timeout=30000)

            if response and response.status == 403:
                logger.warning(f"Realtor.com blocked request with 403 Forbidden")
                return {
                    "url": url,
                    "error": "Realtor.com is blocking automated requests. Try using a proxy service.",
                    "source": "realtor",
                    "blocked": True,
                }
            elif response and response.status != 200:
                logger.warning(f"Non-200 response: {response.status}")

            # Wait for main content to appear
            try:
                await page.wait_for_selector(
                    '[data-testid="address"], [data-testid="list-price"], h1',
                    timeout=15000
                )
            except Exception:
                logger.warning("Content selectors not found, continuing anyway...")

            # Brief delay for dynamic content
            await self.random_delay(500, 1000)
            await self.scroll_page(page, 2)

            # Get page content
            content = await page.content()

            # Parse with BeautifulSoup
            soup = BeautifulSoup(content, "lxml")

            # Extract data
            data = self._parse_listing_page(soup, url)

            # Try to get photos
            photos = await self._extract_photos(page)
            data["photos"] = photos

            return data

        except Exception as e:
            logger.exception(f"Error scraping listing: {e}")
            return {
                "url": url,
                "error": str(e),
                "source": "realtor",
            }
        finally:
            await page.close()

    def _parse_listing_page(self, soup: BeautifulSoup, url: str) -> Dict[str, Any]:
        """Parse listing data from page HTML."""
        data = {
            "url": url,
            "source": "realtor",
        }

        # Try to extract address
        address_elem = soup.select_one('[data-testid="address"]') or soup.select_one('h1')
        if address_elem:
            data["address"] = address_elem.get_text(strip=True)

        # Try to extract price
        price_elem = soup.select_one('[data-testid="list-price"]') or soup.select_one('[class*="price"]')
        if price_elem:
            price_text = price_elem.get_text(strip=True)
            price_match = re.search(r'[\$]?([\d,]+)', price_text)
            if price_match:
                data["price"] = int(price_match.group(1).replace(',', ''))
                data["price_text"] = price_text

        # Try to extract beds/baths/sqft
        stats = soup.select('[data-testid="property-meta"] li')
        for stat in stats:
            text = stat.get_text(strip=True).lower()
            if 'bed' in text:
                match = re.search(r'(\d+)', text)
                if match:
                    data["bedrooms"] = int(match.group(1))
            elif 'bath' in text:
                match = re.search(r'([\d.]+)', text)
                if match:
                    data["bathrooms"] = float(match.group(1))
            elif 'sqft' in text or 'sq ft' in text:
                match = re.search(r'([\d,]+)', text)
                if match:
                    data["sqft"] = int(match.group(1).replace(',', ''))

        # Try to extract description
        desc_elem = soup.select_one('[data-testid="listing-description"]')
        if desc_elem:
            data["description"] = desc_elem.get_text(strip=True)[:500]

        # Try to extract property type
        type_elem = soup.select_one('[data-testid="property-type"]')
        if type_elem:
            data["property_type"] = type_elem.get_text(strip=True)

        # Try to extract agent info
        agent_elem = soup.select_one('[data-testid="agent-name"]')
        if agent_elem:
            data["agent_name"] = agent_elem.get_text(strip=True)

        phone_elem = soup.select_one('[data-testid="agent-phone"]') or soup.select_one('a[href^="tel:"]')
        if phone_elem:
            phone_text = phone_elem.get_text(strip=True)
            data["agent_phone"] = phone_text

        # Try to extract MLS number
        mls_elem = soup.find(string=re.compile(r'MLS', re.I))
        if mls_elem:
            parent = mls_elem.parent
            if parent:
                mls_text = parent.get_text(strip=True)
                match = re.search(r'MLS[#\s:]*\s*([A-Z0-9-]+)', mls_text, re.I)
                if match:
                    data["mls_number"] = match.group(1)

        return data

    async def _extract_photos(self, page) -> List[str]:
        """Extract photo URLs from the listing page."""
        photos = []

        try:
            images = await page.query_selector_all('img[src*="rdcpix"], img[src*="realtor"]')

            for img in images[:10]:
                src = await img.get_attribute("src")
                if src and ("rdcpix" in src or "realtor" in src):
                    # Try to get higher resolution
                    src = re.sub(r'-[ocsm]\d*x\d*', '-o', src)
                    photos.append(src)
        except Exception as e:
            logger.warning(f"Error extracting photos: {e}")

        return photos

    async def scrape_search(
        self,
        location: str,
        filters: Optional[Dict] = None,
        max_results: int = 50
    ) -> List[Dict[str, Any]]:
        """
        Scrape Realtor.com search results.

        Args:
            location: Location to search (e.g., "Austin_TX")
            filters: Optional filters
            max_results: Maximum number of results to return

        Returns:
            List of listing dictionaries
        """
        page = await self.create_page()
        filters = filters or {}

        try:
            # Build search URL
            location_slug = location.replace(", ", "_").replace(" ", "-")
            search_url = f"{self.BASE_URL}/realestateandhomes-search/{location_slug}"

            # Add filters
            filter_parts = []
            if filters.get("min_price"):
                filter_parts.append(f"price-{filters['min_price']}-na")
            if filters.get("max_price"):
                filter_parts.append(f"price-na-{filters['max_price']}")
            if filters.get("beds"):
                filter_parts.append(f"beds-{filters['beds']}-na")

            if filter_parts:
                search_url += "/" + "/".join(filter_parts)

            logger.info(f"Scraping Realtor.com search: {search_url}")

            # Navigate to search page - use domcontentloaded for faster loading
            await page.goto(search_url, wait_until="domcontentloaded", timeout=30000)

            # Wait for search results to appear
            try:
                await page.wait_for_selector(
                    '[data-testid="property-card"], [class*="PropertyCard"]',
                    timeout=15000
                )
            except Exception:
                logger.warning("Search results selectors not found, continuing anyway...")

            await self.random_delay(1000, 2000)

            # Scroll to load more results
            await self.scroll_page(page, 5)

            # Get page content
            content = await page.content()
            soup = BeautifulSoup(content, "lxml")

            # Find listing cards
            listings = []
            cards = soup.select('[data-testid="property-card"]') or soup.select('[class*="PropertyCard"]')

            for card in cards[:max_results]:
                listing = self._parse_search_card(card)
                if listing:
                    listings.append(listing)

            logger.info(f"Found {len(listings)} listings")
            return listings

        except Exception as e:
            logger.exception(f"Error scraping search: {e}")
            return []
        finally:
            await page.close()

    def _parse_search_card(self, card) -> Optional[Dict[str, Any]]:
        """Parse a single search result card."""
        try:
            data = {"source": "realtor"}

            # Get link
            link = card.select_one('a[href*="/realestateandhomes-detail/"]')
            if link:
                href = link.get("href", "")
                if href.startswith("/"):
                    href = self.BASE_URL + href
                data["url"] = href

            # Get address
            address_elem = card.select_one('[data-testid="card-address"]')
            if address_elem:
                data["address"] = address_elem.get_text(strip=True)

            # Get price
            price_elem = card.select_one('[data-testid="card-price"]')
            if price_elem:
                price_text = price_elem.get_text(strip=True)
                match = re.search(r'[\$]?([\d,]+)', price_text)
                if match:
                    data["price"] = int(match.group(1).replace(',', ''))

            # Get beds/baths/sqft from meta
            meta = card.select_one('[data-testid="card-meta"]')
            if meta:
                text = meta.get_text(" ", strip=True).lower()

                beds_match = re.search(r'(\d+)\s*bed', text)
                if beds_match:
                    data["bedrooms"] = int(beds_match.group(1))

                baths_match = re.search(r'([\d.]+)\s*bath', text)
                if baths_match:
                    data["bathrooms"] = float(baths_match.group(1))

                sqft_match = re.search(r'([\d,]+)\s*sq', text)
                if sqft_match:
                    data["sqft"] = int(sqft_match.group(1).replace(',', ''))

            # Get thumbnail
            img = card.select_one('img')
            if img:
                data["thumbnail"] = img.get("src")

            return data if data.get("url") or data.get("address") else None

        except Exception as e:
            logger.warning(f"Error parsing card: {e}")
            return None
