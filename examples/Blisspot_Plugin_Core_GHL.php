<?php
/**
 * SocialEngine
 *
 * GoHighLevel-adapted version of Blisspot_Plugin_Core.
 *
 * Notes:
 * - ActiveCampaign "lists" are emulated with tags: list:{id} and list_name:{name}
 * - ActiveCampaign custom fields field[ID,0] are mapped to associative customFields keys.
 */

require_once(APPLICATION_PATH . '/vendor/autoload.php');
require_once(APPLICATION_PATH_LIB . DS . 'OneSignal' . DS . 'vendor' . DS . 'autoload.php');

use onesignal\client\api\DefaultApi;
use onesignal\client\Configuration;
use GuzzleHttp\Client;

class Blisspot_Plugin_Core
{
    /** @var Client */
    protected $_ghlClient;

    /** @var string */
    protected $_ghlLocationId;

    /** @var Zend_Log */
    protected $_log;

    public function onUserSignupAfter($event)
    {
        try {
            $listid = (int) Engine_Api::_()->getApi('settings', 'core')->getSetting('blisspot.active.campaign.list', 29);
            if (empty($listid)) {
                $listid = 29;
            }

            $payload = $event->getPayload();
            if ($payload instanceof User_Model_User && $payload->verified) {
                if (!empty($payload->campaign_list_id)) {
                    $listid = $payload->campaign_list_id;
                }

                $teamMatesTable = Engine_Api::_()->getDbTable('teammates', 'customize');
                $startup = $teamMatesTable->fetchRow(array('email = ?' => $payload->email));
                if (!empty($startup)) {
                    $startupUser = Engine_Api::_()->user()->getUser($startup->user_id);
                    if (!empty($startupUser) && !empty($startupUser->campaign_list_id)) {
                        $listid = $startupUser->campaign_list_id;
                    }
                }

                if ($listid == '35') {
                    return;
                }
                if (in_array($listid, array(111, 112, 113, 114, 115, 116, 117, 118))) {
                    return;
                }

                $this->addUserToList($payload, $listid);
            }
        } catch (Exception $e) {
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
    }

    public function onUserVerifyAfter($event)
    {
        $this->onUserSignupAfter($event);
    }

    /**
     * Initializes GoHighLevel API client.
     *
     * Required settings:
     * - blisspot.ghl.api.key
     * - blisspot.ghl.location.id
     */
    public function requireApi()
    {
        if ($this->_ghlClient) {
            return $this->_ghlClient;
        }

        $settings = Engine_Api::_()->getApi('settings', 'core');
        $apiKey = $settings->getSetting('blisspot.ghl.api.key', '');
        $locationId = $settings->getSetting('blisspot.ghl.location.id', '');

        if (empty($apiKey) || empty($locationId)) {
            throw new RuntimeException('Missing GoHighLevel API key or location ID setting.');
        }

        $this->_ghlLocationId = $locationId;
        $this->_ghlClient = new Client(array(
            'base_uri' => 'https://services.leadconnectorhq.com/',
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
                'Version' => '2021-07-28',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'timeout' => 20,
        ));

        return $this->_ghlClient;
    }

    protected function splitName($displayname)
    {
        $parts = preg_split('/\s+/', trim((string) $displayname));
        $firstName = isset($parts[0]) ? $parts[0] : '';
        $lastName = isset($parts[1]) ? $parts[1] : '';
        if (count($parts) > 2) {
            $lastName = $parts[2];
        }

        return array($firstName, $lastName);
    }

    protected function buildContactPayload($user)
    {
        list($firstName, $lastName) = $this->splitName($user->displayname);

        $customFields = array(
            'se_user_id' => (string) $user->getIdentity(),
        );

        $timezones = Engine_Api::_()->customize()->getTimezones();
        if (!empty($user->timezone) && isset($timezones[$user->timezone])) {
            $customFields['timezone'] = $timezones[$user->timezone];
        }

        $gender = $user->getGender();
        if (!empty($gender)) {
            $customFields['gender'] = $gender;
        }

        $city = $user->getCity();
        if (!empty($city)) {
            $customFields['city'] = $city;
        }

        $country = $user->getCountry();
        if (!empty($country)) {
            $customFields['country'] = $country;
        }

        if (!empty($user->onesignal_id)) {
            $customFields['onesignal_id'] = $user->onesignal_id;
        }

        $payload = array(
            'locationId' => $this->_ghlLocationId,
            'email' => $user->email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'customFields' => $customFields,
        );

        if (!empty($user->phone)) {
            $payload['phone'] = $user->phone;
        }

        return $payload;
    }

    protected function decodeResponse($response)
    {
        return json_decode((string) $response->getBody());
    }

    /**
     * ActiveCampaign list ID => name mapping provided by Blisspot.
     *
     * @return array<int,string>
     */
    protected function getActiveCampaignListMap()
    {
        return array(
            2 => 'Blisspot-Author-List',
            3 => 'EI-Quiz-WOMEN-Empowered-Eagle',
            4 => 'EI-Quiz-WOMEN-Playful-Parrot',
            5 => 'EI-Quiz-WOMEN-Knowing-Kingfisher',
            6 => 'EI-Quiz-WOMEN-Prudent-Penguin',
            7 => 'US-And-AU-Magazines',
            8 => 'US-And-AU-Radio-Stations',
            9 => 'EI-Quiz-MEN-Empowered-Eagle',
            10 => 'EI-Quiz-MEN-Phenomenal-Parrot-EI-Quiz-MEN',
            11 => 'EI-Quiz-MEN-Knowing-Kingfisher',
            12 => 'EI-Quiz-MEN-Prudent-Penguin',
            17 => 'Daily-Support-Programs-Waitlist',
            23 => 'Feel-like-yourself-again-30-day-challenge',
            25 => 'How-to-Trust-and-Let-Go',
            29 => 'Blisspot-Free-Members',
            32 => 'Investors',
            61 => 'Blisspot-Events',
            62 => 'Blisspot-Paid-Personal-Membership',
            64 => 'Blisspot-Events-Purpose',
            67 => 'Blisspot-Events-Wealth',
            76 => 'Business',
            85 => 'Blisspot-Community',
            86 => 'Blisspot-Events-Sleep',
            88 => 'Interested_affiliates',
            94 => 'Blisspot-Events-Emotional-Mastery',
            97 => 'Blisspot-Events-Gratitude',
            103 => 'Free-Guide-for-Businesses-5-effective-ways-to-Improve-your-teams-focus,-productivity-and-profitability',
            107 => 'Power-Quiz-Women-Phoenix-Rising',
            108 => 'Power-Quiz-Women-Daring-Dragon',
            109 => 'Power-Quiz-Women-Playful-Pegasus',
            110 => 'Power-Quiz-Women-Unique-Unicorn',
            111 => 'Challenges-Raise-Energy',
            112 => 'Challenges-Financial-Wellbeing',
            113 => 'Challenges-Physical-Health',
            114 => 'Challenges-Career-Crisis',
            115 => 'Challenges-Relationship-Issues',
            116 => 'Challenges-Mental-and-Emotional-Stress',
            117 => 'Challenges-Fear-of-the-Future',
            118 => 'Challenges-Disconnected-and-Alone',
            128 => 'Power-Quiz-Men-Unstoppable-Unicorn',
            129 => 'Power-Quiz-Men-Daring-Dragon',
            130 => 'Power-Quiz-Men-Powerful-Pegasus',
            131 => 'Power-Quiz-Men-Phoenix-Rising',
            133 => 'Blisspot-Events-Stress',
            134 => 'Blisspot-Events-Energy',
            140 => "Blisspot-Events-Men's",
            143 => 'Kinesiology',
            149 => 'Reclaim-Your-Worth-Guide',
            150 => 'What-is-Kinesiology-Guide',
            152 => 'Art-of-Thriving-Beta-Cohort-#1',
            156 => 'Sydney-Blisspot-List',
            157 => 'Blisspot-Events-Women',
            159 => 'Business_signup',
        );
    }

    /**
     * Resolves list ID to the corresponding tag name.
     *
     * @param int|string $listId
     * @return string
     */
    protected function getTagNameByListId($listId)
    {
        $listId = (int) $listId;
        $map = $this->getActiveCampaignListMap();

        if (isset($map[$listId])) {
            return $map[$listId];
        }

        return 'list:' . $listId;
    }

    public function addUserToList($payload, $listid)
    {
        try {
            $client = $this->requireApi();
            $data = $this->buildContactPayload($payload);

            // Use mapped ActiveCampaign list name as the GHL tag.
            $data['tags'] = array($this->getTagNameByListId($listid));

            $response = $client->post('contacts/upsert', array('json' => $data));
            return $this->decodeResponse($response);
        } catch (Exception $e) {
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
    }

    public function createList($payload)
    {
        // GHL has no direct "mailing list" resource like ActiveCampaign.
        // Return a virtual list object based on name.
        $name = is_object($payload) ? $payload->getTitle() : $payload;
        $list = new stdClass();
        $list->id = strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $name));
        $list->name = $name;
        return $list;
    }

