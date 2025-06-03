<?php

use Laravel\Lumen\Testing\DatabaseMigrations;
use Laravel\Lumen\Testing\DatabaseTransactions;

class ClientApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test the client listing endpoint
     *
     * @return void
     */
    public function testClientListing()
    {
        $this->get('/api/v1/clients', [
            'X-API-Key' => 'test_api_key'
        ]);

        $this->assertResponseOk();
        $this->seeJsonStructure([
            'items',
            'total',
            'limit',
            'offset'
        ]);
    }

    /**
     * Test client creation
     *
     * @return void
     */
    public function testClientCreation()
    {
        $data = [
            'company_name' => 'Test Company',
            'contact_name' => 'John Doe',
            'email' => 'john@example.com',
            'username' => 'testuser' . time(),
            'password' => 'password123'
        ];

        $this->post('/api/v1/clients', $data, [
            'X-API-Key' => 'test_api_key'
        ]);

        // Should return 202 Accepted since changes go through datalog
        $this->assertResponseStatus(202);

        // Verify datalog entry was created
        $this->seeInDatabase('sys_datalog', [
            'dbtable' => 'client',
            'action' => 'i'
        ]);
    }

    /**
     * Test client update
     *
     * @return void
     */
    public function testClientUpdate()
    {
        // Assuming client with ID 1 exists
        $clientId = 1;
        $data = [
            'company_name' => 'Updated Company Name'
        ];

        $this->put('/api/v1/clients/' . $clientId, $data, [
            'X-API-Key' => 'test_api_key'
        ]);

        // Should return 202 Accepted or 404 if client doesn't exist
        if ($this->response->getStatusCode() == 404) {
            $this->markTestSkipped('Client ID ' . $clientId . ' not found');
        } else {
            $this->assertResponseStatus(202);

            // Verify datalog entry was created
            $this->seeInDatabase('sys_datalog', [
                'dbtable' => 'client',
                'dbidx' => 'client_id:' . $clientId,
                'action' => 'u'
            ]);
        }
    }

    /**
     * Test client deletion
     *
     * @return void
     */
    public function testClientDeletion()
    {
        // Assuming client with ID 1 exists
        $clientId = 1;

        $this->delete('/api/v1/clients/' . $clientId, [], [
            'X-API-Key' => 'test_api_key'
        ]);

        // Should return 202 Accepted or 404 if client doesn't exist
        if ($this->response->getStatusCode() == 404) {
            $this->markTestSkipped('Client ID ' . $clientId . ' not found');
        } else {
            $this->assertResponseStatus(202);

            // Verify datalog entry was created
            $this->seeInDatabase('sys_datalog', [
                'dbtable' => 'client',
                'dbidx' => 'client_id:' . $clientId,
                'action' => 'd'
            ]);
        }
    }
}
