<?php

namespace Daylight\Auth\Passwords;

use Closure;
use UnexpectedValueException;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Contracts\Auth\ActivationBroker as ActivationBrokerContract;
use Illuminate\Contracts\Auth\CanActivateAccount as CanActivateAccountContract;

class ActivationBroker implements ActivationBrokerContract
{
    /**
     * The password token repository.
     *
     * @var \Daylight\Auth\Accounts\TokenRepositoryInterface
     */
    protected $tokens;

    /**
     * The user provider implementation.
     *
     * @var \Illuminate\Contracts\Auth\UserProvider
     */
    protected $users;

    /**
     * The mailer instance.
     *
     * @var \Illuminate\Contracts\Mail\Mailer
     */
    protected $mailer;

    /**
     * The view of the password reset link e-mail.
     *
     * @var string
     */
    protected $emailView;

    /**
     * The custom password validator callback.
     *
     * @var \Closure
     */
    protected $passwordValidator;

    /**
     * Create a new password broker instance.
     *
     * @param  \Daylight\Auth\Passwords\TokenRepositoryInterface  $tokens
     * @param  \Illuminate\Contracts\Auth\UserProvider  $users
     * @param  \Illuminate\Contracts\Mail\Mailer  $mailer
     * @param  string  $emailView
     * @return void
     */
    public function __construct(TokenRepositoryInterface $tokens,
                                UserProvider $users,
                                MailerContract $mailer,
                                $emailView)
    {
        $this->users = $users;
        $this->mailer = $mailer;
        $this->tokens = $tokens;
        $this->emailView = $emailView;
    }

    /**
     * Send an account activation link to a user.
     *
     * @param  array  $credentials
     * @param  \Closure|null  $callback
     * @return string
     */
    public function sendActivationLink(array $credentials, Closure $callback = null)
    {
        // First we will check to see if we found a user at the given credentials and
        // if we did not we will redirect back to this current URI with a piece of
        // "flash" data in the session to indicate to the developers the errors.
        $user = $this->getUser($credentials);

        if (is_null($user)) {
            return ActivationBrokerContract::INVALID_USER;
        }

        // Once we have the reset token, we are ready to send the message out to this
        // user with a link to reset their password. We will then redirect back to
        // the current URI having nothing set in the session to indicate errors.
        $token = $this->tokens->create($user);

        $this->emailActivationLink($user, $token, $callback);

        return ActivationBrokerContract::RESET_LINK_SENT;
    }

    /**
     * Send the password reset link via e-mail.
     *
     * @param  \Daylight\Contracts\Auth\CanActivateAccount  $user
     * @param  string  $token
     * @param  \Closure|null  $callback
     * @return int
     */
    public function emailActivationLink(CanActivateAccountContract $user, $token, Closure $callback = null)
    {
        // We will use the reminder view that was given to the broker to display the
        // password reminder e-mail. We'll pass a "token" variable into the views
        // so that it may be displayed for an user to click for password reset.
        $view = $this->emailView;

        return $this->mailer->send($view, compact('token', 'user'), function ($m) use ($user, $token, $callback) {
            $m->to($user->getEmailForPasswordReset());

            if (! is_null($callback)) {
                call_user_func($callback, $m, $user, $token);
            }
        });
    }

    /**
     * Activate the account for the given token.
     *
     * @param  array  $credentials
     * @param  \Closure  $callback
     * @return mixed
     */
    public function activate(array $credentials, Closure $callback)
    {
        // If the responses from the validate method is not a user instance, we will
        // assume that it is a redirect and simply return it from this method and
        // the user is properly redirected having an error message on the post.
        $user = $this->validateReset($credentials);

        if (! $user instanceof CanActivateAccountContract) {
            return $user;
        }

        $pass = $credentials['password'];

        // Once we have called this callback, we will remove this token row from the
        // table and return the response from this callback so the user gets sent
        // to the destination given by the developers from the callback return.
        call_user_func($callback, $user, $pass);

        $this->tokens->delete($credentials['token']);

        return ActivationBrokerContract::PASSWORD_RESET;
    }

    /**
     * Validate a password reset for the given credentials.
     *
     * @param  array  $credentials
     * @return \Illuminate\Contracts\Auth\CanActivateAccount
     */
    protected function validateReset(array $credentials)
    {
        if (is_null($user = $this->getUser($credentials))) {
            return ActivationBrokerContract::INVALID_USER;
        }

        if (! $this->validateNewPassword($credentials)) {
            return ActivationBrokerContract::INVALID_PASSWORD;
        }

        if (! $this->tokens->exists($user, $credentials['token'])) {
            return ActivationBrokerContract::INVALID_TOKEN;
        }

        return $user;
    }

    /**
     * Set a custom password validator.
     *
     * @param  \Closure  $callback
     * @return void
     */
    public function validator(Closure $callback)
    {
        $this->passwordValidator = $callback;
    }

    /**
     * Determine if the passwords match for the request.
     *
     * @param  array  $credentials
     * @return bool
     */
    public function validateNewPassword(array $credentials)
    {
        list($password, $confirm) = [
            $credentials['password'],
            $credentials['password_confirmation'],
        ];

        if (isset($this->passwordValidator)) {
            return call_user_func(
                $this->passwordValidator, $credentials) && $password === $confirm;
        }

        return $this->validatePasswordWithDefaults($credentials);
    }

    /**
     * Determine if the passwords are valid for the request.
     *
     * @param  array  $credentials
     * @return bool
     */
    protected function validatePasswordWithDefaults(array $credentials)
    {
        list($password, $confirm) = [
            $credentials['password'],
            $credentials['password_confirmation'],
        ];

        return $password === $confirm && mb_strlen($password) >= 6;
    }

    /**
     * Get the user for the given credentials.
     *
     * @param  array  $credentials
     * @return \Illuminate\Contracts\Auth\CanActivateAccount
     *
     * @throws \UnexpectedValueException
     */
    public function getUser(array $credentials)
    {
        $credentials = array_except($credentials, ['token']);

        $user = $this->users->retrieveByCredentials($credentials);

        if ($user && ! $user instanceof CanActivateAccountContract) {
            throw new UnexpectedValueException('User must implement CanActivateAccount interface.');
        }

        return $user;
    }

    /**
     * Get the password reset token repository implementation.
     *
     * @return \Illuminate\Auth\Passwords\TokenRepositoryInterface
     */
    protected function getRepository()
    {
        return $this->tokens;
    }
}
