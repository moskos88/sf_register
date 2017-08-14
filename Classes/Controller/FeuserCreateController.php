<?php
namespace Evoweb\SfRegister\Controller;

/***************************************************************
 * Copyright notice
 *
 * (c) 2011-17 Sebastian Fischer <typo3@evoweb.de>
 * All rights reserved
 *
 * This script is part of the TYPO3 project. The TYPO3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Evoweb\SfRegister\Domain\Model\FrontendUser;

/**
 * An frontend user create controller
 */
class FeuserCreateController extends FeuserController
{
    /**
     * Form action
     *
     * @param FrontendUser $user
     *
     * @return void
     */
    public function formAction(FrontendUser $user = null)
    {
        /** @var \TYPO3\CMS\Extbase\Mvc\Request $originalRequest */
        $originalRequest = $this->request->getOriginalRequest();
        if ($originalRequest !== null && $originalRequest->hasArgument('user')) {
            /** @var array $userData */
            $userData = $this->request->hasArgument('user') ?
                $this->request->getArgument('user') :
                $originalRequest->getArgument('user');
            if (isset($userData['uid'])) {
                unset($userData['uid']);
            }

            $propertyMappingConfiguration = $this->objectManager->get(
                \TYPO3\CMS\Extbase\Property\PropertyMappingConfiguration::class
            );
            $propertyMappingConfiguration->allowAllProperties();
            $propertyMappingConfiguration->forProperty('usergroup')->allowAllProperties();
            $propertyMappingConfiguration->setTypeConverterOption(
                'TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter',
                \TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED,
                true
            );

            /** @var \TYPO3\CMS\Extbase\Property\PropertyMapper $propertyMapper */
            $propertyMapper = $this->objectManager->get(\TYPO3\CMS\Extbase\Property\PropertyMapper::class);
            $user = $propertyMapper->convert(
                $userData,
                FrontendUser::class,
                $propertyMappingConfiguration
            );
        }

        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__,
            [
                'user' => &$user,
                'settings' => $this->settings,
            ]
        );

