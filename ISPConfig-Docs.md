# ISPConfig Database Documentation

## Technical Notation Guide

The following notation is used throughout the database schema for concise representation:

### Data Types
```
i   = int
ui  = unsigned int
bi  = bigint
v   = varchar
t   = text
dt  = datetime
ts  = timestamp
e   = enum
b   = blob/enum('n','y')
d   = date
c   = char
m   = mediumtext
dec = decimal(5,2)
```

### Constraints and Defaults
```
*   = NOT NULL constraint
.   = has default value
->  = references (foreign key)
_   = prefix grouping (e.g., sys_*)
set: = SET type with allowed values
```

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
       'pending',                  -- Initial status
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

## 1. Client Resources

### Client Management (`client` table)
Main table for storing client and reseller information.

**Primary Key:**
```sql
client_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**System Fields:**
```sql
sys_userid int(11) unsigned NOT NULL DEFAULT '0'
sys_groupid int(11) unsigned NOT NULL DEFAULT '0'
sys_perm_user varchar(5) DEFAULT NULL
sys_perm_group varchar(5) DEFAULT NULL
sys_perm_other varchar(5) DEFAULT NULL
```

**Basic Information:**
```sql
company_name varchar(64) DEFAULT NULL
company_id varchar(255) DEFAULT NULL
gender enum('','m','f') NOT NULL DEFAULT ''
contact_firstname varchar(64) NOT NULL DEFAULT ''
contact_name varchar(64) DEFAULT NULL
customer_no varchar(64) DEFAULT NULL
vat_id varchar(64) DEFAULT NULL
```

**Contact Information:**
```sql
street varchar(255) DEFAULT NULL
zip varchar(32) DEFAULT NULL
city varchar(64) DEFAULT NULL
state varchar(32) DEFAULT NULL
country char(2) DEFAULT NULL
telephone varchar(32) DEFAULT NULL
mobile varchar(32) DEFAULT NULL
fax varchar(32) DEFAULT NULL
email varchar(255) DEFAULT NULL
internet varchar(255) NOT NULL DEFAULT ''
icq varchar(16) DEFAULT NULL
notes text
```

**Banking Information:**
```sql
bank_account_owner varchar(255) DEFAULT NULL
bank_account_number varchar(255) DEFAULT NULL
bank_code varchar(255) DEFAULT NULL
bank_name varchar(255) DEFAULT NULL
bank_account_iban varchar(255) DEFAULT NULL
bank_account_swift varchar(255) DEFAULT NULL
paypal_email varchar(255) DEFAULT NULL
```

**Server Assignments:**
```sql
default_mailserver int(11) unsigned NOT NULL DEFAULT '1'
default_xmppserver int(11) unsigned NOT NULL DEFAULT '1'
default_webserver int(11) unsigned NOT NULL DEFAULT '1'
default_dnsserver int(11) unsigned NOT NULL DEFAULT '1'
default_slave_dnsserver int(11) unsigned NOT NULL DEFAULT '1'
default_dbserver int(11) NOT NULL DEFAULT '1'

mail_servers text
xmpp_servers text
web_servers text
dns_servers text
db_servers text
```

**Web Specific Settings:**
```sql
web_php_options varchar(255) NOT NULL DEFAULT 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm'
ssh_chroot varchar(255) NOT NULL DEFAULT 'no,jailkit,ssh-chroot'
limit_web_ip text
force_suexec enum('n','y') NOT NULL DEFAULT 'y'
```

**XMPP Settings:**
```sql
limit_xmpp_auth_options varchar(255) NOT NULL DEFAULT 'plain,hashed,isp'
```

**Authentication & Access:**
```sql
username varchar(64) DEFAULT NULL
password varchar(200) DEFAULT NULL
language char(2) NOT NULL DEFAULT 'en'
usertheme varchar(32) NOT NULL DEFAULT 'default'
can_use_api enum('n','y') NOT NULL DEFAULT 'n'
```

**Template Management:**
```sql
template_master int(11) unsigned NOT NULL DEFAULT '0'
template_additional text
```

**Customer Numbering:**
```sql
customer_no_template varchar(255) DEFAULT 'R[CLIENTID]C[CUSTOMER_NO]'
customer_no_start int(11) NOT NULL DEFAULT '1'
customer_no_counter int(11) NOT NULL DEFAULT '0'
```

**Status & Security:**
```sql
locked enum('n','y') NOT NULL DEFAULT 'n'
canceled enum('n','y') NOT NULL DEFAULT 'n'
created_at bigint(20) DEFAULT NULL
added_date date NULL DEFAULT NULL
added_by varchar(255) DEFAULT NULL
validation_status enum('accept','review','reject') NOT NULL DEFAULT 'accept'
risk_score int(10) unsigned NOT NULL DEFAULT '0'
activation_code varchar(10) NOT NULL DEFAULT ''
tmp_data mediumblob
id_rsa text
ssh_rsa varchar(600) NOT NULL DEFAULT ''
```

**Reseller Related:**
```sql
parent_client_id int(11) unsigned NOT NULL DEFAULT '0'
limit_client int(11) NOT NULL DEFAULT '0'
```

**Template Related Fields:**
```sql
template_master int(11)         # Master template ID
template_additional text        # Additional template settings
```

**Client Information:**
```sql
company_name varchar(64)
contact_name varchar(64)
contact_firstname varchar(64)
customer_no varchar(64)
username varchar(64)            # Login credentials
password varchar(200)
```

**System Fields:**
```sql
sys_userid int(11)
sys_groupid int(11)
sys_perm_user varchar(5)
sys_perm_group varchar(5)
sys_perm_other varchar(5)
```

### Limit Templates (`client_template`)
Templates defining resource limits that can be applied to clients.

**Primary Key:**
- `template_id` int(11) unsigned AUTO_INCREMENT

**Key Fields:**
```sql
template_name varchar(64)
template_type varchar(1)        # 'm' for master template
```

**Resource Limits:**
```sql
# Mail Limits
limit_maildomain int(11) NOT NULL default '-1'          # Number of mail domains
limit_mailbox int(11) NOT NULL default '-1'             # Number of mailboxes
limit_mailalias int(11) NOT NULL default '-1'           # Number of mail aliases
limit_mailaliasdomain int(11) NOT NULL default '-1'     # Number of domain aliases
limit_mailforward int(11) NOT NULL default '-1'         # Number of forwarders
limit_mailcatchall int(11) NOT NULL default '-1'        # Number of catchall accounts
limit_mailrouting int(11) NOT NULL default '0'          # Number of mail routes
limit_mail_wblist int(11) NOT NULL default '0'          # Number of whitelist/blacklist entries
limit_mailfilter int(11) NOT NULL default '-1'          # Number of mail filters
limit_fetchmail int(11) NOT NULL default '-1'           # Number of fetchmail entries
limit_mailquota int(11) NOT NULL default '-1'           # Mail quota in MB
limit_spamfilter_wblist int(11) NOT NULL default '0'    # Number of spamfilter white/blacklist entries
limit_spamfilter_user int(11) NOT NULL default '0'      # Number of spamfilter users
limit_spamfilter_policy int(11) NOT NULL default '0'    # Number of spamfilter policies
limit_mail_backup enum('n','y') NOT NULL default 'y'    # Allow mail backups
limit_relayhost enum('n','y') NOT NULL default 'n'      # Allow relay host configuration

