<?php
/**
 * @package EvolutionScript
 * @author: EvolutionScript S.A.C.
 * @Copyright (c) 2010 - 2020, EvolutionScript.com
 * @link http://www.evolutionscript.com
 */

namespace App\Controllers\Api;


use App\Libraries\TwoFactor;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Config\Services;
use Psr\Log\LoggerInterface;

class Staff extends ResourceController
{
    protected $format = 'json';
    protected $modelName = 'App\Models\Staff';
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger); // TODO: Change the autogenerated stub
        helper(['form','html','helpdesk','number','filesystem','text']);
    }

    public function index()
    {
        $api = Services::api();
        if(!$api->validatePermission('staff/read')){
            return $api->showError();
        }
        if($this->request->getGet('username')){
            $this->model->where('username', $this->request->getGet('username'));
        }
        return $api->output([
            'users' => $this->model->select('id, username, fullname, email, registration, last_login')->findAll()
        ]);
    }

    public function show($id = null)
    {
        $api = Services::api();
        if(!$api->validatePermission('staff/read')){
            return $api->showError();
        }

        if(!is_numeric($id) || $id != round($id)){
            return $api->output(lang('Api.error.staffIdNotValid'), true);
        }
        if(!$result = $this->model->select('id, username, fullname, email, registration, last_login')->find($id)){
            return $api->output(lang('Api.error.staffIdNotFound'), true);
        }else{
            return $api->output(['staff_data' => $result]);
        }
    }

    public function auth()
    {
        $api = Services::api();
        if(!$api->validatePermission('staff/auth')){
            return $api->showError();
        }

        $validation = Services::validation();
        $staff = Services::staff();
        $settings = Services::settings();
        $validation->setRules([
            'username' => 'required|alpha_dash',
            'password' => 'required',
            'ip_address' => 'required|valid_ip'
        ],[
            'username' => [
                'required' => lang('Api.error.usernameMissing.'),
                'alpha_dash' => lang('Api.error.usernameNotValid'),
            ],
            'password' => [
                'required' => lang('Api.error.passwordMissing'),
            ],
            'ip_address' => [
                'required' => lang('Api.error.ipMissing'),
                'valid_ip' => lang('Api.error.ipNotValid')
            ]
        ]);

        if($validation->withRequest($this->request)->run() == FALSE) {
            return $api->output(implode(' ',array_values($validation->getErrors())), true);
        }elseif($staff->isLocked($this->request->getPost('ip_address'))) {
            $error_msg = lang_replace('Api.error.accountLocked', [
                '%n%' => $settings->config('login_attempt_minutes')
            ]);
            $error_msg .= ' '.lang_replace('Api.error.attemptCount',[
                '%1%' => $settings->config('login_attempt'),
                '%2' => $settings->config('login_attempt')
            ]);
            return $api->output($error_msg, true);
        }elseif (!$data = $staff->getRow(['username' => $this->request->getPost('username')])) {
            $attempts = $staff->addLoginAttempt($this->request->getPost('ip_address'));
            $error_msg = lang('Api.error.invalidUsernamePassword');
            if ($attempts > 0) {
                $error_msg .= ' '.lang_replace('Api.error.attemptCount',[
                        '%1%' => $attempts,
                        '%2' => $settings->config('login_attempt')
                    ]);
            }
            return $api->output($error_msg, true);
        }elseif (!$staff->verifyPassword($data)){
            $staff->addLoginLog($data->id, false, $this->request->getPost('ip_address'));
            $attempts = $staff->addLoginAttempt($this->request->getPost('ip_address'));
            $error_msg = lang('Api.error.invalidUsernamePassword');
            if ($attempts > 0) {
                $error_msg .= ' '.lang_replace('Api.error.attemptCount',[
                        '%1%' => $attempts,
                        '%2%' => $settings->config('login_attempt')
                    ]);
            }
            return $api->output($error_msg, true);
        }elseif(!$data->active){
            return $api->output(lang('Api.error.accountNotActive'), true);
        }else{
            if($data->two_factor == '') {
                $staff->update([
                    'login' => time(),
                    'last_login' => ($data->login == 0 ? time() : $data->login)
                ], $data->id);
                $staff->addLoginLog($data->id, true, $this->request->getPost('ip_address'));
                return $api->output(lang('Api.accountLoggedIn'));
            }elseif ($this->request->getPost('two_factor') == ''){
                return $api->output(lang('Api.error.twoFactorMissing'), true);
            }else{
                $twoFactor = new TwoFactor();
                if(!$twoFactor->verifyCode(str_decode($data->two_factor), $this->request->getPost('two_factor'))){
                    $staff->addLoginLog($data->id, false, $this->request->getPost('ip_address'));
                    $attempts = $staff->addLoginAttempt($this->request->getPost('ip_address'));
                    $error_msg = lang('Api.error.twoFactorNotValid');
                    if ($attempts > 0) {
                        $error_msg .= ' '.lang_replace('Api.error.attemptCount',[
                                '%1%' => $attempts,
                                '%2%' => $settings->config('login_attempt')
                            ]);
                    }
                    return $api->output($error_msg, true);
                }else{
                    $staff->update([
                        'login' => time(),
                        'last_login' => ($data->login == 0 ? time() : $data->login)
                    ], $data->id);
                    $staff->addLoginLog($data->id, true, $this->request->getPost('ip_address'));
                    return $api->output(lang('Api.accountLoggedIn'));
                }
            }

        }
    }
}