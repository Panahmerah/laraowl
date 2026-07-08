<?php

use App\Rules\PublicUrl;
use Illuminate\Support\Facades\Validator;

function validatePublicUrl(?string $url): Illuminate\Contracts\Validation\Validator
{
    return Validator::make(['url' => $url], ['url' => ['nullable', new PublicUrl]]);
}

test('accepts a normal public url', function () {
    expect(validatePublicUrl('https://example.com/webhook')->passes())->toBeTrue();
});

test('rejects loopback ip literals', function () {
    expect(validatePublicUrl('http://127.0.0.1/admin')->fails())->toBeTrue();
});

test('rejects link-local cloud metadata address', function () {
    expect(validatePublicUrl('http://169.254.169.254/latest/meta-data/')->fails())->toBeTrue();
});

test('rejects private rfc1918 ip literals', function () {
    expect(validatePublicUrl('http://10.0.0.5/internal')->fails())->toBeTrue();
    expect(validatePublicUrl('http://192.168.1.1/internal')->fails())->toBeTrue();
    expect(validatePublicUrl('http://172.16.0.1/internal')->fails())->toBeTrue();
});

test('rejects non http schemes', function () {
    expect(validatePublicUrl('file:///etc/passwd')->fails())->toBeTrue();
    expect(validatePublicUrl('gopher://127.0.0.1:6379/_INFO')->fails())->toBeTrue();
});

test('allows empty value through since presence is validated separately', function () {
    expect(validatePublicUrl(null)->passes())->toBeTrue();
});