        $this->view->assign('user', $user);
    }

    /**
     * Preview action
     *
     * @param FrontendUser $user
     *
     * @return void
     * @validate $user Evoweb.SfRegister:User
     */
    public function previewAction(FrontendUser $user)
    {
        if ($this->request->hasArgument('temporaryImage')) {
            $this->view->assign('temporaryImage', $this->request->getArgument('temporaryImage'));
        }

        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__,
            [
                'user' => &$user,
                'settings' => $this->settings
            ]
        );

        $this->view->assign('user', $user);
    }

    /**
     * Save action
     *
     * @param FrontendUser $user
     *
     * @return void
     * @validate $user Evoweb.SfRegister:User
     */
    public function saveAction(FrontendUser $user)
    {
        if ($this->settings['confirmEmailPostCreate'] || $this->settings['acceptEmailPostCreate']) {
            $user->setDisable(true);
            $user = $this->changeUsergroup($user, (int) $this->settings['usergroupPostSave']);
        } else {
            $user = $this->changeUsergroup($user, (int) $this->settings['usergroup']);
        }

        $type = 'PostCreateSave';

        if ($this->settings['useEmailAddressAsUsername']) {
            $user->setUsername($user->getEmail());
        }

        $this->signalSlotDispatcher->dispatch(
            __CLASS__,
            __FUNCTION__,
            [
                'user' => &$user,
                'settings' => $this->settings
            ]
        );

        // Persist user to get valid uid
        $plainPassword = $user->getPassword();
        // Avoid plain password being persisted
        $user->setPassword('');
        $this->userRepository->add($user);
        $this->persistAll();

        // Write back plain password
        $user->setPassword($plainPassword);
        $user = $this->sendEmails($user, $type);

        // Encrypt plain password
        $user->setPassword($this->encryptPassword($user->getPassword(), $this->settings));
        $this->userRepository->update($user);
        $this->persistAll();

        $this->objectManager->get(\Evoweb\SfRegister\Services\Session::class)->remove('captchaWasValidPreviously');

        if ($this->settings['autologinPostRegistration']) {
            $this->autoLogin($user, $this->settings['redirectPostRegistrationPageId']);
        }

        if ($this->settings['redirectPostRegistrationPageId']) {
            $this->redirectToPage((int) $this->settings['redirectPostRegistrationPageId']);
        }

        $this->view->assign('user', $user);
    }


    /**
     * Confirm registration process by user
     * Could be followed by acceptance of admin
     *
     * @param FrontendUser $user
     * @param string $hash
     *
     * @return void
     */
    public function confirmAction(FrontendUser $user = null, $hash = null)
    {
        $user = $this->determineFrontendUser($user, $hash);

        if (!($user instanceof FrontendUser)) {
            $this->view->assign('userNotFound', 1);
        } else {
            $this->view->assign('user', $user);

            if (!$user->getDisable() || $this->isUserInUserGroups(
                $user,
                $this->getFollowingUserGroups((int) $this->settings['usergroupPostConfirm'])
            )) {
                $this->view->assign('userAlreadyConfirmed', 1);
            } else {
                $user = $this->changeUsergroup($user, (int) $this->settings['usergroupPostConfirm']);

                if (!$this->settings['acceptEmailPostConfirm']) {
                    $user->setDisable(false);
                }

                $this->signalSlotDispatcher->dispatch(
                    __CLASS__,
                    __FUNCTION__,
                    [
                        'user' => &$user,
                        'settings' => $this->settings
                    ]
                );

                $this->userRepository->update($user);

                $this->sendEmails($user, 'PostCreateConfirm');

                if ($this->settings['autologinPostConfirmation']) {
                    $this->persistAll();
                    $this->autoLogin($user, $this->settings['redirectPostActivationPageId']);
                }

                if ($this->settings['redirectPostActivationPageId']) {
                    $this->redirectToPage((int) $this->settings['redirectPostActivationPageId']);
                }

                $this->view->assign('userConfirmed', 1);
            }
        }
    }

    /**
     * Refuse registration process by user with removing the user data
     *
     * @param FrontendUser $user
     * @param string $hash
     *
     * @return void
     */
    public function refuseAction(FrontendUser $user = null, $hash = null)
    {
        $user = $this->determineFrontendUser($user, $hash);

        if (!($user instanceof FrontendUser)) {
            $this->view->assign('userNotFound', 1);
        } else {
            $this->view->assign('user', $user);

            $this->signalSlotDispatcher->dispatch(
                __CLASS__,
                __FUNCTION__,
                [
                    'user' => &$user,
                    'settings' => $this->settings
                ]
            );

            $this->userRepository->remove($user);

            $this->sendEmails($user, 'PostCreateRefuse');

            $this->view->assign('userRefused', 1);
        }
    }


    /**
     * Accept registration process by admin after user confirmation
     *
     * @param FrontendUser $user
     * @param string $hash
     *
     * @return void
     */
    public function acceptAction(FrontendUser $user = null, $hash = null)
    {
        $user = $this->determineFrontendUser($user, $hash);

        if (!($user instanceof FrontendUser)) {
            $this->view->assign('userNotFound', 1);
        } else {
            $this->view->assign('user', $user);

            if ($user->getActivatedOn() || $this->isUserInUserGroups(
                $user,
                $this->getFollowingUserGroups((int) $this->settings['usergroupPostAccept'])
            )) {
                $this->view->assign('userAlreadyAccepted', 1);
            } else {
                $user = $this->changeUsergroup($user, (int) $this->settings['usergroupPostAccept']);
                $user->setActivatedOn(new \DateTime('now'));

                if (!$this->settings['confirmEmailPostAccept']) {
                    $user->setDisable(false);
                }

                $this->signalSlotDispatcher->dispatch(
                    __CLASS__,
                    __FUNCTION__,
                    [
                        'user' => &$user,
                        'settings' => $this->settings
                    ]
                );

                $this->userRepository->update($user);

                $this->sendEmails($user, 'PostCreateAccept');

                $this->view->assign('userAccepted', 1);
            }
        }
    }

    /**
     * Decline registration process by admin with removing the user data
     *
     * @param FrontendUser $user
     * @param string $hash
     *
     * @return void
     */
    public function declineAction(FrontendUser $user = null, $hash = null)
    {
        $user = $this->determineFrontendUser($user, $hash);

        if (!($user instanceof FrontendUser)) {
            $this->view->assign('userNotFound', 1);
        } else {
            $this->view->assign('user', $user);

            $this->signalSlotDispatcher->dispatch(
                __CLASS__,
                __FUNCTION__,
                [
                    'user' => &$user,
                    'settings' => $this->settings
                ]
            );

            $this->userRepository->remove($user);

            $this->sendEmails($user, 'PostCreateDecline');

            $this->view->assign('userDeclined', 1);
        }
    }
}
