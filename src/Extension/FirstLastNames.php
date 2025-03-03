<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.FirstLastNames
 *
 * @copyright   Copyright (C) NPEU 2023.
 * @license     MIT License; see LICENSE.md
 */

namespace NPEU\Plugin\System\FirstLastNames\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Utilities\ArrayHelper;

/**
 * First and Last names plug-in
 */
class FirstLastNames extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = false;

    /**
     * An internal flag whether plugin should listen any event.
     *
     * @var bool
     *
     * @since   4.3.0
     */
    protected static $enabled = false;

    /**
     * Constructor
     *
     */
    public function __construct($subject, array $config = [], bool $enabled = true)
    {
        // The above enabled parameter was taken from teh Guided Tour plugin but it ir always seems
        // to be false so I'm not sure where this param is passed from. Overriding it for now.
        $enabled = true;

        #$this->loadLanguage();
        $this->autoloadLanguage = $enabled;
        self::$enabled          = $enabled;

        parent::__construct($subject, $config);
    }

    /**
     * function for getSubscribedEvents : new Joomla 4 feature
     *
     * @return array
     *
     * @since   4.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return self::$enabled ? [
            'onAfterInitialise'    => 'onAfterInitialise',
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onUserAfterSave'      => 'onUserAfterSave',
            'onUserAfterDelete'    => 'onUserAfterDelete'
        ] : [];
    }

    /**
     * After initialise.
     *
     * @return  void
     */
    public function onAfterInitialise()
    {
        $input = Factory::getApplication()->input;
        $requestData = $input->post->get('jform', [], 'array');
        if (!empty($requestData['firstname']) && !empty($requestData['lastname'])) {
            $requestData['name'] = trim($requestData['firstname']) . ' ' . trim($requestData['lastname']);
            Factory::getApplication()->input->post->set('jform', $requestData);
        }
    }

    /**
     * Runs on content preparation
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onContentPrepareData(Event $event): void
    {
        [$context, $data] = array_values($event->getArguments());

        // Check we are manipulating a valid form.
        if (!in_array($context, ['com_users.profile', 'com_users.user', 'com_users.registration', 'com_admin.profile'])) {
            return;
        }
        // Profile view only shows name as it's not a form, so unless it's
        // overidden in a template (possible), we don't want to split the names
        // out.
        /*$layout = Factory::getApplication()->input->get('layout');
        if (!Factory::getApplication()->input->get('layout') || Factory::getApplication()->input->get('layout') != 'edit') {
            return true;
        }*/
        if (is_object($data)) {
            $user_id = isset($data->id) ? $data->id : 0;
            if (!isset($data->firstname)){
                $data->firstname = '';
            }
            if (!isset($data->lastname)) {
                $data->lastname = '';
            }

            if (empty($data->firstname) && empty($data->lastname) && $user_id > 0) {
                // Load the profile data from the database.
                $db = Factory::getDbo();
                $sql = 'SELECT profile_key, profile_value FROM #__user_profiles ' .
                       'WHERE user_id = '.(int) $user_id . " AND profile_key LIKE 'firstlastnames.%' " .
                       'ORDER BY ordering';

                $db->setQuery($sql);
                try {
                    $results = $db->loadRowList();
                }
                catch (RuntimeException $e) {
                    throw new GenericDataException($e->getErrorMsg(), 500);
                    return;
                }

                $data->firstname = $results[0][1];
                $data->lastname  = $results[1][1];
            }

            $name = '';
            if ($data->firstname != '' && $data->lastname != '') {
                $name = $data->firstname . ' ' . $data->lastname;
            }
            $data->name = $name;
            return;
        }

        return;
    }

    /**
     * Adds additional fields to the user editing form for logs e-mail notifications
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onContentPrepareForm(Event $event): void
    {
        [$form, $data] = array_values($event->getArguments());

        if (!$form instanceof \Joomla\CMS\Form\Form) {
            return;
        }

        // Check we are manipulating a valid form.
        $name = $form->getName();
        if (!in_array($name, ['com_admin.profile', 'com_users.user', 'com_users.profile', 'com_users.registration'])) {
            return;
        }

        switch ($name) {
            case 'com_users.registration':
                $fieldset  = 'default';
                $form_file = JPATH_COMPONENT . '/forms/registration.xml';
                break;
            case 'com_users.profile':
                $fieldset = 'core';
                $form_file = JPATH_COMPONENT . '/forms/profile.xml';
                break;
            case 'com_admin.profile':
                $fieldset = 'user_details';
                $form_file = JPATH_COMPONENT . '/forms/profile.xml';
                break;
            case 'com_users.user':
                $fieldset = 'user_details';
                $form_file = JPATH_COMPONENT . '/forms/user.xml';
                break;
        } // switch

        // Really hacky xml stuff to manipulate the registration form as it
        // doesn't seem possible do it without redefining the whole form.
        foreach ($form->getFieldset($fieldset) as $fieldname => $field) {
            $form->removeField(str_replace('jform_', '', $fieldname));
        }

        $form_string = file_get_contents($form_file);

        $form_xml    = new \SimpleXMLElement($form_string);

        $first_name                = current($form_xml->xpath('//field[@name="name"]'));
        $first_name['name']        = "firstname";
        $first_name['description'] = "PLG_SYSTEM_FIRSTLASTNAMES_FIELD_FIRST_NAME_DESC";
        $first_name['label']       = "PLG_SYSTEM_FIRSTLASTNAMES_FIELD_FIRST_NAME_LABEL";
        $first_name['message']     = "PLG_SYSTEM_FIRSTLASTNAMES_FIELD_FIRST_NAME_MESSAGE";

        $last_name                 = clone $first_name;
        $last_name['name']         = "lastname";
        $last_name['description']  = "PLG_SYSTEM_FIRSTLASTNAMES_FIELD_LAST_NAME_DESC";
        $last_name['label']        = "PLG_SYSTEM_FIRSTLASTNAMES_FIELD_LAST_NAME_LABEL";
        $last_name['message']      = "PLG_SYSTEM_FIRSTLASTNAMES_FIELD_LAST_NAME_MESSAGE";

        $target_dom = dom_import_simplexml($first_name);
        $insert_dom = $target_dom->ownerDocument->importNode(dom_import_simplexml($last_name), true);
        if ($target_dom->nextSibling) {
            $target_dom->parentNode->insertBefore($insert_dom, $target_dom->nextSibling);
        } else {
            $target_dom->parentNode->appendChild($insert_dom);
        }

        // Add full name field to fool registrations processes (and possible others)
        $full_name                 = clone $first_name;
        $full_name['name']         = "name";
        $full_name['type']         = "hidden";
        $full_name['required']     = "false";

        $target_dom = dom_import_simplexml($first_name);
        $insert_dom = $target_dom->ownerDocument->importNode(dom_import_simplexml($full_name), true);
        // Insert hidden field at the end of the fieldset to avoid a blank line
        // appearing in the output as the template doesn't handle hidden inputs
        // on the 'user_details' fieldset:
        $target_dom->parentNode->appendChild($insert_dom);
        // Update the form. 'replace' param is unintuitive and needs to be false.
        $form->load($form_xml, false);
        return;
    }

    /**
     * Utility method to act on a user after it has been saved.
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onUserAfterSave(Event $event): void
    {
        [$user, $isnew, $success, $msg] = array_values($event->getArguments());
        $user_id = ArrayHelper::getValue($user, 'id', 0, 'int');

        if ($user_id && $success) {
            $db = Factory::getDbo();
            // On activation, the lastname field won't be present within the data,
            // so retrieve it from the profile form and set it:
            if (!isset($user['lastname'])) {
                // Get profile:
                $db = Factory::getDbo();


                // Check for a database error.
                try
                {
                    $db->setQuery(
                        'SELECT profile_key, profile_value FROM #__user_profiles' .
                        ' WHERE user_id = ' . (int) $user_id . " AND profile_key LIKE 'firstlastnames.%'" .
                        ' ORDER BY ordering'
                    );
                    $results = $db->loadRowList();
                }
                catch (RuntimeException $e)
                {
                    throw new GenericDataException($db->getErrorMsg(), 500);
                    return;
                }

                $user['firstname'] = $results[0][1];
                $user['lastname']  = $results[1][1];
            }

            try {
                // Concatenate name and lastname and update table:
                $fullname = trim($user['firstname']) . ' ' . trim($user['lastname']);
                $sql = 'UPDATE #__users SET name = "' . $fullname . '" WHERE id = '.$user_id . ';';

                $db->setQuery($sql);

                if (!$db->execute()) {
                    throw new Exception($db->getErrorMsg());
                }

                // Delete profile (not 100% sure why I'm doing this here...):
                $db->setQuery(
                    'DELETE FROM #__user_profiles WHERE user_id = ' . $user_id .
                    " AND profile_key LIKE 'firstlastnames.%'"
                );

                if (!$db->execute()) {
                    throw new Exception($db->getErrorMsg());
                }

                // Add the first and last name data to the profiles table:
                $sql  = 'INSERT INTO #__user_profiles VALUES ';
                $sql .= '(' . $user_id . ', ' . $db->quote('firstlastnames.firstname') . ', ' . $db->quote($user['firstname']) . ', 1), ';
                $sql .= '(' . $user_id . ', ' . $db->quote('firstlastnames.lastname') . ', ' . $db->quote($user['lastname']) . ', 2);';
                $db->setQuery($sql);

                if (!$db->execute()) {
                    throw new Exception($db->getErrorMsg());
                }
            }
            catch (RuntimeException $e) {
                throw new GenericDataException($e->getErrorMsg(), 500);
                return;
            }
        }

        return;
    }

    /**
     * Removes user preferences
     *
     * Method is called after user data is deleted from the database
     *
     * @param   Event  $event
     *
     * @return  boolean
     */
    public function onUserAfterDelete(Event $event): void
    {
        [$user, $success, $msg] = array_values($event->getArguments());

        if (!$success) {
            return;
        }

        $user_id = ArrayHelper::getValue($user, 'id', 0, 'int');

        if ($user_id) {
            try {
                $db = Factory::getDbo();
                $db->setQuery(
                    'DELETE FROM #__user_profiles WHERE user_id = '.$user_id .
                    " AND profile_key LIKE 'firstlastnames.%'"
                );

                $db->execute();
            }
            catch (Exception $e) {
                throw new GenericDataException($e->getErrorMsg(), 500);
                return;
            }
        }

        return;
    }
}