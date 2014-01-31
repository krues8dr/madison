<?php

/**
*   Global Route Patterns
*/

Route::pattern('annotation', '[0-9a-zA-Z_-]+');
Route::pattern('comment', '[0-9a-zA-Z_-]+');
Route::pattern('doc', '[0-9]+');
Route::pattern('user', '[0-9]+');


/**
*   Route - Model bindings
*/
Route::model('user', 'User'); 

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

//Static Pages
Route::get('about', 'PageController@getAbout');
Route::get('faq', 'PageController@faq');
Route::get('/', array('as' => 'home', 'uses' => 'PageController@home'));

//Document Routes
Route::get('docs/search', 'DocController@getSearch');

Route::get('docs', 'DocController@index');
Route::get('docs/{slug}', 'DocController@index');


//User Routes
Route::get('user/{user}', 'UserController@getIndex');
Route::controller('user', 'UserController');

//Note Routes
Route::get('note/{annotation}', 'NoteController@getIndex');
Route::post('note/{annotation}', 'NoteController@postIndex');
Route::put('note/{annotation}', 'NoteController@putIndex');
Route::controller('note', 'NoteController');

//Dashboard Routes
Route::controller('dashboard', 'DashboardController');

//Api Routes
//Route::get('api', 'ApiController@getIndex');
    //Annotation Action Routes
    Route::post('api/docs/{doc}/annotations/{annotation}/likes', 'AnnotationApiController@postLikes');
    Route::post('api/docs/{doc}/annotations/{annotation}/dislikes', 'AnnotationApiController@postDislikes');
    Route::post('api/docs/{doc}/annotations/{annotation}/flags', 'AnnotationApiController@postFlags');
    Route::get('api/docs/{doc}/annotations/{annotation}/likes', 'AnnotationApiController@getLikes');
    Route::get('api/docs/{doc}/annotations/{annotation}/dislikes', 'AnnotationApiController@getDislikes');
    Route::get('api/docs/{doc}/annotations/{annotation}/flags', 'AnnotationApiController@getFlags');

    //Annotation Comment Routes
    Route::get('api/docs/{doc}/annotations/{annotation}/comments', 'AnnotationApiController@getComments');
    Route::post('api/docs/{doc}/annotations/{annotation}/comments', 'AnnotationApiController@postComments');
    Route::get('api/docs/{doc}/annotations/{annotation}/comments/{comment}', 'AnnotationApiController@getComments');

    //Annotation Routes
    Route::get('api/annotations/search', 'AnnotationApiController@getSearch');
    Route::get('api/docs/{doc}/annotations/{annotation?}', 'AnnotationApiController@getIndex');
    Route::post('api/docs/{doc}/annotations', 'AnnotationApiController@postIndex');
    //Route::get('api/docs/{doc}/annotations/{annotation}', 'AnnotationApiController@getIndex');
    Route::put('api/docs/{doc}/annotations/{annotation}', 'AnnotationApiController@putIndex');
    Route::delete('api/docs/{doc}/annotations/{annotation}', 'AnnotationApiController@deleteIndex');

    //Document Comment Routes
    Route::post('api/docs/{doc}/comments', 'CommentApiController@postIndex');
    Route::get('api/docs/{doc}/comments', 'CommentApiController@getIndex');
    Route::get('api/docs/{doc}/comments/{comment?}', 'CommentApiController@getIndex');

//Logout Route
Route::get('logout', function(){
	Auth::logout();	//Logout the current user
	Session::flush(); //delete the session
	return Redirect::to('/')->with('message', 'You have been successfully logged out.');
});

/**
*	Sitemap Route
*	TODO: What are the performance implications of this?  Are the results cached?  I would assume so, but not sure.
*/
Route::get('sitemap', function(){

	$sitemap = App::make('sitemap');

	$pages = array('about', 'faq', 'user/login', 'user/signup');

	foreach($pages as $page){
		$sitemap->add($page);
	}

    $docs = Doc::all();

    foreach ($docs as $doc)
    {
        $sitemap->add('doc/'.$doc->slug);
    }

    $notes = Note::all();

    foreach($notes as $note){
    	$sitemap->add('note/'.$note->id);
    }

    $users = User::all();

    foreach($users as $user){
    	$sitemap->add('user/'.$user->id);
    }

    // show your sitemap (options: 'xml' (default), 'html', 'txt', 'ror-rss', 'ror-rdf')
    return $sitemap->render('xml');
});


/*
|--------------------------------------------------------------------------
| Route Filters
|--------------------------------------------------------------------------
*/

/*
Route::post('register', array('before' => 'csrf', function()
{
    return 'You gave a valid CSRF token!';
}));
*/

Route::filter('auth', function()
{
	if (!Auth::check()) return Redirect::to('user/login');
});

Route::filter('admin', function(){
	if(Auth::guest() || Auth::user()->user_level != 1) return Redirect::home()->with('message', 'You are not authorized to view that page');
});