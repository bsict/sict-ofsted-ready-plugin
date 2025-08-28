<attempt_completion>
<result>
Below is a detailed implementation plan that covers all dependent files and changes to address the two issues – the non‐responsive “View” button on the history page and the “Export” button on the Generated Content page.

--------------------------------------------------
**Overview**  
This plan targets the following files:  
- The main plugin PHP file (which registers AJAX endpoints and renders the admin pages)  
- The JavaScript file (assets/admin.js) handling the button click events  
- The CSS file (assets/admin.css) for modern UI styling including a new modal and export dropdown effects  

--------------------------------------------------
**1. Main Plugin PHP File (SICT_OfstedReady plugin file)**  
- *AJAX Endpoint Verification:*  
  - Confirm that the AJAX actions “sict_load_content” (for “View”) and “sict_export_content”/“sict_create_post” (for “Export”) are registered.  
  - Ensure nonce checks and permission verifications are in place for all endpoints.  

- *UI Markup Enhancements:*  
  - In the history_page method, append a hidden modal container at the end of the HTML output. For example, add:  
    ```php
    <div id="sict-content-modal" class="sict-modal" style="display:none;">
      <div class="sict-modal-content">
        <span class="sict-modal-close">&times;</span>
        <div class="sict-modal-body"></div>
      </div>
    </div>
    ```  
  - This modal will display the full content when a user clicks “View.”

- *Logging & Error Handling:*  
  - Optionally add logging (using error_log) inside load_content_ajax() and export_content_ajax() for debugging unexpected responses.

--------------------------------------------------
**2. JavaScript File (assets/admin.js)**  
- *Bind “View” Button Events:*  
  - Use jQuery’s document ready function to attach a click event on elements with the class “view-content.”  
  - Extract the “data-content-id” attribute and send an AJAX POST request to the “sict_load_content” endpoint with the nonce and content ID.  
  - On success, insert the returned content into the modal’s body and display the modal; on failure, show an error alert.  
  - Example snippet:
    ```javascript
    jQuery(document).ready(function($) {
      $('.view-content').on('click', function() {
        var contentId = $(this).data('content-id');
        $.post(sict_or_ajax.ajax_url, { 
          action: 'sict_load_content', 
          nonce: sict_or_ajax.nonce, 
          content_id: contentId 
        }, function(response) {
          if(response.success) {
            $('.sict-modal-body').html(response.data.content);
            $('#sict-content-modal').fadeIn();
          } else {
            alert(response.data);
          }
        });
      });
      
      // Modal close functionality
      $('.sict-modal-close').on('click', function() {
        $('#sict-content-modal').fadeOut();
      });
    });
    ```

- *Bind “Export” Functionality:*  
  - Attach a click event to the dropdown toggle to show/hide export options.  
  - For each export option (button with class “export-option”), use its data attributes (data-format and data-action) to decide whether to call “sict_export_content” or “sict_create_post.”  
  - Use an AJAX request sending the generated content (retrieved from the DOM) and its type.  
  - On success, either redirect (if a URL is provided for PDF/DOC) or display a notification (for plain text or WordPress post creation).  
  - Example snippet:
    ```javascript
    $('.sict-or-export-dropdown .dropdown-toggle').on('click', function() {
      $(this).siblings('.sict-or-export-menu').toggle();
    });
    
    $('.sict-or-export-menu .export-option').on('click', function() {
      var format = $(this).data('format');
      var actionType = $(this).data('action');
      var content = $('#generated_content').html(); // generated content area
      var contentType = $('#generated_policy_type').text();
      if (!content) { 
        alert('No content available for export.'); 
        return;
      }
      $.post(sict_or_ajax.ajax_url, {
        action: (actionType === 'export') ? 'sict_export_content' : 'sict_create_post',
        nonce: sict_or_ajax.nonce,
        content: content,
        content_type: contentType,
        format: format
      }, function(response) {
        if(response.success) {
          if(format === 'pdf' || format === 'doc'){
            window.location.href = response.data.url || '';
          } else if(format === 'post'){
            alert("Post created! Edit here: " + response.data.edit_url);
          } else {
            alert(response.data.message || 'Export successful.');
          }
        } else {
          alert(response.data);
        }
      });
    });
    ```

--------------------------------------------------
**3. CSS File (assets/admin.css)**  
- *Modern Modal and Dropdown Styling:*  
  - Create a modern, minimal modal style using clean typography and ample spacing. Example styles:  
    ```css
    .sict-modal {
      position: fixed;
      top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 9999;
    }
    .sict-modal-content {
      background: #fff;
      padding: 20px;
      max-width: 800px;
      width: 90%;
      border-radius: 4px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .sict-modal-close {
      float: right;
      font-size: 24px;
      cursor: pointer;
    }
    .sict-or-export-menu {
      background: #fff;
      border: 1px solid #ddd;
      padding: 10px;
      position: absolute;
      margin-top: 5px;
      border-radius: 4px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      display: none;
    }
    .sict-or-export-menu .export-option {
      display: block;
      margin-bottom: 5px;
      padding: 6px 12px;
      color: #333;
      text-align: left;
      width: 100%;
      background: #f7f7f7;
      border: none;
      cursor: pointer;
    }
    .sict-or-export-menu .export-option:hover {
      background: #e2e2e2;
    }
    ```  
  - Ensure that all UI elements use only typography, color, spacing, and layout rules without external icon libraries.

--------------------------------------------------
**4. Integration & Testing**  
- *User Flow Testing:*  
  - On the History Page, clicking “View” should trigger an AJAX call and then display the content modal with full details.  
  - On the Generated Content Page, clicking the “Export” dropdown, then selecting an export option must begin the appropriate download or post creation process.  

- *Error Handling:*  
  - Ensure error messages are shown via alert or inline notifications if the AJAX request fails or returns an error.  
  - Use browser console logs and PHP error logs (via error_log) to review any backend issues.

- *Real-World Scenario:*  
  - This solution mimics a production-level plugin where content is generated via AI, stored, and later made viewable/exportable with options to save in PDF, DOC, TXT, or create a WordPress post. Authentication (nonce) and admin permission checks are enforced throughout.

--------------------------------------------------
**Summary**  
- The plan revises the PHP file to verify AJAX registration and adds a modal container for viewing content.  
- The assets/admin.js file is updated to bind click events for “View” and “Export” buttons, with AJAX calls and proper error handling.  
- The CSS file is modernized with a clean, accessible design for the modal and dropdown elements.  
- Logging and error notifications are added to assist in debugging.  
- Integration tests using browser tools and curl commands are recommended to ensure functionality.  
- This approach produces a robust, user-friendly interface for managing AI-generated content with multiple export options.
</result>
</attempt_completion>
