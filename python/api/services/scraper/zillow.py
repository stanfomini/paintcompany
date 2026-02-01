"""Zillow scraper implementation."""

import json
import re
from typing import Any, Dict, List, Optional
from urllib.parse import urlencode

from bs4 import BeautifulSoup
from loguru import logger

from .base import BaseScraper


class ZillowScraper(BaseScraper):
    """
    Scraper for Zillow real estate listings.

    Note: Zillow has aggressive anti-bot measures. Use with caution
    and respect their robots.txt and terms of service.
    """

    BASE_URL = "https://www.zillow.com"

    async def scrape_listing(self, url: str) -> Dict[str, Any]:
        """
        Scrape a single Zillow listing.

        Args:
            url: Zillow listing URL

        Returns:
            Dictionary containing listing data
        """
        page = await self.create_page()

        try:
            logger.info(f"Scraping Zillow listing: {url}")

            # Navigate to the page - use domcontentloaded instead of networkidle
            # networkidle never completes on Zillow due to continuous tracking scripts
            response = await page.goto(url, wait_until="domcontentloaded", timeout=30000)

            if response and response.status == 403:
                logger.warning(f"Zillow blocked request with 403 Forbidden")
                return {
                    "url": url,
                    "error": "Zillow is blocking automated requests. Try using a proxy service or scraping API.",
                    "source": "zillow",
                    "blocked": True,
                }
            elif response and response.status != 200:
                logger.warning(f"Non-200 response: {response.status}")

            # Wait for main content to appear (address or price)
            try:
                await page.wait_for_selector(
                    'h1[class*="address"], [data-testid="bdp-building-address"], [data-testid="price"]',
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
                "source": "zillow",
            }
        finally:
            await page.close()

    def _parse_listing_page(self, soup: BeautifulSoup, url: str) -> Dict[str, Any]:
        """Parse listing data from page HTML."""
        data = {
            "url": url,
            "source": "zillow",
        }

        # First, try to extract from JSON-LD structured data (most reliable)
        json_ld_data = self._extract_json_ld(soup)
        if json_ld_data:
            data.update(json_ld_data)

        # Also try __NEXT_DATA__ JSON (Next.js data)
        next_data = self._extract_next_data(soup)
        if next_data:
            # Merge, preferring existing data
            for key, value in next_data.items():
                if key not in data or data[key] is None:
                    data[key] = value

        # Fall back to HTML selectors if JSON extraction didn't work
        if not data.get("address"):
            address_elem = soup.select_one('h1[class*="address"]') or soup.select_one('[data-testid="bdp-building-address"]')
            if address_elem:
                data["address"] = address_elem.get_text(strip=True)

        if not data.get("price"):
            price_elem = soup.select_one('[data-testid="price"]') or soup.select_one('span[class*="price"]')
            if price_elem:
                price_text = price_elem.get_text(strip=True)
                price_match = re.search(r'[\$]?([\d,]+)', price_text)
                if price_match:
                    data["price"] = int(price_match.group(1).replace(',', ''))
                    data["price_text"] = price_text

        # Try to extract beds/baths/sqft from HTML if not found
        if not data.get("bedrooms") or not data.get("bathrooms") or not data.get("sqft"):
            stats = soup.select('[data-testid="bed-bath-sqft"] span')
            for stat in stats:
                text = stat.get_text(strip=True).lower()
                if ('bd' in text or 'bed' in text) and not data.get("bedrooms"):
                    match = re.search(r'(\d+)', text)
                    if match:
                        data["bedrooms"] = int(match.group(1))
                elif ('ba' in text or 'bath' in text) and not data.get("bathrooms"):
                    match = re.search(r'([\d.]+)', text)
                    if match:
                        data["bathrooms"] = float(match.group(1))
                elif ('sqft' in text or 'sq ft' in text) and not data.get("sqft"):
                    match = re.search(r'([\d,]+)', text)
                    if match:
                        data["sqft"] = int(match.group(1).replace(',', ''))

        # Try to extract description
        if not data.get("description"):
            desc_elem = soup.select_one('[data-testid="description"]') or soup.select_one('[class*="description"]')
            if desc_elem:
                data["description"] = desc_elem.get_text(strip=True)[:500]

        # Try to extract property type
        if not data.get("property_type"):
            type_elem = soup.select_one('[data-testid="home-type"]')
            if type_elem:
                data["property_type"] = type_elem.get_text(strip=True)

        # Try to extract year built and lot size from facts
        facts = soup.select('[data-testid="facts-list"] li')
        for fact in facts:
            text = fact.get_text(strip=True).lower()
            if 'year built' in text and not data.get("year_built"):
                match = re.search(r'(\d{4})', text)
                if match:
                    data["year_built"] = int(match.group(1))
            elif 'lot size' in text and not data.get("lot_size"):
                data["lot_size"] = fact.get_text(strip=True)

        # Try to extract MLS number
        if not data.get("mls_number"):
            mls_elem = soup.find(string=re.compile(r'MLS#|MLS ID', re.I))
            if mls_elem:
                parent = mls_elem.parent
                if parent:
                    mls_text = parent.get_text(strip=True)
                    match = re.search(r'MLS[#\s:ID]*\s*([A-Z0-9]+)', mls_text, re.I)
                    if match:
                        data["mls_number"] = match.group(1)

        return data

    def _extract_json_ld(self, soup: BeautifulSoup) -> Dict[str, Any]:
        """Extract data from JSON-LD structured data."""
        data = {}

        try:
            scripts = soup.find_all('script', type='application/ld+json')
            for script in scripts:
                try:
                    json_data = json.loads(script.string)

                    # Handle array of JSON-LD objects
                    if isinstance(json_data, list):
                        for item in json_data:
                            if item.get('@type') in ['SingleFamilyResidence', 'House', 'Apartment', 'RealEstateListing', 'Product']:
                                data.update(self._parse_json_ld_item(item))
                    elif json_data.get('@type') in ['SingleFamilyResidence', 'House', 'Apartment', 'RealEstateListing', 'Product']:
                        data.update(self._parse_json_ld_item(json_data))

                except json.JSONDecodeError:
                    continue

        except Exception as e:
            logger.warning(f"Error extracting JSON-LD: {e}")

        return data

    def _parse_json_ld_item(self, item: Dict) -> Dict[str, Any]:
        """Parse a single JSON-LD item."""
        data = {}

        # Address
        address = item.get('address', {})
        if isinstance(address, dict):
            street = address.get('streetAddress', '')
            city = address.get('addressLocality', '')
            state = address.get('addressRegion', '')
            zip_code = address.get('postalCode', '')
            if street:
                data['address'] = f"{street}, {city}, {state} {zip_code}".strip(', ')

        # Price
        offers = item.get('offers', {})
        if isinstance(offers, dict):
            price = offers.get('price')
            if price:
                data['price'] = int(float(price))

        # Bedrooms/Bathrooms
        if item.get('numberOfRooms'):
            data['bedrooms'] = int(item['numberOfRooms'])
        if item.get('numberOfBathroomsTotal'):
            data['bathrooms'] = float(item['numberOfBathroomsTotal'])

        # Square footage
        floor_size = item.get('floorSize', {})
        if isinstance(floor_size, dict):
            value = floor_size.get('value')
            if value:
                data['sqft'] = int(float(value))

        # Description
        if item.get('description'):
            data['description'] = item['description'][:500]

        # Photos
        images = item.get('image', [])
        if isinstance(images, list):
            data['photos'] = [img for img in images if isinstance(img, str) and 'zillowstatic' in img][:10]
        elif isinstance(images, str):
            data['photos'] = [images]

        return data

    def _extract_next_data(self, soup: BeautifulSoup) -> Dict[str, Any]:
        """Extract data from Next.js __NEXT_DATA__ script."""
        data = {}

        try:
            script = soup.find('script', id='__NEXT_DATA__')
            if script and script.string:
                json_data = json.loads(script.string)

                # Navigate to property data
                props = json_data.get('props', {}).get('pageProps', {})
                property_data = props.get('componentProps', {}).get('gdpClientCache', {})

                # Parse the nested JSON string if present
                if property_data:
                    for key, value in property_data.items():
                        if isinstance(value, str):
                            try:
                                parsed = json.loads(value)
                                prop = parsed.get('property', {})
                                if prop:
                                    data.update(self._parse_zillow_property(prop))
                                    break
                            except json.JSONDecodeError:
                                continue

                # Also try initialReduxState
                redux_state = props.get('initialReduxState', {})
                gdp = redux_state.get('gdp', {})
                if gdp:
                    building = gdp.get('building', {})
                    if building:
                        data.update(self._parse_zillow_property(building))

        except Exception as e:
            logger.warning(f"Error extracting Next.js data: {e}")

        return data

    def _parse_zillow_property(self, prop: Dict) -> Dict[str, Any]:
        """Parse Zillow property object from their API."""
        data = {}

        # Address
        addr = prop.get('address', {})
        if addr:
            street = addr.get('streetAddress', '')
            city = addr.get('city', '')
            state = addr.get('state', '')
            zipcode = addr.get('zipcode', '')
            if street:
                data['address'] = f"{street}, {city}, {state} {zipcode}".strip(', ')

        # Price
        if prop.get('price'):
            data['price'] = int(prop['price'])

        # Beds/Baths/Sqft
        if prop.get('bedrooms'):
            data['bedrooms'] = int(prop['bedrooms'])
        if prop.get('bathrooms'):
            data['bathrooms'] = float(prop['bathrooms'])
        if prop.get('livingArea'):
            data['sqft'] = int(prop['livingArea'])

        # Year built
        if prop.get('yearBuilt'):
            data['year_built'] = int(prop['yearBuilt'])

        # Lot size
        if prop.get('lotSize'):
            data['lot_size'] = str(prop['lotSize'])

        # Property type
        if prop.get('homeType'):
            data['property_type'] = prop['homeType']

        # Description
        if prop.get('description'):
            data['description'] = prop['description'][:500]

        # Photos
        photos = prop.get('photos', []) or prop.get('responsivePhotos', [])
        if photos:
            photo_urls = []
            for photo in photos[:10]:
                if isinstance(photo, dict):
                    # Try different URL formats
                    url = photo.get('url') or photo.get('mixedSources', {}).get('jpeg', [{}])[0].get('url')
                    if url:
                        photo_urls.append(url)
                elif isinstance(photo, str):
                    photo_urls.append(photo)
            data['photos'] = photo_urls

        return data

    async def _extract_photos(self, page) -> List[str]:
        """Extract photo URLs from the listing page."""
        photos = []

        try:
            # Look for various image patterns
            selectors = [
                'img[src*="zillowstatic"][src*="photo"]',
                'img[src*="photos.zillowstatic"]',
                '[data-testid="media-stream"] img',
                'picture source[srcset*="zillowstatic"]',
                'img[alt*="property"]',
            ]

            for selector in selectors:
                try:
                    images = await page.query_selector_all(selector)
                    for img in images[:10]:
                        src = await img.get_attribute("src") or await img.get_attribute("srcset")
                        if src:
                            # Extract first URL from srcset if needed
                            if ',' in src:
                                src = src.split(',')[0].split(' ')[0]
                            # Filter out logos and icons
                            if 'zillowstatic' in src and 'logo' not in src.lower() and 'icon' not in src.lower():
                                # Get higher resolution version
                                src = re.sub(r'-cc_ft_\d+', '-cc_ft_960', src)
                                if src not in photos:
                                    photos.append(src)
                except Exception:
                    continue

                if len(photos) >= 10:
                    break

        except Exception as e:
            logger.warning(f"Error extracting photos: {e}")

        return photos[:10]

    async def scrape_search(
        self,
        location: str,
        filters: Optional[Dict] = None,
        max_results: int = 50
    ) -> List[Dict[str, Any]]:
        """
        Scrape Zillow search results.

        Args:
            location: Location to search (e.g., "Austin, TX")
            filters: Optional filters (min_price, max_price, beds, baths, etc.)
            max_results: Maximum number of results to return

        Returns:
            List of listing dictionaries
        """
        page = await self.create_page()
        filters = filters or {}

        try:
            # Build search URL
            location_slug = location.lower().replace(", ", "-").replace(" ", "-")
            search_url = f"{self.BASE_URL}/{location_slug}/"

            # Add filters to URL
            params = {}
            if filters.get("min_price"):
                params["price_min"] = filters["min_price"]
            if filters.get("max_price"):
                params["price_max"] = filters["max_price"]
            if filters.get("beds"):
                params["beds_min"] = filters["beds"]
            if filters.get("baths"):
                params["baths_min"] = filters["baths"]

            if params:
                search_url += "?" + urlencode(params)

            logger.info(f"Scraping Zillow search: {search_url}")

            # Navigate to search page - use domcontentloaded for faster loading
            await page.goto(search_url, wait_until="domcontentloaded", timeout=30000)

            # Wait for search results to appear
            try:
                await page.wait_for_selector(
                    '[data-test="property-card"], [class*="ListItem"]',
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
            cards = soup.select('[data-test="property-card"]') or soup.select('[class*="ListItem"]')

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
            data = {"source": "zillow"}

            # Get link
            link = card.select_one('a[href*="/homedetails/"]')
            if link:
                href = link.get("href", "")
                if href.startswith("/"):
                    href = self.BASE_URL + href
                data["url"] = href

            # Get address
            address_elem = card.select_one('address') or card.select_one('[data-test="property-card-addr"]')
            if address_elem:
                data["address"] = address_elem.get_text(strip=True)

            # Get price
            price_elem = card.select_one('[data-test="property-card-price"]') or card.select_one('[class*="price"]')
            if price_elem:
                price_text = price_elem.get_text(strip=True)
                match = re.search(r'[\$]?([\d,]+)', price_text)
                if match:
                    data["price"] = int(match.group(1).replace(',', ''))

            # Get beds/baths/sqft
            details = card.select_one('[class*="details"]') or card
            text = details.get_text(" ", strip=True).lower()

            beds_match = re.search(r'(\d+)\s*(?:bd|bed)', text)
            if beds_match:
                data["bedrooms"] = int(beds_match.group(1))

            baths_match = re.search(r'([\d.]+)\s*(?:ba|bath)', text)
            if baths_match:
                data["bathrooms"] = float(baths_match.group(1))

            sqft_match = re.search(r'([\d,]+)\s*(?:sqft|sq ft)', text)
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