# Web Limits
limit_web_domain int(11) NOT NULL default '-1'          # Number of web domains
limit_web_quota int(11) NOT NULL default '-1'           # Web quota in MB
limit_web_subdomain int(11) NOT NULL default '-1'       # Number of web subdomains
limit_web_aliasdomain int(11) NOT NULL default '-1'     # Number of web aliasdomains
limit_ftp_user int(11) NOT NULL default '-1'            # Number of FTP users
limit_shell_user int(11) NOT NULL default '0'           # Number of shell users
limit_webdav_user int(11) NOT NULL default '0'          # Number of webdav users
limit_cgi enum('n','y') NOT NULL default 'n'            # Allow CGI
limit_ssi enum('n','y') NOT NULL default 'n'            # Allow SSI
limit_perl enum('n','y') NOT NULL default 'n'           # Allow Perl
limit_ruby enum('n','y') NOT NULL default 'n'           # Allow Ruby
limit_python enum('n','y') NOT NULL default 'n'         # Allow Python
limit_ssl enum('n','y') NOT NULL default 'n'            # Allow SSL
limit_ssl_letsencrypt enum('n','y') NOT NULL default 'n' # Allow Lets Encrypt
limit_backup enum('n','y') NOT NULL default 'y'         # Allow backups
limit_directive_snippets enum('n','y') NOT NULL default 'n' # Allow directive snippets
limit_aps int(11) NOT NULL default '-1'                 # Number of APS installs

# DNS Limits
limit_dns_zone int(11) NOT NULL default '-1'            # Number of DNS zones
limit_dns_slave_zone int(11) NOT NULL default '-1'      # Number of secondary DNS zones
limit_dns_record int(11) NOT NULL default '-1'          # Number of DNS records

# Database Limits
limit_database int(11) NOT NULL default '-1'            # Number of databases
limit_database_user int(11) NOT NULL default '-1'       # Number of database users
limit_database_quota int(11) NOT NULL default '-1'      # Database quota

# Cron Limits
limit_cron int(11) NOT NULL default '0'                 # Number of cron jobs
limit_cron_type enum('url','chrooted','full')          # Type of cron jobs allowed
limit_cron_frequency int(11) NOT NULL default '5'       # Minimum cron frequency

# Traffic Limits
limit_traffic_quota int(11) NOT NULL default '-1'       # Traffic quota in MB

# XMPP Limits
limit_xmpp_domain int(11) NOT NULL default '-1'         # Number of XMPP domains
limit_xmpp_user int(11) NOT NULL default '-1'           # Number of XMPP users
limit_xmpp_muc enum('n','y') NOT NULL default 'n'      # Allow MUC (Multi-User Chat)
limit_xmpp_anon enum('n','y') NOT NULL default 'n'     # Allow anonymous XMPP
limit_xmpp_vjud enum('n','y') NOT NULL default 'n'     # Allow XMPP user directory
limit_xmpp_proxy enum('n','y') NOT NULL default 'n'    # Allow XMPP proxy
limit_xmpp_status enum('n','y') NOT NULL default 'n'   # Allow XMPP status
limit_xmpp_pastebin enum('n','y') NOT NULL default 'n' # Allow XMPP pastebin
limit_xmpp_httparchive enum('n','y') NOT NULL default 'n' # Allow XMPP HTTP archive

