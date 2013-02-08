<?php

class UserController extends Pix_Controller
{
    public function init()
    {
        if (!$this->user = Hisoku::getLoginUser()) {
            return $this->rediect('/');
        }
        $this->view->user = $this->user;
    }

    public function indexAction()
    {
    }

    public function deletekeyAction()
    {
        if (Hisoku::getStoken() != $_POST['sToken']) {
            // TODO: error
            return $this->redirect('/');
        }

        list(, /*user*/, /*deletekey*/, $id) = explode('/', $this->getURI());
        if (!$userkey = $this->user->keys->search(array('id' => $id))->first()) {
            // TODO: error
            return $this->redirect('/');
        }

        $userkey->delete();
        return $this->redirect('/');
    }

    public function addkeyAction()
    {
        if (Hisoku::getStoken() != $_POST['sToken']) {
            // TODO: error
            return $this->redirect('/');
        }

        try {
            $this->user->addKey($_POST['key']);
        } catch (InvalidException $e) {
            // TODO: error
        } catch (Pix_Table_DuplicateException $e) {
            // TODO: error
        }
        return $this->redirect('/');
    }

    public function addprojectAction()
    {
        if (Hisoku::getStoken() != $_POST['sToken']) {
            // TODO: error
            return $this->redirect('/');
        }

        try {
            $project = $this->user->addProject();
        } catch (InvalidException $e) {
            // TODO: error
            return $this->redirect('/');
        } catch (Pix_Table_DuplicateException $e) {
            // TODO: error
            return $this->redirect('/');
        }

        $project->setEAV('note', strval($_POST['name']));

        return $this->redirect('/');
    }

    public function changepasswordAction()
    {
        if (Hisoku::getStoken() != $_POST['sToken']) {
            return $this->alert('Error', '/');
        }

        if (!$this->user->verifyPassword($_POST['oldpassword'])) {
            return $this->alert('Wrong password', '/');
        }

        if ($_POST['newpassword'] != $_POST['newpassword2']) {
            return $this->alert('Password mismatch', '/');
        }

        if (strlen($_POST['newpassword']) < 4) {
            return $this->alert('Password is too short', '/');
        }

        $this->user->setPassword($_POST['newpassword']);

        return $this->alert('success!', '/');

    }
}
