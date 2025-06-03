<?php

namespace App\Models;

use App\Services\DatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

abstract class BaseModel extends Model
{
    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The system fields that are present in most ISPConfig tables
     */
    protected $systemFields = [
        'sys_userid',
        'sys_groupid',
        'sys_perm_user',
        'sys_perm_group',
        'sys_perm_other',
        'server_id',
        'active'
    ];

    /**
     * Save changes to the datalog instead of directly to the database
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        $wasUpdate = $this->exists;
        $action = $wasUpdate ? 'u' : 'i';

        // For updates, capture original attributes before they are changed by parent::save()
        $original_attributes_for_datalog = $wasUpdate ? $this->getOriginal() : [];

        // Perform the actual save operation using Eloquent's parent::save()
        $saved = parent::save($options);

        if ($saved) {
            $primaryKeyValue = $this->getKey(); // Now contains the ID for inserts
            $current_attributes_for_datalog = $this->getAttributes(); // Get all current attributes after save

            // Ensure the primary key is in the 'new' attributes for datalog, especially for inserts.
            // Eloquent's getAttributes() after save should include the ID.
            if (!$wasUpdate && $this->getKeyName() && !isset($current_attributes_for_datalog[$this->getKeyName()])) {
                 $current_attributes_for_datalog[$this->getKeyName()] = $primaryKeyValue;
            }

            // The $original_attributes_for_datalog holds the 'old' state for updates.
            // For inserts, it's an empty array, which is correct for datalog's 'old' part.

            if ($action === 'u') {
                $diff_old = [];
                $diff_new = [];
                $all_keys = array_unique(array_merge(array_keys($original_attributes_for_datalog), array_keys($current_attributes_for_datalog)));

                foreach ($all_keys as $key) {
                    $old_value = $original_attributes_for_datalog[$key] ?? null;
                    $new_value = $current_attributes_for_datalog[$key] ?? null;

                    // A field is considered changed if its value differs, or if it's added/removed.
                    // Note: ISPConfig's diffrec might have slightly different nuances for null vs. not set,
                    // but this covers the main cases of value changes, additions, and removals.
                    if ($old_value !== $new_value || 
                        (array_key_exists($key, $original_attributes_for_datalog) && !array_key_exists($key, $current_attributes_for_datalog)) || 
                        (!array_key_exists($key, $original_attributes_for_datalog) && array_key_exists($key, $current_attributes_for_datalog))) {
                        
                        // If the key existed in the old record, store its old value
                        if (array_key_exists($key, $original_attributes_for_datalog)) {
                            $diff_old[$key] = $old_value;
                        }
                        // If the key exists in the new record, store its new value
                        if (array_key_exists($key, $current_attributes_for_datalog)) {
                            $diff_new[$key] = $new_value;
                        }
                    }
                }

                // Only proceed if there are actual changes
                if (empty($diff_new) && empty($diff_old)) {
                    return $saved; // No changes to log for an update
                }
                $datalog_payload = ['new' => $diff_new, 'old' => $diff_old];

            } else { // For inserts ('i')
                $datalog_payload = [
                    'new' => $current_attributes_for_datalog,
                    'old' => [] // Original attributes are empty for inserts
                ];
            }

            // Determine server_id and sys_userid for the datalog entry itself
            // Prefer 'new' state, fallback to 'old' (relevant for fields not changing or on delete)
            $datalog_server_id = $current_attributes_for_datalog['server_id']
                               ?? ($original_attributes_for_datalog['server_id'] ?? 0);
            $datalog_sys_userid = $current_attributes_for_datalog['sys_userid']
                                ?? ($original_attributes_for_datalog['sys_userid'] ?? 1);

            $datalogService = App::make(DatalogService::class);
            $datalogService->log(
                $this->getTable(), // Use Eloquent's getTable()
                $this->getKeyName(),
                $primaryKeyValue,
                $action,
                $datalog_payload,
                $datalog_server_id,
                $datalog_sys_userid
            );
        }

        return $saved;
    }

    /**
     * Delete the model from the database via datalog
     *
     * @return bool|null
     */
    public function delete()
    {
        if (is_null($this->getKeyName())) {
            throw new \Exception('No primary key defined on model.');
        }

        if (!$this->exists) {
            // Or return false, or throw exception, depending on desired behavior for non-existent model delete attempt
            return true;
        }

        $original_attributes_for_datalog = $this->getAttributes(); // Capture attributes before actual deletion
        $primaryKeyValue = $this->getKey();

        // Perform the actual delete operation using Eloquent's parent::delete()
        $deleted = parent::delete();

        if ($deleted) {
            $datalog_payload = [
                'new' => [], // 'new' state is empty for deletes
                'old' => $original_attributes_for_datalog
            ];

            $datalog_server_id = $original_attributes_for_datalog['server_id'] ?? 0;
            $datalog_sys_userid = $original_attributes_for_datalog['sys_userid'] ?? 1;

            $datalogService = App::make(DatalogService::class);
            $datalogService->log(
                $this->getTable(), // Use Eloquent's getTable()
                $this->getKeyName(),
                $primaryKeyValue,
                'd',
                $datalog_payload,
                $datalog_server_id,
                $datalog_sys_userid
            );
        }

        return $deleted;
    }
}
