<?php

$app = new PHPSW\Application;

# config/local.yml overrides config/app.yml, e.g. debug: false to run it publicly
foreach (['app', 'local'] as $config) {
    if (file_exists(__DIR__ . '/../config/' . $config . '.yml')) {
        $app->register(new Igorw\Silex\ConfigServiceProvider(__DIR__ . '/../config/' . $config . '.yml'));
    }
}

$app->register(new Cocur\Slugify\Bridge\Silex\SlugifyServiceProvider());
$app->register(new Nicl\Silex\MarkdownServiceProvider());
$app->register(new Silex\Provider\HttpFragmentServiceProvider());
$app->register(new Silex\Provider\SwiftmailerServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider(), ['locale_fallbacks' => ['en']]);
$app->register(new Silex\Provider\TwigServiceProvider, [
    'twig.path' => __DIR__ . '/../views',
    'twig.options' => ['strict_variables' => false] # fixtures lack fields the import tasks add
]);
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

$app['guzzle'] = new GuzzleHttp\Client();

$app['joindin.client'] = function () {
    return new PHPSW\Joindin\Client();
};

$app['meetup.client'] = function ($app) {
    return new PHPSW\Meetup\Client($app, $app['meetup']);
};

# e.g. redis_database: 6 in config/local.yml, to keep clear of other apps flushing db 0
$app['redis'] = new Predis\Client([
    'database' => isset($app['redis_database']) ? $app['redis_database'] : 0
], [
    'prefix' => 'phpsw:'
]);

$app['swiftmailer.options'] = [
    'host' => 'smtp.mandrillapp.com',
    'port' => '587',
    'username' => $app['email'],
    'password' => $app['mandrill']['api']['key']
];

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addExtension(new PHPSW\Twig\EmojiExtension());
    $twig->addExtension(new PHPSW\Twig\SponsorExtension($app['sponsors']));
    $twig->addExtension(new PHPSW\Twig\ThumborExtension(function ($twitter) use ($app) {
        return $app['redis']->hget('avatars', strtolower($twitter));
    }));
    $twig->addExtension(new Salavert\Twig\Extension\TimeAgoExtension($app['translator']));
    $twig->addExtension(new Twig_Extensions_Extension_Text($app));
    $twig->addExtension(
        new Misd\LinkifyBundle\Twig\Extension\LinkifyTwigExtension(
            new Misd\LinkifyBundle\Helper\LinkifyHelper(
                new Misd\Linkify\Linkify()
            )
        )
    );

    return $twig;
}));

$app->get('/',                   'PHPSW\Controller\AppController::indexAction')->bind('home');
$app->get('/500',                'PHPSW\Controller\AppController::errorAction')->bind('error');
$app->get('/brand',              'PHPSW\Controller\AppController::brandAction')->bind('brand');
$app->get('/code-of-conduct',    'PHPSW\Controller\AppController::conductAction')->bind('conduct');
$app->get('/events',             'PHPSW\Controller\EventController::indexAction')->bind('events');
$app->get('/events/{id}-{slug}', 'PHPSW\Controller\EventController::showAction')->bind('event');
$app->get('/members',            'PHPSW\Controller\MemberController::indexAction')->bind('members');
$app->get('/speakers',           'PHPSW\Controller\SpeakerController::indexAction')->bind('speakers');
$app->get('/speakers/{slug}',    'PHPSW\Controller\SpeakerController::showAction')->bind('speaker');
$app->get('/sponsors',           'PHPSW\Controller\AppController::sponsorsAction')->bind('sponsors');
$app->get('/stats',              'PHPSW\Controller\EventController::statsAction')->bind('stats');
$app->get('/meetup/photos',      'PHPSW\Controller\MeetupController::photosAction')->bind('meetup_photos');
$app->get('/meetup/posts',       'PHPSW\Controller\MeetupController::postsAction')->bind('meetup_posts');
$app->get('/meetup/reviews',     'PHPSW\Controller\MeetupController::reviewsAction')->bind('meetup_reviews');
$app->get('/meetup/sponsors',    'PHPSW\Controller\MeetupController::sponsorsAction')->bind('meetup_sponsors');
$app->post('/message',           'PHPSW\Controller\MessageController::sendAction')->bind('message');
$app->get('/talks',              'PHPSW\Controller\TalkController::indexAction')->bind('talks');
$app->get('/talks/{slug}',       'PHPSW\Controller\TalkController::showAction')->bind('talk');

// upgrade links, images and embeds to https, the old http urls are mixed content or redirects now
$app->after(function ($request, $response) {
    if (strpos($response->headers->get('Content-Type'), 'text/html') === 0) {
        $self = preg_quote($request->getHttpHost(), '#');

        $response->setContent(preg_replace(
            ['#\b(src|href|data-src)="http://(?!' . $self . ')#', "#url\\('http://(?!" . $self . ')#'],
            ['$1="https://', "url('https://"],
            $response->getContent()
        ));
    }
});

$app->error(function (Exception $e, $code) use ($app) {
    if ($app['debug']) return;

    switch ($code) {
        case 404:
            $message = $app['twig']->render('error/404.html.twig', ['e' => $e]);
            break;
        default:
            $message = $app['twig']->render('error/500.html.twig', ['e' => $e]);
    }

    return new Symfony\Component\HttpFoundation\Response($message, $code);
});

return $app;
