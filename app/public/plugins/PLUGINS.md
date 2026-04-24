# Plugin System Documentation

## Overview

The file tool plugin system allows extending the file module with custom viewers, editors, and players. Each plugin is a self-contained component with its own JavaScript and CSS resources.

## Plugin Structure

Plugins are stored in `public/plugins/<slug>/` and must contain:

```
public/plugins/<slug>/
├── manifest.json      # Plugin configuration
├── viewer.js          # Main JavaScript (for viewers)
├── viewer.css         # Styles (for viewers)
└── ...               # Other resources as needed
```

## manifest.json

```json
{
    "name": "Plugin Display Name",
    "slug": "unique-plugin-slug",
    "version": "1.0.0",
    "type": "viewer|editor|player",
    "description": "Plugin description",
    "author": "Author Name",
    "supported_mimes": ["application/pdf", "image/*"],
    "resources": {
        "js": ["/plugins/slug/main.js", "/plugins/slug/vendor.js"],
        "css": ["/plugins/slug/style.css"]
    },
    "config": {
        "customOption": "value"
    }
}
```

### Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | Yes | Display name for the plugin |
| slug | string | Yes | Unique identifier (must match directory name) |
| type | enum | Yes | `viewer`, `editor`, or `player` |
| supported_mimes | array | Yes | MIME types the plugin supports. Use wildcards like `image/*` |
| resources | object | Yes | Object with `js` and `css` arrays |
| config | object | No | Custom configuration passed to the plugin |

## JavaScript API

Each plugin must expose an initialization function named `<slug>_init` that receives an options object:

```javascript
function my_plugin_init(options) {
    const container = options.container;  // DOM element for plugin output
    const file = options.file;           // File object from API
    const config = options.config;       // Plugin configuration

    // Initialize your plugin
    container.innerHTML = '<div>My Plugin Content</div>';
}
```

### File Object

```javascript
{
    id: 123,
    name: "document.pdf",
    size: 1024567,
    mime_type: "application/pdf",
    created_at: "2024-01-15T10:30:00Z",
    // ... other file properties
}
```

### Example Plugin

```javascript
// public/plugins/my-viewer/viewer.js
(function() {
    'use strict';

    function my_viewer_init(options) {
        const { container, file, config } = options;

        // Create plugin UI
        container.innerHTML = `
            <div class="my-viewer">
                <h2>${file.name}</h2>
                <p>Size: ${file.size} bytes</p>
                <div class="viewer-content">
                    <!-- Plugin renders here -->
                </div>
            </div>
        `;

        // Initialize your viewer library
        // loadPdf(file.url);
    }

    // Register the plugin
    if (typeof window !== 'undefined') {
        window.my_viewer_init = my_viewer_init;
    }
})();
```

## Creating a New Plugin

1. **Create directory**: `public/plugins/<your-plugin-slug>/`

2. **Create manifest.json**:
   ```bash
   cd public/plugins/your-plugin-slug
   cat > manifest.json << 'EOF'
   {
       "name": "Your Plugin",
       "slug": "your-plugin",
       "type": "viewer",
       "supported_mimes": ["application/pdf"],
       "resources": {
           "js": ["/plugins/your-plugin/viewer.js"],
           "css": ["/plugins/your-plugin/viewer.css"]
       }
   }
   EOF
   ```

3. **Create JavaScript**:
   ```bash
   cat > viewer.js << 'EOF'
   (function() {
       function your_plugin_init(options) {
           options.container.innerHTML = '<div>Plugin content</div>';
       }
       window.your_plugin_init = your_plugin_init;
   })();
   EOF
   ```

4. **Create CSS**:
   ```bash
   cat > viewer.css << 'EOF'
   .your-plugin { color: red; }
   EOF
   ```

5. **Register in database** (via admin panel or seeder):
   ```php
   FileToolPlugin::create([
       'slug' => 'your-plugin',
       'name' => 'Your Plugin',
       'type' => 'viewer',
       'supported_mimes' => ['application/pdf'],
       'resources' => [
           'js' => ['/plugins/your-plugin/viewer.js'],
           'css' => ['/plugins/your-plugin/viewer.css']
       ],
       'is_active' => true
   ]);
   ```

6. **Assign to users** via admin panel or programmatically.

## MIME Type Matching

The system supports:
- Exact matches: `"application/pdf"`
- Wildcard subtype: `"image/*"` matches any image MIME type
- Wildcard type: `"*/*"` matches all MIME types (use sparingly)

## Best Practices

1. **Namespace your CSS**: Use unique class prefixes to avoid conflicts
2. **Handle errors gracefully**: Show fallback UI if resources fail to load
3. **Cleanup on close**: Remove event listeners and resources when modal closes
4. **Use iframes for complex plugins**: Isolate styles and scripts from main app
5. **Validate file access**: Ensure user has permission before displaying content