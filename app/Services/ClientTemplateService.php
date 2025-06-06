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

        // Apply template changes to client limits
        $this->applyClientTemplates($clientId);

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
            // Check if client already has a master template
            if ($client->template_master) {
                throw new \Exception("Client already has a master template assigned");
            }
            
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
            // Check if client already has this additional template
            $hasTemplate = $client->addonTemplates()
                ->where('client_template.template_id', $templateId)
                ->exists();

            if ($hasTemplate) {
                throw new \Exception("Client already has this template assigned");
            }
            
            // For additional templates, use the belongsToMany relationship
            // This will automatically create the entry in the pivot table
            $client->addonTemplates()->attach($templateId);
            
            // Apply template changes to client limits
            $this->applyClientTemplates($clientId);
            
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
        
        // Apply template changes to client limits
        $this->applyClientTemplates($clientId);

        return true;
    }
    
    /**
     * Delete a template assignment
     * 
     * @param int $clientId
     * @param int $templateId
     * @return array|null
     */
    public function deleteTemplateAssignment($clientId, $templateId)
    {
        // Get the client and template
        $client = Client::findOrFail($clientId);
        $template = ClientTemplate::findOrFail($templateId);

        // Determine if this is a master template or an additional template
        if ($template->template_type === 'm' && $client->template_master == $templateId) {
            // Unassign master template
            $client->template_master = null;
            $client->save();
            $result = ['client_id' => $clientId, 'client_template_id' => $templateId, 'is_master' => true];
        } else {
            // Unassign additional template
            $hasTemplate = $client->addonTemplates()
                ->where('client_template.template_id', $templateId)
                ->exists();
                
            if (!$hasTemplate) {
                throw new \Exception("Template assignment not found");
            }
            
            $client->addonTemplates()->detach($templateId);
            $result = ['client_id' => $clientId, 'client_template_id' => $templateId, 'is_master' => false];
        }
        
        // Apply template changes to client limits
        $this->applyClientTemplates($clientId);
        
        return $result;
    }

    /**
     * Field type definitions for client template fields
     * Based on the form definitions in ISPConfig
     */
    protected $fieldTypes = [
        // CHECKBOXARRAY fields - values are combined with separator
        'ssh_chroot' => ['type' => 'CHECKBOXARRAY', 'separator' => ','],
        'web_php_options' => ['type' => 'CHECKBOXARRAY', 'separator' => ','],
        
        // MULTIPLE fields - values are combined with separator
        'mail_servers' => ['type' => 'MULTIPLE', 'separator' => ','],
        'web_servers' => ['type' => 'MULTIPLE', 'separator' => ','],
        'dns_servers' => ['type' => 'MULTIPLE', 'separator' => ','],
        'db_servers' => ['type' => 'MULTIPLE', 'separator' => ','],
        
        // CHECKBOX fields - special handling for y/n values
        'force_suexec' => ['type' => 'CHECKBOX', 'less_limited' => 'n'], // n is less limited
        'active' => ['type' => 'CHECKBOX', 'less_limited' => 'y'], // y is less limited
        'limit_cgi' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_ssi' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_perl' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_ruby' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_python' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'force_suexec' => ['type' => 'CHECKBOX', 'less_limited' => 'n'],
        'limit_hterror' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_wildcard' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_ssl' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_ssl_letsencrypt' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        'limit_directive_snippets' => ['type' => 'CHECKBOX', 'less_limited' => 'y'],
        
        // SELECT fields - lower index value is chosen
        'limit_shell_user' => ['type' => 'SELECT'],
        'limit_webdav_user' => ['type' => 'SELECT'],
        'limit_backup' => ['type' => 'SELECT']
    ];
    
    /**
     * Apply client templates to update client limits
     *
     * @param int $clientId
     * @return bool
     */
    public function applyClientTemplates($clientId)
    {
        // Get client record
        $client = Client::find($clientId);
        if (!$client) {
            return false;
        }
        
        // Check if client is a reseller
        $isReseller = ($client->limit_client != 0);
        
        // Get master template
        $masterTemplate = $client->masterTemplate;
        if (!$masterTemplate) {
            // No master template, nothing to apply
            return false;
        }
        
        // Start with master template limits
        $limits = $masterTemplate->getAttributes();
        
        // Handle reseller-specific adjustments for master template
        if ($isReseller && $limits['limit_client'] == 0) {
            $limits['limit_client'] = -1;
        } elseif (!$isReseller && $limits['limit_client'] != 0) {
            $limits['limit_client'] = 0;
        }
        
        // Get additional templates
        $additionalTemplates = $client->addonTemplates;

        // Process additional templates
        foreach ($additionalTemplates as $template) {
            // Handle reseller-specific adjustments for additional templates
            if ($isReseller && $template->limit_client == 0) {
                continue;
            }
            
            // Process each limit field in the additional template
            foreach ($template->getAttributes() as $key => $value) {
                // Skip non-limit fields and empty values
                if ((!strpos($key, 'limit_') === 0 && 
                     !strpos($key, 'default_') === 0 && 
                     !strpos($key, '_servers') !== false && 
                     $key != 'ssh_chroot' && 
                     $key != 'web_php_options' && 
                     $key != 'force_suexec') || 
                    empty($value)) {
                    continue;
                }
                
                // Handle numeric limits
                if (is_numeric($value)) {
                    // Skip if the current limit is unlimited (-1)
                    if (isset($limits[$key]) && $limits[$key] == -1) {
                        continue;
                    }
                    
                    // For default server fields, take the first non-zero value
                    if (strpos($key, 'default_') === 0) {
                        if (!isset($limits[$key]) || $limits[$key] == 0) {
                            $limits[$key] = $value;
                        }
                    } 
                    // For all other numeric fields, add the values
                    else {
                        if (!isset($limits[$key])) {
                            $limits[$key] = 0;
                        }
                        
                        // If either value is unlimited (-1), result is unlimited
                        if ($limits[$key] == -1 || $value == -1) {
                            $limits[$key] = -1;
                        } else {
                            $limits[$key] += $value;
                        }
                    }
                }
                // Handle string limits based on field type
                elseif (is_string($value)) {
                    // Get field type definition
                    $fieldType = isset($this->fieldTypes[$key]) ? $this->fieldTypes[$key] : null;
                    
                    // Handle based on field type
                    if ($fieldType) {
                        switch ($fieldType['type']) {
                            case 'CHECKBOXARRAY':
                            case 'MULTIPLE':
                                // For fields that combine values with a separator
                                if (!isset($limits[$key])) {
                                    $limits[$key] = '';
                                }
                                
                                $separator = isset($fieldType['separator']) ? $fieldType['separator'] : ',';
                                $limitsValues = !empty($limits[$key]) ? explode($separator, $limits[$key]) : [];
                                $additionalValues = !empty($value) ? explode($separator, $value) : [];
                                
                                // Combine values from both templates
                                $limitsUnified = array_unique(array_merge($limitsValues, $additionalValues));
                                $limits[$key] = implode($separator, array_filter($limitsUnified));
                                break;
                                
                            case 'CHECKBOX':
                                // For checkbox fields, determine which value is less limited
                                $lessLimited = isset($fieldType['less_limited']) ? $fieldType['less_limited'] : 'y';
                                
                                if (!isset($limits[$key])) {
                                    $limits[$key] = ($lessLimited == 'y') ? 'n' : 'y';
                                }
                                
                                if ($lessLimited == 'y') {
                                    // 'y' is less limited than 'n'
                                    if ($limits[$key] == 'y' || $value == 'y') {
                                        $limits[$key] = 'y';
                                    }
                                } else {
                                    // 'n' is less limited than 'y'
                                    if ($limits[$key] == 'n' || $value == 'n') {
                                        $limits[$key] = 'n';
                                    }
                                }
                                break;
                                
                            case 'SELECT':
                                // For SELECT fields, choose the lower index value
                                // In Laravel implementation, we'll just keep the master template value
                                // as we don't have access to the original form definition's value array
                                break;
                                
                            default:
                                // Default handling for unknown types
                                if (!isset($limits[$key])) {
                                    $limits[$key] = $value;
                                }
                        }
                    } else {
                        // Default handling for fields without explicit type definition
                        // For server lists
                        if (in_array($key, ['mail_servers', 'web_servers', 'dns_servers', 'db_servers'])) {
                            if (!isset($limits[$key])) {
                                $limits[$key] = '';
                            }
                            
                            $limitsValues = !empty($limits[$key]) ? explode(',', $limits[$key]) : [];
                            $additionalValues = !empty($value) ? explode(',', $value) : [];
                            
                            $limitsUnified = array_unique(array_merge($limitsValues, $additionalValues));
                            $limits[$key] = implode(',', array_filter($limitsUnified));
                        } 
                        // Default for other string fields - keep master template value
                        else {
                            if (!isset($limits[$key])) {
                                $limits[$key] = $value;
                            }
                        }
                    }
                }
            }
        }
        
        // Update client with new limits
        $updateData = [];
        
        // Only update limit and related fields
        foreach ($limits as $key => $value) {
            if ((strpos($key, 'limit_') === 0 || 
                 strpos($key, 'default_') === 0 || 
                 strpos($key, '_servers') !== false || 
                 $key == 'ssh_chroot' || 
                 $key == 'web_php_options' || 
                 $key == 'force_suexec') && 
                !is_array($value)) {
                
                // Skip default server fields with value 0
                if (strpos($key, 'default_') === 0 && $value == 0) {
                    continue;
                }
                
                // Don't set limit_client for non-resellers
                if (!$isReseller && $key == 'limit_client') {
                    continue;
                }
                
                $updateData[$key] = $value;
            }
        }
        
        // Update client record
        if (!empty($updateData)) {
            foreach ($updateData as $key => $value) {
                $client->$key = $value;
            }
            $client->save();
        }
        
        return true;
    }
}