# Other Limits
limit_client int(11) NOT NULL default '0'               # Number of sub-clients (reseller)
limit_mailmailinglist int(11) NOT NULL default '-1'     # Number of mailing lists
limit_openvz_vm int(11) NOT NULL default '0'            # Number of OpenVZ VMs
limit_domainmodule int(11) NOT NULL default '0'         # Domain module limit
```

**Template Assignment (`client_template_assigned`):**
```sql
assigned_template_id bigint(20)  # Primary Key
client_id bigint(11)            # References client table
client_template_id int(11)      # References client_template table
```

Notes:
- Master template is referenced via client.template_master
- Addon templates are assigned through client_template_assigned
- All template assignments (both master and addon) directly update the limit_* fields in the client table
- The limit values in the client table are the actual effective limits, regardless of template source

### Client Circle (`client_circle`)
Groups of clients for easier management.

**Primary Key:**
- `circle_id` int(11)

**Key Fields:**
```sql
circle_name varchar(64)
client_ids text                 # List of client IDs in this circle
description text
active enum('n','y')
```

### Email Templates (`client_message_template`)
Templates for client communications.

**Primary Key:**
- `client_message_template_id` bigint(20)

**Key Fields:**
```sql
template_type varchar(255)
template_name varchar(255)
subject varchar(255)
message text
```

### Domains (`domain`)
Central domain registry for services.

**Primary Key:**
- `domain_id` int(11) unsigned

**Key Fields:**
```sql
domain varchar(255)             # Domain name
sys_userid int(11)             # Owner client ID
```

Notes:
- Domains must be registered here before being used in web, mail, or DNS services
- Acts as a central domain ownership registry
- Referenced by web_domain, mail_domain, and dns_soa tables

### Key Relationships

1. Client Hierarchy:
   - Resellers are clients with limit_client > 0
   - Regular clients have parent_client_id pointing to their reseller
   - Resellers can only create up to limit_client number of clients

2. Template Application:
   - Each client has one master template (template_master)
   - Additional templates (addons) are assigned through client_template_assigned
   - Limits are cumulative when addon templates are assigned

3. Domain Management:
   - Domains are first registered in domain table
   - Then can be used for:
     - Web hosting (web_domain table)
     - Mail services (mail_domain table)
     - DNS zones (dns_soa table)

# Sites (`web_domain` table)

## Domain Types
The `type` field in web_domain table defines the domain's role:
```sql
type varchar(32) default NULL
```

### Website (type='vhost')
- Primary website domain
- Has its own document_root, system user/group
- Parent for all other types (parent_domain_id = 0)
- Requires server resource configuration (document_root, system_user, system_group)

### Aliasdomain (type='alias')
- Simple alias pointing to a Website
- No separate document_root or system user/group
- References parent via parent_domain_id
- Uses parent's configuration

### Subdomain (type='subdomain') 
- Simple subdomain of a Website
- No separate document_root or system user/group
- References parent via parent_domain_id
- Uses parent's configuration

### Aliasdomain Vhost (type='vhostalias')
- Alias domain with its own vhost configuration
- Uses parent's document_root and system user/group
- Has specific web_folder under parent's document_root
- References parent via parent_domain_id

### Subdomain Vhost (type='vhostsubdomain')
- Subdomain with its own vhost configuration
- Uses parent's document_root and system user/group
- Has specific web_folder under parent's document_root
- References parent via parent_domain_id

## Domain Relationships
```sql
parent_domain_id int(11) unsigned NOT NULL default '0'
```
- 0 for Website (primary domain)
- For all other types, references their parent Website's domain_id

### System Resources
When type is 'vhost', 'vhostsubdomain', or 'vhostalias':
```sql
document_root varchar(255) default NULL       # Base filesystem path
web_folder varchar(100) default NULL          # Subfolder under document_root
system_user varchar(255) default NULL         # Linux system user
system_group varchar(255) default NULL        # Linux system group
```

### Example Structure
```
Primary Domain (type=vhost)
├── Simple Subdomain (type=subdomain)
├── Domain Alias (type=alias)
├── Vhost Subdomain (type=vhostsubdomain)
└── Vhost Alias (type=vhostalias)
```

### Hosting Configuration

**Basic Settings:**
```sql
vhost_type varchar(32) default NULL           # Always "name" in current version
ip_address varchar(39) default NULL           # Can be "*" for all IPs
ipv6_address varchar(255) default NULL
http_port int(11) NOT NULL DEFAULT '80'
https_port int(11) NOT NULL DEFAULT '443'
```

**Document Root Structure:**
```sql
document_root varchar(255) default NULL       # Base path, inherited from parent for child domains
web_folder varchar(100) default NULL          # Empty for main domain, subfolder name for child domains
system_user varchar(255) default NULL         # Linux user, inherited from parent
system_group varchar(255) default NULL        # Linux group, inherited from parent
```

**PHP Configuration:**
```sql
php varchar(32) NOT NULL default 'y'          # PHP handler (fast-cgi, php-fpm, etc.)
php_fpm_use_socket enum('n','y') NOT NULL DEFAULT 'y'
php_fpm_chroot enum('n','y') NOT NULL DEFAULT 'n'
pm enum('static','dynamic','ondemand') NOT NULL DEFAULT 'ondemand'
pm_max_children int(11) NOT NULL DEFAULT '10'
pm_start_servers int(11) NOT NULL DEFAULT '2'
pm_min_spare_servers int(11) NOT NULL DEFAULT '1'
pm_max_spare_servers int(11) NOT NULL DEFAULT '5'
pm_process_idle_timeout int(11) NOT NULL DEFAULT '10'
pm_max_requests int(11) NOT NULL DEFAULT '0'
php_open_basedir mediumtext                  # PHP open_basedir paths
custom_php_ini mediumtext                    # Custom PHP configuration
```

**Web Server Configuration:**
```sql
allow_override varchar(255) NOT NULL default 'All'  # Apache AllowOverride
apache_directives mediumtext NULL             # Custom Apache directives
nginx_directives mediumtext NULL              # Custom Nginx directives
proxy_directives mediumtext                   # Proxy directives
proxy_protocol enum('n','y') NOT NULL default 'n'
```

### Features & Resources
```sql
hd_quota bigint(20) NOT NULL default '0'      # Disk quota (MB)
traffic_quota bigint(20) NOT NULL default '-1' # Traffic quota (MB)
cgi enum('n','y') NOT NULL default 'y'        # CGI support
ssi enum('n','y') NOT NULL default 'y'        # SSI support
perl enum('n','y') NOT NULL default 'n'       # Perl support
ruby enum('n','y') NOT NULL default 'n'       # Ruby support
python enum('n','y') NOT NULL default 'n'     # Python support
```

### SSL/TLS Configuration
```sql
ssl enum('n','y') NOT NULL DEFAULT 'n'                    # SSL enabled
ssl_letsencrypt enum('n','y') NOT NULL DEFAULT 'n'        # Lets Encrypt SSL
ssl_letsencrypt_exclude enum('n','y') DEFAULT 'n'         # Exclude from LE
rewrite_to_https enum('y','n') NOT NULL DEFAULT 'n'       # Force HTTPS

