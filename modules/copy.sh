#!/bin/bash

# USE WITH CAUTION!

# Define source and destination paths
SOURCE="chat_ui_example"
DEST="./chat_ui"

# Copy the folder to the destination
cp -r "$SOURCE" "$DEST"

# Rename files containing chat_ui_example in their names
find "$DEST" -type f -name "*chat_ui_example*" | while read -r file; do
    new_name="${file//chat_ui_example/chat_ui}"
    mv "$file" "$new_name"
done

# Replace all occurrences of chat_ui_example with chat_ui in file contents
find "$DEST" -type f -exec sed -i 's/chat_ui_example/chat_ui/g' {} \;

echo "Folder copied and modifications complete! ✅"
