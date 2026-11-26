<?php

use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->headers = $this->getAuthenticationHeaders();
});

dataset('dam api id routes', [
    'asset show'           => ['get', '/api/v1/rest/assets/%s'],
    'asset edit (get)'     => ['get', '/api/v1/rest/assets/edit/%s'],
    'asset edit (put)'     => ['put', '/api/v1/rest/assets/edit/%s'],
    'asset metadata'       => ['get', '/api/v1/rest/assets/%s/metadata'],
    'asset tags'           => ['get', '/api/v1/rest/assets/%s/tags'],
    'asset update'         => ['put', '/api/v1/rest/assets/%s'],
    'asset destroy'        => ['delete', '/api/v1/rest/assets/%s'],
    'asset download'       => ['get', '/api/v1/rest/assets/download/%s'],
    'directory get'        => ['get', '/api/v1/rest/directories/%s'],
    'directory update'     => ['put', '/api/v1/rest/directories/%s'],
    'directory delete'     => ['delete', '/api/v1/rest/directories/%s'],
    'comment get'          => ['get', '/api/v1/rest/comments/%s'],
    'comment update'       => ['put', '/api/v1/rest/comments/%s'],
    'comment delete'       => ['delete', '/api/v1/rest/comments/%s'],
    'tag get'              => ['get', '/api/v1/rest/tags/%s'],
    'tag destroy'          => ['delete', '/api/v1/rest/tags/%s'],
    'property get'         => ['get', '/api/v1/rest/properties/%s'],
    'property add'         => ['post', '/api/v1/rest/properties/%s'],
    'property update'      => ['patch', '/api/v1/rest/properties/%s'],
    'property delete'      => ['delete', '/api/v1/rest/properties/%s'],
    'linked resource get'  => ['get', '/api/v1/rest/linked-resource/%s'],
    'share show'           => ['get', '/api/v1/rest/shares/%s'],
    'share update'         => ['put', '/api/v1/rest/shares/%s'],
    'share revoke'         => ['post', '/api/v1/rest/shares/%s/revoke'],
    'share reauthorize'    => ['post', '/api/v1/rest/shares/%s/reauthorize'],
    'share destroy'        => ['delete', '/api/v1/rest/shares/%s'],
]);

dataset('invalid ids', [
    'text'     => ['abc'],
    'overflow' => ['9999999999999999999'],
]);

it('returns 404 instead of a server error for a non-numeric or overflowing id', function (string $method, string $uri, string $id) {
    $this->withHeaders($this->headers)
        ->json($method, sprintf($uri, $id))
        ->assertNotFound();
})->with('dam api id routes')->with('invalid ids');

it('still returns 404 for a well-formed id that does not exist', function () {
    $this->withHeaders($this->headers)
        ->getJson('/api/v1/rest/assets/999999')
        ->assertNotFound();
});

it('returns 404 for a non-numeric id on the signed download route', function () {
    $url = URL::temporarySignedRoute('admin.api.dam.assets.private.download', now()->addMinutes(5), ['id' => 'abc']);

    $this->get($url)->assertNotFound();
});