# SSL Certificate Details
ssl_state varchar(255) DEFAULT NULL                       # State/Province
ssl_locality varchar(255) DEFAULT NULL                    # City/Locality
ssl_organisation varchar(255) DEFAULT NULL                # Organization
ssl_organisation_unit varchar(255) DEFAULT NULL           # Department
ssl_country varchar(255) DEFAULT NULL                     # Country
ssl_domain varchar(255) DEFAULT NULL                      # SSL domain
ssl_request mediumtext DEFAULT NULL                       # CSR
ssl_cert mediumtext DEFAULT NULL                          # Certificate
ssl_bundle mediumtext DEFAULT NULL                        # CA bundle
ssl_key mediumtext DEFAULT NULL                           # Private key
ssl_action varchar(16) DEFAULT NULL                       # SSL action status
```

### Domain Routing & Redirects
```sql
redirect_type varchar(255) DEFAULT NULL                   # Redirect type
redirect_path varchar(255) DEFAULT NULL                   # Redirect target
seo_redirect varchar(255) DEFAULT NULL                   # SEO redirect settings
subdomain enum('none','www','*') NOT NULL DEFAULT 'none' # Subdomain handling
is_subdomainwww tinyint(1) NOT NULL DEFAULT 1           # Auto-www subdomain
```

### Statistics & Analytics
```sql
stats_password varchar(255) DEFAULT NULL                  # Stats access password
stats_type varchar(255) DEFAULT 'awstats'                # Stats software
errordocs tinyint(1) NOT NULL DEFAULT 1                  # Custom error docs
```

### Backup Configuration
```sql
backup_interval varchar(255) NOT NULL DEFAULT 'none'      # Backup frequency
backup_copies int(11) NOT NULL DEFAULT 1                 # Number of backups
backup_format_web varchar(255) NOT NULL DEFAULT 'default' # Web backup format
backup_format_db varchar(255) NOT NULL DEFAULT 'gzip'     # Database backup format
backup_encrypt enum('n','y') NOT NULL DEFAULT 'n'        # Backup encryption
backup_password varchar(255) NOT NULL DEFAULT ''          # Backup password
backup_excludes mediumtext DEFAULT NULL                  # Excluded paths
```

### Resource Monitoring
```sql
traffic_quota_lock enum('n','y') NOT NULL DEFAULT 'n'    # Traffic quota status
last_quota_notification date DEFAULT NULL                # Last quota alert
log_retention int(11) NOT NULL DEFAULT 10                # Log retention days
```

### Jailkit Configuration
```sql
jailkit_chroot_app_sections mediumtext DEFAULT NULL      # Jailkit sections
jailkit_chroot_app_programs mediumtext DEFAULT NULL      # Jailkit programs
delete_unused_jailkit enum('n','y') NOT NULL DEFAULT 'n' # Auto cleanup
last_jailkit_update date DEFAULT NULL                    # Last update
last_jailkit_hash varchar(255) DEFAULT NULL              # Configuration hash
```

### Additional Settings
```sql
enable_pagespeed enum('y','n') NOT NULL DEFAULT 'n'      # Enable PageSpeed
disable_symlinknotowner enum('n','y') NOT NULL DEFAULT 'n' # Symlink protection
rewrite_rules mediumtext DEFAULT NULL                    # URL rewrite rules
added_date date DEFAULT NULL                             # Creation date
added_by varchar(255) DEFAULT NULL                       # Creator
directive_snippets_id int(11) unsigned NOT NULL DEFAULT 0 # Linked directives
folder_directive_snippets text DEFAULT NULL              # Folder directives
```

## Traffic & Quota Monitoring

### Web Traffic (`web_traffic` table)
```sql
CREATE TABLE `web_traffic` (
  `hostname` varchar(255) NOT NULL DEFAULT '',
  `traffic_date` date DEFAULT NULL,
  `traffic_bytes` bigint(32) unsigned NOT NULL DEFAULT 0,
  UNIQUE KEY `hostname` (`hostname`,`traffic_date`)
);
```

### FTP Traffic (`ftp_traffic` table)
```sql
CREATE TABLE `ftp_traffic` (
  `hostname` varchar(255) NOT NULL,
  `traffic_date` date NOT NULL,
  `in_bytes` bigint(32) unsigned NOT NULL,
  `out_bytes` bigint(32) unsigned NOT NULL,
  UNIQUE KEY `hostname` (`hostname`,`traffic_date`)
);
```

## Backup Statistics (`web_backup` table)
```sql
CREATE TABLE `web_backup` (
  `backup_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `server_id` int(10) unsigned NOT NULL DEFAULT 0,
  `parent_domain_id` int(10) unsigned NOT NULL DEFAULT 0,
  `backup_type` enum('web','mysql','mongodb') NOT NULL DEFAULT 'web',
  `backup_mode` varchar(64) NOT NULL DEFAULT '',
  `backup_format` varchar(64) NOT NULL DEFAULT '',
  `tstamp` int(10) unsigned NOT NULL DEFAULT 0,
  `filename` varchar(255) NOT NULL DEFAULT '',
  `filesize` varchar(20) NOT NULL DEFAULT '',
  `backup_password` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`backup_id`)
);
```

## Cron Jobs (`cron` table)
```sql
CREATE TABLE `cron` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_domain_id` int(11) unsigned NOT NULL DEFAULT 0,
  `type` enum('url','chrooted','full') NOT NULL DEFAULT 'url',
  `command` text DEFAULT NULL,
  `run_min` varchar(100) DEFAULT NULL,
  `run_hour` varchar(100) DEFAULT NULL,
  `run_mday` varchar(100) DEFAULT NULL,
  `run_month` varchar(100) DEFAULT NULL,
  `run_wday` varchar(100) DEFAULT NULL,
  `log` enum('n','y') NOT NULL DEFAULT 'n',
  `active` enum('n','y') NOT NULL DEFAULT 'y',
  PRIMARY KEY (`id`)
);
```

## Databases (`web_database` table)
Stores database instances associated with websites.

**Primary Key:**
```sql
database_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Relationships:**
```sql
parent_domain_id int(11) unsigned NOT NULL DEFAULT '0'     # References web_domain.domain_id
database_user_id int(11) unsigned DEFAULT NULL             # References web_database_user
database_ro_user_id int(11) unsigned DEFAULT NULL          # Read-only user reference
```

**Database Settings:**
```sql
database_name varchar(64) DEFAULT NULL                     # Actual database name
database_name_prefix varchar(50) NOT NULL DEFAULT ''       # Prefix for database name
database_charset varchar(64) DEFAULT NULL                  # Database character set
type varchar(16) NOT NULL DEFAULT 'y'                     # Database type (mysql, etc)
```

**Resource Limits:**
```sql
database_quota int(11) DEFAULT NULL                       # Database size quota
quota_exceeded enum('n','y') NOT NULL DEFAULT 'n'         # Quota status flag
last_quota_notification date DEFAULT NULL                 # Last quota notification
```

**Access Control:**
```sql
remote_access enum('n','y') NOT NULL DEFAULT 'y'          # Allow remote access
remote_ips text DEFAULT NULL                              # Allowed remote IPs
```

**Backup Settings:**
```sql
backup_interval varchar(255) NOT NULL DEFAULT 'none'       # Backup frequency
backup_copies int(11) NOT NULL DEFAULT 1                  # Number of backup copies
```

## Database Users (`web_database_user` table)
Stores database user credentials.

**Primary Key:**
```sql
database_user_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**User Credentials:**
```sql
database_user varchar(64) DEFAULT NULL                    # Database username
database_user_prefix varchar(50) NOT NULL DEFAULT ''      # Username prefix
database_password varchar(64) DEFAULT NULL                # MySQL/MariaDB password hash
database_password_mongo varchar(32) DEFAULT NULL          # MongoDB password hash
```

## FTP Users (`ftp_user` table)
FTP account management.

**Primary Key:**
```sql
ftp_user_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Relationships:**
```sql
parent_domain_id int(11) unsigned NOT NULL DEFAULT '0'     # References web_domain.domain_id
```

**Account Details:**
```sql
username varchar(64) DEFAULT NULL                         # FTP username
username_prefix varchar(50) NOT NULL DEFAULT ''           # Username prefix
password varchar(200) DEFAULT NULL                        # Password hash
uid varchar(64) DEFAULT NULL                             # System user ID
gid varchar(64) DEFAULT NULL                             # System group ID
```

**Storage Settings:**
```sql
dir varchar(255) DEFAULT NULL                            # Home directory
quota_size bigint(20) NOT NULL DEFAULT -1                # Disk quota
quota_files bigint(20) NOT NULL DEFAULT -1               # File count quota
```

**FTP Settings:**
```sql
ul_ratio int(11) NOT NULL DEFAULT -1                     # Upload ratio
dl_ratio int(11) NOT NULL DEFAULT -1                     # Download ratio
ul_bandwidth int(11) NOT NULL DEFAULT -1                 # Upload bandwidth limit
dl_bandwidth int(11) NOT NULL DEFAULT -1                 # Download bandwidth limit
```

## WebDAV Users (`webdav_user` table)
WebDAV access management.

