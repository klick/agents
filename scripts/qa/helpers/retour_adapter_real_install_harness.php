#!/usr/bin/env php
<?php

declare(strict_types=1);

use craft\helpers\Json;
use craft\helpers\StringHelper;
use Klick\Agents\Plugin as AgentsPlugin;
use yii\db\Query;

foreach ([5, 6] as $levels) {
    $candidateRoot = dirname(__DIR__, $levels);
    $bootstrapPath = $candidateRoot . '/bootstrap.php';
    if (is_file($bootstrapPath)) {
        require $bootstrapPath;
        break;
    }
}

if (!defined('CRAFT_BASE_PATH')) {
    fwrite(STDERR, "Unable to locate Craft bootstrap.php for the Retour adapter harness.\n");
    exit(1);
}

require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

const AGENTS_CREDENTIALS_TABLE = '{{%agents_credentials}}';
const RETOUR_REDIRECTS_TABLE = '{{%retour_redirects}}';
const TEST_SOURCE_URL = '/qa/f12-retour-source';
const TEST_DESTINATION_URL = '/qa/f12-retour-destination';
const TEST_ALLOW_HANDLE = 'f12-retour-read';
const TEST_DENY_HANDLE = 'f12-retour-deny';
const TEST_ALLOW_TOKEN = 'agents-f12-retour-read-token';
const TEST_DENY_TOKEN = 'agents-f12-retour-deny-token';
const TEST_EXTERNAL_SCOPE = 'plugins:retour:redirects:read';

$command = trim((string)($argv[1] ?? ''));

try {
    switch ($command) {
        case 'snapshot':
            respond(snapshot());

        case 'seed-redirect':
            respond(seedRedirect());

        case 'ensure-test-credentials':
            respond(ensureTestCredentials());

        case 'cleanup-fixtures':
            respond(cleanupFixtures());

        default:
            fail('Unknown command.', [
                'command' => $command,
                'availableCommands' => [
                    'snapshot',
                    'seed-redirect',
                    'ensure-test-credentials',
                    'cleanup-fixtures',
                ],
            ]);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), [
        'exception' => get_class($e),
    ]);
}

function respond(array $payload, int $exitCode = 0): never
{
    $encoded = Json::encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        $encoded = '{"ok":false,"message":"Unable to encode response."}';
        $exitCode = 1;
    }

    fwrite(STDOUT, $encoded . PHP_EOL);
    exit($exitCode);
}

function fail(string $message, array $context = []): never
{
    respond([
        'ok' => false,
        'message' => $message,
        'context' => $context,
    ], 1);
}

