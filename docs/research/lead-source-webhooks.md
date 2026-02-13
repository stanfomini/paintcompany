# Research: Lead Source Webhook/API Integration
*Generated: 2026-02-10*

## Requirements

Build a system where painting contractors can:
1. Create a unique webhook URL for each lead source (Angi, CraftJack, Thumbtack, etc.)
2. Receive a test payload to detect the incoming field structure
3. Map incoming fields to our Customer and Deal model fields
4. Auto-create customers and deals from incoming leads in real-time

---

## Lead Source Analysis

---

### 1. Angi Leads (formerly HomeAdvisor / Angie's List)

**Webhook Support**: YES - Angi pushes JSON via HTTP POST to your endpoint.

**Setup Process**:
- Email `crmintegrations@angi.com` (or `crmintegrations@homeadvisor.com`) with:
  - Your company name
  - Angi Leads account number (or phone number on file)
  - Your webhook endpoint URL (testing + production)
  - A CRM Key / API Key
- Angi configures the webhook on their side. You cannot self-serve set it up.
- Angi sends ALL fields -- they cannot customize what they send. Your endpoint must accept their full payload.

**Authentication**:
- `X-API-KEY` header sent with every request
- You provide the API key during setup; Angi includes it in headers
- Your endpoint should validate this header

**Test/Sandbox**:
- Angi provides both a testing and production endpoint URL setup
- They send test payloads during integration setup
- No self-service sandbox; testing is coordinated with their CRM integrations team

**Response Requirements**:
- Your endpoint must return a JSON response containing the word `"success"` to confirm receipt

**Payload Format**: JSON via HTTP POST

**Reconstructed JSON Payload** (assembled from multiple CRM integration docs):

```json
{
  "leadOid": 987654321,
  "srOid": 123456789,
  "spEntityId": "SP-12345",
  "spCompanyName": "Apex Painting Co",
  "firstName": "Jane",
  "lastName": "Doe",
  "address": "123 Main Street",
  "city": "Denver",
  "stateProvince": "CO",
  "postalCode": "80210",
  "primaryPhone": "3031234567",
  "phoneExt": "1234",
  "secondaryPhone": "3037654321",
  "secondaryPhoneExt": "5678",
  "email": "janedoe@gmail.com",
  "fee": 25.67,
  "taskName": "Interior Painting",
  "matchType": "Matched",
  "leadDescription": "Looking for interior painting for a 3-bedroom house",
  "comments": "I need my living room and two bedrooms painted. The house is about 2000 sqft.",
  "contactStatus": "New",
  "crmKey": "your-api-key-here",
  "leadSource": "Angi",
  "trustedFormUrl": "https://cert.trustedform.com/abc123",
  "automatedContactCompliant": true,
  "primaryPhoneDetails": {
    "maskedNumber": "(303) ***-4567"
  },
  "interview": [
    {
      "question": "What type of painting do you need?",
      "answer": "Interior painting"
    },
    {
      "question": "How many rooms?",
      "answer": "3 rooms"
    },
    {
      "question": "When do you need the work done?",
      "answer": "Within 2 weeks"
    },
    {
      "question": "What is your zip code?",
      "answer": "80210"
    }
  ]
}
```

**Key Fields for Our Mapping**:

| Angi Field | Our Customer Field | Our Deal Field |
|---|---|---|
| `firstName` | `first_name` | - |
| `lastName` | `last_name` | - |
| `email` | `email` | - |
| `primaryPhone` | `phone` | - |
| `address` | `address` | - |
| `city` | `city` | - |
| `stateProvince` | `state` | - |
| `postalCode` | `zip` | - |
| `comments` | `notes` | `description` |
| `taskName` | - | `title` |
| `leadOid` | - | (store as external_id) |
| `fee` | - | (store as lead_cost) |
| `interview` | - | (append to description) |
| - | `source` = 'angi' | `lead_source` = 'home_advisor' |

**SDKs/Official Integrations**: None. Webhook-only. Many CRMs use Zapier as a bridge.