**Primary Key:**
```sql
webdav_user_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Relationships:**
```sql
parent_domain_id int(11) unsigned NOT NULL DEFAULT '0'     # References web_domain.domain_id
```

**Access Details:**
```sql
username varchar(64) DEFAULT NULL                         # WebDAV username
username_prefix varchar(50) NOT NULL DEFAULT ''           # Username prefix
password varchar(200) DEFAULT NULL                        # Password hash
dir varchar(255) DEFAULT NULL                            # WebDAV directory
```

## Protected Folders (`web_folder` table)
Password-protected directory configuration.

**Primary Key:**
```sql
web_folder_id bigint(20) NOT NULL AUTO_INCREMENT
```

**Relationships:**
```sql
parent_domain_id int(11) NOT NULL DEFAULT '0'             # References web_domain.domain_id
```

**Folder Settings:**
```sql
path varchar(255) DEFAULT NULL                            # Protected directory path
```

## Protected Folder Users (`web_folder_user` table)
User access for protected folders.

**Primary Key:**
```sql
web_folder_user_id bigint(20) NOT NULL AUTO_INCREMENT
```

**Relationships:**
```sql
web_folder_id int(11) NOT NULL DEFAULT '0'                # References web_folder.web_folder_id
```

**User Credentials:**
```sql
username varchar(255) DEFAULT NULL                        # Protected folder username
password varchar(255) DEFAULT NULL                        # Protected folder password
```

## Shell Users (`shell_user` table)
SSH/SFTP user management.

**Primary Key:**
```sql
shell_user_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Relationships:**
```sql
parent_domain_id int(11) unsigned NOT NULL DEFAULT '0'     # References web_domain.domain_id
```

**Account Details:**
```sql
username varchar(64) DEFAULT NULL                         # Shell username
username_prefix varchar(50) NOT NULL DEFAULT ''           # Username prefix
password varchar(200) DEFAULT NULL                        # Password hash
puser varchar(255) DEFAULT NULL                          # Parent user
pgroup varchar(255) DEFAULT NULL                         # Parent group
shell varchar(255) NOT NULL DEFAULT '/bin/bash'          # Shell path
```

**Storage Settings:**
```sql
dir varchar(255) DEFAULT NULL                            # Home directory
quota_size bigint(20) NOT NULL DEFAULT -1                # Disk quota
chroot varchar(255) NOT NULL DEFAULT ''                  # Chroot settings
```

**SSH Keys:**
```sql
ssh_rsa text DEFAULT NULL                                # SSH RSA key
```

# Email Resources

## Mail Domains (`mail_domain` table)
Primary table for email domain configuration.

**Primary Key:**
```sql
domain_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Domain Settings:**
```sql
domain varchar(255) NOT NULL DEFAULT ''                   # Email domain name
active enum('n','y') NOT NULL DEFAULT 'n'                # Domain status
```

**DKIM Configuration:**
```sql
dkim enum('n','y') NOT NULL DEFAULT 'n'                  # DKIM enabled
dkim_selector varchar(63) NOT NULL DEFAULT 'default'      # DKIM selector
dkim_private mediumtext DEFAULT NULL                     # Private key
dkim_public mediumtext DEFAULT NULL                      # Public key
```

**Relay Configuration:**
```sql
relay_host varchar(255) NOT NULL DEFAULT ''              # Relay server
relay_user varchar(255) NOT NULL DEFAULT ''              # Relay username
relay_pass varchar(255) NOT NULL DEFAULT ''              # Relay password
```

## Mail User (Mailboxes) (`mail_user` table)
Individual email account configuration.

**Primary Key:**
```sql
mailuser_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Account Details:**
```sql
email varchar(255) NOT NULL DEFAULT ''                   # Email address
login varchar(255) NOT NULL DEFAULT ''                   # Login username
password varchar(255) NOT NULL DEFAULT ''                # Password hash
name varchar(255) NOT NULL DEFAULT ''                    # Display name
```

**Storage Settings:**
```sql
uid int(11) NOT NULL DEFAULT 5000                        # System user ID
gid int(11) NOT NULL DEFAULT 5000                        # System group ID
maildir varchar(255) NOT NULL DEFAULT ''                 # Mailbox path
maildir_format varchar(255) NOT NULL DEFAULT 'maildir'   # Mailbox format
quota bigint(20) NOT NULL DEFAULT 0                      # Mailbox quota
homedir varchar(255) NOT NULL DEFAULT ''                 # Home directory
```

**Email Features:**
```sql
postfix enum('n','y') NOT NULL DEFAULT 'y'               # Postfix enabled
disableimap enum('n','y') NOT NULL DEFAULT 'n'           # IMAP access
disablepop3 enum('n','y') NOT NULL DEFAULT 'n'           # POP3 access
disabledeliver enum('n','y') NOT NULL DEFAULT 'n'        # Mail delivery
disablesmtp enum('n','y') NOT NULL DEFAULT 'n'           # SMTP access
```

**Auto-Response:**
```sql
autoresponder enum('n','y') NOT NULL DEFAULT 'n'         # Auto-responder status
autoresponder_start_date datetime DEFAULT NULL           # Start date
autoresponder_end_date datetime DEFAULT NULL             # End date
autoresponder_subject varchar(255) NOT NULL DEFAULT 'Out of office reply'
autoresponder_text mediumtext DEFAULT NULL               # Response message
```

**Mail Management:**
```sql
move_junk enum('y','a','n') NOT NULL DEFAULT 'y'         # Spam handling
purge_trash_days int(11) NOT NULL DEFAULT 0              # Trash retention
purge_junk_days int(11) NOT NULL DEFAULT 0               # Spam retention
custom_mailfilter mediumtext DEFAULT NULL                # Custom filters
```

## Mail Aliases (`mail_forwarding` table)
Email aliases and forwarding rules.

**Primary Key:**
```sql
forwarding_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Forwarding Settings:**
```sql
source varchar(255) NOT NULL DEFAULT ''                  # Source address
destination text DEFAULT NULL                            # Target address(es)
type enum('alias','aliasdomain','forward','catchall')    # Rule type
active enum('n','y') NOT NULL DEFAULT 'n'                # Status
allow_send_as enum('n','y') NOT NULL DEFAULT 'n'         # Allow send as
greylisting enum('n','y') NOT NULL DEFAULT 'n'           # Greylisting
```

## Mailing Lists (`mail_mailinglist` table)
Mailing list configuration.

**Primary Key:**
```sql
mailinglist_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**List Settings:**
```sql
domain varchar(255) NOT NULL DEFAULT ''                  # List domain
listname varchar(255) NOT NULL DEFAULT ''                # List name
email varchar(255) NOT NULL DEFAULT ''                   # List address
password varchar(255) NOT NULL DEFAULT ''                # Admin password
```

## Spamfilter Configuration

### Policies (`spamfilter_policy` table)
```sql
id int(11) unsigned NOT NULL AUTO_INCREMENT,
policy_name varchar(64) DEFAULT NULL,
virus_lover enum('N','Y') DEFAULT 'N',
spam_lover enum('N','Y') DEFAULT 'N',
banned_files_lover enum('N','Y') DEFAULT 'N',
spam_modifies_subj enum('N','Y') DEFAULT 'N',
spam_tag_level decimal(5,2) DEFAULT NULL,
spam_tag2_level decimal(5,2) DEFAULT NULL,
spam_kill_level decimal(5,2) DEFAULT NULL
```

### Users (`spamfilter_users` table)
```sql
id int(11) unsigned NOT NULL AUTO_INCREMENT,
priority tinyint(3) unsigned NOT NULL DEFAULT 7,
policy_id int(11) unsigned NOT NULL DEFAULT 0,
email varchar(255) NOT NULL DEFAULT '',
fullname varchar(64) DEFAULT NULL,
local varchar(1) DEFAULT NULL
```

