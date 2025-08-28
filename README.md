
Built by https://www.blackbox.ai

---

# SICT Ofsted Ready Plugin

## Project Overview
The SICT Ofsted Ready Plugin is a WordPress plugin designed to manage AI-generated content seamlessly. It provides features for viewing, exporting, and creating posts from generated content. This plugin addresses issues related to non-responsive buttons on specific pages, enhancing the user experience with modern UI elements, modals, and AJAX functionality.

## Installation
To install the SICT Ofsted Ready Plugin, follow these steps:
1. Download the plugin ZIP file from the repository.
2. Go to your WordPress dashboard.
3. Navigate to **Plugins > Add New**.
4. Click on **Upload Plugin** and select the downloaded ZIP file.
5. Click **Install Now**, and then activate the plugin.

## Usage
Once installed and activated, you can:
- Navigate to the History Page to view previously generated content. Click on the "View" button to display the content in a modal window.
- Use the Generated Content Page to export your content. Click the "Export" dropdown to choose your desired format (PDF, DOC, etc.) and initiate the download or post creation process.

## Features
- **AJAX Functionality:** Seamless user interactions for viewing and exporting content without page reloads.
- **Modern UI:** Clean and accessible design for modals and dropdown menus that improve user experience.
- **Export Options:** Ability to export generated content in various formats or create WordPress posts directly.
- **Error Handling:** Alerts and notifications for users to notify of successful actions or issues.

## Dependencies
The SICT Ofsted Ready Plugin uses the following dependencies as found in the `package.json` file:

```json
{
  "dependencies": {
    "jquery": "^3.5.1"
  }
}
```

*(Note: Actual dependencies may need to be checked in the provided `package.json`)*

## Project Structure
The project consists of the following structure:

```
/sict-ofsted-ready-plugin/
│
├── assets/
│   ├── admin.js          # JavaScript for handling AJAX events and UI interactions
│   └── admin.css         # CSS for styling the plug-in's admin interface
│
├── includes/
│   ├── class-plugin.php   # Main plugin file that initializes the plugin and registers AJAX endpoints
│
├── languages/
│   └── plugin-translations.po  # Language files for localization (if applicable)
│
├── README.md              # Project documentation
└── plan.md                # Project implementation plan and notes
```

---

For further details, contributions, or issues, please check the repository's issue tracker or submit a pull request.