**Documentation**: Not publicly available. Must request "ANGI Leads NEW JSON Lead Feed" document from `crmintegrations@angi.com`.

**Sources**:
- https://intercom.help/angi/en/articles/10288125-setting-up-api-integration-with-angi
- https://intercom.help/angi/en/articles/9047038-creating-a-webhook-in-zapier-for-custom-lead-integration-with-angi-ads
- https://help.builderprime.com/bp-knowledgebase/angi-integration
- https://serviceminder.knowledgeowl.com/help/integrations-lead-generation
- https://support.clienttether.com/home-advisor/

---

### 2. CraftJack

**Webhook Support**: YES - CraftJack POSTs to your URL when a new lead is matched.

**Setup Process**:
- Every Lead Manager account has built-in webhook configuration
- Self-service setup within the CraftJack Lead Manager dashboard
- Configure your endpoint URL, content type, and payload template

**Authentication**:
- Supports custom headers (key-value pairs)
- Example: `Authorization: Bearer YOUR_TOKEN` or `X-Api-Key: YOUR_KEY`
- Custom headers are configured in the CraftJack webhook settings

**Test/Sandbox**:
- CraftJack provides sample payloads you can use with Postman
- Send as `application/xml` (default) or your chosen content type
- No dedicated sandbox, but test payloads are available

**Default Payload Format**: XML (Content-Type: `application/xml`)

**Default XML Payload**:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Lead>
  <LeadId>12345</LeadId>
  <FirstName>John</FirstName>
  <LastName>Smith</LastName>
  <Address>456 Oak Avenue</Address>
  <City>Boulder</City>
  <State>CO</State>
  <ZipCode>80302</ZipCode>
  <PhoneNumber>3039876543</PhoneNumber>
  <DirectLeadPhoneNumber>3039876543</DirectLeadPhoneNumber>
  <EmailAddress>john.smith@email.com</EmailAddress>
  <ServiceId>101</ServiceId>
  <ServiceName>Interior Painting</ServiceName>
  <ServiceAreaName>Boulder County</ServiceAreaName>
  <Service>Painting</Service>
  <TimeFrame>Within 2 weeks</TimeFrame>
  <Notes>Need the entire interior painted, 4 bedrooms, 2 bathrooms</Notes>
  <OriginalPrice>35.00</OriginalPrice>
  <DiscountedPrice>28.00</DiscountedPrice>
  <TrustedForm>https://cert.trustedform.com/xyz789</TrustedForm>
  <CustomerType>Homeowner</CustomerType>
  <IpAddress>192.168.1.100</IpAddress>
  <AdditionalDetails>Looking for a professional painting company with good reviews</AdditionalDetails>