### White/Blacklists (`spamfilter_wblist` table)
```sql
wblist_id int(11) unsigned NOT NULL AUTO_INCREMENT,
wb enum('W','B') NOT NULL DEFAULT 'W',                   # Whitelist/Blacklist
rid int(11) unsigned NOT NULL DEFAULT 0,                 # Rule ID
email varchar(255) NOT NULL DEFAULT '',                  # Email pattern
priority tinyint(3) unsigned NOT NULL DEFAULT 0          # Rule priority
```

## Mail Transport Rules (`mail_transport` table)
Custom mail routing configuration.

**Primary Key:**
```sql
transport_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Transport Settings:**
```sql
domain varchar(255) NOT NULL DEFAULT '',                 # Domain pattern
transport varchar(255) NOT NULL DEFAULT '',              # Transport rule
sort_order int(11) unsigned NOT NULL DEFAULT 5           # Rule priority
```

## Mail Statistics

### Traffic (`mail_traffic` table)
```sql
traffic_id int(11) unsigned NOT NULL AUTO_INCREMENT,
mailuser_id int(11) unsigned NOT NULL DEFAULT 0,
month char(7) NOT NULL DEFAULT '',
traffic bigint(20) unsigned NOT NULL DEFAULT 0
```

### Quota (`mail_user` relevant fields)
```sql
quota bigint(20) NOT NULL DEFAULT 0,                     # Mailbox quota
last_quota_notification date DEFAULT NULL                # Last quota alert
```

# DNS Resources

## DNS Zones (`dns_soa` table)
Primary DNS zone configuration (Start of Authority records).

**Primary Key:**
```sql
id int(10) unsigned NOT NULL AUTO_INCREMENT
```

**Zone Configuration:**
```sql
origin varchar(255) NOT NULL DEFAULT ''                  # Zone name
ns varchar(255) NOT NULL DEFAULT ''                      # Primary nameserver
mbox varchar(255) NOT NULL DEFAULT ''                    # Admin email
serial int(11) unsigned NOT NULL DEFAULT 1               # Zone serial
refresh int(11) unsigned NOT NULL DEFAULT 28800          # Refresh time
retry int(11) unsigned NOT NULL DEFAULT 7200             # Retry time
expire int(11) unsigned NOT NULL DEFAULT 604800          # Expire time
minimum int(11) unsigned NOT NULL DEFAULT 3600           # Minimum TTL
ttl int(11) unsigned NOT NULL DEFAULT 3600               # Default TTL
```

**Access Control:**
```sql
xfer text DEFAULT NULL                                   # Zone transfer ACL
also_notify text DEFAULT NULL                           # Additional notify
update_acl varchar(255) DEFAULT NULL                    # Dynamic update ACL
```

**DNSSEC Settings:**
```sql
dnssec_initialized enum('Y','N') NOT NULL DEFAULT 'N'    # DNSSEC status
dnssec_wanted enum('Y','N') NOT NULL DEFAULT 'N'         # DNSSEC enabled
dnssec_algo set('NSEC3RSASHA1','ECDSAP256SHA256')       # DNSSEC algorithm
dnssec_info text DEFAULT NULL                           # DNSSEC information
dnssec_last_signed bigint(20) NOT NULL DEFAULT 0        # Last signing time
```

## DNS Records (`dns_rr` table)
Individual DNS resource records.

**Primary Key:**
```sql
id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Record Settings:**
```sql
zone int(11) unsigned NOT NULL DEFAULT 0                 # Zone ID reference
name varchar(255) NOT NULL DEFAULT ''                    # Record name
type enum('A','AAAA','ALIAS','CNAME','DNAME','CAA','DS',
          'HINFO','LOC','MX','NAPTR','NS','PTR','RP',
          'SRV','SSHFP','TXT','TLSA','DNSKEY')          # Record type
data text NOT NULL                                      # Record data
aux int(11) unsigned NOT NULL DEFAULT 0                  # Priority/weight
ttl int(11) unsigned NOT NULL DEFAULT 3600               # Record TTL
active enum('N','Y') NOT NULL DEFAULT 'Y'                # Record status
serial int(10) unsigned DEFAULT NULL                    # Record serial
```

## Secondary DNS (`dns_slave` table)
Secondary (slave) zone configuration.

**Primary Key:**
```sql
id int(10) unsigned NOT NULL AUTO_INCREMENT
```

**Slave Settings:**
```sql
origin varchar(255) NOT NULL DEFAULT ''                  # Zone name
ns varchar(255) NOT NULL DEFAULT ''                      # Master nameserver
active enum('N','Y') NOT NULL DEFAULT 'N'                # Zone status
xfer text DEFAULT NULL                                   # Transfer ACL
```

## DNS Templates (`dns_template` table)
Templates for zone creation.

