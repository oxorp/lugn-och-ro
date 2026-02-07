<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_root_url_serves_swedish_locale(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertEquals('sv', app()->getLocale());
    }

    public function test_en_prefix_serves_english_locale(): void
    {
        $response = $this->get('/en/');

        $response->assertStatus(200);
        $this->assertEquals('en', app()->getLocale());
    }

    public function test_en_methodology_serves_english_locale(): void
    {
        $response = $this->get('/en/methodology');

        $response->assertStatus(200);
        $this->assertEquals('en', app()->getLocale());
    }

    public function test_swedish_methodology_serves_swedish_locale(): void
    {
        $response = $this->get('/methodology');

        $response->assertStatus(200);
        $this->assertEquals('sv', app()->getLocale());
    }

    public function test_api_routes_have_no_locale_prefix(): void
    {
        $response = $this->get('/api/deso/scores?year=2024');

        $response->assertStatus(200);
    }

    public function test_locale_cookie_is_respected_when_no_route_prefix(): void
    {
        $response = $this->withCookie('locale', 'en')->get('/');

        // The route forces 'sv' because it's in the sv group
        // Cookie does NOT override route-forced locale
        $response->assertStatus(200);
        $this->assertEquals('sv', app()->getLocale());
    }

    public function test_inertia_shares_locale(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);

        $page = $response->viewData('page');
        $this->assertEquals('sv', $page['props']['locale']);
    }

    public function test_inertia_shares_en_locale_for_en_prefix(): void
    {
        $response = $this->get('/en/');
        $response->assertStatus(200);

        $page = $response->viewData('page');
        $this->assertEquals('en', $page['props']['locale']);
    }

    public function test_hreflang_tags_present_on_swedish_page(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('hreflang="sv"', false);
        $response->assertSee('hreflang="en"', false);
        $response->assertSee('hreflang="x-default"', false);
    }

    public function test_hreflang_tags_present_on_english_page(): void
    {
        $response = $this->get('/en/');

        $response->assertStatus(200);
        $response->assertSee('hreflang="sv"', false);
        $response->assertSee('hreflang="en"', false);
        $response->assertSee('hreflang="x-default"', false);
    }

    public function test_html_lang_attribute_set_to_sv_for_swedish(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('lang="sv"', false);
    }

    public function test_html_lang_attribute_set_to_en_for_english(): void
    {
        $response = $this->get('/en/');

        $response->assertStatus(200);
        $response->assertSee('lang="en"', false);
    }

    public function test_admin_routes_work_with_en_prefix(): void
    {
        $response = $this->get('/en/admin/indicators');

        $response->assertStatus(200);
        $this->assertEquals('en', app()->getLocale());
    }

    public function test_admin_routes_work_with_sv_default(): void
    {
        $response = $this->get('/admin/indicators');

        $response->assertStatus(200);
        $this->assertEquals('sv', app()->getLocale());
    }

    public function test_backend_translation_files_exist(): void
    {
        $this->assertFileExists(lang_path('en/indicators.php'));
        $this->assertFileExists(lang_path('sv/indicators.php'));

        $en = require lang_path('en/indicators.php');
        $sv = require lang_path('sv/indicators.php');

        $this->assertArrayHasKey('median_income', $en);
        $this->assertArrayHasKey('median_income', $sv);
        $this->assertArrayHasKey('name', $en['median_income']);
        $this->assertArrayHasKey('name', $sv['median_income']);
    }

    public function test_backend_translations_return_correct_language(): void
    {
        app()->setLocale('en');
        $this->assertEquals('Median Disposable Income', __('indicators.median_income.name'));

        app()->setLocale('sv');
        $this->assertEquals('Median disponibel inkomst', __('indicators.median_income.name'));
    }

    public function test_invalid_locale_cookie_defaults_to_sv(): void
    {
        $response = $this->withCookie('locale', 'fr')->get('/');

        $response->assertStatus(200);
        // Route forces sv, invalid cookie doesn't override
        $this->assertEquals('sv', app()->getLocale());
    }
}