</Lead>
```

**Custom JSON Payload** (using template variables):

CraftJack supports custom payload templates with `{{fieldName}}` syntax. You can configure JSON instead:

```json
{
  "lead_id": "{{LeadId}}",
  "first_name": "{{FirstName}}",
  "last_name": "{{LastName}}",
  "address": "{{Address}}",
  "city": "{{City}}",
  "state": "{{State}}",
  "zip": "{{ZipCode}}",
  "phone": "{{DirectLeadPhoneNumber}}",
  "speed_dial_phone": "{{PhoneNumber}}",
  "email": "{{EmailAddress}}",
  "service_id": "{{ServiceId}}",
  "service_name": "{{ServiceName}}",
  "service_area": "{{ServiceAreaName}}",
  "service": "{{Service}}",
  "timeframe": "{{TimeFrame}}",
  "notes": "{{Notes}}",
  "original_price": "{{OriginalPrice}}",
  "discounted_price": "{{DiscountedPrice}}",
  "trusted_form": "{{TrustedForm}}",
  "customer_type": "{{CustomerType}}",
  "ip_address": "{{IpAddress}}",
  "additional_details": "{{AdditionalDetails}}"
}
```

**Available Template Variables**:
- `{{LeadId}}` - Unique lead identifier
- `{{FirstName}}` - Customer first name
- `{{LastName}}` - Customer last name
- `{{Address}}` - Street address
- `{{City}}` - City
- `{{State}}` - State abbreviation
- `{{ZipCode}}` - ZIP/postal code
- `{{PhoneNumber}}` - Speed-To-Call number (if enrolled) or direct number
- `{{DirectLeadPhoneNumber}}` - Always the lead's actual phone number
- `{{EmailAddress}}` - Customer email
- `{{ServiceId}}` - CraftJack service category ID
- `{{ServiceName}}` - Human-readable service name
- `{{ServiceAreaName}}` - Geographic service area
- `{{Service}}` - Service type
- `{{TimeFrame}}` - When the customer wants work done
- `{{Notes}}` - Customer's project description
- `{{OriginalPrice}}` - Lead cost before discounts
- `{{DiscountedPrice}}` - Lead cost after discounts
- `{{TrustedForm}}` - TrustedForm certificate URL
- `{{CustomerType}}` - Homeowner, Business, etc.
- `{{IpAddress}}` - Customer's IP address
- `{{AdditionalDetails}}` - Extra project details

**Key Fields for Our Mapping**:

| CraftJack Field | Our Customer Field | Our Deal Field |
|---|---|---|
| `FirstName` | `first_name` | - |
| `LastName` | `last_name` | - |
| `EmailAddress` | `email` | - |
| `DirectLeadPhoneNumber` | `phone` | - |
| `Address` | `address` | - |
| `City` | `city` | - |
| `State` | `state` | - |
| `ZipCode` | `zip` | - |
| `Notes` | `notes` | `description` |
| `ServiceName` | - | `title` |
| `LeadId` | - | (store as external_id) |
| `DiscountedPrice` | - | (store as lead_cost) |
| - | `source` = 'craftjack' | `lead_source` = 'other' |

**SDKs/Official Integrations**: None. Webhook-only with customizable templates.

**Documentation**: https://help.craftjack.com/article/31-what-are-web-hook-lead-notifications

**Sources**:
- https://help.craftjack.com/article/31-what-are-web-hook-lead-notifications

---

### 3. Thumbtack

**Webhook Support**: YES - Full developer platform with OAuth 2.0 and webhook-based Negotiations (Leads) API.

**Setup Process**:
1. Request developer access via the Thumbtack Partner Platform (Request Access button)
2. Thumbtack creates OAuth credentials for you in production
3. Implement OAuth 2.0 authorization code flow
4. After user authorizes, list their businesses via API
5. Create webhooks for each business using `POST /api/v4/businesses/{businessID}/webhooks`
6. Subscribe to `NegotiationCreatedV4` event type

**Authentication**:
- OAuth 2.0 (authorization code flow)
- Thumbtack provides client_id and client_secret
- Users authorize your app to access their Thumbtack account
- Access tokens used for API calls

**Test/Sandbox**:
- Thumbtack provides a staging environment during developer onboarding
- Mirrors the full Thumbtack product experience (customer + pro side)
- Can use https://webhook.site for testing webhook behavior
- End-to-end testing possible in staging

**API Endpoints**:
- Base URL: `https://api.thumbtack.com`
- Developer Portal: https://developers.thumbtack.com/
- API Reference: https://api.thumbtack.com/docs/
- Create Webhook: `POST /api/v4/businesses/{businessID}/webhooks`
- List Businesses: `GET /api/v4/businesses`
- Get Negotiation: `GET /api/v4/businesses/{businessID}/negotiations/{negotiationID}`
- Send Message: `POST /api/v4/businesses/{businessID}/negotiations/{negotiationID}/messages`

**Webhook Event Types**:
- `NegotiationCreatedV4` - New lead received
- `MessageCreatedV4` - New message in a lead conversation

**Payload Format**: JSON via HTTP POST

**Complete Webhook Payload Example** (NegotiationCreatedV4):

