---
trigger: always_on
---

## Implementation Rules

1. **Always follow the YAML API spec**

   * For any new or updated endpoint, first open `/api/modules/{module_name}/*.yaml` and implement exactly the paths, methods, parameters and response definitions there.
   * Do *not* make up your own URL patterns, parameter names or status codes—mirror the spec verbatim.

2. **Use the shared schemas**

   * All request/response bodies must reference one of the schemas under `/api/components/schemas/*.yaml`.
   * Always use the correct schema when validating input/output.

3. **Keep behavior in sync with original ISPConfig**

   * Before coding any business logic, locate the existing implementation in the legacy codebase under `/source_code/interface/web/{module_name}/`.
   * Copy over field validations, default values, side-effects, and permission checks exactly.
   * Your implementation must behave identically unless explicitly discussed.

4. **Extend our BaseModel**

   * Every new data model in your API must extend `App\Models\BaseModel`.
   * Don’t roll your own save/delete logic—`BaseModel` provides built-in `save()` and `delete()` methods that handle datalogging and auditing.

5. **Prevent route shadowing**

    * When updating routes/web.php, do not shadow routes
    * always check and use proper order of routes

6. **Error handling & status codes**

   * Exception Handling in Controllers
      - a. Direct HTTP Response Approach
        The ClientController uses direct HTTP responses with appropriate status codes rather than throwing exceptions:

          Not Found (404): When a client is not found, it returns a JSON response with status code Response::HTTP_NOT_FOUND
          ```php
          return response()->json(['error' => 'Client not found'], Response::HTTP_NOT_FOUND);
    
          ```

          Bad Request (400): For validation failures like invalid parent_client_id
          ```php
          return response()->json([
             'message' => 'Parent reseller not found',
             'error' => 'The specified parent_client_id does not exist'
          ], Response::HTTP_BAD_REQUEST);
          ```

          Internal Server Error (500): For general exceptions caught in try/catch blocks
          ```php
          return response()->json([
            'message' => 'Failed to update client',
            'error' => $e->getMessage()
          ], Response::HTTP_INTERNAL_SERVER_ERROR);
          ```

           Accepted (202): For successful operations (following ISPConfig's datalog pattern)
           ```php
           return response()->json($client, Response::HTTP_ACCEPTED);
           ```

       - b. Transaction Management
         - Uses DB transactions with try/catch blocks
         - Rolls back transactions on exceptions
         - Logs errors with detailed context
       
       - c. Validation
         - Uses Laravel's built-in validation with $this->validate()
         - Validation failures automatically return 422 responses (handled by Laravel)
         - Custom validation rules are applied with model-based rules
   
       - d. No Custom Exception Classes

       - e. Error Response Format
         Consistent error response format:
    
           ```json
           {
             "message": "Human-readable message",
             "error": "Specific error details"
           }
           ```

7. **No local PHP server spins**

   * Don’t commit or push `php -S` scripts or references. We manage the local dev server via Docker Compose—your changes must work inside that container.
