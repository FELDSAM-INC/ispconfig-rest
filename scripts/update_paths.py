import re
import sys

def update_paths(file_path):
    # Define the patterns to replace
    patterns = [
        (r"\'\\.\\./\\.\\./components/", "'../../components/"),
        (r'"\\.\\./\\.\\./components/', '"../../components/'),
    ]
    
    # Read the file
    with open(file_path, 'r') as f:
        content = f.read()
    
    # Apply all replacements
    updated_content = content
    for pattern, replacement in patterns:
        updated_content = re.sub(pattern, replacement, updated_content)
    
    # Only write if changes were made
    if updated_content != content:
        with open(file_path, 'w') as f:
            f.write(updated_content)
        print(f"Updated paths in {file_path}")
    else:
        print(f"No path updates needed in {file_path}")

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python update_paths.py <file_path>")
        sys.exit(1)
    
    update_paths(sys.argv[1])
