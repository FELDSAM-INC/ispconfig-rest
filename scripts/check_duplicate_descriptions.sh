#!/bin/bash

# Directory containing the schema files
SCHEMA_DIR="/Users/kristianfeldsam/Sites/ispconfig_rest/api/components/schemas"

# Find files with duplicate descriptions
echo "Checking for files with duplicate descriptions..."

for file in "$SCHEMA_DIR"/*.yaml; do
    # Skip _index.yaml
    if [[ "$file" == *"_index.yaml" ]]; then
        continue
    fi
    
    # Count occurrences of 'description:' in the first 10 lines
    count=$(head -n 10 "$file" | grep -c "description:")
    
    if [ "$count" -gt 1 ]; then
        echo "Found $count description fields in $file"
        # Show the first 10 lines for context
        head -n 10 "$file"
        echo "----------------------------------------"
    fi
done

echo "Done checking for duplicate descriptions."
