<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;

class Controller extends BaseController
{
    /**
     * Get the current authenticated user ID
     * 
     * @return int User ID of the authenticated user, defaults to 1 (admin) if not authenticated
     */
    protected function getCurrentUserId()
    {
        // Get authenticated user ID or default to admin (1) if not authenticated
        return auth()->id() ?? 1;
    }
    
    /**
     * Get the current authenticated user's group ID
     * 
     * @return int Group ID of the authenticated user, defaults to 1 (admin group) if not authenticated
     */
    protected function getCurrentGroupId()
    {
        // Get authenticated user's group ID or default to admin group (1) if not authenticated
        if (auth()->check() && auth()->user()->default_group) {
            return auth()->user()->default_group;
        }
        
        return 1; // Default to admin group
    }
}