**Primary Key:**
```sql
template_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Template Settings:**
```sql
name varchar(64) DEFAULT NULL                           # Template name
fields varchar(255) DEFAULT NULL                        # Template variables
template text DEFAULT NULL                              # Zone template
visible enum('N','Y') NOT NULL DEFAULT 'Y'               # Template visibility
```

## SSL CA Records (`dns_ssl_ca` table)
Certificate Authority CAA record management.

**Primary Key:**
```sql
id int(10) unsigned NOT NULL AUTO_INCREMENT
```

**CA Settings:**
```sql
ca_name varchar(255) NOT NULL DEFAULT ''                 # CA name
ca_issue varchar(255) NOT NULL DEFAULT ''                # Issue domain
ca_wildcard enum('Y','N') NOT NULL DEFAULT 'N'           # Wildcard allowed
ca_iodef text NOT NULL                                  # IODEF URL
ca_critical tinyint(1) NOT NULL DEFAULT 0               # Critical flag
```

# Monitor Components

## System Monitoring Tables

### Monitor Data (`monitor_data` table)
Stores monitoring results.

```sql
CREATE TABLE monitor_data (
    server_id int(11) unsigned NOT NULL DEFAULT 0,
    type varchar(255) NOT NULL DEFAULT '',
    created int(11) unsigned NOT NULL DEFAULT 0,
    data mediumtext DEFAULT NULL,
    state enum('no_state','unknown','ok','info','warning','critical','error') 
          NOT NULL DEFAULT 'unknown'
);
```

Monitored Types Include:
- backup_utils
- cpu_info
- database_size
- disk_usage
- mailq
- mem_usage
- raid_state
- services
- system_update
- uptime
- log files (various)

### System Log (`sys_log` table)
System event logging.

```sql
CREATE TABLE sys_log (
    syslog_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    server_id int(11) unsigned NOT NULL DEFAULT 0,
    datalog_id int(11) unsigned NOT NULL DEFAULT 0,
    loglevel tinyint(4) NOT NULL DEFAULT 0,
    tstamp int(11) unsigned NOT NULL DEFAULT 0,
    message text DEFAULT NULL,
    PRIMARY KEY (syslog_id)
);
```

### Data Log (`sys_datalog` table)
Records data changes.

```sql
CREATE TABLE sys_datalog (
    datalog_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    server_id int(11) unsigned NOT NULL DEFAULT 0,
    dbtable varchar(255) NOT NULL DEFAULT '',
    dbidx varchar(255) NOT NULL DEFAULT '',
    action char(1) NOT NULL DEFAULT '',
    tstamp int(11) NOT NULL DEFAULT 0,
    user varchar(255) NOT NULL DEFAULT '',
    data longtext DEFAULT NULL,
    status set('pending','ok','warning','error') NOT NULL DEFAULT 'ok',
    error mediumtext DEFAULT NULL,
    PRIMARY KEY (datalog_id)
);
```

## Remote Actions (`sys_remoteaction` table)
Tracks remote server commands.

```sql
CREATE TABLE sys_remoteaction (
    action_id int(11) unsigned NOT NULL AUTO_INCREMENT,
    server_id int(11) unsigned NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    action_type varchar(20) NOT NULL DEFAULT '',
    action_param mediumtext DEFAULT NULL,
    action_state enum('pending','ok','warning','error') NOT NULL DEFAULT 'pending',
    response mediumtext DEFAULT NULL,
    PRIMARY KEY (action_id)
);
```

## System Cron (`sys_cron` table)
Internal task scheduling.

```sql
CREATE TABLE sys_cron (
    name varchar(50) NOT NULL DEFAULT '',
    last_run datetime DEFAULT NULL,
    next_run datetime DEFAULT NULL,
    running tinyint(1) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
);
```

# System Configuration

## Server Management (`server` table)
Main server configuration table.

**Primary Key:**
```sql
server_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Server Type Flags:**
```sql
mail_server tinyint(1) NOT NULL DEFAULT 0              # Mail services
web_server tinyint(1) NOT NULL DEFAULT 0               # Web services
dns_server tinyint(1) NOT NULL DEFAULT 0               # DNS services
file_server tinyint(1) NOT NULL DEFAULT 0              # File services
db_server tinyint(1) NOT NULL DEFAULT 0                # Database services
vserver_server tinyint(1) NOT NULL DEFAULT 0           # Virtual server
proxy_server tinyint(1) NOT NULL DEFAULT 0             # Proxy services
firewall_server tinyint(1) NOT NULL DEFAULT 0          # Firewall services
xmpp_server tinyint(1) NOT NULL DEFAULT 0              # XMPP services
```

**Server Settings:**
```sql
server_name varchar(255) NOT NULL DEFAULT ''            # Server hostname
config text DEFAULT NULL                               # Server configuration
updated bigint(20) unsigned NOT NULL DEFAULT 0         # Last update
mirror_server_id int(11) unsigned NOT NULL DEFAULT 0   # Mirror server
dbversion int(11) unsigned NOT NULL DEFAULT 1          # Database version
active tinyint(1) NOT NULL DEFAULT 1                   # Server status
```

## Server IP Management (`server_ip` table)
IP address management for servers.

**Primary Key:**
```sql
server_ip_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**IP Configuration:**
```sql
server_id int(11) unsigned NOT NULL DEFAULT 0          # Associated server
client_id int(11) unsigned NOT NULL DEFAULT 0          # Owner client
ip_type enum('IPv4','IPv6') NOT NULL DEFAULT 'IPv4'    # IP version
ip_address varchar(39) DEFAULT NULL                    # IP address
virtualhost enum('n','y') NOT NULL DEFAULT 'y'         # Virtual hosting
virtualhost_port varchar(255) DEFAULT '80,443'         # Listening ports
```

## IP Address Mapping (`server_ip_map` table)
IP address mapping/NAT configuration.

**Primary Key:**
```sql
server_ip_map_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Mapping Settings:**
```sql
server_id int(11) unsigned NOT NULL DEFAULT 0          # Associated server
source_ip varchar(15) DEFAULT NULL                     # Source IP
destination_ip varchar(35) DEFAULT ''                  # Target IP
active enum('n','y') NOT NULL DEFAULT 'y'              # Mapping status
```

## PHP Versions (`server_php` table)
PHP version management for servers.

**Primary Key:**
```sql
server_php_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**PHP Configuration:**
```sql
server_id int(11) unsigned NOT NULL DEFAULT 0          # Associated server
client_id int(11) unsigned NOT NULL DEFAULT 0          # Owner client
name varchar(255) DEFAULT NULL                         # Version name
php_fastcgi_binary varchar(255) DEFAULT NULL          # FastCGI binary
php_fastcgi_ini_dir varchar(255) DEFAULT NULL         # FastCGI config dir
php_fpm_init_script varchar(255) DEFAULT NULL         # FPM init script
php_fpm_ini_dir varchar(255) DEFAULT NULL             # FPM config dir
php_fpm_pool_dir varchar(255) DEFAULT NULL            # FPM pool dir
php_fpm_socket_dir varchar(255) DEFAULT NULL          # FPM socket dir
active enum('n','y') NOT NULL DEFAULT 'y'              # Version status
sortprio int(20) NOT NULL DEFAULT 100                 # Sort priority
```

## System Configuration (`sys_config` table)
Global system configuration storage.

```sql
CREATE TABLE sys_config (
    `group` varchar(64) NOT NULL DEFAULT '',
    name varchar(64) NOT NULL DEFAULT '',
    value varchar(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`group`,`name`)
);
```

## User Management

### Users (`sys_user` table)
System user management.

**Primary Key:**
```sql
userid int(11) unsigned NOT NULL AUTO_INCREMENT
```

**User Information:**
```sql
username varchar(64) NOT NULL DEFAULT ''                # Login username
passwort varchar(200) NOT NULL DEFAULT ''               # Password hash
modules varchar(255) NOT NULL DEFAULT ''                # Allowed modules
startmodule varchar(255) NOT NULL DEFAULT ''            # Default module
app_theme varchar(32) NOT NULL DEFAULT 'default'        # UI theme
typ varchar(16) NOT NULL DEFAULT 'user'                 # User type
language varchar(2) NOT NULL DEFAULT 'en'               # Interface language
```

**Group Management:**
```sql
groups text DEFAULT NULL                               # Group memberships
default_group int(11) unsigned NOT NULL DEFAULT 0      # Primary group
client_id int(11) unsigned NOT NULL DEFAULT 0          # Associated client
```

**Security Settings:**
```sql
lost_password_function tinyint(1) NOT NULL DEFAULT 1   # Password reset
otp_type set('none','email') NOT NULL DEFAULT 'none'   # 2FA type
otp_data varchar(255) DEFAULT NULL                     # 2FA configuration
otp_recovery varchar(64) DEFAULT NULL                  # Recovery codes
otp_attempts tinyint(4) NOT NULL DEFAULT 0             # Failed attempts
```

### Groups (`sys_group` table)
User group management.

**Primary Key:**
```sql
groupid int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Group Settings:**
```sql
name varchar(64) NOT NULL DEFAULT ''                   # Group name
description text DEFAULT NULL                         # Group description
client_id int(11) unsigned NOT NULL DEFAULT 0          # Associated client
```

