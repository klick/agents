<?php

namespace Klick\Agents\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;
use Klick\Agents\Plugin;

class SendOperatorNotificationJob extends BaseJob implements RetryableJobInterface
{
    public int $notificationId = 0;

    public function execute($queue): void
    {
        if ($this->notificationId <= 0) {
            throw new \RuntimeException('Missing notification log ID.');
        }

        $sent = Plugin::getInstance()->getNotificationService()->processNotificationLog($this->notificationId);
        if (!$sent) {
            throw new \RuntimeException(sprintf('Operator notification #%d could not be sent.', $this->notificationId));
        }
    }

    public function getTtr(): int
    {
        return 300;
    }

    public function canRetry($attempt, $error): bool
    {
        Craft::warning(sprintf(
            'Operator notification #%d failed on attempt %d: %s',
            $this->notificationId,
            $attempt,
            $error instanceof \Throwable ? $error->getMessage() : 'unknown error'
        ), __METHOD__);

        return $attempt < 3;
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Send operator notification #%d', $this->notificationId);
    }
}
