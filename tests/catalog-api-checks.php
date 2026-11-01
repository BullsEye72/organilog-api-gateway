<?php
declare(strict_types=1);

// Included by run.php. Verify every catalog write is routed and blocked before
// upstream access on a fresh installation, including the nonstandard "new" action.
$catalog = new Organilog\Catalog();
foreach ($catalog->all()['endpoints'] as $endpoint) {
    check(Organilog\Catalog::path($endpoint) === $endpoint['entity'] . '/'
        . ($endpoint['action'] === '' ? '' : $endpoint['action'] . '.php'),
        'Catalog path must preserve each Organilog action: ' . $endpoint['id']);
    if ($endpoint['kind'] === 'read') continue;
    [$status, $data] = request($base, '/v1/' . $endpoint['id'], $admin);
    check($status === 405 && $data['error']['code'] === 'method_not_allowed',
        'GET must reject every write route: ' . $endpoint['id']);
    [$status, $data] = request($base, '/v1/' . $endpoint['id'], $admin, 'POST', []);
    check($status === 403 && $data['error']['code'] === 'writes_disabled',
        'Write switch must protect every write route: ' . $endpoint['id']);
}
check($catalog->get('custom_field/new')['kind'] === 'create', 'Creating a field definition needs create permission.');
check($catalog->get('custom_field/update')['kind'] === 'update', 'Updating a field definition needs update permission.');
