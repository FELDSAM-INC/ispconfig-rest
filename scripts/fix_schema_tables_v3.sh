#!/bin/bash

# Directory containing the schema files
SCHEMA_DIR="/Users/kristianfeldsam/Sites/ispconfig_rest/api/components/schemas"

# Process each YAML file in the schema directory
for file in "$SCHEMA_DIR"/*.yaml; do
    # Get just the filename
    filename=$(basename "$file")
    
    # Skip _index.yaml
    if [[ "$filename" == "_index.yaml" ]]; then
        echo "Skipping index file: $file"
        continue
    fi
    
    echo "Processing: $file"
    
    # Define table names based on filename
    case "$filename" in
        "DnsRecord.yaml") table_name="dns_rr" ;;
        "DnsSlave.yaml") table_name="dns_slave" ;;
        "DnsSoa.yaml") table_name="dns_soa" ;;
        "DnsTemplate.yaml") table_name="dns_template" ;;
        "FtpUser.yaml") table_name="ftp_user" ;;
        "MailAccess.yaml") table_name="mail_access" ;;
        "MailAliasDomain.yaml") table_name="mail_alias_domain" ;;
        "MailContentFilter.yaml") table_name="mail_content_filter" ;;
        "MailDomain.yaml") table_name="mail_domain" ;;
        "MailForwarding.yaml") table_name="mail_forwarding" ;;
        "MailGet.yaml") table_name="mail_get" ;;
        "MailRelayDomain.yaml") table_name="mail_relay_domain" ;;
        "MailRelayRecipient.yaml") table_name="mail_relay_recipient" ;;
        "MailTransport.yaml") table_name="mail_transport" ;;
        "MailUser.yaml") table_name="mail_user" ;;
        "MailUserFilter.yaml") table_name="mail_user_filter" ;;
        "ShellUser.yaml") table_name="shell_user" ;;
        "SpamfilterConfig.yaml") table_name="server" ;;
        "SpamfilterPolicy.yaml") table_name="spamfilter_policy" ;;
        "SpamfilterUser.yaml") table_name="spamfilter_users" ;;
        "SpamfilterWBList.yaml") table_name="spamfilter_wblist" ;;
        "WebChildDomain.yaml") table_name="web_domain" ;;
        "WebDomain.yaml") table_name="web_domain" ;;
        "WebFolder.yaml") table_name="web_folder" ;;
        "WebFolderUser.yaml") table_name="web_folder_user" ;;
        "WebdavUser.yaml") table_name="webdav_user" ;;
        "DatabaseUser.yaml") table_name="web_database_user" ;;
        *) table_name="" ;;
    esac
    
    if [ -n "$table_name" ]; then
        echo "  - Using table: $table_name"
        
        # Create a backup of the original file
        cp "$file" "${file}.bak"
        
        # Remove all x-db-table lines
        grep -v "x-db-table:" "${file}.bak" > "${file}.tmp"
        
        # Add x-db-table at the root level after type: object
        sed -e "s/^type: object$/type: object\nx-db-table: $table_name\ndescription: Auto-generated schema for $table_name/" "${file}.tmp" > "$file"
        
        # Clean up
        rm "${file}.bak" "${file}.tmp"
        
        echo "  ✓ Fixed $file"
    else
        # Check if it already has x-db-table at root
        if head -n 10 "$file" | grep -q "^x-db-table:"; then
            echo "  ✓ Already has x-db-table at root level"
        elif grep -q "x-db-table:" "$file"; then
            echo "  ⚠️  Has x-db-table but not at root (needs manual fix): $file"
        else
            echo "  ⚠️  No x-db-table found in $file"
        fi
    fi
done

echo "\nDone fixing schema files."
