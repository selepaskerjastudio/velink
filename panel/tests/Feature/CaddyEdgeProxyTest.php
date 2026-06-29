<?php

use App\Services\Edge\CaddyEdgeProxy;
use Illuminate\Support\Facades\Http;

const CADDY_ADMIN = 'http://caddy.test:2019';

test('addRoute deletes any existing route then posts the new one', function () {
    Http::fake([
        CADDY_ADMIN.'/id/*' => Http::response('', 200),
        CADDY_ADMIN.'/config/apps/http/servers/edge/routes' => Http::response('', 200),
    ]);

    (new CaddyEdgeProxy(CADDY_ADMIN, 'edge'))
        ->addRoute('app.example.com', '10.0.0.30:80', 'uuid-123');

    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && $req->url() === CADDY_ADMIN.'/id/velink-app-uuid-123');

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && $req->url() === CADDY_ADMIN.'/config/apps/http/servers/edge/routes'
            && $req['@id'] === 'velink-app-uuid-123'
            && $req['match'][0]['host'][0] === 'app.example.com'
            && $req['handle'][0]['upstreams'][0]['dial'] === '10.0.0.30:80';
    });
});

test('removeRoute deletes the route by id', function () {
    Http::fake([CADDY_ADMIN.'/id/*' => Http::response('', 200)]);

    (new CaddyEdgeProxy(CADDY_ADMIN, 'edge'))->removeRoute('uuid-123');

    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && $req->url() === CADDY_ADMIN.'/id/velink-app-uuid-123');
});

test('addRoute is non-blocking when Caddy returns an error', function () {
    Http::fake([
        CADDY_ADMIN.'/id/*' => Http::response('', 200),
        CADDY_ADMIN.'/config/*' => Http::response('boom', 500),
    ]);

    // Must not throw — an edge failure can't break app provisioning.
    (new CaddyEdgeProxy(CADDY_ADMIN, 'edge'))
        ->addRoute('app.example.com', '10.0.0.30:80', 'uuid-123');

    expect(true)->toBeTrue();
});

test('a missing admin url is a safe no-op', function () {
    Http::fake();

    (new CaddyEdgeProxy(null, 'edge'))->addRoute('app.example.com', '10.0.0.30:80', 'uuid-123');

    Http::assertNothingSent();
});