```json
{
  "leadID": "380497493950742534",
  "createTimestamp": "1747102856",
  "price": "$258/hour",
  "leadType": "ESTIMATION",
  "request": {
    "requestID": "380497492346044421",
    "category": "Interior Painting",
    "categoryID": "1234567890",
    "title": "Interior Painting",
    "description": "I need my living room and kitchen painted",
    "schedule": "Dates: May 13, May 14, May 15\nTimes: Morning (9am - 12pm), Afternoon (12pm - 3pm)",
    "location": {
      "address1": "",
      "address2": "",
      "city": "San Francisco",
      "state": "CA",
      "zipCode": "94103"
    },
    "travelPreferences": "Professional must travel to my address",
    "details": [
      {
        "question": "What type of painting do you need?",
        "answer": "Interior painting"
      },
      {
        "question": "How many rooms?",
        "answer": "2-3 rooms"
      },
      {
        "question": "Scheduling",
        "answer": "Flexible"
      },
      {
        "question": "Travel Preferences",
        "answer": "Professional must travel to my address"
      }
    ]
  },
  "customer": {
    "customerID": "380497491930800133",
    "name": "John Davis"
  },
  "business": {
    "businessID": "380497492389642246",
    "name": "Apex Painting Co"
  }
}
```

**Key Fields for Our Mapping**:

| Thumbtack Field | Our Customer Field | Our Deal Field |
|---|---|---|
| `customer.name` | `first_name` + `last_name` (split) | - |
| `request.location.city` | `city` | - |
| `request.location.state` | `state` | - |
| `request.location.zipCode` | `zip` | - |
| `request.location.address1` | `address` | - |
| `request.description` | `notes` | `description` |
| `request.title` / `request.category` | - | `title` |
| `leadID` | - | (store as external_id) |
| `request.details` | - | (append to description) |
| `request.schedule` | - | (parse for expected_start_date) |
| - | `source` = 'thumbtack' | `lead_source` = 'thumbtack' |

**Important Notes**:
- Thumbtack only provides `customer.name` (full name), NOT separate first/last -- you must split it
- `request.location.address1` is often empty; only city/state/zip are reliable
- Phone and email are NOT in the webhook payload -- they come through Thumbtack messaging
- The `price` field is the customer's budget expectation, not a lead cost
- `leadType` can be `ESTIMATION` or other values

**SDKs/Official Integrations**: No official SDKs. REST API with OAuth 2.0. Well-documented developer portal.

**Documentation**:
- https://developers.thumbtack.com/
- https://developers.thumbtack.com/docs/negotiations/implementation
- https://api.thumbtack.com/docs/
- https://developers.thumbtack.com/guides/2h0b5q80VWk2mEqqMduxuJ
- https://developers.thumbtack.com/guides/45ZPNwtrN2YGqXHUarpg4m (Testing)
- https://developers.thumbtack.com/guides/2Hqdq7prrqyDfW2HvoPRXb (OAuth)

**Sources**:
- https://developers.thumbtack.com/docs/negotiations/implementation
- https://developers.thumbtack.com/guides/2h0b5q80VWk2mEqqMduxuJ
- https://help.thumbtack.com/article/how-to-create-a-custom-lead-integration
- https://help.supermove.com/hc/en-us/articles/37468756060436-Thumbtack-Integration

---

## Comparison Table

| Feature | Angi Leads | CraftJack | Thumbtack |
|---|---|---|---|
| **Webhook Push** | Yes (JSON POST) | Yes (XML default, customizable) | Yes (JSON POST) |
| **Self-Service Setup** | No (email their team) | Yes (Lead Manager dashboard) | Partial (dev portal + OAuth) |
| **Auth Method** | X-API-KEY header | Custom headers | OAuth 2.0 |
| **Payload Format** | JSON | XML (or custom JSON template) | JSON |
| **Customer Name** | firstName + lastName | FirstName + LastName | Single "name" field |
| **Phone** | primaryPhone | DirectLeadPhoneNumber | NOT in webhook (via messaging) |
| **Email** | email | EmailAddress | NOT in webhook (via messaging) |
| **Address** | Full address fields | Full address fields | City/state/zip only (usually) |
| **Project Details** | taskName + interview[] | ServiceName + Notes | category + details[] |
| **Lead Cost** | fee | OriginalPrice/DiscountedPrice | N/A |
| **Test/Sandbox** | Coordinated with Angi team | Postman sample payloads | Full staging environment |
| **SDK** | None | None | None (REST API) |
| **Documentation** | Private (request via email) | Public knowledge base | Full developer portal |
| **Complexity** | Low (receive-only) | Low (receive-only) | High (OAuth + API calls) |

