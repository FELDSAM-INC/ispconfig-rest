# ISPConfig REST API

## Overview

This is a REST API implementation for ISPConfig using the PHP Lumen framework. The API provides programmatic access to ISPConfig's functionality, following the API specifications defined in the `api/` directory.

## Key Features

- RESTful API endpoints for ISPConfig entities (clients, domains, etc.)
- Follows ISPConfig's datalog pattern for database changes
- Proper permission handling using ISPConfig's permission system
- API authentication via API keys
- Pagination, filtering, and sorting support

## Architecture

This API implementation follows the ISPConfig database change management system through the `sys_datalog` table. As specified in the ISPConfig architecture:

- Direct database modifications are prohibited
- All changes must be logged through the `sys_datalog` table
- System fields (sys_userid, sys_groupid, etc.) are included in all operations

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/yourusername/ispconfig_rest.git
   cd ispconfig_rest
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   ```
   Edit the `.env` file with your ISPConfig database credentials and other settings.

4. Generate application key:
   ```bash
   php artisan key:generate
   ```

5. Start the development server:
   ```bash
   php -S localhost:8000 -t public
   ```

## API Endpoints

### Clients

- `GET /api/v1/clients` - List all clients
- `GET /api/v1/clients/{id}` - Get a specific client
- `POST /api/v1/clients` - Create a new client
- `PUT /api/v1/clients/{id}` - Update a client
- `DELETE /api/v1/clients/{id}` - Delete a client

### Domains

- `GET /api/v1/domains` - List all domains
- `GET /api/v1/domains/{id}` - Get a specific domain
- `POST /api/v1/domains` - Create a new domain
- `PUT /api/v1/domains/{id}` - Update a domain
- `DELETE /api/v1/domains/{id}` - Delete a domain

### DataLog

- `GET /api/v1/datalog/status` - Get status of pending datalog entries
- `GET /api/v1/datalog/{id}` - Get details of a specific datalog entry

## Authentication

All API requests require an API key to be included in the request headers:

```
X-API-Key: your_api_key_here
```

## Response Format

All API responses are in JSON format. List endpoints return paginated results with the following structure:

```json
{
  "items": [...],
  "total": 100,
  "limit": 25,
  "offset": 0
}
```

## Error Handling

Errors are returned with appropriate HTTP status codes and a JSON response containing error details:

```json
{
  "error": "Error message"
}
```

## ISPConfig Database Integration

This API follows ISPConfig's database change management system:

1. Changes are not made directly to tables
2. All modifications are logged through the `sys_datalog` table
3. The ISPConfig system processes these changes asynchronously

This ensures proper change tracking, system consistency, and audit trail maintenance.

## License

This project is licensed under the BSD License - see the LICENSE file for details.
