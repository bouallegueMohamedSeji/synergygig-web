<?php

namespace App\EventListener;

use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Events;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;

#[AsDoctrineListener(event: Events::postConnect)]
class DoctrineTimezoneListener
{
    public function postConnect(ConnectionEventArgs $event): void
    {
        $connection = $event->getConnection();
        $phpTz = date_default_timezone_get();

        // Use UTC offset directly — named timezones (e.g. Africa/Lagos) can hang
        // MariaDB on XAMPP even when timezone tables are present.
        $tz = new \DateTimeZone($phpTz);
        $offset = $tz->getOffset(new \DateTime('now', $tz));
        $sign = $offset >= 0 ? '+' : '-';
        $hours = str_pad((string) intdiv(abs($offset), 3600), 2, '0', STR_PAD_LEFT);
        $minutes = str_pad((string) (abs($offset) % 3600 / 60), 2, '0', STR_PAD_LEFT);
        $mysqlTz = $sign . $hours . ':' . $minutes;

        $connection->executeStatement('SET time_zone = :tz', ['tz' => $mysqlTz]);
    }
}