---

## Field Mapping UX Research

### LeadsBridge Pattern

LeadsBridge uses a "Bridge" concept connecting a Source to a Destination:

**Step 1: Source Configuration**
- Select source (e.g., "Webhook")
- Generate a unique webhook URL
- Configure authentication

**Step 2: Auto-Detection**
- Send a test lead to the webhook URL
- LeadsBridge automatically detects and parses all fields from the payload
- Fields appear in a list for mapping

**Step 3: Field Mapping Interface**
- Two-column layout: Source fields (left) -> Destination fields (right)
- Destination fields show field name with the CRM logo
- Required fields marked with * (star)
- "Show unmapped fields" button reveals all available destination fields not yet mapped
- Dropdown to add additional destination fields
- Transform functions available (reformat dates, modify text, phone numbers)
- Leave blank to skip a field

**Step 4: Filters (Optional)**
- Add conditions to filter incoming leads
- Example: Only process leads where `taskName` contains "painting"

**Step 5: Test**
- "Test Connection" button sends a sample lead through the mapping
- Verify the lead appears correctly in the destination system

**Key UX Insight**: LeadsBridge's power is in auto-detecting fields from a test payload, then showing a simple drag-and-drop / dropdown mapping interface.

**Sources**:
- https://leadsbridge.com/documentation/webhook/
- https://leadsbridge.com/documentation/fields-mapping/
- https://leadsbridge.com/documentation/leadsbridge-functions/

---

### Zapier "Catch Hook" Pattern

**Step 1: Create Trigger**
- Select "Webhooks by Zapier" -> "Catch Hook"
- Zapier generates a unique webhook URL (e.g., `https://hooks.zapier.com/hooks/catch/123456/abcdef/`)
- Optionally specify a "Pick Off Child Key" to extract a subset of data

**Step 2: Send Test Request**
- User sends a sample request to the URL (or triggers from the source app)
- Zapier waits and captures the payload
- All fields are automatically parsed and separated
- Nested JSON is flattened with double-underscore notation (e.g., `request__location__city`)

**Step 3: Map Fields in Action**
- In the "Action" step (e.g., "Create CRM Contact"), each field has a dropdown
- Dropdown shows "Insert Data" with all detected fields from Step 2
- Fields labeled with their key names (e.g., `firstName`, `customer__name`)
- User clicks a field to insert it as a dynamic value

**Step 4: Test**
- "Test Step" sends the captured data through the action
- Shows success/failure with the created record

**Key UX Insights**:
- URL is generated instantly -- no configuration needed upfront
- "Send test request" + "I did this" button pattern is very intuitive
- Nested data flattened with `__` separator for simplicity
- Field selection via searchable dropdown, not drag-and-drop
- Each destination field has its own picker (not a two-column mapping view)

**Sources**:
- https://help.zapier.com/hc/en-us/articles/8496083355661-How-to-get-started-with-Webhooks-by-Zapier
- https://help.zapier.com/hc/en-us/articles/8496288690317-Trigger-Zaps-from-webhooks

---

### Make.com Webhook Pattern

**Step 1: Create Custom Webhook**
- Add "Webhooks > Custom Webhook" module as trigger
- Make.com generates a unique URL
- URL is immediately ready to receive data

**Step 2: Determine Data Structure**
- Click "Re-determine data structure" in the module settings
- Make.com enters a "listening" mode
- Send your test payload to the webhook URL
- Make.com parses the payload and creates a data structure

**Step 3: Refine Structure**
- Editor allows renaming fields to more readable names
- Adjust field types (string, number, date, etc.)
- Remove fields you don't want exposed for mapping
- Save the structure

