<?php
$PluginInfo['PushbulletNotification'] = [
    'Name' => 'Pushbullet Notification',
    'Description' => 'Allows users to be notified by <a href="https://www.pushbullet.com/">Pushbullet</a>.',
    'Version' => '0.1.1',
    'RequiredApplications' => ['Vanilla' => '>= 2.2'],
    'RequiredPlugins' => false,
    'RequiredTheme' => false,
    'SettingsPermission' => 'Garden.Settings.Manage',
    'SettingsUrl' => '/settings/pushbulletnotification',
    'RegisterPermissions' => ['Plugins.PushbulletNotification.Allow'],
    'MobileFriendly' => true,
    'HasLocale' => true, // Well, not yet...
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/44046/R_J',
    'License' => 'MIT'
];

class PushbulletNotificationPlugin extends Gdn_Plugin {
    /**
     * Run when plugin is enabled.
     *
     * @return void.
     */
    public function setup() {
        $this->structure();
    }

    /**
     * Change db structure.
     *
     * @return void.
     */
    public function structure() {
        // New column for sent status of push messages.
        Gdn::structure()
            ->table('Activity')
            ->column('Pushbullet', 'tinyint(1)', 0)
            ->set();
    }

    /**
     * Let admin configure needed API keys.
     *
     * @param SettingsController $sender Instance of the sending class.
     *
     * @return void.
     */
    public function settingsController_pushbulletNotification_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->title(t('Pushbullet Notification Settings'));
        $sender->addSideMenu('dashboard/settings/plugins');

        $configurationModule = new ConfigurationModule($sender);
        $configurationModule->initialize(
            [
                'PushbulletNotification.APIKey' => [
                    'Control' => 'TextBox',
                    'LabelCode' => 'Please enter the API key',
                    'Description' => 'Create an "Access Token" on your <a href="https://www.pushbullet.com/#settings/account">Pushbullets  account page</a> (if you not already have one). Enter that token here.<br/>Afterwards you have to give your users the permission to use Pushbullet notifications. They will be able to see a new column in their notification preferences.'
                ]
            ]
        );
        $configurationModule->renderAll();
    }

    /**
     * Helper function to check if notification provider has been set up.
     *
     * @return bool Whether the notification provider is configured or not.
     */
    private function isConfigured() {
        return is_string(c('PushbulletNotification.APIKey', false));
    }

    /**
     * Extend notifications screen to show additional notification provider.
     *
     * @param ProfileController $sender The calling controller.
     *
     * @return void.
     */
    public function profileController_afterPreferencesDefined_handler($sender) {
        // Stop if not configured or user doesn't have appropriate rights.
        if (
            !$this->isConfigured() ||
            !Gdn::session()->checkPermission(
                'Plugins.PushbulletNotification.Allow'
            )
        ) {
            return;
        }

        // Add new column to notification preferences.
        foreach ($sender->Preferences as $preferenceGroup => $preferences) {
            foreach ($preferences as $name => $description) {
                $nameParts = explode('.', $name);
                $sender->Preferences[$preferenceGroup]['Pushbullet.'.$nameParts[1]] = $description;
            }
        }
    }

    /**
     * Add Activity to Queue.
     *
     * Ensure that this activity is queued.
     * TODO: check if this causes double activities!
     *
     * @param ActivityModel $sender Instance of the sending class.
     * @param mixed         $args   Event arguments.
     *
     * @return void.
     */
    public function activityModel_beforeCheckPreference_handler($sender, $args) {
        if (!$this->isConfigured()) {
            return;
        }

        // Check if user wants to be notified of such events.
        if (
            !$sender->notificationPreference(
                $args['Preference'],
                $args['Data']['NotifyUserID'],
                'Pushbullet'
            )
        ) {
            return;
        }

        $args['Data']['Pushbullet'] = ActivityModel::SENT_PENDING;

        ActivityModel::$Queue[$args['Data']['NotifyUserID']][$args['Data']['ActivityType']] = [
            $args['Data'],
            $args['Options']
        ];
    }

    /**
     * Send custom notification and change activities sent status.
     *
     * @param ActivityModel $sender Instance of the sending class.
     * @param mixed         $args   Event arguments.
     *
     * @return void.
     */
    public function activityModel_beforeSave_handler($sender, $args) {
        if (!$this->isConfigured()) {
            return;
        }

        // Only continue if notification has not been already sent or has
        // been a fatal error.
        if (
            !($args['Activity']['Pushbullet'] == ActivityModel::SENT_PENDING ||
            $args['Activity']['Pushbullet'] == ActivityModel::SENT_ERROR)
        ) {
            return;
        }

        // Result will be an "Activity Status" (see class ActivityModel).
        $result = $this->notify($args['Activity']);
        $args['Activity']['Pushbullet'] = $result;
    }

    /**
     * Send notification with custom notification provider.
     *
     * This function must return one of the "Activity Status" codes defined
     * in ActivityModel.
     * SENT_OK    = successful delivered
     * SENT_ERROR = repeat delivery
     * SENT_FAIL  = fatal error
     *
     * @param object $activity Activity object.
     *
     * @return integer One of the SENT_... constants of ActivityModel.
     */
    private function notify($activity) {
        if (!$this->isConfigured()) {
            return;
        }

        $activity['Data'] = unserialize($activity['Data']);

        // Form the Activity headline
        $activity['Headline'] = formatString(
            $activity['HeadlineFormat'],
            $activity
        );
        // Get the notify user because we need his mail address.
        $user = Gdn::userModel()->getID($activity['NotifyUserID']);
        // Build the message we have to sent to the API
        $link = [
            'type' => 'link',
            'body' => Gdn_Format::text($activity['Headline']),
            'url' => Gdn::Request()->url($activity['Route'], true),
            'email' => $user->Email
        ];

        $proxyRequest = new ProxyRequest();
        $result = $proxyRequest->request(
            [
                'URL' => 'https://api.pushbullet.com/v2/pushes',
                'Method' => 'POST',
                'TransferMode' => 'binary'
            ], // options
            json_encode($link), // query params
            [], // files
            [
                'Access-Token' => c('PushbulletNotification.APIKey'),
                'Content-Type' => 'application/json'
            ] // extra headers
        );

        if (intval($proxyRequest->ResponseStatus) == 200) {
            return ActivityModel::SENT_OK;
        }

        if (intval($proxyRequest->ResponseStatus) >= 500) {
            return ActivityModel::SENT_ERROR;
        }

        return ActivityModel::SENT_FAIL;
    }
}
