<?php

namespace Raddit\AppBundle\Form\Model;

use Raddit\AppBundle\Entity\User;

class UserSettings {
    private $locale;
    private $nightMode;
    private $showCustomStylesheets;

    public function getLocale() {
        return $this->locale;
    }

    public function setLocale($locale) {
        $this->locale = $locale;
    }

    public function getNightMode() {
        return $this->nightMode;
    }

    public function setNightMode($nightMode) {
        $this->nightMode = $nightMode;
    }

    public function getShowCustomStylesheets() {
        return $this->showCustomStylesheets;
    }

    public function setShowCustomStylesheets($showCustomStylesheets) {
        $this->showCustomStylesheets = $showCustomStylesheets;
    }

    public function updateUser(User $user) {
        $user->setLocale($this->locale);
        $user->setNightMode($this->nightMode);
        $user->setShowCustomStylesheets($this->showCustomStylesheets);
    }

    public static function fromUser(User $user): UserSettings {
        $self = new self();
        $self->locale = $user->getLocale();
        $self->nightMode = $user->isNightMode();
        $self->showCustomStylesheets = $user->isShowCustomStylesheets();

        return $self;
    }
}
