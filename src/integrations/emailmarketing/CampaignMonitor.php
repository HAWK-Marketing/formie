<?php
namespace verbb\formie\integrations\emailmarketing;

use verbb\formie\base\Integration;
use verbb\formie\base\EmailMarketing;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\EmailMarketingField;
use verbb\formie\models\EmailMarketingList;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;

class CampaignMonitor extends EmailMarketing
{
    // Properties
    // =========================================================================

    public $handle = 'campaignMonitor';


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function getName(): string
    {
        return Craft::t('formie', 'Campaign Monitor');
    }

    /**
     * @inheritDoc
     */
    public function getIconUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/emailmarketing/dist/img/campaign-monitor.svg', true);
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'Sign up users to your Campaign Monitor lists to grow your audience for campaigns.');
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('formie/integrations/email-marketing/campaign-monitor/_plugin-settings', [
            'integration' => $this,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFormSettingsHtml(Form $form): string
    {
        return Craft::$app->getView()->renderTemplate('formie/integrations/email-marketing/campaign-monitor/_form-settings', [
            'integration' => $this,
            'form' => $form,
            'listOptions' => $this->getListOptions(),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave(): bool
    {
        if ($this->enabled) {
            $apiKey = $this->settings['apiKey'] ?? '';
            $clientId = $this->settings['clientId'] ?? '';

            if (!$apiKey) {
                $this->addError('apiKey', Craft::t('formie', 'API key is required.'));
                return false;
            }

            if (!$clientId) {
                $this->addError('clientId', Craft::t('formie', 'Client ID is required.'));
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchLists()
    {
        $allLists = [];

        try {
            $clientId = $this->settings['clientId'] ?? '';

            $lists = $this->_request('GET', 'clients/' . $clientId . '/lists.json');

            foreach ($lists as $list) {
                // While we're at it, fetch the fields for the list
                $fields = $this->_request('GET', 'lists/' . $list['ListID'] . '/customfields.json');

                $listFields = [
                    new EmailMarketingField([
                        'tag' => 'Email',
                        'name' => Craft::t('formie', 'Email'),
                        'type' => 'email',
                        'required' => true,
                    ]),
                    new EmailMarketingField([
                        'tag' => 'Name',
                        'name' => Craft::t('formie', 'Name'),
                        'type' => 'name',
                    ]),
                ];

                foreach ($fields as $field) {
                    $listFields[] = new EmailMarketingField([
                        'tag' => str_replace(['[', ']'], '', $field['Key']),
                        'name' => $field['FieldName'],
                        'type' => $field['DataType'],
                    ]);
                }

                $allLists[] = new EmailMarketingList([
                    'id' => $list['ListID'],
                    'name' => $list['Name'],
                    'fields' => $listFields,
                ]);
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }

        return $allLists;
    }

    /**
     * @inheritDoc
     */
    public function sendPayload(Submission $submission): bool
    {
        try {
            $fieldValues = $this->getFieldMappingValues($submission);

            // Pull out email, as it needs to be top level
            $email = ArrayHelper::remove($fieldValues, 'Email');
            $name = ArrayHelper::remove($fieldValues, 'Name');

            // Format custom fields
            $customFields = [];

            foreach ($fieldValues as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $customFields[] = [
                            'Key' => $key,
                            'Value' => $v,
                        ];
                    }
                } else {
                    $customFields[] = [
                        'Key' => $key,
                        'Value' => $value,
                    ];
                }
            }

            $payload = [
                'EmailAddress' => $email,
                'Name' => $name,
                'CustomFields' => $customFields,
                'Resubscribe' => true,
                'RestartSubscriptionBasedAutoresponders' => true,
                'ConsentToTrack' => 'Yes',
            ];

            // Allow events to cancel sending
            if (!$this->beforeSendPayload($submission)) {
                return false;
            }

            // Add or update
            $response = $this->_request('POST', "subscribers/{$this->listId}.json", [
                'json' => $payload,
            ]);

            // Allow events to say the response is invalid
            if (!$this->afterSendPayload($submission, $response)) {
                return false;
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function fetchConnection(): bool
    {
        try {
            $clientId = $this->settings['clientId'] ?? '';

            $response = $this->_request('GET', 'clients/' . $clientId . '.json');
            $error = $response['error'] ?? '';
            $apiKey = $response['ApiKey'] ?? '';

            if ($error) {
                Integration::error($this, $error, true);
                return false;
            }

            if (!$apiKey) {
                Integration::error($this, 'Unable to find “{ApiKey}” in response.', true);
                return false;
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Private Methods
    // =========================================================================

    private function _getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        $apiKey = $this->settings['apiKey'] ?? '';

        if (!$apiKey) {
            Integration::error($this, 'Invalid API Key for Campaign Monitor', true);
        }

        return $this->_client = Craft::createGuzzleClient([
            'base_uri' => 'https://api.createsend.com/api/v3.2/',
            'auth' => [$apiKey, 'formie'],
        ]);
    }

    private function _request(string $method, string $uri, array $options = [])
    {
        $response = $this->_getClient()->request($method, trim($uri, '/'), $options);

        return Json::decode((string)$response->getBody());
    }
}