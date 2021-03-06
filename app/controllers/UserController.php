<?php
/**
 * 	Controller for user actions.
 */
class UserController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     *	getGroups
     *		Returns a user's groups with the user's role included.
     *
     *	@param User $user
     *
     * @return Response::json
     */
    public function getGroups(User $user)
    {
        $groups = $user->groups()->get();

        foreach ($groups as $group) {
            $group->role = $group->findMemberByUserId($user->id)->role;
        }

        return Response::json($groups);
    }

    /**
     *	API PUT Route to update a user's notification settings.
     *
     *	@param User $user
     *
     *	@return Response::json
     *
     * @todo There has to be a more efficient way to do this... We should probably only send changes from Angular.  We can also separate the array matching into helper functions
     */
    public function putNotifications(User $user)
    {
        if (Auth::user()->id !== $user->id) {
            return Response::json($this->growlMessage("You do not have permissions to edit this user's notification settings", "error"));
        }

        //Grab notification array
        $notifications = Input::get('notifications');

        //Retrieve valid notification events
        $validNotifications = Notification::getUserNotifications();
        $events = array_keys($validNotifications);

        //Loop through each notification
        foreach ($notifications as $notification) {
            //Ensure this is a known user event.
            if (!in_array($notification['event'], $events)) {
                return Response::json($this->growlMessage("Unable to save settings.  Unknown event: ".$notification['event'], "error"));
            }

            //Grab this notification from the database
            $model = Notification::where('user_id', '=', $user->id)->where('event', '=', $notification['event'])->first();

            //If we don't want that notification (and it exists), delete it
            if ($notification['selected'] === false) {
                if (isset($model)) {
                    $model->delete();
                }
            } else {
                //If the entry doesn't already exist, create it.
                    //Otherwise, ignore ( there was no change )
                if (!isset($model)) {
                    $model = new Notification();
                    $model->user_id = $user->id;
                    $model->event = $notification['event'];
                    $model->type = "email";

                    $model->save();
                }
            }
        }

        return Response::json($this->growlMessage("Settings saved successfully.", "success"));
    }

    /**
     *	API GET Route to get viable User notifications and notification statuses for current user.
     *
     *	@param User $user
     *
     *	@return Response::json
     *
     *	@todo I'm sure this can be simplified...
     */
    public function getNotifications(User $user)
    {
        if (Auth::user()->id !== $user->id) {
            return Response::json($this->growlMessage("You do not have permission to view this user's notification settings", "error"), 401);
        }

        //Retrieve all valid user notifications as associative array (event => description)
        $validNotifications = Notification::getUserNotifications();

        //Filter out event keys
        $events = array_keys($validNotifications);

        //Retreive all User Events for the current user
        $currentNotifications = Notification::select('event')->where('user_id', '=', $user->id)->whereIn('event', $events)->get();

        //Filter out event names from selected notifications
        $currentNotifications = $currentNotifications->toArray();
        $selectedEvents = array();
        foreach ($currentNotifications as $notification) {
            array_push($selectedEvents, $notification['event']);
        }

        //Build array of notifications and their selected status
        $toReturn = array();
        foreach ($validNotifications as $event => $description) {
            $notification = array();
            $notification['event'] = $event;
            $notification['description'] = $description;
            $notification['selected'] = in_array($event, $selectedEvents) ? true : false;

            array_push($toReturn, $notification);
        }

        return Response::json($toReturn);
    }

    /**
     *	Notification Preference Page.
     *
     *	@param User $user
     *
     *	@return Illuminate\View\View
     */
    public function editNotifications(User $user)
    {
        //Render view and return
        return View::make('single');
    }

    /**
     *	Api route to edit user's email.
     *
     * @param User $user
     *
     * @return array $response
     */
    public function editEmail(User $user)
    {
        //Check authorization
        if (Auth::user()->id !== $user->id) {
            return Response::json($this->growlMessage("You are not authorized to change that user's email", "error"));
        }

        $user->email = Input::get('email');
        $user->password = Input::get('password');

        if ($user->save()) {
            return Response::json($this->growlMessage("Email saved successfully.  Thank you.", 'success'), 200);
        } else {
            $errors = $user->getErrors();
            $messages = array();

            foreach ($errors->all() as $error) {
                array_push($messages, $error);
            }

            return Response::json($this->growlMessage($messages, 'error'), 500);
        }
    }

    /**
     *	Api route to get logged in user / group.
     *
     *	@param void
     *
     * @return JSON user
     */
    public function getCurrent()
    {
        if (!Auth::check()) {
            return Response::json(null);
        }

        $user = Auth::user();

        //Grab the active group from the user
        $activeGroup = $user->activeGroup();

        $user->display_name = $user->getDisplayName();
        $user->admin = $user->hasRole('Admin');
        if ($user->hasRole('Independent Sponsor')) {
            $user->independent_sponsor = true;
        } elseif ($user->getSponsorStatus() !== null) {
            $user->independent_sponsor = $user->getSponsorStatus();
        }
        $user->verified = $user->verified();

        //Grab all of the user's groups
        $groups = $user->groups()->get();

        //Set the user's role in each group
        foreach ($groups as $group) {
            $role = $group->getMemberRole($user->id);

            $group->role = $role;
        }

        $userArray = $user->toArray();
        $groupArray = $groups->toArray();
        $activeGroupId = $activeGroup != null ? $activeGroup->id : null;

        $returned = [
        'user'      => $userArray,
        'groups'    => $groupArray,
        'activeGroupId' => $activeGroupId,
        ];

        return Response::json($returned);
    }

    /**
     *	putEdit.
     *
     *	User's put request to update their profile
     *
     *	@param User $user
     *
     *	@return Illuminate\Http\RedirectResponse
     */
    public function putEdit(User $user)
    {
        if (!Auth::check()) {
            return Response::json($this->growlMessage('Please log in to edit user profile', 'error'), 401);
        } elseif (Auth::user()->id != $user->id) {
            return Response::json($this->growlMessage('You do not have access to that profile.', 'error'), 403);
        } elseif ($user == null) {
            return Response::error('404');
        }

        if (strlen(Input::get('password')) > 0) {
            $user->password = Input::get('password');
        }

        $verify = Input::get('verify_request');

        $user->email = Input::get('email');
        $user->fname = Input::get('fname');
        $user->lname = Input::get('lname');
        $user->url = Input::get('url');
        $user->phone = Input::get('phone');
        $user->verify = $verify;

        // Don't allow oauth logins to update the user's data anymore,
        // since they've set values within Madison.
        $user->oauth_update = false;
        if (!$user->save()) {
            $messages = $user->getErrors()->toArray();
            $messageArray = [];

            foreach ($messages as $key => $value) {
                //If an array of messages have been passed, push each one onto messageArray
                if (is_array($value)) {
                    Log::info($value);
                    foreach ($value as $message) {
                        array_push($messageArray, $message);
                    }
                } else { //Otherwise just push the message value
                    array_push($messageArray, $value);
                }
            }

            return Response::json($this->growlMessage($messageArray, 'error'), 400);
        }

        if (isset($verify)) {
            $meta = new UserMeta();
            $meta->meta_key = 'verify';
            $meta->meta_value = 'pending';
            $meta->user_id = $user->id;
            $meta->save();

            Event::fire(MadisonEvent::VERIFY_REQUEST_USER, $user);

            return Response::json($this->growlMessage(['Your profile has been updated', 'Your verified status has been requested.'], 'success'));
        }

        return Response::json($this->growlMessage('Your profile has been updated.', 'success'));
    }

    /**
     *	putIndex.
     *
     *	Returns 404 Response
     *
     *	@param $id
     *
     *	@return Response
     *
     *	@todo Remove route and method
     */
    public function putIndex($id = null)
    {
        return Response::error('404');
    }

    /**
     *	postIndex.
     *
     *	Returns 404 Response
     *
     *	@param $id
     *
     *	@return Response
     *
     *	@todo remove route and method
     */
    public function postIndex($id = null)
    {
        return Response::error('404');
    }

    /**
     *	postLogin.
     *
     *	Handles POST requests for users logging in
     *
     *	@param void
     *
     *	@return Illuminate\Http\RedirectResponse
     *
     *  @todo Does not appear to be used?
     */
    public function postLogin()
    {
        //Retrieve POST values
        $email = Input::get('email');
        $password = Input::get('password');
        $previous_page = Input::get('previous_page');
        $remember = Input::get('remember');
        $user_details = Input::all();

        //Rules for login form submission
        $rules = array('email' => 'required', 'password' => 'required');
        $validation = Validator::make($user_details, $rules);

        //Validate input against rules
        if ($validation->fails()) {
            return Redirect::to('user/login')->withInput()->withErrors($validation);
        }

        //Check that the user account exists
        $user = User::where('email', $email)->first();

        if (!isset($user)) {
            return Redirect::to('user/login')->with('error', 'That email does not exist.');
        }

        //If the user's token field isn't blank, he/she hasn't confirmed their account via email
        if ($user->token != '') {
            return Redirect::to('user/login')->with('error', 'Please click the link sent to your email to verify your account.');
        }

        //Attempt to log user in
        $credentials = array('email' => $email, 'password' => $password);

        if (Auth::attempt($credentials, ($remember == 'true') ? true : false)) {
            Auth::user()->last_login = new DateTime();
            Auth::user()->save();
            if (isset($previous_page)) {
                return Redirect::to($previous_page)->with('message', 'You have been successfully logged in.');
            } else {
                return Redirect::to('/docs/')->with('message', 'You have been successfully logged in.');
            }
        } else {
            return Redirect::to('user/login')->with('error', 'Incorrect login credentials')->withInput(array('previous_page' => $previous_page));
        }
    }

    /**
     * 	postSignup.
     *
     *	Handles POST requests for users signing up natively through Madison
     *		Fires MadisonEvent::NEW_USER_SIGNUP Event
     *
     *	@param void
     *
     *	@return Illuminate\Http\RedirectResponse
     */
    public function postSignup()
    {
        //Retrieve POST values
        $email = Input::get('email');
        $password = Input::get('password');
        $fname = Input::get('fname');
        $lname = Input::get('lname');

        //Create user token for email verification
        $token = str_random();

        //Create new user
        $user = new User();
        $user->email = $email;
        $user->password = $password;
        $user->fname = $fname;
        $user->lname = $lname;
        $user->token = $token;
        if (! $user->save()) {
            return Redirect::to('user/signup')->withInput()->withErrors($user->getErrors());
        }

        Event::fire(MadisonEvent::NEW_USER_SIGNUP, $user);

        //Send email to user for email account verification
        Mail::queue('email.signup', array('token' => $token), function ($message) use ($email, $fname) {
            $message->subject('Welcome to the Madison Community');
            $message->from('sayhello@opengovfoundation.org', 'Madison');
            $message->to($email); // Recipient address
        });

        return Redirect::to('user/login')->with('message', 'An email has been sent to your email address.  Please follow the instructions in the email to confirm your email address before logging in.');
    }

    /**
     * 	postVerify.
     *
     *	Handles POST requests for email verifications
     *
     *	@param string $token
     */
    public function postVerify()
    {
        $token = Input::get('token');

        $user = User::where('token', $token)->first();

        if (isset($user)) {
            $user->token = '';
            $user->save();

            Auth::login($user);

            return Response::json($this->growlMessage('Your email has been verified and you have been logged in.  Welcome '.$user->fname, 'success'));
        } else {
            return Response::json($this->growlMessage('The verification link is invalid.', 'error'), 400);
        }
    }

    /**
     * getFacebookLogin.
     *
     *	Handles OAuth communication with Facebook for signup / login
     *		Calls $this->getAuthorizationUri() if the oauth code is passed via Input
     *		Otherwise calls $fb->getAuthorizationUri()
     *
     *	@param void
     *
     *	@return Illuminate\Http\RedirectResponse || $this->oauthLogin($user_info)
     *
     *	@todo clean up this doc block
     */
    public function getFacebookLogin()
    {
        // get data from input
        $code = Input::get('code');

        // get fb service
        $fb = OAuth::consumer('Facebook', url('api/user/facebook-login'), null);

        // check if code is valid
        // if code is provided get user data and sign in
        if (!empty($code)) {
            // This was a callback request from facebook, get the token
            $token = $fb->requestAccessToken($code);

            // Send a request with it
            $result = json_decode($fb->request('/me?fields=first_name,last_name,email,id'), true);

            // Remap the $result to something that matches our schema.
            $user_info = array(
                'fname' => $result['first_name'],
                'lname' => $result['last_name'],
                'email' => $result['email'],
                'oauth_vendor' => 'facebook',
                'oauth_id' => $result['id'],
            );

            return $this->oauthLogin($user_info);
        }
        // if not ask for permission first
        else {
            // get fb authorization
            $url = $fb->getAuthorizationUri();

            $res = [
                'authUrl' => urldecode($url),
            ];

            // return to facebook login url
            return Response::json($res);
        }
    }

    /**
     * getFacebookLogin.
     *
     *	Handles OAuth communication with Twitter for signup / login
     *		Calls $this->oauthLogin() if the oauth code is passed via Input
     *		Otherwise calls $tw->requestRequestToken()
     *
     *	@param void
     *
     *	@return Illuminate\Http\RedirectResponse || $this->oauthLogin($user_info)
     *
     *	@todo clean up this doc block
     */
    public function getTwitterLogin()
    {
        // get data from input
        $token = Input::get('oauth_token');
        $verify = Input::get('oauth_verifier');

        // get twitter service
        $tw = OAuth::consumer('Twitter');

        // check if code is valid

        // if code is provided get user data and sign in
        if (!empty($token) && !empty($verify)) {
            // This was a callback request from twitter, get the token
        $token = $tw->requestAccessToken($token, $verify);

        // Send a request with it
        $result = json_decode($tw->request('account/verify_credentials.json'), true);

            $user_info = array(
                    'fname' => $result['name'],
                    'lname' => '-',
                    'oauth_vendor' => 'twitter',
                    'oauth_id' => $result['id'],
                );

            return $this->oauthLogin($user_info, 'user/login/twitter-login');
        }
        // if not ask for permission first
        else {
            // get request token
          $reqToken = $tw->requestRequestToken();

          // get Authorization Uri sending the request token
          $url = $tw->getAuthorizationUri(array('oauth_token' => $reqToken->getRequestToken()));

            $res = [
                    'authUrl' => urldecode($url),
                ];

                // return to facebook login url
                return Response::json($res);
        }
    }

    /**
     * getFacebookLogin.
     *
     *	Handles OAuth communication with Facebook for signup / login
     *		Calls $this->oauthLogin() if the oauth code is passed via Input
     *		Otherwise calls $linkedinService->getAuthorizationUri()
     *
     *	@param void
     *
     *	@return Illuminate\Http\RedirectResponse || $this->oauthLogin($user_info)
     *
     *	@todo clean up this doc block
     */
    public function getLinkedinLogin()
    {
        // get data from input
        $code = Input::get('code');

        $linkedinService = OAuth::consumer('Linkedin');

        if (!empty($code)) {
            // retrieve the CSRF state parameter
            $state = isset($_GET['state']) ? $_GET['state'] : null;

            // This was a callback request from linkedin, get the token
            $token = $linkedinService->requestAccessToken($_GET['code'], $state);

            // Send a request with it. Please note that XML is the default format.
            $result = json_decode($linkedinService->request('/people/~:(id,first-name,last-name,email-address)?format=json'), true);

            // Remap the $result to something that matches our schema.
            $user_info = array(
                'fname' => $result['firstName'],
                'lname' => $result['lastName'],
                'email' => $result['emailAddress'],
                'oauth_vendor' => 'linkedin',
                'oauth_id' => $result['id'],
            );

            return $this->oauthLogin($user_info);
        }// if not ask for permission first
        else {
            // get linkedinService authorization
            $url = $linkedinService->getAuthorizationUri();

            $res = [
                'authUrl' => urldecode($url),
            ];

            // return to linkedin login url
            return Response::json($res);
        }
    }

    /**
     *	oauthLogin.
     *
     * Use OAuth data to login user.  Create account if necessary.
     *
     *	@param array $user_info
     *
     *	@return Illuminate\Http\RedirectResponse
     *
     *	@todo Should this be moved to the User model?
     */
    public function oauthLogin($user_info, $redirect = false)
    {
        // See if we already have a matching user in the system
        $user = User::where('oauth_vendor', $user_info['oauth_vendor'])
            ->where('oauth_id', $user_info['oauth_id'])->first();

        if (!isset($user)) {
            // Make sure this user doesn't already exist in the system.
            if (isset($user_info['email'])) {
                $existing_user = User::where('email', $user_info['email'])->first();

                if (isset($existing_user)) {
                    Auth::login($existing_user);

                    return Redirect::to('/user/login/facebook-login');
                }
            }

            // Create a new user since we don't have one.
            $user = new User();
            $user->oauth_vendor = $user_info['oauth_vendor'];
            $user->oauth_id = $user_info['oauth_id'];
            $user->oauth_update = true;

            $new_user = true;
        }

        // Now that we have a user for sure, update the user and log them in.
        $user->fname = $user_info['fname'];
        $user->lname = $user_info['lname'];
        if (isset($user_info['email'])) {
            $user->email = $user_info['email'];
        }

        // If the user is new, or if we are allowed to update the user via oauth.
        // Note: The oauth_update flag is turned to off the first time the user
        // edits their account within Madison, locking in their info.
        if (isset($new_user) || (isset($user->oauth_update) && $user->oauth_update == true)) {
            if (!$user->save()) {
                Log::error('Unable to save user: ', $user_info);
            }
        }

        if ($user instanceof User) {
            Auth::login($user);
        } else {
            Log::error('Trying to authenticate user of incorrect type', $user->toArray());
        }

        if (isset($new_user)) {
            $message = 'Welcome '.$user->fname;
        } else {
            $message = 'Welcome back, '.$user->fname;
        }

        if ($redirect) {
            return Redirect::to($redirect);
        } else {
            return Response::json($this->growlMessage($message, 'success'));
        }
    }
}
