<?php

use App\Models\Tenant;

if (! function_exists('currentTenant')) {
    function currentTenant(): ?Tenant
    {
        return app()->bound('currentTenant') ? app('currentTenant') : null;
    }
}