**Step 4: Map to Downstream Modules**
- Click next module in the scenario
- Open the mapping panel
- Choose values from the webhook output (body, headers, query params)
- Visual drag-from-source-to-target interface
- Supports expressions and transformations

**Key UX Insights**:
- "Listening mode" is elegant -- generate URL, click listen, send data, auto-detect
- Field renaming/type adjustment after detection is useful
- Module-based architecture makes it modular
- Visual mapping panel with clear source -> target flow

**Sources**:
- https://help.make.com/webhooks
- https://www.help.make.com/en/help/tools/webhooks

---

## Recommended Architecture for Apex

### Database Schema

```
webhook_endpoints
├── id
├── name (e.g., "Angi Leads", "CraftJack", "My Thumbtack")
├── slug (URL-safe, unique)
├── token (UUID, used in webhook URL)
├── source_type (angi, craftjack, thumbtack, generic)
├── is_active (boolean)
├── auth_type (none, api_key, bearer, hmac)
├── auth_value (encrypted - the expected API key / token)
├── auth_header (e.g., "X-API-KEY", "Authorization")
├── last_payload_at (timestamp)
├── sample_payload (JSON - stored from test request)
├── created_by (user_id)
├── timestamps

webhook_field_mappings
├── id
├── webhook_endpoint_id (FK)
├── source_path (dot-notation: "request.location.city", "firstName")
├── target_model (customer, deal, property)
├── target_field (first_name, city, title, description, etc.)
├── transform (null, split_first_name, split_last_name, append, etc.)
├── is_active (boolean)
├── sort_order
├── timestamps

webhook_logs
├── id
├── webhook_endpoint_id (FK)
├── customer_id (FK, nullable - created customer)
├── deal_id (FK, nullable - created deal)
├── payload (JSON - full incoming payload)
├── status (received, processed, failed, duplicate)
├── error_message (nullable)
├── ip_address
├── processed_at (timestamp)
├── timestamps
```

### Webhook URL Format

```
https://apexnegotiations.com/api/webhooks/{token}
```

Example: `https://apexnegotiations.com/api/webhooks/a1b2c3d4-e5f6-7890-abcd-ef1234567890`

### Processing Flow

```
1. POST hits /api/webhooks/{token}
2. Validate token exists and endpoint is active
3. Validate authentication (check X-API-KEY header, Bearer token, etc.)
4. Store raw payload in webhook_logs
5. Parse JSON (or XML for CraftJack default)
6. Apply field mappings to extract data
7. Check for duplicate (by email + phone, or external_id)
8. Create Customer (or find existing)
9. Create Deal (stage = 'lead', lead_source from source_type)
10. Create Property (from address fields)
11. Log activity on customer and deal
12. Return success response (Angi needs "success" in body)
13. Optionally: dispatch notification to assigned user
```

### UX Flow (Settings Page)

**Step 1: Create Webhook**
- User enters a name (e.g., "Angi Leads")
- Selects source type (Angi, CraftJack, Thumbtack, Generic)
- System generates unique webhook URL + auto-configures auth based on source type
- Shows instructions specific to the chosen source

**Step 2: Test & Detect**
- "Waiting for test payload..." state with animated indicator
- User sends test from source (or clicks "Send test payload" for built-in presets)
- System captures payload, parses all fields, flattens nested objects with dot notation
- Shows the raw JSON and a list of detected fields

**Step 3: Map Fields**
- Two-column interface:
  - LEFT: Detected source fields (with sample values shown)
  - RIGHT: Dropdown for each -> our Customer/Deal/Property fields
- Pre-populated with smart defaults based on source_type:
  - Angi: `firstName` -> `customer.first_name`, `primaryPhone` -> `customer.phone`, etc.
  - CraftJack: `FirstName` -> `customer.first_name`, etc.
  - Thumbtack: `customer.name` -> `customer.first_name` (with split transform)
- Transform options: Split Name, Format Phone, Append Text, Default Value
- "Unmapped fields" section at bottom

