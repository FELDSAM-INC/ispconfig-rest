<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientTemplate;
use App\Models\ClientTemplateAssigned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientTemplateService
{
    /**
     * Update client template assignments
     * 
     * @param int $clientId
     * @param array $templates Array of template assignments in format: ['assigned_id:template_id', 'template_id', ...]
     * @return bool
     */
    public function updateClientTemplates($clientId, $templates = [])
    {
        if (!is_array($templates)) {
            return false;
        }

        // Handle empty array - this means unassign all additional templates
        if (empty($templates)) {
            ClientTemplateAssigned::where('client_id', $clientId)->delete();
            return true;
        }

        $newTemplates = [];
        $usedAssigned = [];
        $neededTypes = [];
        $oldStyle = true;

        // Parse template assignments
        foreach ($templates as $item) {
            $item = trim($item);
            if ($item == '') continue;

            $templateId = 0;
            $assignedId = 0;

            if (strpos($item, ':') === false) {
                // Old style: just template ID
                $templateId = $item;
            } else {
                // New style: assigned_id:template_id
                $oldStyle = false;
                list($assignedId, $templateId) = explode(':', $item, 2);
                if (substr($assignedId, 0, 1) === 'n') {
                    $assignedId = 0; // newly inserted items
                }
            }

            if (!array_key_exists($templateId, $neededTypes)) {
                $neededTypes[$templateId] = 0;
            }
            $neededTypes[$templateId]++;

            if ($assignedId > 0) {
                $usedAssigned[] = $assignedId;
            } else {
                $newTemplates[] = $templateId;
            }
        }

        if ($oldStyle) {
            // Handle old-style template assignments
            $inDb = ClientTemplateAssigned::where('client_id', $clientId)
                ->select('assigned_template_id', 'client_template_id')
                ->get()
                ->toArray();

            if (count($inDb) > 0) {
                foreach ($inDb as $item) {
                    if (!array_key_exists($item['client_template_id'], $neededTypes)) {
                        $neededTypes[$item['client_template_id']] = 0;
                    }
                    $neededTypes[$item['client_template_id']]--;
                }
            }

            foreach ($neededTypes as $templateId => $count) {
                if ($count > 0) {
                    // Add new template assignments
                    for ($i = $count; $i > 0; $i--) {
                        $this->createTemplateAssignment($clientId, $templateId);
                    }
                } elseif ($count < 0) {
                    // Remove old template assignments
                    for ($i = $count; $i < 0; $i++) {
                        ClientTemplateAssigned::where('client_id', $clientId)
                            ->where('client_template_id', $templateId)
                            ->limit(1)
                            ->delete();
                    }
                }
            }
        } else {
            // Handle new-style template assignments
            $inDb = ClientTemplateAssigned::where('client_id', $clientId)
                ->select('assigned_template_id', 'client_template_id')
                ->get()
                ->toArray();

            if (count($inDb) > 0) {
                // Remove assignments that are no longer needed
                foreach ($inDb as $item) {
                    if (!in_array($item['assigned_template_id'], $usedAssigned)) {
                        ClientTemplateAssigned::where('assigned_template_id', $item['assigned_template_id'])
                            ->delete();
                    }
                }
            }

            // Add new template assignments
            if (count($newTemplates) > 0) {
                foreach ($newTemplates as $templateId) {
                    $this->createTemplateAssignment($clientId, $templateId);
                }
            }
        }

        return true;
    }

    /**
     * Create a new template assignment
     * 
     * @param int $clientId
     * @param int $templateId
     * @return array Response with assignment details
     * @throws \Exception
     */
    public function createTemplateAssignment($clientId, $templateId)
    {
        // Get client
        $client = Client::find($clientId);
        
        if (!$client) {
            throw new \Exception("Client not found");
        }
        
        // Check if this is a master template or additional template
        $template = ClientTemplate::find($templateId);
        
        if (!$template) {
            throw new \Exception("Template not found");
        }
        
        // If it's a master template, assign it to the client
        if ($template->template_type === 'm') {
            // Update the master template
            $this->updateClientMasterTemplate($clientId, $templateId);
            
            // Return formatted response
            return [
                'client_id' => $clientId,
                'client_template_id' => $templateId,
                'is_master' => true,
                'template' => $template
            ];
        } else {
            // For additional templates, use the belongsToMany relationship
            // This will automatically create the entry in the pivot table
            $client->addonTemplates()->attach($templateId, [
                'sys_userid' => $client->sys_userid,
                'sys_groupid' => $client->sys_groupid,
                'sys_perm_user' => $client->sys_perm_user,
                'sys_perm_group' => $client->sys_perm_group,
                'sys_perm_other' => $client->sys_perm_other
            ]);
            
            // Return formatted response
            return [
                'client_id' => $clientId,
                'client_template_id' => $templateId,
                'is_master' => false,
                'template' => $template
            ];
        }
    }

    /**
     * Get current template assignments for a client
     * 
     * @param int $clientId
     * @return array
     */
    public function getClientTemplateAssignments($clientId)
    {
        $assignments = ClientTemplateAssigned::where('client_id', $clientId)
            ->select('assigned_template_id', 'client_template_id')
            ->get()
            ->toArray();

        $result = [];
        foreach ($assignments as $assignment) {
            $result[] = $assignment['assigned_template_id'] . ':' . $assignment['client_template_id'];
        }

        return $result;
    }

    /**
     * Get legacy template assignments from template_additional field
     * 
     * @param string $templateAdditional
     * @return array
     */
    public function parseLegacyTemplateAssignments($templateAdditional)
    {
        if (empty($templateAdditional)) {
            return [];
        }

        $templates = explode('/', $templateAdditional);
        $result = [];
        
        foreach ($templates as $template) {
            $template = trim($template);
            if (!empty($template)) {
                $result[] = $template;
            }
        }

        return $result;
    }

    /**
     * Validate template assignments
     * 
     * @param array $templateIds
     * @return array Array of validation errors
     */
    public function validateTemplateAssignments($templateIds)
    {
        $errors = [];
        
        if (!is_array($templateIds)) {
            return $errors;
        }

        foreach ($templateIds as $templateId) {
            // Extract template ID from assignment format
            if (strpos($templateId, ':') !== false) {
                list($assignedId, $templateId) = explode(':', $templateId, 2);
            }
            
            $templateId = trim($templateId);
            if (empty($templateId)) {
                continue;
            }

            // Check if template exists
            $template = ClientTemplate::find($templateId);
            if (!$template) {
                $errors[] = "Template with ID {$templateId} does not exist";
            }
        }

        return $errors;
    }

    /**
     * Update client master template
     * 
     * @param int $clientId
     * @param int|null $templateId Template ID or null to unassign
     * @return bool
     */
    public function updateClientMasterTemplate($clientId, $templateId = null)
    {
        $client = Client::find($clientId);
        if (!$client) {
            return false;
        }

        // Update the template_master field directly
        $client->template_master = $templateId;
        $client->save();

        return true;
    }
    
    /**
     * Delete a template assignment
     * 
     * @param int $clientId
     * @param int $templateId
     * @return bool
     * @throws \Exception
     */
    public function deleteTemplateAssignment($clientId, $templateId)
    {
        $client = Client::find($clientId);
        
        if (!$client) {
            throw new \Exception("Client not found");
        }
        
        // Check if this is a master template or additional template
        $template = ClientTemplate::find($templateId);
        
        if (!$template) {
            throw new \Exception("Template not found");
        }
        
        // If it's a master template, clear the field in the client table
        if ($template->template_type === 'm') {
            // Only update if this is actually the client's master template
            if ($client->masterTemplate && $client->masterTemplate->template_id == $templateId) {
                $client->template_master = null;
                $client->save();
            } else {
                throw new \Exception("This template is not assigned as the master template for this client");
            }
        } else {
            // For additional templates, detach using the relationship
            $hasTemplate = $client->addonTemplates()
                ->where('client_template.template_id', $templateId)
                ->exists();
                
            if (!$hasTemplate) {
                throw new \Exception("Template assignment not found");
            }
            
            $client->addonTemplates()->detach($templateId);
        }
        
        return true;
    }
}
