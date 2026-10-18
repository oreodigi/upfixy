<?php

namespace App\Repairs;

/**
 * The ONLY provider surface the repair executor may use (FIX-003, AI-SEC-05):
 * read one record, overwrite one A/AAAA record, check a token can see one zone.
 * No delete, no create, no bulk, no other record types. There is deliberately
 * no generic request method.
 */
interface DnsProvider
{
    /** @return array{id:string, type:string, name:string, content:string, ttl:int, proxied:bool, ref:?string} */
    public function getRecord(string $token, string $zoneId, string $recordId): array;

    /**
     * @param array{type:string, name:string, content:string, ttl:int, proxied:bool} $record
     * @return array{id:string, type:string, name:string, content:string, ttl:int, proxied:bool, ref:?string}
     */
    public function updateAddressRecord(string $token, string $zoneId, string $recordId, array $record): array;

    /** @return array{zone_name:string, ref:?string} */
    public function zone(string $token, string $zoneId): array;
}