    public function getList($name)
    {
        // Virtual list lookup for compatibility.
        if (empty($name)) {
            return null;
        }
        return $this->createList($name);
    }

    public function getContact($email)
    {
        try {
            $client = $this->requireApi();
            $response = $client->get('contacts/search/duplicate', array(
                'query' => array(
                    'locationId' => $this->_ghlLocationId,
                    'email' => $email,
                ),
            ));
            $body = $this->decodeResponse($response);
            return !empty($body->contact) ? $body->contact : null;
        } catch (Exception $e) {
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
        return null;
    }

    public function subscribeGroupList($group, $user)
    {
        $groupList = $this->createList($group);
        if (empty($groupList)) {
            return;
        }

        return $this->subscribeListByEmail($groupList->name, $user, false);
    }

    public function subscribeListByEmail($name, $user, $removeOtherLists = false)
    {
        try {
            $client = $this->requireApi();
            $contact = $this->getContact($user->email);

            if (empty($contact) || empty($contact->id)) {
                return $this->addUserToList($user, $name);
            }

            $payload = $this->buildContactPayload($user);
            $existingTags = !empty($contact->tags) && is_array($contact->tags) ? $contact->tags : array();

            $listTag = is_numeric($name)
                ? $this->getTagNameByListId($name)
                : (string) $name;
            $payload['tags'] = $removeOtherLists ? array($listTag) : array_values(array_unique(array_merge($existingTags, array($listTag))));

            $response = $client->put('contacts/' . $contact->id, array('json' => $payload));
            return $this->decodeResponse($response);
        } catch (Exception $e) {
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
        return null;
    }

    public function updateContact($user)
    {
        if (empty($user)) {
            $this->log("No user provided for contact update\n");
            return;
        }

        try {
            $client = $this->requireApi();
            $contact = $this->getContact(urlencode($user->email));
            if (empty($contact) || empty($contact->id)) {
                $this->log("No contact found for update\n");
                return;
            }

            $payload = $this->buildContactPayload($user);
            if (!empty($contact->tags) && is_array($contact->tags)) {
                $payload['tags'] = $contact->tags;
            }

            $response = $client->put('contacts/' . $contact->id, array('json' => $payload));
            return $this->decodeResponse($response);
        } catch (Exception $e) {
            $this->log($e->getMessage() . ' -- ' . $e->getTraceAsString() . "\n");
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
        return null;
    }

    public function updateOneSignalId($user, $subscriptionId)
    {
        try {
            $this->log("updateOneSignalId\n");
            if (empty($subscriptionId)) {
                $this->log("OneSignal No Subscription\n");
                return;
            }

            $settingsApi = Engine_Api::_()->getApi('settings', 'core');
            $appId = $settingsApi->getSetting('core.general.onesignal.app.id');
            $restApi = $settingsApi->getSetting('core.general.onesignal.rest.api');
            $orgApiKey = $settingsApi->getSetting('core.general.onesignal.org.api');

            $config = Configuration::getDefaultConfiguration()
                ->setAppKeyToken($restApi)
                ->setUserKeyToken($orgApiKey);

            $apiInstance = new DefaultApi(new Client(), $config);

            $aliasResult = $apiInstance->fetchAliases($appId, $subscriptionId);
            if (empty($aliasResult)) {
                $this->log("OneSignal No Result\n");
                return;
            }

            $identity = $aliasResult->getIdentity();
            if (empty($identity['onesignal_id'])) {
                $this->log("OneSignal Identity Not Found\n");
                return;
            }

            $oneSignalId = $identity['onesignal_id'];
            $user->onesignal_id = $oneSignalId;
            $user->save();

            return $this->updateContact($user);
        } catch (Exception $e) {
            $this->log($e->getMessage() . ' -- ' . $e->getTraceAsString() . "\n");
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
        return null;
    }

    public function getLog()
    {
        if (null === $this->_log) {
            $log = new Zend_Log();
            $log->addWriter(new Zend_Log_Writer_Stream(APPLICATION_PATH . '/temporary/log/UserSignups.log'));
            $this->_log = $log;
        }
        return $this->_log;
    }

    public function setLog(Zend_Log $log)
    {
        $this->_log = $log;
        return $this;
    }

    public function log($text)
    {
        $this->getLog()->log($text, Zend_Log::INFO);
    }

    public function deleteContact($user)
    {
        if (empty($user)) {
            return;
        }

        try {
            $client = $this->requireApi();
            $contact = $this->getContact(urlencode($user->email));
            if (empty($contact) || empty($contact->id)) {
                return;
            }

            $response = $client->delete('contacts/' . $contact->id);
            return $this->decodeResponse($response);
        } catch (Exception $e) {
            $this->log($e->getMessage() . ' -- ' . $e->getTraceAsString() . "\n");
            if (APPLICATION_ENV === 'development') {
                throw $e;
            }
        }
        return null;
    }
}
