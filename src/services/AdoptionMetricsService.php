<?php

namespace Klick\Agents\services;

use Craft;
use craft\base\Component;
use DateTimeImmutable;
use DateTimeZone;

class AdoptionMetricsService extends Component
{
    public function getSnapshot(array $defaultScopes): array
    {
        $plugin = \Klick\Agents\Plugin::getInstance();
        if ($plugin === null) {
            return [
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'funnel' => [
                    'configuredCredentials' => 0,
                    'managedCredentialsTotal' => 0,
                    'managedCredentialsUsedAtLeastOnce' => 0,
                    'managedCredentialsUnused' => 0,
                ],
                'timeToFirstSuccess' => [
                    'sampleSize' => 0,
                    'medianSeconds' => null,
                    'p90Seconds' => null,
                ],
                'usage' => [
                    'windowDays7' => ['activeCredentials' => 0, 'inactiveCredentials' => 0],
                    'windowDays30' => ['activeCredentials' => 0, 'inactiveCredentials' => 0],
                ],
            ];
        }

        $securityPosture = $plugin->getSecurityPolicyService()->getCpPosture();
        $configuredCredentials = (int)($securityPosture['authentication']['credentialCount'] ?? 0);
        $managedCredentials = $plugin->getCredentialService()->getManagedCredentials($defaultScopes);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $managedTotal = 0;
        $usedAtLeastOnce = 0;
        $active7d = 0;
        $active30d = 0;
        $ttfsSamples = [];

        foreach ($managedCredentials as $credential) {
            if (!is_array($credential)) {
                continue;
            }

            if ((bool)($credential['revoked'] ?? false)) {
                continue;
            }

            $managedTotal++;
            $createdAt = $this->parseIsoDate($credential['dateCreated'] ?? null);
            $lastUsedAt = $this->parseIsoDate($credential['lastUsedAt'] ?? null);

            if ($lastUsedAt === null) {
                continue;
            }

            $usedAtLeastOnce++;

            $secondsSinceLastUse = max(0, $now->getTimestamp() - $lastUsedAt->getTimestamp());
            if ($secondsSinceLastUse <= (7 * 86400)) {
                $active7d++;
            }
            if ($secondsSinceLastUse <= (30 * 86400)) {
                $active30d++;
            }

            if ($createdAt !== null) {
                $ttfsSamples[] = max(0, $lastUsedAt->getTimestamp() - $createdAt->getTimestamp());
            }
        }

        sort($ttfsSamples);
        $ttfsMedian = $this->percentileSeconds($ttfsSamples, 0.5);
        $ttfsP90 = $this->percentileSeconds($ttfsSamples, 0.9);

        return [
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'funnel' => [
                'configuredCredentials' => $configuredCredentials,
                'managedCredentialsTotal' => $managedTotal,
                'managedCredentialsUsedAtLeastOnce' => $usedAtLeastOnce,
                'managedCredentialsUnused' => max(0, $managedTotal - $usedAtLeastOnce),
            ],
            'timeToFirstSuccess' => [
                'sampleSize' => count($ttfsSamples),
                'medianSeconds' => $ttfsMedian,
                'p90Seconds' => $ttfsP90,
            ],
            'usage' => [
                'windowDays7' => [
                    'activeCredentials' => $active7d,
                    'inactiveCredentials' => max(0, $managedTotal - $active7d),
                ],
                'windowDays30' => [
                    'activeCredentials' => $active30d,
                    'inactiveCredentials' => max(0, $managedTotal - $active30d),
                ],
            ],
        ];
    }

    private function parseIsoDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($trimmed, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            Craft::warning('Unable to parse adoption metrics timestamp: ' . $trimmed, __METHOD__);
            return null;
        }
    }

    private function percentileSeconds(array $samples, float $percentile): ?int
    {
        $count = count($samples);
        if ($count === 0) {
            return null;
        }

        $rank = (int)ceil($percentile * $count) - 1;
        if ($rank < 0) {
            $rank = 0;
        }
        if ($rank >= $count) {
            $rank = $count - 1;
        }

        return (int)$samples[$rank];
    }
}