## File Synchronization (`sys_filesync` table)
File synchronization configuration.

```sql
CREATE TABLE sys_filesync (
    id int(11) unsigned NOT NULL AUTO_INCREMENT,
    jobname varchar(64) NOT NULL DEFAULT '',
    sync_interval_minutes int(11) unsigned NOT NULL DEFAULT 0,
    ftp_host varchar(255) NOT NULL DEFAULT '',
    ftp_path varchar(255) NOT NULL DEFAULT '',
    ftp_username varchar(64) NOT NULL DEFAULT '',
    ftp_password varchar(64) NOT NULL DEFAULT '',
    local_path varchar(255) NOT NULL DEFAULT '',
    wput_options varchar(255) NOT NULL DEFAULT '--timestamping --reupload --dont-continue',
    active tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
);
```

## Database Synchronization (`sys_dbsync` table)
Database synchronization configuration.

```sql
CREATE TABLE sys_dbsync (
    id int(11) unsigned NOT NULL AUTO_INCREMENT,
    jobname varchar(64) NOT NULL DEFAULT '',
    sync_interval_minutes int(11) unsigned NOT NULL DEFAULT 0,
    db_type varchar(16) NOT NULL DEFAULT '',
    db_host varchar(255) NOT NULL DEFAULT '',
    db_name varchar(64) NOT NULL DEFAULT '',
    db_username varchar(64) NOT NULL DEFAULT '',
    db_password varchar(64) NOT NULL DEFAULT '',
    db_tables varchar(255) NOT NULL DEFAULT 'admin,forms',
    empty_datalog int(11) unsigned NOT NULL DEFAULT 0,
    sync_datalog_external int(11) unsigned NOT NULL DEFAULT 0,
    active tinyint(1) NOT NULL DEFAULT 1,
    last_datalog_id int(11) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
);
```

# Firewall & Security Configuration

## Firewall Rules (`firewall` table)
Firewall configuration management.

**Primary Key:**
```sql
firewall_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Rule Configuration:**
```sql
server_id int(11) unsigned NOT NULL DEFAULT 0           # Associated server
tcp_port text DEFAULT NULL                             # Allowed TCP ports
udp_port text DEFAULT NULL                             # Allowed UDP ports
active enum('n','y') NOT NULL DEFAULT 'y'               # Rule status
```

## iptables Configuration (`iptables` table)
Low-level firewall rule management.

**Primary Key:**
```sql
iptables_id int(10) unsigned NOT NULL AUTO_INCREMENT
```

**Rule Settings:**
```sql
server_id int(10) unsigned NOT NULL DEFAULT 0           # Associated server
table varchar(10) DEFAULT NULL                         # INPUT/OUTPUT/FORWARD
source_ip varchar(16) DEFAULT NULL                     # Source IP
destination_ip varchar(16) DEFAULT NULL                # Destination IP
protocol varchar(10) DEFAULT 'TCP'                     # TCP/UDP/GRE
singleport varchar(10) DEFAULT NULL                    # Single port
multiport varchar(40) DEFAULT NULL                     # Port range
state varchar(20) DEFAULT NULL                         # Connection state
target varchar(10) DEFAULT NULL                        # ACCEPT/DROP/REJECT/LOG
active enum('n','y') NOT NULL DEFAULT 'y'               # Rule status
```

# Remote Access Management

## Remote Users (`remote_user` table)
External API access configuration.

**Primary Key:**
```sql
remote_userid int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Access Configuration:**
```sql
remote_username varchar(64) NOT NULL DEFAULT ''         # API username
remote_password varchar(200) NOT NULL DEFAULT ''        # API password
remote_access enum('y','n') NOT NULL DEFAULT 'y'        # Access enabled
remote_ips text DEFAULT NULL                           # Allowed IPs
remote_functions text DEFAULT NULL                     # Allowed functions
```

# System Configuration Storage

## System INI (`sys_ini` table)
System-wide configuration storage.

**Primary Key:**
```sql
sysini_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Configuration Storage:**
```sql
config longtext DEFAULT NULL                           # System configuration
default_logo text DEFAULT NULL                        # Default system logo
custom_logo text DEFAULT NULL                         # Custom branding logo
```

# Message Templates

## Support Messages (`support_message` table)
Internal support message system.

**Primary Key:**
```sql
support_message_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Message Details:**
```sql
recipient_id int(11) unsigned NOT NULL DEFAULT 0        # Message recipient
sender_id int(11) unsigned NOT NULL DEFAULT 0           # Message sender
subject varchar(255) DEFAULT NULL                      # Message subject
message text DEFAULT NULL                              # Message content
tstamp int(11) NOT NULL DEFAULT 0                      # Timestamp
```

## Client Message Templates (`client_message_template` table)
Templates for client communications.

**Primary Key:**
```sql
client_message_template_id bigint(20) NOT NULL AUTO_INCREMENT
```

**Template Configuration:**
```sql
template_type varchar(255) DEFAULT NULL                # Template type
template_name varchar(255) DEFAULT NULL                # Template name
subject varchar(255) DEFAULT NULL                      # Email subject
message text DEFAULT NULL                              # Message content
```

# Directive Management

## Directive Snippets (`directive_snippets` table)
Reusable configuration directives.

**Primary Key:**
```sql
directive_snippets_id int(11) unsigned NOT NULL AUTO_INCREMENT
```

**Snippet Configuration:**
```sql
name varchar(255) DEFAULT NULL                         # Snippet name
type varchar(255) DEFAULT NULL                        # Snippet type
snippet mediumtext DEFAULT NULL                       # Directive content
customer_viewable enum('n','y') NOT NULL DEFAULT 'n'   # Client visibility
required_php_snippets varchar(255) NOT NULL DEFAULT '' # PHP requirements
active enum('n','y') NOT NULL DEFAULT 'y'              # Snippet status
master_directive_snippets_id int(11) unsigned NOT NULL DEFAULT 0  # Parent snippet
update_sites enum('y','n') NOT NULL DEFAULT 'n'        # Auto-update sites
```

# Key Relationships and Dependencies

1. Server Components:
   - server → server_ip (one-to-many)
   - server → server_php (one-to-many)
   - server → firewall (one-to-many)

2. User Management:
   - sys_user → sys_group (many-to-many)
   - client → sys_user (one-to-one)
   - remote_user → client (many-to-one)

3. Configuration Management:
   - directive_snippets → web_domain (many-to-many)
   - sys_ini → global system
   - sys_config → component-specific settings

4. Security:
   - firewall → server (many-to-one)
   - iptables → server (many-to-one)
   - remote_user → access control