**Step 4: Configure Options**
- Default deal stage (default: 'lead')
- Default assigned user (optional)
- Duplicate handling (skip, update, create new)
- Notifications (email alert when new lead arrives)

**Step 5: Activate**
- Toggle switch to go live
- Dashboard shows: total leads received, last lead time, error count

### Source-Specific Presets

For each known source type, pre-configure the field mappings:

**Angi Preset**:
```php
[
    ['source_path' => 'firstName',      'target_model' => 'customer', 'target_field' => 'first_name'],
    ['source_path' => 'lastName',       'target_model' => 'customer', 'target_field' => 'last_name'],
    ['source_path' => 'email',          'target_model' => 'customer', 'target_field' => 'email'],
    ['source_path' => 'primaryPhone',   'target_model' => 'customer', 'target_field' => 'phone'],
    ['source_path' => 'address',        'target_model' => 'customer', 'target_field' => 'address'],
    ['source_path' => 'city',           'target_model' => 'customer', 'target_field' => 'city'],
    ['source_path' => 'stateProvince',  'target_model' => 'customer', 'target_field' => 'state'],
    ['source_path' => 'postalCode',     'target_model' => 'customer', 'target_field' => 'zip'],
    ['source_path' => 'comments',       'target_model' => 'customer', 'target_field' => 'notes'],
    ['source_path' => 'taskName',       'target_model' => 'deal',     'target_field' => 'title'],
    ['source_path' => 'comments',       'target_model' => 'deal',     'target_field' => 'description'],
    ['source_path' => 'leadOid',        'target_model' => 'deal',     'target_field' => 'external_id'],
]
```

**CraftJack Preset** (using custom JSON template):
```php
[
    ['source_path' => 'FirstName',              'target_model' => 'customer', 'target_field' => 'first_name'],
    ['source_path' => 'LastName',               'target_model' => 'customer', 'target_field' => 'last_name'],
    ['source_path' => 'EmailAddress',           'target_model' => 'customer', 'target_field' => 'email'],
    ['source_path' => 'DirectLeadPhoneNumber',  'target_model' => 'customer', 'target_field' => 'phone'],
    ['source_path' => 'Address',                'target_model' => 'customer', 'target_field' => 'address'],
    ['source_path' => 'City',                   'target_model' => 'customer', 'target_field' => 'city'],
    ['source_path' => 'State',                  'target_model' => 'customer', 'target_field' => 'state'],
    ['source_path' => 'ZipCode',                'target_model' => 'customer', 'target_field' => 'zip'],
    ['source_path' => 'Notes',                  'target_model' => 'customer', 'target_field' => 'notes'],
    ['source_path' => 'ServiceName',            'target_model' => 'deal',     'target_field' => 'title'],
    ['source_path' => 'Notes',                  'target_model' => 'deal',     'target_field' => 'description'],
    ['source_path' => 'LeadId',                 'target_model' => 'deal',     'target_field' => 'external_id'],
]
```

**Thumbtack Preset**:
```php
[
    ['source_path' => 'customer.name',           'target_model' => 'customer', 'target_field' => 'first_name', 'transform' => 'split_first_name'],
    ['source_path' => 'customer.name',           'target_model' => 'customer', 'target_field' => 'last_name',  'transform' => 'split_last_name'],
    ['source_path' => 'request.location.city',   'target_model' => 'customer', 'target_field' => 'city'],
    ['source_path' => 'request.location.state',  'target_model' => 'customer', 'target_field' => 'state'],
    ['source_path' => 'request.location.zipCode','target_model' => 'customer', 'target_field' => 'zip'],
    ['source_path' => 'request.location.address1','target_model' => 'customer', 'target_field' => 'address'],
    ['source_path' => 'request.description',     'target_model' => 'customer', 'target_field' => 'notes'],
    ['source_path' => 'request.category',        'target_model' => 'deal',     'target_field' => 'title'],
    ['source_path' => 'request.description',     'target_model' => 'deal',     'target_field' => 'description'],
    ['source_path' => 'leadID',                  'target_model' => 'deal',     'target_field' => 'external_id'],
]
```

