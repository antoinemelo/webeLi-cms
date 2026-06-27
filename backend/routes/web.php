<?php

return [
    ['GET', '/docs/public-api', 'App\\Application\\Frontend\\PublicApiDocsController@index'],
    ['GET', '/docs/public-api/{file:index\\.html|openapi\\.v1\\.json|openapi\\.v1\\.yaml|quickstart\\.md|authentication\\.md|errors\\.md|examples\\.md}', 'App\\Application\\Frontend\\PublicApiDocsController@show'],
    ['GET', '/examples/{example:headless-next|headless-nuxt|headless-astro|headless-vanilla}/README.md', 'App\Application\Frontend\PublicApiDocsController@example'],
    ['GET', '/business/memos/share/{token:[A-Za-z0-9]{32,128}}', 'App\Application\Frontend\BusinessMemoShareController@show'],
    ['GET', '/business/unsubscribe/{token:[A-Za-z0-9]{32,128}}', 'App\Application\Frontend\BusinessUnsubscribeController@show'],
    ['POST', '/business/unsubscribe/{token:[A-Za-z0-9]{32,128}}', 'App\Application\Frontend\BusinessUnsubscribeController@confirm'],
    ['GET', '/', 'App\Application\Frontend\HomeController@index'],
    ['GET', '/{path:.+}', 'App\Application\Frontend\RouteResolutionController@show'],
];
