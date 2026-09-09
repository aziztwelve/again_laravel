#!/bin/bash
cd /var/www/html/laravel
exec php artisan schedule:work