### Transforms Available

| Transform | Description | Example |
|---|---|---|
| `split_first_name` | Take first word from a full name | "John Davis" -> "John" |
| `split_last_name` | Take everything after first word | "John Davis" -> "Davis" |
| `format_phone` | Normalize to 10-digit format | "(303) 123-4567" -> "3031234567" |
| `append` | Append to existing value with newline | Combine comments + interview |
| `default` | Use a static value if source is empty | "" -> "No description provided" |
| `truncate` | Limit string length | Long text -> 255 chars |
| `map_value` | Map source value to our enum | "Interior Painting" -> "interior" |
| `join_details` | Join Q&A array into text | details[] -> "Q: ... A: ..." |

### Our Target Fields (Customer + Deal + Property)

**Customer Fields**:
- `first_name` (required)
- `last_name` (required)
- `email`
- `phone`
- `address`
- `city`
- `state`
- `zip`
- `notes`
- `source` (auto-set from webhook source_type)
- `status` (auto-set to 'active')

**Deal Fields**:
- `title` (required - falls back to "New Lead from {source}")
- `description`
- `stage` (auto-set to 'lead')
- `lead_source` (auto-set from webhook source_type)
- `estimated_value`
- `expected_start_date`
- `external_id` (new field - for deduplication)

**Property Fields** (auto-created from Customer address via existing booted hook):
- `address`
- `city`
- `state`
- `zip`
- `type` (default 'residential')

---

## Implementation Recommendation

### Phase 1: Core Webhook Receiver (MVP)
- `webhook_endpoints` table + model
- `webhook_logs` table + model
- API controller to receive webhooks at `/api/webhooks/{token}`
- Basic JSON parsing and logging
- Settings page to create/manage webhook endpoints
- Pre-built Angi + CraftJack + Thumbtack presets (hardcoded mappings)
- Auto-create Customer + Deal from known formats

### Phase 2: Field Mapping UI
- `webhook_field_mappings` table + model
- "Send test payload" detection with auto-field-discovery
- Two-column mapping interface (Livewire SFC)
- Transform functions
- Custom/generic webhook support for any source

### Phase 3: Advanced Features
- Duplicate detection and merge logic
- Webhook delivery retry / failure notifications
- Lead assignment rules (round-robin, by zip code, etc.)
- Real-time notification when leads arrive (broadcasting)
- Thumbtack OAuth flow (full 2-way integration)
- Analytics dashboard (leads by source, conversion rates)

---

## Technical Notes

### CraftJack XML Parsing
CraftJack defaults to XML. Two options:
1. **Tell CraftJack to send JSON** using their custom payload template feature (recommended)
2. **Parse XML** in our controller using `simplexml_load_string()` and convert to array

Recommendation: Provide instructions to the user on how to configure CraftJack to send JSON using their template feature. This keeps our webhook controller JSON-only.

### Angi Response Format
Angi requires the response body to contain the word "success". Our controller should return:
```php
return response()->json(['status' => 'success', 'message' => 'Lead received']);
```

### Thumbtack Complexity
Thumbtack requires OAuth 2.0 and webhook creation via API. This is significantly more complex than Angi/CraftJack. For Phase 1, support Thumbtack via their "custom lead integration" feature (simpler webhook approach described at https://help.thumbtack.com/article/how-to-create-a-custom-lead-integration) rather than the full Partner Platform API.

### Add `external_id` to Deals
The Deal model needs an `external_id` field to store the lead source's unique ID (Angi's `leadOid`, CraftJack's `LeadId`, Thumbtack's `leadID`). This enables deduplication.

### Add New Customer Sources
Customer.SOURCES and Deal.LEAD_SOURCES should be updated:
- Customer::SOURCES needs `'angi'`, `'craftjack'`, `'thumbtack'`
- Deal::LEAD_SOURCES already has `'home_advisor'` and `'thumbtack'`; add `'craftjack'`