function snapshot(): array
{
    $plugin = AgentsPlugin::getInstance();
    $plugin->refreshExternalResourceProviders();

    $registry = $plugin->getExternalResourceRegistryService();
    $resources = $registry->getCapabilitiesResources();

    $retourResource = null;
    foreach ($resources as $provider) {
        if (($provider['plugin'] ?? '') !== 'retour') {
            continue;
        }

        foreach ((array)($provider['resources'] ?? []) as $resource) {
            if (($resource['handle'] ?? '') === 'redirects') {
                $retourResource = $resource;
                break 2;
            }
        }
    }

    $posture = $plugin->getSecurityPolicyService()->getCpPosture();

    return [
        'ok' => true,
        'primarySiteUrl' => rtrim((string)Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/'),
        'plugins' => [
            'agents' => pluginState('agents'),
            'retour' => pluginState('retour'),
            'agents-retour' => pluginState('agents-retour'),
        ],
        'tables' => [
            'retourRedirects' => Craft::$app->getDb()->tableExists(RETOUR_REDIRECTS_TABLE),
            'agentsCredentials' => Craft::$app->getDb()->tableExists(AGENTS_CREDENTIALS_TABLE),
        ],
        'externalProviders' => $resources,
        'retourRedirectsResource' => $retourResource,
        'defaultScopes' => (array)($posture['authentication']['tokenScopes'] ?? []),
    ];
}

function seedRedirect(): array
{
    ensureRetourReady();

    $site = Craft::$app->getSites()->getPrimarySite();
    $siteId = (int)$site->id;
    $associatedElementId = firstElementId();
    $existing = (new Query())
        ->from(RETOUR_REDIRECTS_TABLE)
        ->where(['redirectSrcUrl' => TEST_SOURCE_URL])
        ->one();

    $now = gmdate('Y-m-d H:i:s');
    $row = [
        'dateCreated' => $existing['dateCreated'] ?? $now,
        'dateUpdated' => $now,
        'uid' => $existing['uid'] ?? StringHelper::UUID(),
        'siteId' => $siteId,
        'associatedElementId' => $associatedElementId,
        'enabled' => 1,
        'redirectSrcUrl' => TEST_SOURCE_URL,
        'redirectSrcUrlParsed' => TEST_SOURCE_URL,
        'redirectSrcMatch' => 'pathonly',
        'redirectMatchType' => 'exactmatch',
        'redirectDestUrl' => TEST_DESTINATION_URL,
        'redirectHttpCode' => 301,
        'priority' => 5,
        'hitCount' => 0,
        'hitLastTime' => null,
    ];

    if (is_array($existing)) {
        Craft::$app->getDb()->createCommand()
            ->update(RETOUR_REDIRECTS_TABLE, $row, ['id' => (int)$existing['id']])
            ->execute();

        $id = (int)$existing['id'];
    } else {
        Craft::$app->getDb()->createCommand()
            ->insert(RETOUR_REDIRECTS_TABLE, $row)
            ->execute();

        $id = (int)Craft::$app->getDb()->getLastInsertID(RETOUR_REDIRECTS_TABLE);
    }

    return [
        'ok' => true,
        'redirect' => [
            'id' => $id,
            'sourceUrl' => TEST_SOURCE_URL,
            'destinationUrl' => TEST_DESTINATION_URL,
            'siteId' => $siteId,
            'associatedElementId' => $associatedElementId,
        ],
    ];
}

function ensureTestCredentials(): array
{
    $plugin = AgentsPlugin::getInstance();
    if (!Craft::$app->getDb()->tableExists(AGENTS_CREDENTIALS_TABLE)) {
        fail('Agents credentials table is unavailable.');
    }

    $posture = $plugin->getSecurityPolicyService()->getCpPosture();
    $defaultScopes = (array)($posture['authentication']['tokenScopes'] ?? []);

    Craft::$app->getDb()->createCommand()
        ->delete(AGENTS_CREDENTIALS_TABLE, ['handle' => [TEST_ALLOW_HANDLE, TEST_DENY_HANDLE]])
        ->execute();

    $credentialService = $plugin->getCredentialService();
    $allow = $credentialService->createManagedCredential(
        TEST_ALLOW_HANDLE,
        'F12 Retour Read',
        '',
        '',
        null,
        [],
        false,
        TEST_ALLOW_TOKEN,
        ['auth:read', 'capabilities:read', TEST_EXTERNAL_SCOPE],
        [],
        $defaultScopes
    );
    $deny = $credentialService->createManagedCredential(
        TEST_DENY_HANDLE,
        'F12 Retour Deny',
        '',
        '',
        null,
        [],
        false,
        TEST_DENY_TOKEN,
        ['auth:read'],
        [],
        $defaultScopes
    );

    return [
        'ok' => true,
        'credentials' => [
            'allow' => [
                'handle' => TEST_ALLOW_HANDLE,
                'token' => TEST_ALLOW_TOKEN,
                'scopes' => (array)($allow['credential']['scopes'] ?? []),
                'id' => (int)($allow['credential']['id'] ?? 0),
            ],
            'deny' => [
                'handle' => TEST_DENY_HANDLE,
                'token' => TEST_DENY_TOKEN,
                'scopes' => (array)($deny['credential']['scopes'] ?? []),
                'id' => (int)($deny['credential']['id'] ?? 0),
            ],
        ],
    ];
}

function cleanupFixtures(): array
{
    $deletedRedirects = 0;
    $deletedCredentials = 0;

    if (Craft::$app->getDb()->tableExists(RETOUR_REDIRECTS_TABLE)) {
        $deletedRedirects = Craft::$app->getDb()->createCommand()
            ->delete(RETOUR_REDIRECTS_TABLE, ['redirectSrcUrl' => TEST_SOURCE_URL])
            ->execute();
    }

    if (Craft::$app->getDb()->tableExists(AGENTS_CREDENTIALS_TABLE)) {
        $deletedCredentials = Craft::$app->getDb()->createCommand()
            ->delete(AGENTS_CREDENTIALS_TABLE, ['handle' => [TEST_ALLOW_HANDLE, TEST_DENY_HANDLE]])
            ->execute();
    }

    return [
        'ok' => true,
        'deletedRedirects' => $deletedRedirects,
        'deletedCredentials' => $deletedCredentials,
    ];
}

function pluginState(string $handle): array
{
    $plugins = Craft::$app->getPlugins();
    $plugin = $plugins->getPlugin($handle, false);

    return [
        'installed' => $plugins->isPluginInstalled($handle),
        'enabled' => $plugin !== null,
        'version' => $plugin?->schemaVersion ?? null,
    ];
}

function ensureRetourReady(): void
{
    if (!Craft::$app->getPlugins()->isPluginInstalled('retour')) {
        fail('Retour is not installed.');
    }
    if (!Craft::$app->getDb()->tableExists(RETOUR_REDIRECTS_TABLE)) {
        fail('Retour redirects table is unavailable.');
    }
}

function firstElementId(): int
{
    $id = (new Query())
        ->from('{{%elements}}')
        ->select(['id'])
        ->orderBy(['id' => SORT_ASC])
        ->scalar();
    if ($id === false || $id === null) {
        fail('No Craft elements are available for Retour redirect association.');
    }

    return (int)$id;
}
