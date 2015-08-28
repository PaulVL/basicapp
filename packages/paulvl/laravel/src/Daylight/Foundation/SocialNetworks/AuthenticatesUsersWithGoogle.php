<?php

namespace Daylight\Foundation\SocialNetworks;

use BadFunctionCallException;
use Socialite;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Auth;
use Daylight\Support\Facades\Confirmation;
use Illuminate\Foundation\Auth\RedirectsUsers;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait AuthenticatesUsersWithGoogle
{   

    /**
     * Redirect the user to the Google authentication page.
     *
     * @return Response
     */
    public function getIndex()
    {
        if( !method_exists($this, 'create') ) {
            throw new BadFunctionCallException("'create' method does not exists on ".get_class($this));
        }
        return Socialite::driver($this->provider)->redirect();
    }

    /**
     * Obtain the user information from Google.
     *
     * @return Response
     */
    public function getCallback()
    {
        $user = Socialite::driver($this->provider)->user();
        Auth::login($this->create($user));
        return redirect($this->redirectPath())->with('status', 'Logged in with Google.');
    }

    public function create($user)
    {

    }

}
