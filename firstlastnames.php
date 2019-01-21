<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.FirstLastNames
 *
 * @copyright   Copyright (C) NPEU 2019.
 * @license     MIT License; see LICENSE.md
 */

defined('_JEXEC') or die;

/**
 * Adds first and last name support.
 */
class plgSystemFirstLastNames extends JPlugin
{
    protected $autoloadLanguage = true;

    /**
     * Constructor.
     *
     * @param   object  &$subject  The object to observe.
     * @param   array   $config    An optional associative array of configuration settings.
     */
    /*public function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);
        $this->loadLanguage();
    }*/


    /**
     * Runs on content preparation
     *
     * @param   string  $context  The context for the data
     * @param   object  $data     An object containing the data for the form.
     *
     * @return  boolean
     */
    public function onContentPrepareData($context, $data)
    {
        // Check we are manipulating a valid form.
        if (!in_array($context, array('com_users.profile', 'com_users.user', 'com_users.registration', 'com_admin.profile'))) {
            return true;
        }
        // Profile view only shows name as it's not a form, so unless it's
        // overidden in a template (possible), we don't want to split the names
        // out.
        /*$layout = JFactory::getApplication()->input->get('layout');
        if (!JFactory::getApplication()->input->get('layout') || JFactory::getApplication()->input->get('layout') != 'edit') {
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
                $db = JFactory::getDbo();
                $sql = 'SELECT profile_key, profile_value FROM #__user_profiles ' .
                       'WHERE user_id = '.(int) $user_id . " AND profile_key LIKE 'firstlastnames.%' " .
                       'ORDER BY ordering';

                $db->setQuery($sql);
                try {
                    $results = $db->loadRowList();
                }
                catch (RuntimeException $e) {
                    $this->_subject->setError($e->getMessage());
                    return false;
                }

                $data->firstname = $results[0][1];
                $data->lastname  = $results[1][1];
            }

            $data->name = $data->firstname . ' ' . $data->lastname;
            return true;
        }

        return true;
    }

    /**
     * Adds additional fields to the user editing form for logs e-mail notifications
     *
     * @param   JForm  $form  The form to be altered.
     * @param   mixed  $data  The associated data for the form.
     *
     * @return  boolean
     */
    public function onContentPrepareForm($form, $data)
    {
        if (!($form instanceof JForm)){
            $this->_subject->setError('JERROR_NOT_A_FORM');
            return false;
        }

        // Check we are manipulating a valid form.
        $name = $form->getName();
        if (!in_array($name, array('com_admin.profile', 'com_users.user', 'com_users.profile', 'com_users.registration'))) {
            return true;
        }

        switch ($name) {
            case 'com_users.registration':
                $fieldset  = 'default';
                $form_file = JPATH_COMPONENT . '/models/forms/registration.xml';
                break;
            case 'com_users.profile':
                $fieldset = 'core';
                $form_file = JPATH_COMPONENT . '/models/forms/profile.xml';
                break;
            case 'com_admin.profile':
                $fieldset = 'user_details';
                $form_file = JPATH_COMPONENT . '/models/forms/profile.xml';
                break;
            case 'com_users.user':
                $fieldset = 'user_details';
                $form_file = JPATH_COMPONENT . '/models/forms/user.xml';
                break;
        } // switch

        // Really hacky xml stuff to manipulate the registration form as it
        // doesn't seem possible do it without redefining the whole form.
        foreach ($form->getFieldset($fieldset) as $fieldname => $field) {
            $form->removeField(str_replace('jform_', '', $fieldname));
        }

        $form_string = file_get_contents($form_file);

        $form_xml    = new SimpleXMLElement($form_string);

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
        return true;
    }

    /**
     * Utility method to act on a user after it has been saved.
     *
     * @param   array    $user     Holds the new user data.
     * @param   boolean  $isNew    True if a new user is stored.
     * @param   boolean  $success  True if user was successfully stored in the database.
     * @param   string   $msg      Message.
     *
     * @return  boolean
     */
    public function onUserAfterSave($user, $isNew, $success, $msg)
    {
        $user_id = JArrayHelper::getValue($user, 'id', 0, 'int');

        if ($user_id && $result) {
            $db = JFactory::getDbo();
            // On activation, the lastname field won't be resent with the data,
            // so retrieve it from the profile form and set it:
            if (!isset($user['lastname'])) {
                // Get profile:
                $db = JFactory::getDbo();
                $db->setQuery(
                    'SELECT profile_key, profile_value FROM #__user_profiles' .
                    ' WHERE user_id = '.(int) $user_id." AND profile_key LIKE 'firstlastnames.%'" .
                    ' ORDER BY ordering'
                );
                $results = $db->loadRowList();

                // Check for a database error.
                if ($db->getErrorNum()) {
                    $this->_subject->setError($db->getErrorMsg());
                    return false;
                }
                $user['firstname'] = $results[0][1];
                $user['lastname']  = $results[1][1];
            }

            try {
                // Concatenate name and lastname ad update table:
                $fullname = $user['firstname'] . ' ' . $user['lastname'];
                $sql = 'UPDATE #__users SET name = "' . $fullname . '" WHERE id = '.$user_id . ';';

                $db->setQuery($sql);

                if (!$db->execute()) {
                    throw new Exception($db->getErrorMsg());
                }

                // Delete profile:
                $db->setQuery(
                    'DELETE FROM #__user_profiles WHERE user_id = '.$user_id .
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
                $this->_subject->setError($e->getMessage());
                return false;
            }
        }

        return true;
    }

    /**
     * Removes user preferences
     *
     * Method is called after user data is deleted from the database
     *
     * @param   array    $user     Holds the user data
     * @param   boolean  $success  True if user was successfully stored in the database
     * @param   string   $msg      Message
     *
     * @return  boolean
     */
    public function onUserAfterDelete($user, $success, $msg)
    {
        if (!$success) {
            return false;
        }

        $user_id = JArrayHelper::getValue($user, 'id', 0, 'int');

        if ($user_id) {
            try {
                $db = JFactory::getDbo();
                $db->setQuery(
                    'DELETE FROM #__user_profiles WHERE user_id = '.$user_id .
                    " AND profile_key LIKE 'firstlastnames.%'"
                );

                $db->execute();
            }
            catch (Exception $e) {
                $this->_subject->setError($e->getMessage());
                return false;
            }
        }

        return true;
    }
}