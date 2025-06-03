---
trigger: always_on
---

Strictly follow API specification in api/ directory. There are paths in api/models/{module}/*.yaml. There are schemas in api/components/schemas

Analyze source_code/interface/web/{module}/ to get insight about how data are fetch from DB and how there are saved to datalog.

There is no need to alter DB structure, so migrations are no needed.

## Common Fields
The following fields are present in most ISPConfig tables and handle permissions, ownership and server assignment:

```sql
-- System and Permission Fields
sys_userid int(11) unsigned NOT NULL default '0'      # Owner user ID
sys_groupid int(11) unsigned NOT NULL default '0'     # Owner group ID
sys_perm_user varchar(5) NULL default NULL           # Owner permissions (r=read, i=insert, u=update, d=delete)
sys_perm_group varchar(5) NULL default NULL          # Group permissions
sys_perm_other varchar(5) NULL default NULL          # Other permissions

-- Server Assignment (where applicable)
server_id int(11) unsigned NOT NULL default '0'      # Associated server ID

-- Common Status Fields
active enum('n','y') NOT NULL default 'y'           # Record active status
```

These fields implement ISPConfig's permission system, allowing granular control over who can view and modify records. Understanding these common fields is crucial for working with any table in the system.

## Database Change Management

ISPConfig implements a robust change management system through the `sys_datalog` table. This architectural pattern is critical for any application interfacing with the database, including REST APIs.

### Direct Database Modifications are Prohibited

**Important:** Database changes must never be made directly to the tables. Instead, all modifications must be logged through the `sys_datalog` table. This ensures:
- Proper change tracking
- System consistency
- Audit trail maintenance
- Proper event handling
- Asynchronous processing

### Datalog Structure
```sql
datalog_id int(11) unsigned NOT NULL AUTO_INCREMENT,
server_id int(11) unsigned NOT NULL DEFAULT '0',
dbtable varchar(255) NOT NULL DEFAULT '',    # Target table
dbidx varchar(255) NOT NULL DEFAULT '',      # Primary key value
action char(1) NOT NULL DEFAULT '',          # i=insert, u=update, d=delete
tstamp int(11) NOT NULL DEFAULT 0,           # Timestamp
user varchar(255) NOT NULL DEFAULT '',       # User performing action
data longtext DEFAULT NULL,                  # Changed data (serialized)
status set('pending','ok','warning','error') NOT NULL DEFAULT 'ok',
error mediumtext DEFAULT NULL,               # Error messages if any
session_id varchar(64) NOT NULL DEFAULT '',  # Session tracking
```

### Required System Fields
All database operations must include these Common Fields metioted above.

### Change Process
1. Instead of direct SQL operations:
   ```sql
   -- DO NOT USE direct SQL:
   INSERT INTO client (company_name, ...) VALUES ('New Corp', ...);
   UPDATE client SET company_name = 'Updated Corp' WHERE client_id = 1;
   DELETE FROM client WHERE client_id = 1;
   ```

2. Record changes in sys_datalog:
   ```sql
   INSERT INTO sys_datalog (
       dbtable, dbidx, action, data, status, tstamp, user
   ) VALUES (
       'client',                    -- Target table
       'client_id:1',              -- Primary key
       'u',                        -- Action (i/u/d)
       '[serialized data array]',  -- Old/new values
       'ok',                  -- Initial status
       UNIX_TIMESTAMP(),           -- Current time
       'api_user'                  -- Acting user
   );
   ```

3. The system processes these changes:
   - Validates the modification
   - Applies the change to the actual table
   - Updates the datalog status
   - Handles any related events or triggers

### REST API Implementation
When implementing a REST API:

1. POST/PUT/DELETE endpoints should:
   - Validate input data
   - Include required system fields
   - Write to sys_datalog, not directly to tables
   - Return appropriate status codes (202 Accepted for pending changes)

2. GET endpoints can:
   - Read directly from tables
   - Apply permission checks based on sys_perm fields
   - Include system fields in response