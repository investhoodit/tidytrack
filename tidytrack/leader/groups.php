<?php
// PHP block for groups.php
session_start();
require_once '../dp.php';

$userId = $_SESSION['user_id'] ?? null;

// Security check
if (!isset($userId) || $_SESSION['role'] !== 'leader') {
    http_response_code(403);
    die("Access Denied.");
}

// Ensure the database connection is available
if (!isset($conn) || $conn->connect_error) {
    // Return a structured error for JavaScript to handle
    http_response_code(500);
    die("Database Connection Error.");
}
?>

<style>
/* Group-specific Styles - Added here for AJAX loaded content 
    These should ideally be in a separate CSS file or your leader_index.php style block
*/
.groups-management-container {
    padding: 20px;
}
.group-section {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    margin-bottom: 30px;
}
.group-section h3 {
    font-size: 18px; 
    color: #333; 
    margin-bottom: 15px;
    border-bottom: 1px solid #f0f0f0;
    padding-bottom: 10px;
}
.group-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}
.group-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    border: 1px solid #eee;
    border-left: 5px solid #1d3557; /* Accent border */
    border-radius: 6px;
    transition: background-color 0.2s;
}
.group-item:hover {
    background-color: #f9f9f9;
}
.group-info {
    display: flex;
    align-items: center;
    gap: 15px;
}
.group-icon-display {
    font-size: 24px;
    color: #1d3557;
    width: 40px;
    text-align: center;
}
.group-details {
    display: flex;
    flex-direction: column;
}
.group-details strong {
    font-size: 16px;
    color: #333;
}
.group-details span {
    font-size: 12px;
    color: #777;
}
.group-actions button {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: opacity 0.2s;
}
.group-actions .delete-btn {
    background: #dc3545;
    color: white;
}
.group-actions .delete-btn:hover {
    opacity: 0.8;
}

.request-actions button {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s ease;
            margin-left: 8px;
        }

        .request-actions .approve-btn {
            background: var(--success);
            color: #fff;
        }

        .request-actions .approve-btn:hover {
            background: #1e7e34;
        }

        .request-actions .reject-btn {
            background: var(--danger);
            color: #fff;
        }

        .request-actions .reject-btn:hover {
            background: #bd2130;
        }
</style>

<div class="groups-management-container">
    
    <h2 style="font-size: 24px; color: #1d3557; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px;">
        <i class="fas fa-users-cog"></i> Group Management
    </h2>

    <div class="create-group group-section">
        <h3><i class="fas fa-plus-circle"></i> Create New Group</h3>
        
        <form id="createGroupForm" style="display: flex; flex-direction: column; gap: 10px;">
            <input type="text" name="groupName" placeholder="Group Name" required style="padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
            <textarea name="description" placeholder="Group Description" style="padding: 10px; border: 1px solid #ccc; border-radius: 4px;"></textarea>
            
            <select name="groupType" required style="padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                <option value="private">Private (Invite Only)</option>
                <option value="public">Public (Anyone can join)</option>
            </select>
            
            <select name="groupIcon" required style="padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                    <option value="fas fa-users">Users</option>
                    <option value="fas fa-bullhorn">Bullhorn</option>
                    <option value="fas fa-user-tie">User Tie</option>
                    <option value="fas fa-tasks">Tasks</option>
                    <option value="fas fa-coffee">Coffee</option>
                    <option value="fas fa-bug">Bug</option>
                    <option value="fas fa-headset">Headset</option>
                    <option value="fas fa-cogs">Cogs</option>
                    <option value="fas fa-chart-line">Chart Line</option>
                    <option value="fas fa-folder">Folder</option>
            </select>
            
            <button type="submit" style="padding: 10px; background: #1d3557; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Create Group</button>
        </form>
    </div>

    <div class="my-groups group-section">
        <h3><i class="fas fa-layer-group"></i> Groups I Lead</h3>
        
        <div id="myGroupsContainer" class="group-list">
            <p style="color: #666;">Loading your groups...</p>
        </div>
    </div>
    
    <div class="pending-requests group-section">
        <h3><i class="fas fa-user-clock"></i> Pending Join Requests</h3>
        
        <div id="pendingRequestsContainer" class="group-list">
            <p style="color: #666;">Loading requests...</p>
        </div>
    </div>
    
    </div>
    
    <script>
        
             function loadPage(page) {
       fetch(page, { headers: { 'X-Requested-With': 'fetch' } })
         .then(res => {
           if (!res.ok) throw new Error('Page not found');
           return res.text();
         })
         .then(html => {
           setHTMLAndRunScripts(document.getElementById('main-content'), html);
           // keep dark mode after page injections
           applyDarkFromStorage();
           if (page === 'groups.php' && typeof attachGroupPageListeners === 'function') attachGroupPageListeners();
         })
         .catch(err => {
           document.getElementById('main-content').innerHTML =
             `<div class="alert alert-danger mt-3">Error: ${err.message}</div>`;
         });
     }

        
    </script>