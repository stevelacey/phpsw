<?php

namespace PHPSW\Controller;

use Silex\Application,
    Symfony\Component\HttpFoundation\Response;

class AppController extends AbstractController
{
    public function indexAction(Application $app)
    {
        $meetup = $app['meetup.client'];

        $description = $meetup->getGroup()->description;
        $description = str_replace($app['website']['url'], $app['meetup']['url'], $description);
        $description = str_replace($app['website']['urlshort'], $app['meetup']['urlshort'], $description);
        $description = str_replace($app['meetup']['url'] . '/code-of-conduct', $app['website']['url'] . '/code-of-conduct', $description);

        return $this->render($app, 'index.html.twig', [
            'description' => $description,
            'events' => $meetup->getUpcomingEvents() ?: array_slice($meetup->getPastEvents(), 0, 1)
        ]);
    }

    public function brandAction(Application $app)
    {
        return $this->render($app, 'brand.html.twig');
    }

    public function conductAction(Application $app)
    {
        return $this->render($app, 'code-of-conduct.html.twig');
    }

    public function errorAction(Application $app)
    {
        throw new \Exception();
    }

    public function sponsorsAction(Application $app)
    {
        return $this->render($app, 'sponsors.html.twig');
    }
}
