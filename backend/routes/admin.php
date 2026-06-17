<?php

return [
    ['GET', '/admin', 'App\Application\Admin\AdminSpaController@index'],
    ['GET', '/admin/', 'App\Application\Admin\AdminSpaController@index'],
    ['GET', '/admin/login', 'App\Application\Admin\AdminAuthController@login'],
    ['POST', '/admin/login', 'App\Application\Admin\AdminAuthController@login'],
    ['GET', '/admin/forgot-password', 'App\Application\Admin\AdminAuthController@forgotPassword'],
    ['POST', '/admin/forgot-password', 'App\Application\Admin\AdminAuthController@forgotPassword'],
    ['GET', '/admin/reset-password', 'App\Application\Admin\AdminAuthController@resetPassword'],
    ['POST', '/admin/reset-password', 'App\Application\Admin\AdminAuthController@resetPassword'],
    ['POST', '/admin/logout', 'App\Application\Admin\AdminAuthController@logout'],
    ['GET', '/admin/app', 'App\Application\Admin\AdminSpaController@index'],
    ['GET', '/admin/app/', 'App\Application\Admin\AdminSpaController@index'],
    ['GET', '/admin/app/{path:.+}', 'App\Application\Admin\AdminSpaController@index'],
];
