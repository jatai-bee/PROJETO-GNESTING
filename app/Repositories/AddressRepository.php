<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

/** Endereços salvos dos clientes com conta. */
final class AddressRepository extends Repository
{
    private const FIELDS = 'id, customer_id, label, recipient_name, zip_code, street, number, complement, district, city, state, is_default';

    /** @return list<array<string, mixed>> padrão primeiro */
    public function forCustomer(int $customerId): array
    {
        return $this->fetchAll(
            'SELECT ' . self::FIELDS . ' FROM addresses WHERE customer_id = :id ORDER BY is_default DESC, updated_at DESC, id DESC',
            ['id' => $customerId]
        );
    }

    /** @return array<string, mixed>|null somente se o endereço for do cliente */
    public function find(int $customerId, int $addressId): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::FIELDS . ' FROM addresses WHERE id = :id AND customer_id = :customer_id',
            ['id' => $addressId, 'customer_id' => $customerId]
        );
    }

    /** Evita duplicar o mesmo endereço a cada compra. */
    public function exists(int $customerId, string $zip, string $number, ?string $complement): bool
    {
        return $this->fetchValue(
            'SELECT 1 FROM addresses WHERE customer_id = :id AND zip_code = :zip AND number = :number AND COALESCE(complement, \'\') = :complement',
            ['id' => $customerId, 'zip' => $zip, 'number' => $number, 'complement' => $complement ?? '']
        ) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(int $customerId, array $data): int
    {
        return $this->insert(
            'INSERT INTO addresses (customer_id, recipient_name, zip_code, street, number, complement, district, city, state, is_default)
             SELECT :customer_id, :recipient_name, :zip_code, :street, :number, :complement, :district, :city, :state,
                    NOT EXISTS (SELECT 1 FROM addresses a WHERE a.customer_id = :customer_id2)',
            $data + ['customer_id' => $customerId, 'customer_id2' => $customerId]
        );
    }
}
